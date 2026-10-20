<?php

namespace App\Providers;

use App\Contracts\OfficeDocumentConverter;
use App\Http\Middleware\EnsureOperationalModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationLedAssessment;
use App\Models\AccreditationLedAssessmentCycle;
use App\Models\AccreditationLedAssessmentRevision;
use App\Models\AccreditationLedAssessorAssignment;
use App\Models\AccreditationLedIndicator;
use App\Models\AccreditationLedIndicatorItemMapping;
use App\Models\AccreditationLedInstrumentVersion;
use App\Models\AccreditationLedRubric;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Policies\AccreditationCriterionPolicy;
use App\Policies\AccreditationDocumentPolicy;
use App\Policies\AccreditationDocumentRequirementPolicy;
use App\Policies\AccreditationLedAssessmentCyclePolicy;
use App\Policies\AccreditationLedAssessmentPolicy;
use App\Policies\AccreditationLedAssessmentRevisionPolicy;
use App\Policies\AccreditationLedAssessorAssignmentPolicy;
use App\Policies\AccreditationLedIndicatorItemMappingPolicy;
use App\Policies\AccreditationLedIndicatorPolicy;
use App\Policies\AccreditationLedInstrumentVersionPolicy;
use App\Policies\AccreditationLedRubricPolicy;
use App\Policies\AccreditationProjectPolicy;
use App\Policies\AccreditationReviewAttachmentPolicy;
use App\Policies\AccreditationReviewSubmissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\LibreOfficeDocumentConverter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OfficeDocumentConverter::class, LibreOfficeDocumentConverter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            SetLocale::class,
            EnsureUserIsActive::class,
            EnsureOperationalModuleAccess::class,
        ]);
        Gate::before(fn (User $user): ?bool => $user->is_active ? null : false);

        Gate::policy(AccreditationProject::class, AccreditationProjectPolicy::class);
        Gate::policy(AccreditationCriterion::class, AccreditationCriterionPolicy::class);
        Gate::policy(AccreditationDocumentRequirement::class, AccreditationDocumentRequirementPolicy::class);
        Gate::policy(AccreditationDocument::class, AccreditationDocumentPolicy::class);
        Gate::policy(AccreditationLedAssessment::class, AccreditationLedAssessmentPolicy::class);
        Gate::policy(AccreditationLedAssessmentCycle::class, AccreditationLedAssessmentCyclePolicy::class);
        Gate::policy(AccreditationLedAssessmentRevision::class, AccreditationLedAssessmentRevisionPolicy::class);
        Gate::policy(AccreditationLedAssessorAssignment::class, AccreditationLedAssessorAssignmentPolicy::class);
        Gate::policy(AccreditationLedIndicator::class, AccreditationLedIndicatorPolicy::class);
        Gate::policy(AccreditationLedIndicatorItemMapping::class, AccreditationLedIndicatorItemMappingPolicy::class);
        Gate::policy(AccreditationLedInstrumentVersion::class, AccreditationLedInstrumentVersionPolicy::class);
        Gate::policy(AccreditationLedRubric::class, AccreditationLedRubricPolicy::class);
        Gate::policy(AccreditationReviewSubmission::class, AccreditationReviewSubmissionPolicy::class);
        Gate::policy(AccreditationReviewAttachment::class, AccreditationReviewAttachmentPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200']]);
    }
}
