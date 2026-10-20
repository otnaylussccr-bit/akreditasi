<main class="login-shell">
    <section class="login-intro">
        <div class="login-intro-content">
            <span class="eyebrow">{{ __('auth.intro.eyebrow') }}</span>
            <h1>{{ __('auth.intro.title_start') }}<br>{{ __('auth.intro.title_end') }}</h1>
            <p>{{ __('auth.intro.body') }}</p>
        </div>
        <div class="intro-line" aria-hidden="true"></div>
        <p class="intro-footer">{{ __('auth.intro.footer') }}</p>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-heading">
                <span>{{ __('auth.welcome') }}</span>
                <h2>{{ __('auth.heading') }}</h2>
                <p>{{ __('auth.help') }}</p>
            </div>

            @if (session('status'))
                <div class="login-status" role="status">{{ session('status') }}</div>
            @endif

            <form wire:submit="login" class="login-form">
                <label for="email">{{ __('auth.email') }}</label>
                <input wire:model="email" id="email" name="email" type="email" autocomplete="email" placeholder="nama@apu.ac.id" required autofocus aria-describedby="email-error" @if ($errors->has('email')) aria-invalid="true" @endif>
                @error('email') <p id="email-error" class="field-error">{{ $message }}</p> @enderror

                <label for="password">{{ __('auth.password') }}</label>
                <div x-data="{ visible: false }" class="password-control login-password-control">
                    <input wire:model="password" id="password" name="password" type="password" x-bind:type="visible ? 'text' : 'password'" autocomplete="current-password" placeholder="{{ __('auth.password_placeholder') }}" required aria-describedby="password-error" @if ($errors->has('password')) aria-invalid="true" @endif>
                    <x-password-visibility-toggle />
                </div>
                @error('password') <p id="password-error" class="field-error">{{ $message }}</p> @enderror

                <label class="remember-row" for="remember">
                    <input wire:model="remember" id="remember" type="checkbox">
                    <span>{{ __('auth.remember') }}</span>
                </label>

                <button class="primary-button" type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="login">{{ __('auth.submit') }}</span>
                    <span wire:loading wire:target="login">{{ __('auth.loading') }}</span>
                </button>
            </form>

            <p class="login-note">{{ __('auth.no_registration') }}</p>
        </div>
    </section>
</main>
