<?php

namespace App\Providers;

use App\Contracts\OfficeDocumentConverter;
use App\Http\Middleware\EnsureOperationalModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\AccreditationCriterion;
use App\Models\AccreditationDocument;
use App\Models\AccreditationDocumentRequirement;
use App\Models\AccreditationProject;
use App\Models\AccreditationReviewAttachment;
use App\Models\AccreditationReviewSubmission;
use App\Models\User;
use App\Policies\AccreditationCriterionPolicy;
use App\Policies\AccreditationDocumentPolicy;
use App\Policies\AccreditationDocumentRequirementPolicy;
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
            EnsureUserIsActive::class,
            EnsureOperationalModuleAccess::class,
        ]);
        Gate::before(fn (User $user): ?bool => $user->is_active ? null : false);

        Gate::policy(AccreditationProject::class, AccreditationProjectPolicy::class);
        Gate::policy(AccreditationCriterion::class, AccreditationCriterionPolicy::class);
        Gate::policy(AccreditationDocumentRequirement::class, AccreditationDocumentRequirementPolicy::class);
        Gate::policy(AccreditationDocument::class, AccreditationDocumentPolicy::class);
        Gate::policy(AccreditationReviewSubmission::class, AccreditationReviewSubmissionPolicy::class);
        Gate::policy(AccreditationReviewAttachment::class, AccreditationReviewAttachmentPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:51200']]);
    }
}
