<?php

namespace App\Policies;

use App\Models\AccreditationReviewAttachment;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;

class AccreditationReviewAttachmentPolicy
{
    public function __construct(private AccreditationAuthorizationService $authorization) {}

    public function view(User $user, AccreditationReviewAttachment $attachment): bool
    {
        $attachment->loadMissing('reviewEvent.submission.requirement.item.criterion.project.studyProgram');
        $event = $attachment->reviewEvent;
        $submission = $event?->submission;

        if (! $submission || $event->action !== 'need_revision') {
            return false;
        }

        $requirement = $submission->requirement;
        $criterion = $requirement->item->criterion;

        if ($this->authorization->isActivePic($user, $criterion)) {
            return $this->authorization->canViewRequirement($user, $requirement);
        }

        return $this->authorization->canReviewSubmission($user, $submission);
    }
}
