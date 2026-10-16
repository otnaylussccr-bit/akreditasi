<?php

namespace App\Livewire\Profile;

use App\Services\UserAccessManagementService;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Ubah Password | APU Accreditation')]
class ChangePassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $successMessage = null;

    public function updatePassword(UserAccessManagementService $access): void
    {
        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'currentPassword' => 'Terlalu banyak percobaan. Coba kembali dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        RateLimiter::hit($key, 600);
        $validated = $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => PasswordPolicy::rules(),
        ]);

        $access->changeOwnPassword(
            Auth::user(),
            $validated['currentPassword'],
            $validated['newPassword'],
            session()->getId(),
        );

        RateLimiter::clear($key);
        session()->regenerate(true);
        $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);
        $this->successMessage = 'Password berhasil diperbarui. Session lain telah diakhiri.';
    }

    public function render()
    {
        return view('livewire.profile.change-password', [
            'user' => Auth::user(),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate('password-change|'.Auth::id().'|'.request()->ip());
    }
}
