<?php

namespace App\Services;

use App\Models\AccreditationProject;
use App\Support\BusinessDateTime;
use Illuminate\Support\Facades\DB;

class LedOrganizationSnapshotService
{
    public function build(AccreditationProject $project): array
    {
        $project->loadMissing(['studyProgram', 'instrument']);
        $effectiveDate = BusinessDateTime::today()->toDateString();
        $assignments = DB::table('qa_study_program_unit_assignments as assignments')
            ->join('qa_organizational_units as units', 'units.id', '=', 'assignments.unit_id')
            ->where('assignments.study_program_id', $project->study_program_id)
            ->whereDate('assignments.valid_from', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate): void {
                $query->whereNull('assignments.valid_until')->orWhereDate('assignments.valid_until', '>=', $effectiveDate);
            })
            ->where('units.is_active', true)
            ->whereNull('units.deleted_at')
            ->whereDate('units.valid_from', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate): void {
                $query->whereNull('units.valid_until')->orWhereDate('units.valid_until', '>=', $effectiveDate);
            })
            ->orderBy('assignments.relationship_type')
            ->orderBy('units.unit_code')
            ->get([
                'assignments.relationship_type',
                'assignments.valid_from as assignment_valid_from',
                'assignments.valid_until as assignment_valid_until',
                'assignments.decision_ref',
                'units.uuid as unit_uuid',
                'units.unit_code',
                'units.unit_name',
                'units.unit_type',
                'units.parent_unit_id',
                'units.valid_from as unit_valid_from',
                'units.valid_until as unit_valid_until',
                'units.source_decision_ref',
            ])
            ->map(fn ($row): array => (array) $row)
            ->all();

        return [
            'schema_version' => 1,
            'effective_date' => $effectiveDate,
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status,
            ],
            'study_program' => [
                'id' => $project->studyProgram->id,
                'code' => $project->studyProgram->code,
                'name' => $project->studyProgram->name,
            ],
            'instrument' => [
                'id' => $project->instrument->id,
                'code' => $project->instrument->code,
                'name' => $project->instrument->name,
            ],
            'unit_assignments' => $assignments,
        ];
    }

    public function json(array $snapshot): string
    {
        return json_encode($this->canonicalize($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
