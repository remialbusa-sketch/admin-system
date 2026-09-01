<div class="max-w-5xl space-y-5">
    <x-admin.page-header
        eyebrow="Administration"
        title="Users"
        description="Provision accounts, assign roles and regions, and reset passwords. Only Superadmins can open this page."
    />

    @if (session('user-created'))
        <div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm font-semibold text-success" role="status">
            Account created for {{ session('user-created') }}.
        </div>
    @endif

    <section class="admin-surface p-5 sm:p-6">
        <h2 class="text-base font-bold text-base-content">Create account</h2>
        <p class="mt-1 text-xs text-base-content/55">New accounts are created with a verified email and the role you pick here.</p>
        <form wire:submit="createUser" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="new-name" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Name</label>
                <input id="new-name" type="text" wire:model="newUser.name" class="admin-control w-full" autocomplete="off" required>
                @error('newUser.name') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-email" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Email</label>
                <input id="new-email" type="email" wire:model="newUser.email" class="admin-control w-full" autocomplete="off" required>
                @error('newUser.email') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-password" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Password</label>
                <input id="new-password" type="text" wire:model="newUser.password" class="admin-control w-full font-mono" autocomplete="new-password" required>
                @error('newUser.password') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="new-role" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Role</label>
                <select id="new-role" wire:model="newUser.role" class="admin-control w-full">
                    @foreach ($roles as $role)
                        <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="new-region" class="mb-1 block text-xs font-bold uppercase tracking-[0.1em] text-base-content/50">Region (regional managers)</label>
                <select id="new-region" wire:model="newUser.region" class="admin-control w-full">
                    <option value="">—</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region }}">{{ $region }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="admin-primary-button w-full sm:w-auto" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="createUser">Create account</span>
                    <span wire:loading wire:target="createUser">Creating…</span>
                </button>
            </div>
        </form>
    </section>

    <section class="admin-surface overflow-hidden">
        <div class="border-b border-base-300 px-5 py-4 sm:px-6">
            <h2 class="text-base font-bold text-base-content">Accounts</h2>
            <p class="mt-1 text-xs text-base-content/55">Regional managers are automatically scoped to their region on the executive dashboard.</p>
        </div>
        <div class="admin-scrollbar overflow-x-auto">
            <table class="data-table w-full text-left">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Region</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td class="font-semibold text-base-content">
                                {{ $user->name }}
                                @if ($user->email_verified_at === null)
                                    <span class="ml-1 rounded-full bg-warning/15 px-2 py-0.5 text-[10px] font-bold text-warning-content">unverified</span>
                                @endif
                            </td>
                            <td class="text-base-content/65">{{ $user->email }}</td>
                            @if ($editingId === $user->id)
                                <td>
                                    <select wire:model="editing.role" class="admin-control h-8 w-auto py-1 text-xs" aria-label="Role for {{ $user->name }}">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select wire:model="editing.region" class="admin-control h-8 w-auto py-1 text-xs" aria-label="Region for {{ $user->name }}">
                                        <option value="">—</option>
                                        @foreach ($regions as $region)
                                            <option value="{{ $region }}">{{ $region }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <input type="text" wire:model="editing.password" class="admin-control h-8 w-40 py-1 font-mono text-xs" placeholder="New password (optional)" aria-label="New password">
                                        <button type="button" wire:click="saveEditing" class="admin-primary-button h-8 px-3 text-xs">Save</button>
                                        <button type="button" wire:click="cancelEditing" class="admin-secondary-button h-8 px-3 text-xs">Cancel</button>
                                    </div>
                                    @error('editing.password') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                                </td>
                            @else
                                <td class="text-base-content/70">{{ $user->role->label() }}</td>
                                <td class="text-base-content/70">{{ $user->region ?? '—' }}</td>
                                <td class="text-right">
                                    <button type="button" wire:click="startEditing({{ $user->id }})" class="admin-secondary-button h-8 px-3 text-xs">Edit</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
