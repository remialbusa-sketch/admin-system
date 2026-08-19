<div class="max-w-4xl space-y-5">
    <x-admin.page-header
        eyebrow="Workspace configuration"
        title="Settings"
        description="Control how the operations workspace presents records, notifications, and personal preferences."
    />

    <section class="admin-surface divide-y divide-base-300">
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Workspace modules</h2>
            <p class="mt-1 text-xs text-base-content/55">Keep the operating surface focused on the workflows in use.</p>
        </div>
        <div class="divide-y divide-base-300">
            <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
                <div>
                    <p class="text-sm font-semibold text-base-content">Records table</p>
                    <p class="mt-1 text-xs leading-5 text-base-content/55">Allow users to import files and enrich records with custom fields.</p>
                </div>
                <input type="checkbox" class="toggle toggle-primary" checked aria-label="Enable records table">
            </div>
            <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
                <div>
                    <p class="text-sm font-semibold text-base-content">TSP Analytics</p>
                    <p class="mt-1 text-xs leading-5 text-base-content/55">Show coverage, response, and resolution performance reporting.</p>
                </div>
                <input type="checkbox" class="toggle toggle-primary" checked aria-label="Enable TSP Analytics">
            </div>
            <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
                <div>
                    <p class="text-sm font-semibold text-base-content">Record activity notifications</p>
                    <p class="mt-1 text-xs leading-5 text-base-content/55">Receive a notification when an assigned record changes status.</p>
                </div>
                <input type="checkbox" class="toggle toggle-primary" checked aria-label="Enable record activity notifications">
            </div>
        </div>
    </section>

    <section class="admin-surface divide-y divide-base-300">
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Display preferences</h2>
            <p class="mt-1 text-xs text-base-content/55">These preferences apply to your current workspace session.</p>
        </div>
        <div class="flex items-center justify-between gap-5 p-5 sm:px-6">
            <div>
                <p class="text-sm font-semibold text-base-content">Week starts on</p>
                <p class="mt-1 text-xs text-base-content/55">Used for calendar summaries and weekly reporting.</p>
            </div>
            <select class="admin-control w-32" aria-label="Week starts on">
                <option>Monday</option>
                <option>Sunday</option>
                <option>Saturday</option>
            </select>
        </div>
    </section>

    <section class="admin-surface border-error/30">
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
            <div>
                <h2 class="text-base font-bold text-error">Account actions</h2>
                <p class="mt-1 text-xs leading-5 text-base-content/55">Manage profile information or permanently remove this account.</p>
            </div>
            <a href="{{ route('profile') }}" wire:navigate class="admin-secondary-button">Open profile</a>
        </div>
    </section>
</div>
