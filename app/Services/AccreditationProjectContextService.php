<?php

namespace App\Services;

use App\Models\AccreditationProject;
use Illuminate\Support\Collection;

class AccreditationProjectContextService
{
    public const SESSION_KEY = 'accreditation.current_project_id';

    public function resolve(Collection $authorizedProjects, ?int $explicitProjectId = null): ?AccreditationProject
    {
        $projects = $this->ordered($authorizedProjects);

        if ($explicitProjectId !== null) {
            return $this->select($projects, $explicitProjectId);
        }

        $rememberedProjectId = $this->currentProjectId();

        if ($rememberedProjectId !== null) {
            $rememberedProject = $projects->firstWhere('id', $rememberedProjectId);

            if ($rememberedProject) {
                return $rememberedProject;
            }

            session()->forget(self::SESSION_KEY);
        }

        $project = $projects->firstWhere('status', 'active') ?? $projects->first();

        if ($project) {
            $this->synchronize($project);
        }

        return $project;
    }

    public function select(Collection $authorizedProjects, int $projectId): AccreditationProject
    {
        $project = $authorizedProjects->firstWhere('id', $projectId);
        abort_unless($project, 403);
        $this->synchronize($project);

        return $project;
    }

    public function synchronize(AccreditationProject $project): void
    {
        session()->put(self::SESSION_KEY, $project->id);
    }

    public function currentProjectId(): ?int
    {
        $projectId = session()->get(self::SESSION_KEY);

        return is_numeric($projectId) ? (int) $projectId : null;
    }

    public function ordered(Collection $projects): Collection
    {
        return $projects
            ->sortBy(fn (AccreditationProject $project): array => [
                $project->status === 'active' ? 0 : 1,
                $project->status === 'active'
                    ? $project->final_deadline->getTimestamp()
                    : -$project->final_deadline->getTimestamp(),
                $project->status === 'active' ? $project->id : -$project->id,
            ])
            ->values();
    }
}
