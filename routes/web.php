<?php

use App\Livewire\Dashboard;
use App\Livewire\HelpCenter;
use App\Livewire\HistoricalTsmsTable;
use App\Livewire\InstalledProductsTable;
use App\Livewire\ServiceRequestTable;
use App\Livewire\Settings;
use App\Livewire\TablesList;
use App\Livewire\TechnicalPersonnelTable;
use App\Livewire\TechnicalReportTable;
use App\Livewire\TechnicalServiceAnalysis;
use App\Livewire\TspAnalytics;
use App\Livewire\UserManagement;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::redirect('president', 'dashboard')->name('president');

    Route::get('tables', TablesList::class)->name('tables');
    Route::get('installed-products', InstalledProductsTable::class)->name('installed-products');
    Route::get('service-requests', ServiceRequestTable::class)->name('service-requests');
    Route::get('technical-reports', TechnicalReportTable::class)->name('technical-reports');
    Route::get('history-reports', HistoricalTsmsTable::class)->name('history-reports');
    Route::get('personnel', TechnicalPersonnelTable::class)->name('personnel');

    Route::get('technical-service-analysis', TechnicalServiceAnalysis::class)->name('technical-service-analysis');
    Route::get('tsp-analytics', TspAnalytics::class)->name('tsp-analytics');

    Route::get('settings', Settings::class)->name('settings');
    Route::get('help-center', HelpCenter::class)->name('help-center');

    Route::get('users', UserManagement::class)
        ->name('users')
        ->middleware('can:manageUsers');
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
