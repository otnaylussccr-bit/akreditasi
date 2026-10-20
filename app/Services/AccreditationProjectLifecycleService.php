<?php

namespace App\Services;

use App\Models\AccreditationProject;
use Illuminate\Auth\Access\AuthorizationException;

class AccreditationProjectLifecycleService
{
    public const WORKFLOW_STATUS = 'active';

    public function allowsWorkflowMutation(AccreditationProject $project): bool
    {
        return $project->status === self::WORKFLOW_STATUS;
    }

    public function assertWorkflowMutable(AccreditationProject $project): void
    {
        if (! $this->allowsWorkflowMutation($project)) {
            throw new AuthorizationException(
                __('documents.errors.project_not_active'),
            );
        }
    }

    public function lockAndAssertWorkflowMutable(int $projectId): AccreditationProject
    {
        $project = AccreditationProject::query()->lockForUpdate()->findOrFail($projectId);
        $this->assertWorkflowMutable($project);

        return $project;
    }
}
