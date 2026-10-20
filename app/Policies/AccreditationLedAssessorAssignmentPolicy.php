<?php

namespace App\Policies;

use App\Models\AccreditationLedAssessorAssignment;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;

class AccreditationLedAssessorAssignmentPolicy
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function view(User $user, AccreditationLedAssessorAssignment $assignment): bool
    {
        return $this->authorization->canViewCycle($user, $assignment->cycle);
    }

    public function revoke(User $user, AccreditationLedAssessorAssignment $assignment): bool
    {
        return $assignment->revoked_at === null && $this->authorization->canManageAssignments($user, $assignment->cycle);
    }
}
