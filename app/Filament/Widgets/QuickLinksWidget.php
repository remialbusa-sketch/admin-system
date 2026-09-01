<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class QuickLinksWidget extends Widget
{
    protected string $view = 'filament.widgets.quick-links';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -1;

    protected function getViewData(): array
    {
        return [
            'links' => [
                [
                    'url' => route('dashboard'),
                    'icon' => 'heroicon-o-home',
                    'title' => 'Executive dashboard',
                    'description' => 'Command view over the installed base and service operations.',
                ],
                [
                    'url' => route('installed-products'),
                    'icon' => 'heroicon-o-cube',
                    'title' => 'Product database',
                    'description' => 'Browse and edit the imported product records.',
                ],
                [
                    'url' => route('personnel'),
                    'icon' => 'heroicon-o-user-group',
                    'title' => 'Technical personnel',
                    'description' => 'Personnel list backing the TSP analytics.',
                ],
                [
                    'url' => route('settings'),
                    'icon' => 'heroicon-o-cog-6-tooth',
                    'title' => 'Settings',
                    'description' => 'Workspace preferences and account controls.',
                ],
            ],
        ];
    }
}
