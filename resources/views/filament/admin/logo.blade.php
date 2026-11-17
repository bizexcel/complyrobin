@php
    use App\Filament\Brand;

    /** @var bool $dark */
    $label = $dark ? 'color: #F8FAFC; border-color: #475569;' : 'color: '.Brand::INK.'; border-color: #CBD5E1;';
@endphp

{{-- The staff panel's logo: the ComplyRobin logo with an "Admin" label, so staff never mistake it for the app. --}}
<span style="display: inline-flex; align-items: center; gap: 0.5rem; height: 100%;">
    <img src="{{ Brand::logo($dark) }}" alt="ComplyRobin" style="height: 100%;">
    <span style="font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; border: 1px solid; border-radius: 9999px; padding: 0.1rem 0.5rem; {{ $label }}">Admin</span>
</span>
