@props(['title' => null])

{{--
    Bridge so plain (non-Livewire) pages can use the dashboard shell with the
    same <x-layouts.dashboard> tag Livewire pages get via ->layout().
    Renders the existing layout with the component slot + title.

    Why this file must exist: Volt's ensureViewsAreCached() (used by
    assertSeeVolt and Volt's testing macros) compiles EVERY Blade view in
    resources/views, so a <x-layouts.dashboard> tag with no matching component
    or component view breaks unrelated tests at compile time.
--}}
@include('layouts.dashboard', ['slot' => $slot, 'title' => $title])
