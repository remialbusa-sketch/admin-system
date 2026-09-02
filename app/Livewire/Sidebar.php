<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The left-hand navigation chrome, promoted to a Livewire component so it can
 * react to real-time changes — specifically table pinning. When a user pins or
 * unpins a table on the /tables page, TablesList dispatches `table-pins-updated`;
 * the sidebar refreshes and re-reads the user's pinned tables immediately,
 * with no page refresh or SPA navigation.
 *
 * The rendered markup lives in resources/views/components/admin/sidebar.blade.php
 * (kept there so the collapse/mobile Alpine behaviour and responsive HTML are
 * unchanged).
 */
class Sidebar extends Component
{
    #[On('table-pins-updated')]
    public function refreshPins(): void
    {
        // State is re-read from the DB in render(); nothing to cache here.
    }

    public function render()
    {
        return view('components.admin.sidebar');
    }
}
