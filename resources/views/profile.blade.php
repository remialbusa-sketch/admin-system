<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-primary">Account</p>
            <h1 class="mt-1 font-display text-2xl font-semibold tracking-tight text-base-content">Profile</h1>
            <p class="mt-1 text-sm text-base-content/60">Update your personal information and account security.</p>
        </div>
    </x-slot>

    <div class="space-y-5">
        <section class="admin-surface p-5 sm:p-6">
            <livewire:profile.update-profile-information-form />
        </section>

        <section class="admin-surface p-5 sm:p-6">
            <livewire:profile.update-password-form />
        </section>
    </div>
</x-app-layout>
