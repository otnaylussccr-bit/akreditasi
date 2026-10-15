<?php

namespace App\Policies;

use App\Models\AccreditationCriterion;
use App\Models\User;
use App\Services\AccreditationAuthorizationService;

class AccreditationCriterionPolicy
{
    public function __construct(private AccreditationAuthorizationService $authorization) {}

    public function view(User $user, AccreditationCriterion $criterion): bool
    {
        return $this->authorization->canViewCriterion($user, $criterion);
    }

    public function upload(User $user, AccreditationCriterion $criterion): bool
    {
        return $this->authorization->canUploadForCriterion($user, $criterion);
    }

    public function submit(User $user, AccreditationCriterion $criterion): bool
    {
        return $this->authorization->canSubmitForCriterion($user, $criterion);
    }
}
