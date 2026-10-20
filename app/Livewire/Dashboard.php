<?php

namespace App\Livewire;

use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProgressService;
use App\Services\AccreditationProjectContextService;
use App\Services\AccreditationWorkflowActivityService;
use App\Support\BusinessDateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

#[Layout('components.layouts.app')]
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

    public ?string $lastUpdatedAt = null;

    public function mount(
        AccreditationAuthorizationService $authorization,
        AccreditationProjectContextService $context,
    ): void {
        $this->authorizeDashboard();

        if ($this->isViewerTvMode()) {
            $this->lastUpdatedAt = now()->toIso8601String();
        }

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
        $this->authorizeDashboard();
        $this->projectId = $context->select($this->authorizedProjects($authorization), $projectId)->id;
    }

    public function refreshDashboard(): void
    {
        $this->authorizeDashboard();
        abort_unless($this->isViewerTvMode(), 403);

        $this->lastUpdatedAt = now()->toIso8601String();
    }

    public function render(
        AccreditationAuthorizationService $authorization,
        AccreditationProgressService $progress,
        AccreditationWorkflowActivityService $activity,
        AccreditationProjectContextService $context,
    ) {
        $this->authorizeDashboard();
        $user = Auth::user();
        $viewerTvMode = $this->isViewerTvMode();
        $projects = $context->ordered($this->authorizedProjects($authorization));

        if ($projects->isEmpty()) {
            return view('livewire.dashboard', $this->emptyViewData($projects, $progress))
                ->title(__('dashboard.meta_title'));
        }

        $project = $projects->firstWhere('id', $this->projectId);

        if (! $project && $this->projectId === null) {
            $project = $context->resolve($projects);
            $this->projectId = $project?->id;
        }

        abort_unless($project, 403);
        $criteria = $authorization->accessibleCriteria($user, $project)
            ->select(['id', 'project_id', 'code', 'name', 'color_header', 'color_body', 'position'])
            ->with(['items' => function ($items): void {
                $items
                    ->select(['id', 'criterion_id', 'number', 'title', 'source_deadline', 'target_deadline', 'position'])
                    ->orderBy('position')
                    ->with(['requirements' => fn ($requirements) => $requirements
                        ->select(['id', 'item_id', 'is_required', 'workflow_status'])]);
            }])
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
            'attentionItems' => $this->attentionItems($summary['work_items'], $project, ! $viewerTvMode),
            'attentionThresholdDays' => self::ATTENTION_THRESHOLD_DAYS,
            'activities' => $canViewActivity ? $activity->latest($user, $project) : collect(),
            'canViewActivity' => $canViewActivity,
            'activityUrl' => $canViewActivity
                ? route('team-activity.index', ['proyek' => $project->id])
                : null,
            'followUpUrl' => $viewerTvMode
                ? null
                : route('follow-up-led.index', ['proyek' => $project->id]),
            'viewerTvMode' => $viewerTvMode,
        ])->title(__('dashboard.meta_title'));
    }

    private function authorizedProjects(AccreditationAuthorizationService $authorization): Collection
    {
        return $authorization->accessibleProjects(Auth::user())
            ->select(['id', 'code', 'name', 'study_program_id', 'final_deadline', 'status'])
            ->with('studyProgram:id,name')
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
            'viewerTvMode' => $this->isViewerTvMode(),
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
        $businessDeadline = $deadline->copy()->setTimezone(config('app.business_timezone'))->startOfDay();
        $days = (int) BusinessDateTime::today()->diffInDays($businessDeadline, false);

        return [
            'date' => $deadline,
            'days' => $days,
            'state' => $days < 0 ? 'overdue' : ($days === 0 ? 'today' : 'upcoming'),
            'label' => match (true) {
                $days < 0 => trans_choice('dashboard.deadline.overdue', abs($days), ['count' => abs($days)]),
                $days === 0 => __('dashboard.deadline.today'),
                default => trans_choice('dashboard.deadline.upcoming', $days, ['count' => $days]),
            },
        ];
    }

    private function attentionItems(
        Collection $workItems,
        AccreditationProject $project,
        bool $includeLinks,
    ): Collection {
        return $workItems
            ->filter(fn (AccreditationItem $item): bool => $item->aggregate_status !== 'verified')
            ->map(function (AccreditationItem $item) use ($includeLinks, $project): ?array {
                $deadline = $item->target_deadline ?? $item->source_deadline;
                $days = $deadline
                    ? (int) BusinessDateTime::today()->diffInDays(
                        $deadline->copy()->setTimezone(config('app.business_timezone'))->startOfDay(),
                        false,
                    )
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
                        $days === null => __('dashboard.attention.not_defined'),
                        $days < 0 => trans_choice('dashboard.deadline.overdue', abs($days), ['count' => abs($days)]),
                        $days === 0 => __('dashboard.attention.today'),
                        default => trans_choice('dashboard.deadline.upcoming', $days, ['count' => $days]),
                    },
                    'url' => $includeLinks
                        ? route('follow-up-led.index', [
                            'proyek' => $project->id,
                            'kriteria' => $item->criterion_id,
                            'cari' => $item->title,
                        ])
                        : null,
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

    private function authorizeDashboard(): void
    {
        abort_unless(Auth::user()?->can('dashboard.view'), 403);
    }

    private function isViewerTvMode(): bool
    {
        return Auth::user()?->hasExactRoles('Viewer') ?? false;
    }
}
