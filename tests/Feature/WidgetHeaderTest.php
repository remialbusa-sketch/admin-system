<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widget headers show only what is useful and customizable: the
 * settings-driven title (and subtitle). The hardcoded decorative kicker
 * tags (KPI, COMPARE, SHARE, …) are gone from every widget.
 */
class WidgetHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_header_renders_title_without_the_kicker_tag(): void
    {
        $html = view('components.dashboard.widget-header', [
            'tag' => 'KPI',
            'title' => 'Installed products',
            'subtitle' => 'units installed',
            'tone' => 'primary',
        ])->render();

        // The kicker renders as a text node right after its line span.
        $this->assertStringNotContainsString('</span>KPI', $html);
        $this->assertStringContainsString('Installed products', $html);
        $this->assertStringContainsString('units installed', $html);
    }
}
