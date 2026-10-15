<?php

namespace App\Livewire;

use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProgressService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationWorkflowActivityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Ringkasan Progres | APU Accreditation')]
class Dashboard extends Component
{
    private const ATTENTION_THRESHOLD_DAYS = 30;

    private const STATUS_COLORS = [
        'verified' => '#43A878',
        'pending_review' => '#E9AC36',
        'need_revision' => '#C94B4B',
        'draft' => '#4D91D1',
        'in_progress' => '#F2BE4D',
        'not_started' => '#DF7373',
    ];

    #[Url(as: 'proyek', except: null)]
    public ?int $projectId = null;

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        abort_unless(Auth::user()->can('dashboard.view'), 403);
        $projects = $context->ordered($this->authorizedProjects($authorization));
        $project = $context->resolve($projects, $this->projectId);

        if (! $project) {
            $this->projectId = null;

            return;
        }

        $this->projectId = $project->id;
    }

    public function selectProject(
        int $projectId,
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $this->projectId = $context->select($this->authorizedProjects($authorization), $projectId)->id;
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProgressService $progress,
        AccreditationWorkflowActivityService $activity,
        AccreditationProjectContextService $context,
    ) {
        $user = Auth::user();
        $projects = $context->ordered($this->authorizedProjects($authorization));

        if ($projects->isEmpty()) {
            return view('livewire.dashboard', $this->emptyViewData($projects, $progress));
        }

        $project = $projects->firstWhere('id', $this->projectId);
        abort_unless($project, 403);
        $criteria = $authorization->accessibleCriteria($user, $project)
            ->with('items.requirements')
            ->orderBy('position')
            ->get();
        $items = $criteria->flatMap(function ($criterion): Collection {
            return $criterion->items->each(
                fn (AccreditationItem $item) => $item->setRelation('criterion', $criterion),
            );
        })->values();
        $summary = $progress->summarize($items, $project);
        $statusBuckets = $this->statusBuckets($summary, $progress);
        $canViewActivity = $activity->canView($user);

        return view('livewire.dashboard', [
            'projects' => $projects,
            'project' => $project,
            'criteriaProgress' => $criteria->map(function ($criterion) use ($progress, $project): array {
                $criterionSummary = $progress->summarize($criterion->items, $project);

                return [
                    'criterion' => $criterion,
                    'summary' => $criterionSummary,
                    'segments' => $this->statusBuckets($criterionSummary, $progress),
                ];
            }),
            'summary' => $summary,
            'statusBuckets' => $statusBuckets,
            'donutGradient' => $this->donutGradient($statusBuckets, $summary['work_count']),
            'deadline' => $this->deadlineSummary($project),
            'attentionItems' => $this->attentionItems($summary['work_items'], $project),
            'attentionThresholdDays' => self::ATTENTION_THRESHOLD_DAYS,
            'activities' => $activity->latest($user, $project),
            'canViewActivity' => $canViewActivity,
            'activityUrl' => $canViewActivity
                ? route('team-activity.index', ['proyek' => $project->id])
                : null,
            'followUpUrl' => route('follow-up-led.index', ['proyek' => $project->id]),
        ]);
    }

    private function authorizedProjects(AccreditationAuthorizationService $authorization): Collection
    {
        return $authorization->accessibleProjects(Auth::user())
            ->with('studyProgram')
            ->get();
    }

    private function emptyViewData(Collection $projects, AccreditationProgressService $progress): array
    {
        return [
            'projects' => $projects,
            'project' => null,
            'criteriaProgress' => collect(),
            'summary' => null,
            'statusBuckets' => collect(),
            'donutGradient' => null,
            'deadline' => null,
            'attentionItems' => collect(),
            'attentionThresholdDays' => self::ATTENTION_THRESHOLD_DAYS,
            'activities' => collect(),
            'canViewActivity' => app(AccreditationWorkflowActivityService::class)->canView(Auth::user()),
            'activityUrl' => null,
            'followUpUrl' => null,
            'statusLabels' => $progress->statusLabels(),
        ];
    }

    private function statusBuckets(array $summary, AccreditationProgressService $progress): Collection
    {
        $labels = $progress->statusLabels();
        $total = $summary['work_count'];

        return collect(AccreditationProgressService::AGGREGATE_STATUSES)
            ->map(function (string $status) use ($summary, $labels, $total): array {
                $count = $summary['status_counts'][$status];

                return [
                    'status' => $status,
                    'label' => $labels[$status],
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                    'color' => self::STATUS_COLORS[$status],
                ];
            });
    }

    private function donutGradient(Collection $buckets, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        $cursor = 0.0;
        $segments = [];

        foreach ($buckets as $bucket) {
            if ($bucket['count'] === 0) {
                continue;
            }

            $end = $cursor + (($bucket['count'] / $total) * 100);
            $segments[] = sprintf('%s %.4f%% %.4f%%', $bucket['color'], $cursor, $end);
            $cursor = $end;
        }

        return 'conic-gradient('.implode(', ', $segments).')';
    }

    private function deadlineSummary(AccreditationProject $project): array
    {
        $deadline = $project->final_deadline->copy()->startOfDay();
        $days = (int) now()->startOfDay()->diffInDays($deadline, false);

        return [
            'date' => $deadline,
            'days' => $days,
            'state' => $days < 0 ? 'overdue' : ($days === 0 ? 'today' : 'upcoming'),
            'label' => match (true) {
                $days < 0 => 'Terlambat '.abs($days).' hari',
                $days === 0 => 'Jatuh tempo hari ini',
                default => $days.' hari lagi',
            },
        ];
    }

    private function attentionItems(Collection $workItems, AccreditationProject $project): Collection
    {
        return $workItems
            ->filter(fn (AccreditationItem $item): bool => $item->aggregate_status !== 'verified')
            ->map(function (AccreditationItem $item) use ($project): ?array {
                $deadline = $item->target_deadline ?? $item->source_deadline;
                $days = $deadline
                    ? (int) now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false)
                    : null;
                $isOverdue = $days !== null && $days < 0;
                $isNearDeadline = $days !== null && $days >= 0 && $days <= self::ATTENTION_THRESHOLD_DAYS;

                $priority = match (true) {
                    $isOverdue => 0,
                    $item->has_required_revision => 1,
                    $item->has_pending_review => 2,
                    $isNearDeadline => 3,
                    default => null,
                };

                if ($priority === null) {
                    return null;
                }

                return [
                    'item' => $item,
                    'deadline' => $deadline,
                    'days' => $days,
                    'priority' => $priority,
                    'time_label' => match (true) {
                        $days === null => 'Belum ditentukan',
                        $days < 0 => 'Terlambat '.abs($days).' hari',
                        $days === 0 => 'Hari ini',
                        default => $days.' hari lagi',
                    },
                    'url' => route('follow-up-led.index', [
                        'proyek' => $project->id,
                        'kriteria' => $item->criterion_id,
                        'cari' => $item->title,
                    ]),
                ];
            })
            ->filter()
            ->sortBy(fn (array $entry): array => [
                $entry['priority'],
                $entry['deadline']?->timestamp ?? PHP_INT_MAX,
                $entry['item']->criterion->position,
                $entry['item']->position,
            ])
            ->take(5)
            ->values();
    }
}
