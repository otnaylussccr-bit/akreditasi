<?php

namespace App\Services;

use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationItem;
use App\Models\AccreditationProject;
use Illuminate\Support\Collection;

class AccreditationProgressService
{
    public const AGGREGATE_STATUSES = [
        'verified',
        'pending_review',
        'need_revision',
        'draft',
        'in_progress',
        'not_started',
    ];

    private const BIOTECH_PROJECT_CODE = 'APU-BIOTEK-2026';

    public function summarize(Collection $items, AccreditationProject $project): array
    {
        $items->each(fn (AccreditationItem $item) => $this->decorateItem($item, $project));
        $headerItems = $items->where('is_led_header', true);
        $workItems = $items->where('is_led_header', false);
        $statusCounts = collect(self::AGGREGATE_STATUSES)
            ->mapWithKeys(fn (string $status): array => [$status => 0]);

        foreach ($workItems as $item) {
            $status = in_array($item->aggregate_status, self::AGGREGATE_STATUSES, true)
                ? $item->aggregate_status
                : 'in_progress';
            $statusCounts->put($status, $statusCounts->get($status) + 1);
        }

        $verifiedCount = $statusCounts['verified'];
        $workCount = $workItems->count();

        return [
            'items' => $items,
            'header_items' => $headerItems,
            'work_items' => $workItems,
            'structure_count' => $items->count(),
            'header_count' => $headerItems->count(),
            'work_count' => $workCount,
            'verified_count' => $verifiedCount,
            'completion_percentage' => $workCount > 0
                ? (int) round(($verifiedCount / $workCount) * 100)
                : 0,
            'status_counts' => $statusCounts,
        ];
    }

    public function decorateItem(AccreditationItem $item, AccreditationProject $project): AccreditationItem
    {
        $isHeader = $this->isHeader($item, $project);
        $item->setAttribute('is_led_header', $isHeader);

        if ($isHeader) {
            $item->setAttribute('required_count', 0);
            $item->setAttribute('verified_required_count', 0);
            $item->setAttribute('verified_progress', null);
            $item->setAttribute('aggregate_status', 'header');
            $item->setAttribute('has_required_revision', false);
            $item->setAttribute('has_pending_review', false);

            return $item;
        }

        $required = $item->requirements->where('is_required', true);
        $requiredCount = $required->count();
        $verifiedCount = $required->where('workflow_status', 'verified')->count();

        $item->setAttribute('required_count', $requiredCount);
        $item->setAttribute('verified_required_count', $verifiedCount);
        $item->setAttribute('verified_progress', $requiredCount > 0
            ? (int) round(($verifiedCount / $requiredCount) * 100)
            : 0);
        $item->setAttribute('aggregate_status', $this->aggregateStatus($required));
        $item->setAttribute('has_required_revision', $required->contains('workflow_status', 'need_revision'));
        $item->setAttribute('has_pending_review', $required->contains('workflow_status', 'pending_review'));

        return $item;
    }

    public function statusLabels(): array
    {
        return [
            'verified' => 'Terverifikasi',
            'pending_review' => 'Menunggu Verifikasi',
            'need_revision' => 'Perlu Revisi',
            'draft' => 'Draft',
            'in_progress' => 'Dalam Proses',
            'not_started' => 'Belum Mulai',
        ];
    }

    private function isHeader(AccreditationItem $item, AccreditationProject $project): bool
    {
        return $project->code === self::BIOTECH_PROJECT_CODE && $item->number === 1;
    }

    private function aggregateStatus(Collection $required): string
    {
        $statuses = $required
            ->map(fn (AccreditationDocumentRequirement $requirement): string => $requirement->workflow_status)
            ->unique()
            ->values();

        if ($statuses->isEmpty()) {
            return 'not_started';
        }

        if ($statuses->count() === 1 && in_array($statuses->first(), self::AGGREGATE_STATUSES, true)) {
            return $statuses->first();
        }

        return 'in_progress';
    }
}
