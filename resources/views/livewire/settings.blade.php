<div class="max-w-4xl space-y-5">
    <x-admin.page-header
        eyebrow="Workspace configuration"
        title="Settings"
        description="Account and display preferences for the operations workspace."
    />

    {{-- Every control on this page is real: preferences below either persist
         (profile, per-table grid layout) or are honest pointers. The previous
         decorative toggles (workspace modules, notifications, week start) were
         removed — nothing behind them was wired up. --}}

    <section class="admin-surface divide-y divide-base-300">
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Appearance</h2>
            <p class="mt-1 text-xs text-base-content/55">Applies immediately and is remembered by your browser.</p>
        </div>
        <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
            <div>
                <p class="text-sm font-semibold text-base-content">Theme</p>
                <p class="mt-1 text-xs leading-5 text-base-content/55">Switch between light and dark mode with the toggle in the top bar.</p>
            </div>
            <x-mary-theme-toggle class="admin-icon-button" />
        </div>
        <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
            <div>
                <p class="text-sm font-semibold text-base-content">Grid density &amp; columns</p>
                <p class="mt-1 text-xs leading-5 text-base-content/55">Set per table: use the density selector above each grid, and right-click a column header to freeze, hide, reorder, or resize. Column layout is saved per account.</p>
            </div>
        </div>
    </section>

    <section class="admin-surface divide-y divide-base-300">
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Account</h2>
            <p class="mt-1 text-xs text-base-content/55">Profile details and password are managed on your profile page.</p>
        </div>
        <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
            <div>
                <p class="text-sm font-semibold text-base-content">Profile</p>
                <p class="mt-1 text-xs leading-5 text-base-content/55">Name, email, password, and account deletion.</p>
            </div>
            <a href="{{ route('profile') }}" wire:navigate class="admin-secondary-button">Open profile</a>
        </div>
        <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
            <div>
                <p class="text-sm font-semibold text-base-content">Role &amp; access</p>
                <p class="mt-1 text-xs leading-5 text-base-content/55">
                    You are signed in as <span class="font-semibold text-base-content">{{ auth()->user()?->name }}</span>
                    ({{ auth()->user()?->role?->label() ?? 'No role' }}). Roles and regions are assigned by a Superadmin —
                    only Superadmins can edit table records and run imports.
                </p>
            </div>
        </div>
    </section>
</div>
