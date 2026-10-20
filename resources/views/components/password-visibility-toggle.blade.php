@props(['label' => 'password'])

<button
    type="button"
    class="password-visibility-toggle"
    x-on:click="visible = ! visible"
    x-bind:aria-pressed="visible"
    x-bind:aria-label="visible ? @js(__('common.password.hide', ['label' => $label])) : @js(__('common.password.show', ['label' => $label]))"
    aria-label="{{ __('common.password.show', ['label' => $label]) }}"
    aria-pressed="false"
>
    <svg x-show="! visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M2.1 12s3.6-7 9.9-7 9.9 7 9.9 7-3.6 7-9.9 7-9.9-7-9.9-7Z" />
        <circle cx="12" cy="12" r="3" />
    </svg>
    <svg x-cloak x-show="visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="m3 3 18 18" />
        <path d="M10.6 5.2A10.9 10.9 0 0 1 12 5c6.3 0 9.9 7 9.9 7a15.8 15.8 0 0 1-2.1 3.1" />
        <path d="M6.6 6.6A16.7 16.7 0 0 0 2.1 12s3.6 7 9.9 7a10.4 10.4 0 0 0 4.1-.9" />
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
    </svg>
</button>
