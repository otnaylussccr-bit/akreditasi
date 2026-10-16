<?php

namespace App\Services;

use App\Models\AccreditationActivityLog;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Support\BusinessDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccreditationWorkflowActivityService
{
    public const EVENTS = [
        'document.uploaded' => [
            'label' => 'Dokumen diunggah',
            'short_label' => 'Upload',
            'category' => 'upload',
            'category_label' => 'Upload Dokumen',
            'color' => '#4D91D1',
        ],
        'document.emergency_uploaded' => [
            'label' => 'Dokumen diunggah oleh Super Admin',
            'short_label' => 'Upload',
            'category' => 'upload',
            'category_label' => 'Upload Dokumen',
            'color' => '#4D91D1',
        ],
        'document.submitted' => [
            'label' => 'Dokumen dikirim untuk verifikasi',
            'short_label' => 'Dikirim',
            'category' => 'submission',
            'category_label' => 'Pengiriman Verifikasi',
            'color' => '#E9AC36',
        ],
        'document.revision_requested' => [
            'label' => 'Perbaikan dokumen diminta',
            'short_label' => 'Perlu Revisi',
            'category' => 'revision',
            'category_label' => 'Revisi',
            'color' => '#C94B4B',
        ],
        'document.verified' => [
            'label' => 'Dokumen terverifikasi',
            'short_label' => 'Terverifikasi',
            'category' => 'verification',
            'category_label' => 'Verifikasi',
            'color' => '#43A878',
        ],
    ];

    public function __construct(
        private readonly AccreditationAuthorizationService $authorization,
    ) {}

    public function canView(User $user): bool
    {
        return $user->can('activity.view')
            || ($user->hasRole('PIC Kriteria') && $user->can('document.view'));
    }

    public function query(User $user, AccreditationProject $project, ?int $criterionId = null): Builder
    {
        $query = AccreditationActivityLog::query();

        if (! $this->canView($user)) {
            return $query->whereKey([]);
        }

        $criterionIds = $this->authorization->accessibleCriteria($user, $project)
            ->when($criterionId !== null, fn (Builder $criteria) => $criteria->whereKey($criterionId))
            ->select('accreditation_criteria.id');

        return $query
            ->where('project_id', $project->id)
            ->whereIn('event', array_keys(self::EVENTS))
            ->where(function (Builder $subjects) use ($criterionIds): void {
                $subjects
                    ->where(function (Builder $documents) use ($criterionIds): void {
                        $documents->where('subject_type', AccreditationDocument::class)
                            ->whereIn('subject_id', $this->documentIds($criterionIds));
                    })
                    ->orWhere(function (Builder $submissions) use ($criterionIds): void {
                        $submissions->where('subject_type', AccreditationReviewSubmission::class)
                            ->whereIn('subject_id', $this->submissionIds($criterionIds));
                    });
            });
    }

    public function filteredQuery(
        Builder $scopedQuery,
        AccreditationProject $project,
        array $filters,
    ): Builder {
        $query = clone $scopedQuery;
        $event = $filters['event'] ?? '';
        $actorId = $filters['actor_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $query
            ->when($event !== '', fn (Builder $activities) => $activities->where('event', $event))
            ->when($actorId !== null, fn (Builder $activities) => $activities->where('actor_id', $actorId))
            ->when($dateFrom !== null, fn (Builder $activities) => $activities
                ->where('created_at', '>=', BusinessDateTime::startOfDayUtc($dateFrom)))
            ->when($dateTo !== null, fn (Builder $activities) => $activities
                ->where('created_at', '<=', BusinessDateTime::endOfDayUtc($dateTo)));

        if ($search !== '') {
            $criterionIds = AccreditationCriterion::query()
                ->select('accreditation_criteria.id')
                ->where('project_id', $project->id);
            $matchingActors = User::query()
                ->select('users.id')
                ->where('name', 'like', "%{$search}%");

            $query->where(function (Builder $matches) use ($criterionIds, $matchingActors, $search): void {
                $matches
                    ->whereIn('actor_id', $matchingActors)
                    ->orWhere(function (Builder $documents) use ($criterionIds, $search): void {
                        $documents->where('subject_type', AccreditationDocument::class)
                            ->whereIn('subject_id', $this->documentIds($criterionIds, $search));
                    })
                    ->orWhere(function (Builder $submissions) use ($criterionIds, $search): void {
                        $submissions->where('subject_type', AccreditationReviewSubmission::class)
                            ->whereIn('subject_id', $this->submissionIds($criterionIds, $search));
                    });
            });
        }

        return $query;
    }

    public function latest(User $user, AccreditationProject $project, int $limit = 5): Collection
    {
        $logs = $this->query($user, $project)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->project($logs, $project);
    }

    public function project(Collection $logs, AccreditationProject $project): Collection
    {
        if ($logs->isEmpty()) {
            return collect();
        }

        $documents = AccreditationDocument::query()
            ->with('requirement.item.criterion')
            ->whereIn('id', $logs->where('subject_type', AccreditationDocument::class)->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $submissions = AccreditationReviewSubmission::query()
            ->with('requirement.item.criterion')
            ->whereIn('id', $logs->where('subject_type', AccreditationReviewSubmission::class)->pluck('subject_id'))
            ->get()
            ->keyBy('id');
        $actors = User::query()
            ->with('roles:id,name')
            ->whereIn('id', $logs->pluck('actor_id')->filter())
            ->get()
            ->keyBy('id');

        return $logs->map(function (AccreditationActivityLog $log) use (
            $documents,
            $submissions,
            $actors,
            $project,
        ): ?array {
            $subject = match ($log->subject_type) {
                AccreditationDocument::class => $documents->get($log->subject_id),
                AccreditationReviewSubmission::class => $submissions->get($log->subject_id),
                default => null,
            };
            $item = $subject?->requirement?->item;
            $event = self::EVENTS[$log->event] ?? null;

            if (! $item || ! $event) {
                return null;
            }

            $actor = $actors->get($log->actor_id);

            return [
                'id' => $log->id,
                'event' => $log->event,
                'label' => $event['label'],
                'short_label' => $event['short_label'],
                'category' => $event['category'],
                'category_label' => $event['category_label'],
                'color' => $event['color'],
                'actor_id' => $actor?->id,
                'actor' => $actor?->name ?? 'Sistem',
                'actor_initials' => $this->initials($actor?->name ?? 'Sistem'),
                'actor_role' => $this->actorRole($actor),
                'created_at' => $log->created_at,
                'criterion_id' => $item->criterion_id,
                'criterion_code' => $item->criterion->code,
                'item_code' => $item->criterion->code.'.'.$item->number,
                'item_title' => $item->title,
                'url' => route('follow-up-led.index', [
                    'proyek' => $project->id,
                    'kriteria' => $item->criterion_id,
                    'cari' => $item->title,
                ]),
            ];
        })->filter()->values();
    }

    public function openRevisionCount(
        User $user,
        AccreditationProject $project,
        ?int $criterionId = null,
    ): int {
        $criterionIds = $this->authorization->accessibleCriteria($user, $project)
            ->when($criterionId !== null, fn (Builder $criteria) => $criteria->whereKey($criterionId))
            ->select('accreditation_criteria.id');

        return AccreditationDocumentRequirement::query()
            ->where('workflow_status', 'need_revision')
            ->whereHas('item', fn (Builder $items) => $items->whereIn('criterion_id', $criterionIds))
            ->count();
    }

    public function mostActivePic(Builder $filteredQuery): ?array
    {
        $activityCounts = (clone $filteredQuery)
            ->whereNotNull('actor_id')
            ->selectRaw('actor_id, COUNT(*) as activity_count')
            ->groupBy('actor_id')
            ->pluck('activity_count', 'actor_id');

        if ($activityCounts->isEmpty()) {
            return null;
        }

        $pic = User::query()
            ->role('PIC Kriteria')
            ->whereIn('id', $activityCounts->keys())
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (User $user): array => [-(int) $activityCounts[$user->id], $user->name, $user->id])
            ->first();

        if (! $pic) {
            return null;
        }

        return [
            'name' => $pic->name,
            'initials' => $this->initials($pic->name),
            'count' => (int) $activityCounts[$pic->id],
        ];
    }

    public function categorySummaries(Builder $filteredQuery, AccreditationProject $project): Collection
    {
        $eventCounts = (clone $filteredQuery)
            ->selectRaw('event, COUNT(*) as activity_count')
            ->groupBy('event')
            ->pluck('activity_count', 'event');

        return collect(['upload', 'submission', 'revision', 'verification'])
            ->map(function (string $category) use ($eventCounts, $filteredQuery, $project): array {
                $events = collect(self::EVENTS)
                    ->filter(fn (array $definition): bool => $definition['category'] === $category)
                    ->keys();
                $latestLog = (clone $filteredQuery)
                    ->whereIn('event', $events)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();
                $latest = $latestLog ? $this->project(collect([$latestLog]), $project)->first() : null;

                return [
                    'category' => $category,
                    'label' => $latest['category_label'] ?? match ($category) {
                        'upload' => 'Upload Dokumen',
                        'submission' => 'Pengiriman Verifikasi',
                        'revision' => 'Revisi',
                        default => 'Verifikasi',
                    },
                    'count' => $events->sum(fn (string $event): int => (int) ($eventCounts[$event] ?? 0)),
                    'latest' => $latest,
                ];
            });
    }

    private function documentIds(Builder $criterionIds, ?string $search = null): Builder
    {
        return AccreditationDocument::query()
            ->select('accreditation_documents.id')
            ->whereHas('requirement.item', fn (Builder $items) => $items
                ->whereIn('criterion_id', clone $criterionIds))
            ->when($search !== null, function (Builder $documents) use ($search): void {
                $documents->where(function (Builder $matches) use ($search): void {
                    $matches
                        ->where('original_name', 'like', "%{$search}%")
                        ->orWhereHas('requirement', fn (Builder $requirements) => $requirements
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('item', fn (Builder $items) => $items
                                ->where('title', 'like', "%{$search}%")
                                ->orWhereHas('criterion', fn (Builder $criteria) => $criteria
                                    ->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%"))));
                });
            });
    }

    private function submissionIds(Builder $criterionIds, ?string $search = null): Builder
    {
        return AccreditationReviewSubmission::query()
            ->select('accreditation_review_submissions.id')
            ->whereHas('requirement.item', fn (Builder $items) => $items
                ->whereIn('criterion_id', clone $criterionIds))
            ->when($search !== null, function (Builder $submissions) use ($search): void {
                $submissions->where(function (Builder $matches) use ($search): void {
                    $matches
                        ->whereHas('documents', fn (Builder $documents) => $documents
                            ->where('original_name', 'like', "%{$search}%"))
                        ->orWhereHas('requirement', fn (Builder $requirements) => $requirements
                            ->where('title', 'like', "%{$search}%")
                            ->orWhereHas('item', fn (Builder $items) => $items
                                ->where('title', 'like', "%{$search}%")
                                ->orWhereHas('criterion', fn (Builder $criteria) => $criteria
                                    ->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%"))));
                });
            });
    }

    private function actorRole(?User $actor): string
    {
        if (! $actor) {
            return 'Sistem';
        }

        return match (true) {
            $actor->hasRole('Reviewer') => 'Verifikator Internal',
            $actor->hasRole('PIC Kriteria') => 'PIC Kriteria',
            $actor->hasRole('Project Manager') => 'Project Manager',
            $actor->hasRole('Super Admin') => 'Super Admin',
            default => $actor->getRoleNames()->first() ?? 'Tim Akreditasi',
        };
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
