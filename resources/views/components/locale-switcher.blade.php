@php($currentLocale = app()->getLocale())

<div
    class="locale-switcher"
    role="group"
    aria-label="{{ __('common.language.select') }}"
    data-locale-switcher
    data-locale-attribute="aria-label"
    data-locale-en="{{ __('common.language.select', [], 'en') }}"
    data-locale-id="{{ __('common.language.select', [], 'id') }}"
>
    @foreach (config('localization.supported') as $locale => $label)
        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale }}">
            <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}">
            <button
                type="submit"
                lang="{{ $locale }}"
                @if ($currentLocale === $locale) aria-current="true" @endif
            >{{ $label }}</button>
        </form>
        @if (! $loop->last)<span aria-hidden="true">|</span>@endif
    @endforeach
</div>
<span
    class="locale-switcher-status"
    role="status"
    aria-live="polite"
    data-locale-switch-status
    data-locale-en="{{ __('common.language.changed', [], 'en') }}"
    data-locale-id="{{ __('common.language.changed', [], 'id') }}"
></span>

@once
    <script>
        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-locale-switcher] form');

            if (!form || !window.fetch || !window.Livewire) return;

            event.preventDefault();
            const locale = form.querySelector('[name="locale"]').value;
            const buttons = form.closest('[data-locale-switcher]').querySelectorAll('button');
            buttons.forEach((button) => button.disabled = true);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) throw new Error('Locale update failed');

                document.documentElement.lang = locale;
                document.querySelectorAll('[data-locale-en][data-locale-id]').forEach((element) => {
                    const value = element.dataset[`locale${locale.charAt(0).toUpperCase()}${locale.slice(1)}`];
                    const attribute = element.dataset.localeAttribute;

                    if (attribute) {
                        element.setAttribute(attribute, value);
                    } else if (!element.matches('[data-locale-switcher]')) {
                        element.textContent = value;
                    }
                });
                document.querySelectorAll('[data-locale-aria-en][data-locale-aria-id]').forEach((element) => {
                    element.setAttribute('aria-label', element.dataset[`localeAria${locale.charAt(0).toUpperCase()}${locale.slice(1)}`]);
                });
                document.querySelectorAll('[data-locale-title-en][data-locale-title-id]').forEach((element) => {
                    element.title = element.dataset[`localeTitle${locale.charAt(0).toUpperCase()}${locale.slice(1)}`];
                });

                document.querySelectorAll('[data-locale-switcher] button').forEach((button) => {
                    const active = button.lang === locale;
                    button.toggleAttribute('aria-current', active);
                });

                const components = Livewire.all();

                if (components.length === 0) {
                    window.location.assign(form.querySelector('[name="redirect"]').value);
                    return;
                }

                await Promise.all(components.map((component) => component.$wire.refreshLocale()));

                const status = document.querySelector('[data-locale-switch-status]');
                status.textContent = status.dataset[`locale${locale.charAt(0).toUpperCase()}${locale.slice(1)}`];
            } catch (error) {
                form.submit();
            } finally {
                document.querySelectorAll('[data-locale-switcher] button').forEach((button) => {
                    button.disabled = false;
                });
            }
        });
    </script>
@endonce
