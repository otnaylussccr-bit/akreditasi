<div class="account-page">
    <header class="page-heading account-heading">
        <div>
            <span class="eyebrow dark">{{ __('profile.eyebrow') }}</span>
            <h1>{{ __('profile.title') }}</h1>
            <p>{{ __('profile.intro', ['email' => $user->email]) }}</p>
        </div>
        <span class="role-badge">{{ $user->getRoleNames()->map(fn (string $role) => \App\Support\LocalizedLabel::role($role))->join(', ') ?: __('common.roles.none') }}</span>
    </header>

    @if ($successMessage)
        <div class="upload-success" role="status">{{ $successMessage }}</div>
    @endif

    <section class="account-card">
        <div class="account-card-intro">
            <strong>{{ __('profile.strong_password') }}</strong>
            <p>{{ __('profile.policy_intro') }}</p>
            <ul class="account-password-policy" aria-label="{{ __('profile.policy_aria') }}">
                <li>{{ __('profile.policy.length') }}</li>
                <li>{{ __('profile.policy.letter') }}</li>
                <li>{{ __('profile.policy.number') }}</li>
                <li>{{ __('profile.policy.different') }}</li>
            </ul>
        </div>

        <form wire:submit="updatePassword" class="account-form">
            <input class="account-autofill-username" type="email" name="username" value="{{ $user->email }}" autocomplete="username" readonly tabindex="-1" aria-hidden="true">

            <div x-data="{ visible: false }" class="account-field">
                <label for="current-password">{{ __('profile.current') }}</label>
                <div class="password-control">
                    <input wire:model="currentPassword" id="current-password" name="current-password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="current-password" required aria-describedby="current-password-error" wire:loading.attr="disabled" wire:target="updatePassword" @if ($errors->has('currentPassword')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle :label="__('profile.current')" />
                </div>
                @error('currentPassword') <p id="current-password-error" class="field-error">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ visible: false }" class="account-field">
                <label for="new-password">{{ __('profile.new') }}</label>
                <div class="password-control">
                    <input wire:model="newPassword" id="new-password" name="new-password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required aria-describedby="new-password-error" wire:loading.attr="disabled" wire:target="updatePassword" @if ($errors->has('newPassword')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle :label="__('profile.new')" />
                </div>
                @error('newPassword') <p id="new-password-error" class="field-error">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ visible: false }" class="account-field">
                <label for="new-password-confirmation">{{ __('profile.confirmation') }}</label>
                <div class="password-control">
                    <input wire:model="newPassword_confirmation" id="new-password-confirmation" name="new-password-confirmation" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="new-password" required aria-describedby="new-password-confirmation-error" wire:loading.attr="disabled" wire:target="updatePassword" @if ($errors->has('newPassword_confirmation')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle :label="__('profile.confirmation')" />
                </div>
                @error('newPassword_confirmation') <p id="new-password-confirmation-error" class="field-error">{{ $message }}</p> @enderror
            </div>

            <button class="primary-button account-submit" type="submit" wire:loading.attr="disabled" wire:target="updatePassword">
                <span wire:loading.remove wire:target="updatePassword">{{ __('profile.submit') }}</span>
                <span wire:loading wire:target="updatePassword">{{ __('profile.loading') }}</span>
            </button>
        </form>
    </section>
</div>
