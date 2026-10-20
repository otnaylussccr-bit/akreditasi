<?php

namespace App\Policies;

use App\Models\AccreditationLedAssessmentRevision;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;

class AccreditationLedAssessmentRevisionPolicy
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function submit(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $this->authorization->canSubmitAssessment($user, $revision);
    }

    public function review(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $this->authorization->canReviewRevision($user, $revision);
    }

    public function approve(User $user, AccreditationLedAssessmentRevision $revision): bool
    {
        return $this->authorization->canApproveRevision($user, $revision);
    }
}
