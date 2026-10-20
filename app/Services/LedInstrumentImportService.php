<?php

namespace App\Services;

use App\Models\AccreditationInstrument;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationLedRubric;
use App\Models\User;
use App\Rules\SecureAccreditationDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class LedInstrumentImportService
{
    public function __construct(
        private readonly LedInstrumentVersionService $versions,
        private readonly AdministrativeAuditService $audit,
    ) {}

    public function preview(
        AccreditationInstrument $instrument,
        UploadedFile $sourcePdf,
        UploadedFile $manifestFile,
        User $actor,
    ): array {
        $this->authorize($actor);
        $this->validateFiles($sourcePdf, $manifestFile);
        $manifest = $this->validatedManifest($manifestFile);

        if (AccreditationLedInstrumentVersion::query()
            ->where('instrument_id', $instrument->id)
            ->where('version_code', $manifest['version_code'])
            ->exists()) {
            throw ValidationException::withMessages(['manifestFile' => __('led_setup.messages.import.version_code_taken')]);
        }

        return [
            'metadata' => collect($manifest)->except('indicators')->all(),
            'indicators' => $manifest['indicators'],
            'indicator_count' => count($manifest['indicators']),
            'assessable_count' => collect($manifest['indicators'])->where('is_assessable', true)->count(),
            'rubric_count' => collect($manifest['indicators'])->sum(fn (array $indicator): int => count($indicator['rubrics'])),
            'source_filename' => $sourcePdf->getClientOriginalName(),
            'source_pdf_sha256' => hash_file('sha256', $sourcePdf->getRealPath()),
            'rubric_manifest_sha256' => $this->versions->configurationHash($manifest['indicators']),
        ];
    }

    public function import(
        AccreditationInstrument $instrument,
        UploadedFile $sourcePdf,
        UploadedFile $manifestFile,
        User $actor,
    ): AccreditationLedInstrumentVersion {
        $preview = $this->preview($instrument, $sourcePdf, $manifestFile, $actor);
        $manifest = [...$preview['metadata'], 'indicators' => $preview['indicators']];

        $directory = "accreditation/instruments/{$instrument->id}";
        $storedPath = $sourcePdf->storeAs($directory, Str::uuid().'.pdf', 'local');

        if (! is_string($storedPath)) {
            throw new RuntimeException(__('led_setup.messages.import.source_store_failed'));
        }

        try {
            return DB::transaction(function () use ($instrument, $manifest, $preview, $sourcePdf, $storedPath, $actor): AccreditationLedInstrumentVersion {
                $version = AccreditationLedInstrumentVersion::create([
                    'instrument_id' => $instrument->id,
                    'version_code' => $manifest['version_code'],
                    'assessment_pathway' => $manifest['assessment_pathway'],
                    'education_level' => $manifest['education_level'],
                    'source_title' => $manifest['source_title'],
                    'source_reference' => $manifest['source_reference'],
                    'source_effective_date' => $manifest['source_effective_date'],
                    'source_filename' => $sourcePdf->getClientOriginalName(),
                    'source_disk' => 'local',
                    'source_storage_path' => $storedPath,
                    'source_pdf_sha256' => $preview['source_pdf_sha256'],
                    'status' => 'draft',
                    'created_by' => $actor->id,
                ]);

                foreach ($manifest['indicators'] as $indicatorData) {
                    $rubrics = $indicatorData['rubrics'];
                    unset($indicatorData['rubrics']);
                    $indicator = AccreditationLedIndicator::create([
                        'instrument_version_id' => $version->id,
                        ...$indicatorData,
                    ]);

                    foreach ($rubrics as $rubricData) {
                        AccreditationLedRubric::create(['indicator_id' => $indicator->id, ...$rubricData]);
                    }
                }

                $version->update(['rubric_manifest_sha256' => $this->versions->manifestHash($version->fresh())]);
                $this->audit->record($actor, 'instrument_version.imported', $version, $version->version_code, [], [
                    'status' => 'draft',
                    'indicator_count' => $preview['indicator_count'],
                    'rubric_count' => $preview['rubric_count'],
                ]);

                return $version->fresh('indicators.rubrics');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }
    }

    private function authorize(User $actor): void
    {
        if (Auth::id() !== $actor->id || ! $actor->is_active || ! $actor->hasRole('Super Admin') || ! $actor->can('project.manage')) {
            throw new AuthorizationException(__('led_setup.messages.import.unauthorized'));
        }
    }

    private function validateFiles(UploadedFile $sourcePdf, UploadedFile $manifestFile): void
    {
        Validator::make(
            ['sourcePdf' => $sourcePdf, 'manifestFile' => $manifestFile],
            [
                'sourcePdf' => ['required', 'file', 'max:51200'],
                'manifestFile' => ['required', 'file', 'max:2048'],
            ],
            [],
            __('led_setup.validation.import_attributes'),
        )->after(function ($validator) use ($sourcePdf, $manifestFile): void {
            (new SecureAccreditationDocument)->validate(
                'sourcePdf',
                $sourcePdf,
                fn () => $validator->errors()->add('sourcePdf', __('led_setup.messages.import.source_security_invalid')),
            );

            if (strtolower($sourcePdf->getClientOriginalExtension()) !== 'pdf') {
                $validator->errors()->add('sourcePdf', __('led_setup.messages.import.source_must_be_pdf'));
            }

            if (strtolower($manifestFile->getClientOriginalExtension()) !== 'json') {
                $validator->errors()->add('manifestFile', __('led_setup.messages.import.manifest_must_be_json'));
            }
        })->validate();
    }

    private function validatedManifest(UploadedFile $manifestFile): array
    {
        try {
            $contents = file_get_contents($manifestFile->getRealPath());
            $manifest = is_string($contents) ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $manifest = null;
        }

        if (! is_array($manifest)) {
            throw ValidationException::withMessages(['manifestFile' => __('led_setup.messages.import.manifest_invalid')]);
        }

        $validator = Validator::make(['manifest' => $manifest], [
            'manifest' => ['required', 'array:version_code,assessment_pathway,education_level,source_title,source_reference,source_effective_date,indicators'],
            'manifest.version_code' => ['required', 'string', 'max:80'],
            'manifest.assessment_pathway' => ['required', 'string', 'max:64'],
            'manifest.education_level' => ['required', 'string', 'max:32'],
            'manifest.source_title' => ['required', 'string', 'max:255'],
            'manifest.source_reference' => ['required', 'string', 'max:255'],
            'manifest.source_effective_date' => ['nullable', 'date_format:Y-m-d'],
            'manifest.indicators' => ['required', 'array', 'min:1'],
            'manifest.indicators.*' => ['required', 'array:indicator_number,criterion_code,title,description,position,page_start,page_end,is_assessable,rubrics'],
            'manifest.indicators.*.indicator_number' => ['required', 'integer', 'min:1', 'max:65535', 'distinct:strict'],
            'manifest.indicators.*.criterion_code' => ['required', 'string', 'max:24'],
            'manifest.indicators.*.title' => ['required', 'string', 'max:512'],
            'manifest.indicators.*.description' => ['nullable', 'string'],
            'manifest.indicators.*.position' => ['required', 'integer', 'min:1', 'max:65535', 'distinct:strict'],
            'manifest.indicators.*.page_start' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'manifest.indicators.*.page_end' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'manifest.indicators.*.is_assessable' => ['required', 'boolean'],
            'manifest.indicators.*.rubrics' => ['present', 'array'],
            'manifest.indicators.*.rubrics.*' => ['required', 'array:category_code,category_label,internal_score,description,position,page_start,page_end'],
            'manifest.indicators.*.rubrics.*.category_code' => ['required', 'string', 'max:32'],
            'manifest.indicators.*.rubrics.*.category_label' => ['required', 'string', 'max:80'],
            'manifest.indicators.*.rubrics.*.internal_score' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'manifest.indicators.*.rubrics.*.description' => ['required', 'string'],
            'manifest.indicators.*.rubrics.*.position' => ['required', 'integer', 'min:1', 'max:65535'],
            'manifest.indicators.*.rubrics.*.page_start' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'manifest.indicators.*.rubrics.*.page_end' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [], __('led_setup.validation.import_attributes'));

        $validator->after(function ($validator) use ($manifest): void {
            foreach ($manifest['indicators'] ?? [] as $index => $indicator) {
                if (($indicator['page_end'] ?? null) !== null
                    && (($indicator['page_start'] ?? null) === null || $indicator['page_end'] < $indicator['page_start'])) {
                    $validator->errors()->add("manifest.indicators.{$index}.page_end", __('led_setup.messages.import.indicator_page_range_invalid'));
                }

                if (($indicator['is_assessable'] ?? false) && empty($indicator['rubrics'])) {
                    $validator->errors()->add("manifest.indicators.{$index}.rubrics", __('led_setup.messages.import.assessable_indicator_requires_rubric'));
                }

                $codes = [];
                $positions = [];

                foreach ($indicator['rubrics'] ?? [] as $rubricIndex => $rubric) {
                    if (in_array($rubric['category_code'] ?? null, $codes, true)) {
                        $validator->errors()->add("manifest.indicators.{$index}.rubrics.{$rubricIndex}.category_code", __('led_setup.messages.import.rubric_category_unique'));
                    }

                    if (in_array($rubric['position'] ?? null, $positions, true)) {
                        $validator->errors()->add("manifest.indicators.{$index}.rubrics.{$rubricIndex}.position", __('led_setup.messages.import.rubric_position_unique'));
                    }

                    if (($rubric['page_end'] ?? null) !== null
                        && (($rubric['page_start'] ?? null) === null || $rubric['page_end'] < $rubric['page_start'])) {
                        $validator->errors()->add("manifest.indicators.{$index}.rubrics.{$rubricIndex}.page_end", __('led_setup.messages.import.rubric_page_range_invalid'));
                    }

                    $codes[] = $rubric['category_code'] ?? null;
                    $positions[] = $rubric['position'] ?? null;
                }
            }
        });

        $validated = $validator->validate()['manifest'];

        return collect($validated)->put('source_effective_date', $validated['source_effective_date'] ?? null)->all();
    }
}
