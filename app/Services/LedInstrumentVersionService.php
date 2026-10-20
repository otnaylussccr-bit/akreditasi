<?php

namespace App\Services;

use App\Models\AccreditationLedInstrumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LedInstrumentVersionService
{
    public function __construct(private readonly AdministrativeAuditService $audit) {}

    public function manifestHash(AccreditationLedInstrumentVersion $version): string
    {
        $version->loadMissing('indicators.rubrics');
        $indicators = $version->indicators->map(fn ($indicator): array => [
            'indicator_number' => $indicator->indicator_number,
            'criterion_code' => $indicator->criterion_code,
            'title' => $indicator->title,
            'description' => $indicator->description,
            'position' => $indicator->position,
            'page_start' => $indicator->page_start,
            'page_end' => $indicator->page_end,
            'is_assessable' => $indicator->is_assessable,
            'rubrics' => $indicator->rubrics->map(fn ($rubric): array => [
                'category_code' => $rubric->category_code,
                'category_label' => $rubric->category_label,
                'internal_score' => $rubric->internal_score,
                'description' => $rubric->description,
                'position' => $rubric->position,
                'page_start' => $rubric->page_start,
                'page_end' => $rubric->page_end,
            ])->values()->all(),
        ])->values()->all();

        return $this->configurationHash($indicators);
    }

    public function configurationHash(array $indicators): string
    {
        $manifest = collect($indicators)
            ->sortBy('position')
            ->map(fn (array $indicator): array => [
                'indicator_number' => (int) $indicator['indicator_number'],
                'criterion_code' => (string) $indicator['criterion_code'],
                'title' => (string) $indicator['title'],
                'description' => $indicator['description'] ?? null,
                'position' => (int) $indicator['position'],
                'page_start' => isset($indicator['page_start']) ? (int) $indicator['page_start'] : null,
                'page_end' => isset($indicator['page_end']) ? (int) $indicator['page_end'] : null,
                'is_assessable' => (bool) $indicator['is_assessable'],
                'rubrics' => collect($indicator['rubrics'] ?? [])
                    ->sortBy('position')
                    ->map(fn (array $rubric): array => [
                        'category_code' => (string) $rubric['category_code'],
                        'category_label' => (string) $rubric['category_label'],
                        'internal_score' => number_format((float) $rubric['internal_score'], 2, '.', ''),
                        'description' => (string) $rubric['description'],
                        'position' => (int) $rubric['position'],
                        'page_start' => isset($rubric['page_start']) ? (int) $rubric['page_start'] : null,
                        'page_end' => isset($rubric['page_end']) ? (int) $rubric['page_end'] : null,
                    ])->values()->all(),
            ])->values()->all();

        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function publish(AccreditationLedInstrumentVersion $version, User $actor): AccreditationLedInstrumentVersion
    {
        if (Auth::id() !== $actor->id || ! $actor->can('project.manage') || ! $actor->hasRole('Super Admin')) {
            throw new AuthorizationException(__('led_setup.messages.version.publish_unauthorized'));
        }

        return DB::transaction(function () use ($version, $actor): AccreditationLedInstrumentVersion {
            $target = AccreditationLedInstrumentVersion::query()
                ->with('indicators.rubrics')
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($target->status !== 'draft') {
                throw ValidationException::withMessages(['instrument' => __('led_setup.messages.version.only_draft_publishable')]);
            }

            if (! preg_match('/^[a-f0-9]{64}$/i', $target->source_pdf_sha256)
                || ! Storage::disk($target->source_disk)->exists($target->source_storage_path)
                || ! hash_equals(strtolower($target->source_pdf_sha256), $this->storedFileHash($target))) {
                throw ValidationException::withMessages(['source' => __('led_setup.messages.version.source_or_hash_invalid')]);
            }

            $manifestHash = $this->manifestHash($target);

            if (! is_string($target->rubric_manifest_sha256)
                || ! preg_match('/^[a-f0-9]{64}$/i', $target->rubric_manifest_sha256)
                || ! hash_equals(strtolower($target->rubric_manifest_sha256), $manifestHash)) {
                throw ValidationException::withMessages(['manifest' => __('led_setup.messages.version.manifest_hash_mismatch')]);
            }

            if ($target->indicators->isEmpty() || $target->indicators->contains(fn ($indicator): bool => $indicator->is_assessable && $indicator->rubrics->isEmpty())) {
                throw ValidationException::withMessages(['manifest' => __('led_setup.messages.version.assessable_indicator_requires_sourced_rubric')]);
            }

            $target->update(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()]);
            $this->audit->record($actor, 'instrument_version.published', $target, $target->version_code, ['status' => 'draft'], ['status' => 'published']);

            return $target->fresh('indicators.rubrics');
        });
    }

    private function storedFileHash(AccreditationLedInstrumentVersion $version): string
    {
        $stream = Storage::disk($version->source_disk)->readStream($version->source_storage_path);

        if ($stream === false) {
            throw new RuntimeException(__('led_setup.messages.version.source_unreadable'));
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
