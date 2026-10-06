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
                    ({{ auth()->user()?->role?->label() ?? 'No role' }} · {{ auth()->user()?->permission?->label() ?? 'No level' }}).
                    Roles, regions and permission levels are assigned by a Superadmin. Your permission level decides whether you can
                    (Viewer) read only, (Editor) also edit records, or (Admin) also import workbooks. Superadmins always have full access.
                </p>
            </div>
        </div>
    </section>

    <section class="admin-surface divide-y divide-base-300">
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-bold text-base-content">Mail delivery</h2>
            <p class="mt-1 text-xs text-base-content/55">Where system emails (credentials, verify, digest) actually go. Misconfiguration here is silent — confirm the status before assuming an email was sent.</p>
        </div>
        <div class="p-5 sm:px-6">
            @php
                $mh = $mailHealth;
                $status = $mh['sending']
                    ? ['tone' => 'success', 'label' => 'Sending']
                    : ($mh['configured'] ? ['tone' => 'info', 'label' => 'Not sending (intentional)'] : ['tone' => 'error', 'label' => 'Misconfigured']);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-base-content">
                        Driver
                        <x-admin.badge :tone="$status['tone']">{{ $status['label'] }}</x-admin.badge>
                    </p>
                    <p class="mt-1 text-xs leading-5 text-base-content/55">
                        <code class="font-mono text-base-content/80">{{ $mh['driver'] }}</code>
                        @if ($mh['from']) — from <code class="font-mono text-base-content/80">{{ $mh['from'] }}</code>@endif
                    </p>
                    @if ($mh['reason'] !== null)
                        <p class="mt-2 text-xs text-error">{{ $mh['reason'] }}</p>
                    @endif
                </div>
            </div>

            @if (auth()->user()?->role === \App\Enums\UserRole::Superadmin)
                <form wire:submit="sendTestEmail" class="mt-5 flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <label for="test-email" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Send a test email to</label>
                        <input id="test-email" type="email" wire:model="testEmailAddress" class="admin-control w-full" required>
                        @error('testEmailAddress') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="admin-primary-button" wire:loading.attr="disabled" wire:target="sendTestEmail">
                        <span wire:loading.remove wire:target="sendTestEmail">Send test email</span>
                        <span wire:loading wire:target="sendTestEmail">Sending…</span>
                    </button>
                </form>
                @if (session('test-email'))
                    <p class="mt-3 text-xs text-success">{{ session('test-email') }}</p>
                @endif
                @if (session('test-email-error'))
                    <p class="mt-3 text-xs text-error">{{ session('test-email-error') }}</p>
                @endif
            @endif
        </div>
    </section>

    @if (auth()->user()?->role === \App\Enums\UserRole::Superadmin)
        <section class="admin-surface divide-y divide-base-300">
            <div class="p-5 sm:p-6">
                <h2 class="text-base font-bold text-base-content">monday.com</h2>
                <p class="mt-1 text-xs text-base-content/55">
                    Credentials for the board sync used by every table's "Connect monday.com board" menu.
                    Get the token from monday.com → Avatar → Developers → My access tokens.
                    Stored in the database — no server (.env) edits needed.
                </p>
            </div>
            <div class="p-5 sm:px-6">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <label for="monday-token" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">API token</label>
                        <div class="flex gap-2">
                            <input
                                id="monday-token"
                                type="{{ $mondayShowToken ? 'text' : 'password' }}"
                                wire:model="mondayToken"
                                autocomplete="off"
                                placeholder="{{ $mondayTokenSaved ? '(saved — leave blank to keep)' : 'Paste your monday.com API token' }}"
                                class="admin-control w-full"
                            >
                            <button
                                type="button"
                                wire:click="$toggle('mondayShowToken')"
                                class="admin-secondary-button shrink-0"
                                aria-label="{{ $mondayShowToken ? 'Hide token' : 'Show token' }}"
                            >
                                {{ $mondayShowToken ? 'Hide' : 'Show' }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-base-content/50">
                            @if ($mondayTokenSaved)
                                A token is saved on this server.
                            @else
                                No token saved yet.
                            @endif
                        </p>
                        @error('mondayToken') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="mt-4 flex items-start gap-3">
                    <input type="checkbox" wire:model="mondayEnabled" class="checkbox checkbox-primary mt-0.5">
                    <span class="text-sm text-base-content">
                        <span class="font-semibold">Enable monday.com sync (global)</span>
                        <span class="block text-xs leading-5 text-base-content/55">When on, boards connected on tables pull new items every minute. Turning this off pauses everything at once without losing any connection.</span>
                    </span>
                </label>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="saveMondaySettings" class="admin-primary-button" wire:loading.attr="disabled" wire:target="saveMondaySettings">
                        <span wire:loading.remove wire:target="saveMondaySettings">Save monday.com settings</span>
                        <span wire:loading wire:target="saveMondaySettings">Saving…</span>
                    </button>
                    <button type="button" wire:click="testMondaySettings" class="admin-secondary-button" wire:loading.attr="disabled" wire:target="testMondaySettings">
                        <span wire:loading.remove wire:target="testMondaySettings">Test connection</span>
                        <span wire:loading wire:target="testMondaySettings">Testing…</span>
                    </button>
                </div>

                @if ($mondayMessage !== '')
                    <p class="mt-3 text-xs {{ $mondayMessageTone === 'success' ? 'text-success' : ($mondayMessageTone === 'error' ? 'text-error' : 'text-base-content/55') }}">{{ $mondayMessage }}</p>
                @endif
            </div>
        </section>
    @endif
</div>
