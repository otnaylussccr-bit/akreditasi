@php
    $currentLocale = app()->getLocale();
    $supportedLocales = config('localization.supported');
    $currentLabel = $supportedLocales[$currentLocale] ?? $supportedLocales['en'];
    $optionsId = 'locale-switcher-options';
@endphp

<div
    class="locale-switcher"
    data-locale-switcher
    data-locale-en="{{ __('common.language.select', [], 'en') }}"
    data-locale-id="{{ __('common.language.select', [], 'id') }}"
>
    <button
        class="locale-switcher-trigger"
        type="button"
        data-locale-trigger
        aria-expanded="false"
        aria-controls="{{ $optionsId }}"
        aria-haspopup="listbox"
        aria-label="{{ __('common.language.select') }}: {{ $currentLabel }}"
    >
        <svg class="locale-switcher-globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M3.5 9h17M3.5 15h17M12 3c2.3 2.5 3.5 5.5 3.5 9S14.3 18.5 12 21M12 3C9.7 5.5 8.5 8.5 8.5 12S9.7 18.5 12 21" />
        </svg>
        <span class="locale-switcher-current" data-locale-current>{{ $currentLabel }}</span>
        <svg class="locale-switcher-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="m5 7.5 5 5 5-5" />
        </svg>
    </button>

    <div
        class="locale-switcher-options"
        id="{{ $optionsId }}"
        role="listbox"
        data-locale-options
        hidden
        aria-label="{{ __('common.language.select') }}"
        data-locale-attribute="aria-label"
        data-locale-en="{{ __('common.language.select', [], 'en') }}"
        data-locale-id="{{ __('common.language.select', [], 'id') }}"
    >
        @foreach ($supportedLocales as $locale => $label)
            <form method="POST" action="{{ route('locale.update') }}" role="presentation">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}">
                <button
                    class="locale-switcher-option"
                    type="submit"
                    role="option"
                    lang="{{ $locale }}"
                    data-locale-option
                    data-locale-code="{{ $locale }}"
                    aria-selected="{{ $currentLocale === $locale ? 'true' : 'false' }}"
                    @if ($currentLocale === $locale) aria-current="true" @endif
                    tabindex="-1"
                >
                    <span>{{ $label }}</span>
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path d="m4 10 4 4 8-9" />
                    </svg>
                </button>
            </form>
        @endforeach
    </div>
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
        (() => {
            if (window.__apuLocaleSwitcherInitialized) return;

            window.__apuLocaleSwitcherInitialized = true;
            const localeOptions = (switcher) => [...switcher.querySelectorAll('[data-locale-option]')];

            const closeLocaleSwitcher = (switcher, restoreFocus = false) => {
                const trigger = switcher.querySelector('[data-locale-trigger]');
                const options = switcher.querySelector('[data-locale-options]');

                trigger.setAttribute('aria-expanded', 'false');
                options.hidden = true;

                if (restoreFocus) trigger.focus();
            };

            const openLocaleSwitcher = (switcher, focusTarget = 'active') => {
                document.querySelectorAll('[data-locale-switcher]').forEach((candidate) => {
                    if (candidate !== switcher) closeLocaleSwitcher(candidate);
                });

                const trigger = switcher.querySelector('[data-locale-trigger]');
                const options = switcher.querySelector('[data-locale-options]');
                const buttons = localeOptions(switcher);
                const target = focusTarget === 'last'
                    ? buttons.at(-1)
                    : buttons.find((button) => button.getAttribute('aria-selected') === 'true') ?? buttons[0];

                trigger.setAttribute('aria-expanded', 'true');
                options.hidden = false;
                target?.focus();
            };

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest?.('[data-locale-trigger]');

                if (trigger) {
                    const switcher = trigger.closest('[data-locale-switcher]');
                    const expanded = trigger.getAttribute('aria-expanded') === 'true';

                    expanded ? closeLocaleSwitcher(switcher) : openLocaleSwitcher(switcher);
                    return;
                }

                document.querySelectorAll('[data-locale-switcher]').forEach((switcher) => {
                    if (!switcher.contains(event.target)) closeLocaleSwitcher(switcher);
                });
            });

            document.addEventListener('keydown', (event) => {
                const switcher = event.target.closest?.('[data-locale-switcher]');

                if (!switcher) return;

                const trigger = switcher.querySelector('[data-locale-trigger]');
                const buttons = localeOptions(switcher);
                const currentIndex = buttons.indexOf(event.target);

                if (event.key === 'Escape' && trigger.getAttribute('aria-expanded') === 'true') {
                    event.preventDefault();
                    closeLocaleSwitcher(switcher, true);
                    return;
                }

                if (event.target === trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                    event.preventDefault();
                    openLocaleSwitcher(switcher, event.key === 'ArrowUp' ? 'last' : 'active');
                    return;
                }

                if (currentIndex === -1) return;

                const targetIndex = {
                    ArrowDown: (currentIndex + 1) % buttons.length,
                    ArrowUp: (currentIndex - 1 + buttons.length) % buttons.length,
                    Home: 0,
                    End: buttons.length - 1,
                }[event.key];

                if (targetIndex !== undefined) {
                    event.preventDefault();
                    buttons[targetIndex].focus();
                }
            });

            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-locale-switcher] form');

                if (!form || !window.fetch || !window.Livewire) return;

                event.preventDefault();
                const locale = form.querySelector('[name="locale"]').value;
                const switcher = form.closest('[data-locale-switcher]');
                const buttons = switcher.querySelectorAll('[data-locale-option]');
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

                    const selectedOption = switcher.querySelector(`[data-locale-option][data-locale-code="${locale}"]`);
                    localeOptions(switcher).forEach((button) => {
                        const active = button.dataset.localeCode === locale;
                        button.setAttribute('aria-selected', active ? 'true' : 'false');
                        button.toggleAttribute('aria-current', active);
                    });
                    switcher.querySelector('[data-locale-current]').textContent = selectedOption.textContent.trim();
                    switcher.querySelector('[data-locale-trigger]').setAttribute(
                        'aria-label',
                        `${switcher.dataset[`locale${locale.charAt(0).toUpperCase()}${locale.slice(1)}`]}: ${selectedOption.textContent.trim()}`,
                    );
                    closeLocaleSwitcher(switcher, true);

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
                    buttons.forEach((button) => {
                        button.disabled = false;
                    });
                }
            });
        })();
    </script>
@endonce
