<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-8 text-center">
        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-primary">Secure access</p>
        <h1 class="mt-3 font-display text-3xl font-semibold tracking-tight text-base-content">Sign in to your workspace</h1>
        <p class="mt-2 text-sm leading-6 text-base-content/60">Use your administrator credentials to continue.</p>
    </div>

    <x-auth-session-status class="mb-5" :status="session('status')" />

    <form wire:submit="login" class="space-y-5">
        <div>
            <label for="email" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Email address</label>
            <input wire:model="form.email" id="email" class="admin-control h-11 w-full" type="email" name="email" required autofocus autocomplete="username" placeholder="you@company.com">
            <x-input-error :messages="$errors->get('form.email')" class="mt-1.5" />
        </div>

        <div>
            <div class="mb-1.5 flex items-center justify-between gap-3">
                <label for="password" class="block text-xs font-bold uppercase tracking-[0.08em] text-base-content/55">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" wire:navigate class="text-xs font-semibold text-primary hover:underline">Forgot password?</a>
                @endif
            </div>
            <input wire:model="form.password" id="password" class="admin-control h-11 w-full" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
            <x-input-error :messages="$errors->get('form.password')" class="mt-1.5" />
        </div>

        <label for="remember" class="flex items-center justify-center gap-2 text-sm text-base-content/60">
            <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-base-300 text-primary focus:ring-primary/30" name="remember">
            Remember me on this device
        </label>

        <button type="submit" class="admin-primary-button h-11 w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in...</span>
        </button>
    </form>

    @if (Route::has('register'))
        <p class="mt-8 text-center text-sm text-base-content/55">
            Need an account?
            <a href="{{ route('register') }}" wire:navigate class="font-semibold text-primary hover:underline">Request access</a>
        </p>
    @endif
</div>
