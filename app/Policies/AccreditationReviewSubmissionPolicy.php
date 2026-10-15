<?php

namespace App\Policies;

use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;
use App\Services\AccreditationProjectLifecycleService;

class AccreditationReviewSubmissionPolicy
{
    public function __construct(
        private AccreditationAuthorizationService $authorization,
        private AccreditationProjectLifecycleService $lifecycle,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canViewReviewQueue($user);
    }

    public function view(User $user, AccreditationReviewSubmission $submission): bool
    {
        return $this->authorization->canViewReviewSubmission($user, $submission);
    }

    public function review(User $user, AccreditationReviewSubmission $submission): bool
    {
        return $this->authorization->canReviewSubmission($user, $submission);
    }

    public function verify(User $user, AccreditationReviewSubmission $submission): bool
    {
        return $user->can('document.verify')
            && $this->lifecycle->allowsWorkflowMutation($submission->requirement->item->criterion->project)
            && $this->authorization->canReviewSubmission($user, $submission);
    }

    public function requestRevision(User $user, AccreditationReviewSubmission $submission): bool
    {
        return $user->can('document.request_revision')
            && $this->lifecycle->allowsWorkflowMutation($submission->requirement->item->criterion->project)
            && $this->authorization->canReviewSubmission($user, $submission);
    }
}
