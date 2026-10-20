<?php

namespace App\Livewire\Profile;

use App\Livewire\Component;
use App\Services\UserAccessManagementService;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.app')]
class ChangePassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $successMessage = null;

    public function updatePassword(UserAccessManagementService $access): void
    {
        $this->successMessage = null;
        $key = $this->throttleKey();

        try {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages([
                    'currentPassword' => __('profile.throttled', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }

            RateLimiter::hit($key, 600);
            $validated = $this->validate([
                'currentPassword' => ['required', 'string'],
                'newPassword' => PasswordPolicy::rules(),
                'newPassword_confirmation' => ['required', 'string', 'same:newPassword'],
            ]);

            $access->changeOwnPassword(
                $validated['currentPassword'],
                $validated['newPassword'],
                session()->getId(),
            );

            RateLimiter::clear($key);
            session()->regenerate(true);
            $this->successMessage = __('profile.success');
        } finally {
            $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);
        }
    }

    public function render()
    {
        return view('livewire.profile.change-password', [
            'user' => Auth::user(),
        ])->title(__('profile.meta_title'));
    }

    private function throttleKey(): string
    {
        return Str::transliterate('password-change|'.Auth::id().'|'.request()->ip());
    }
}
