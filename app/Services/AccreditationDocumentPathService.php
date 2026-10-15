<?php

namespace App\Services;

use App\Models\AccreditationDocumentRequirement;
use App\Rules\SecureAccreditationDocument;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AccreditationDocumentPathService
{
    public function build(
        AccreditationDocumentRequirement $requirement,
        int $revision,
        string $uuid,
        string $extension,
    ): string {
        $requirement->loadMissing('item.criterion.project.studyProgram');
        $item = $requirement->item;
        $criterion = $item?->criterion;
        $project = $criterion?->project;
        $studyProgram = $project?->studyProgram;

        if (! $item || ! $criterion || ! $project || ! $studyProgram) {
            throw new RuntimeException('Relasi dokumen akreditasi tidak lengkap untuk membentuk storage path.');
        }

        if (! $requirement->exists || ! $item->exists || ! $criterion->exists || ! $project->exists || ! $studyProgram->exists
            || $revision < 1 || $item->number < 1 || $requirement->number < 1) {
            throw new InvalidArgumentException('Nomor butir, rincian, dan revisi harus bernilai positif.');
        }

        $uuid = strtolower($uuid);
        $extension = strtolower($extension);

        if (! Str::isUuid($uuid)
            || preg_match('/\A[a-z0-9]+\z/', $extension) !== 1
            || ! in_array($extension, SecureAccreditationDocument::EXTENSIONS, true)) {
            throw new InvalidArgumentException('UUID atau ekstensi storage tidak aman.');
        }

        $programSlug = Str::slug($studyProgram->name);
        $programSlug = $programSlug !== '' ? substr($programSlug, 0, 80) : 'study-program';
        $projectCode = $this->safeCode($project->code, 'project');
        $criterionCode = $this->safeCode($criterion->code, 'criterion');

        return implode('/', [
            'accreditation',
            'study-programs',
            "{$programSlug}--id-{$studyProgram->id}",
            'projects',
            "{$projectCode}--id-{$project->id}",
            'criteria',
            $criterionCode,
            'items',
            "{$criterionCode}.{$item->number}--id-{$item->id}",
            'requirements',
            "R{$requirement->number}--id-{$requirement->id}",
            'revisions',
            "v{$revision}",
            "{$uuid}.{$extension}",
        ]);
    }

    private function safeCode(string $value, string $fallback): string
    {
        $value = Str::ascii(trim($value));
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 80) : $fallback;
    }
}
