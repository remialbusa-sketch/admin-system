<?php

use App\Livewire\Dashboard;
use App\Livewire\HelpCenter;
use App\Livewire\RecordsTable;
use App\Livewire\Settings;
use App\Livewire\TspAnalytics;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('tsp-analytics', TspAnalytics::class)->name('tsp-analytics');
    Route::get('records', RecordsTable::class)->name('records');
    Route::get('settings', Settings::class)->name('settings');
    Route::get('help-center', HelpCenter::class)->name('help-center');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::post('logout', function (App\Livewire\Actions\Logout $logout) {
    $logout();

    return redirect('/');
})
    ->middleware(['auth'])
    ->name('logout');

require __DIR__.'/auth.php';
