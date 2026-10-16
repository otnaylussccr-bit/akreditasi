@props(['label'])

<span
    class="nav-link nav-link-muted viewer-disabled-nav"
    aria-disabled="true"
    aria-label="{{ $label }} - Akses terbatas untuk role Viewer"
    title="Akses terbatas untuk role Viewer"
    data-viewer-disabled="true"
>
    {{ $slot }}
    <span>{{ $label }}</span>
</span>
