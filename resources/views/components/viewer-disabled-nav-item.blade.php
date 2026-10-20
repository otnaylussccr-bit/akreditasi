@props(['labelKey'])

@php($label = __($labelKey))

<span
    class="nav-link nav-link-muted viewer-disabled-nav"
    aria-disabled="true"
    aria-label="{{ __('navigation.viewer_restricted', ['label' => $label]) }}"
    title="{{ __('navigation.viewer_restricted_title') }}"
    data-locale-aria-en="{{ __('navigation.viewer_restricted', ['label' => __($labelKey, [], 'en')], 'en') }}"
    data-locale-aria-id="{{ __('navigation.viewer_restricted', ['label' => __($labelKey, [], 'id')], 'id') }}"
    data-locale-title-en="{{ __('navigation.viewer_restricted_title', [], 'en') }}"
    data-locale-title-id="{{ __('navigation.viewer_restricted_title', [], 'id') }}"
    data-viewer-disabled="true"
>
    {{ $slot }}
    <x-localized-text :key="$labelKey" />
</span>
