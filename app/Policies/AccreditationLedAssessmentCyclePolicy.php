<?php

namespace App\Policies;

use App\Models\AccreditationLedAssessmentCycle;
use App\Models\User;
use App\Services\LedAssessmentAuthorizationService;

class AccreditationLedAssessmentCyclePolicy
{
    public function __construct(private readonly LedAssessmentAuthorizationService $authorization) {}

    public function view(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        return $this->authorization->canViewCycle($user, $cycle);
    }

    public function manage(User $user, AccreditationLedAssessmentCycle $cycle): bool
    {
        return $this->authorization->canManageCycle($user, $cycle);
    }
}
