<?php

namespace Tests\Unit;

use Tests\TestCase;

class DashboardWidgetResponsiveShellTest extends TestCase
{
    /**
     * Phase 2 — the widget shell responds to the widget's own width, not
     * just the viewport: a 320px phone, a narrow desktop column
     * (md:col-span-3) and paper all render the same blade set. These scans
     * pin the contracts that make that work:
     *  - content boxes never overshoot the widget shell they live in;
     *  - sparse vertical charts scale fluidly, dense/horizontal ones keep
     *    natural size and scroll instead of becoming unreadable;
     *  - widgets are size containers and the padded body reacts to them;
     *  - paper shows whole charts, never scroll-cropped ones;
     *  - resize grips keep a real target on touch screens.
     */
    protected function css(): string
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($css);

        return $css;
    }

    protected function widgetView(string $path): string
    {
        $source = file_get_contents(resource_path('views/components/dashboard/'.$path));
        $this->assertNotFalse($source);

        return $source;
    }

    public function test_widget_content_boxes_fit_the_shell_instead_of_fixed_pixels(): void
    {
        foreach (['widgets/column-chart.blade.php', 'widgets/slicer.blade.php'] as $path) {
            $source = $this->widgetView($path);

            $this->assertStringNotContainsString(
                'style="height: {{ $contentHeight }}px"',
                $source,
                $path.' sizes the content box with a bare pixel height that can overshoot the widget shell.'
            );
            $this->assertStringNotContainsString(
                'height: min({{ $contentHeight }}px, 100%)',
                $source,
                $path.' must not cap the content box with a percentage height: the grid shell is '
                .'indefinite during intrinsic row sizing, so min(…, 100%) resolves as auto there and '
                .'the full content height (hundreds of option buttons, an oversized chart) inflates '
                .'the shared row tracks — see the super-long TSA reference slicers.'
            );
            $this->assertStringContainsString(
                'style="height: {{ $contentHeight }}px; max-height: 100%"',
                $source,
                $path.' must size the content box with a definite pixel height (so intrinsic row '
                .'sizing can never inflate the track) and cap it at the shell with max-height: 100% '
                .'(the live-resize follow that min(…, 100%) used to provide).'
            );
        }
    }

    public function test_no_widget_blade_caps_a_content_height_with_a_percentage(): void
    {
        foreach (glob(resource_path('views/components/dashboard/widgets/*.blade.php')) as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source);

            $this->assertStringNotContainsString(
                'height: min(',
                $source,
                basename($file).' caps a content height with min(…): a percentage inside a height is '
                .'indefinite while the grid sizes its row tracks, so the cap silently resolves as auto '
                .'and the full content height inflates every track the widget spans. Use a definite '
                .'pixel height plus max-height: 100% instead.'
            );
        }
    }

    public function test_sparse_charts_scale_fluidly_and_horizontal_ones_fold_without_an_800px_floor(): void
    {
        $source = $this->widgetView('widgets/column-chart.blade.php');

        $this->assertDoesNotMatchRegularExpression(
            '/\$isHorizontal \? 800\b/',
            $source,
            'Horizontal drawings need no 800px natural floor; 460 (labels + plot) stays readable without forcing phone-sized columns to scroll horizontally.'
        );
        $this->assertStringContainsString(
            '$isHorizontal ? 460 :',
            $source,
            'Horizontal natural width: 200px label column + 260px plot.'
        );
        $this->assertStringContainsString(
            '$fluidHeight',
            $source,
            'Squish-tolerant vertical drawings must size to their box.'
        );
        $this->assertStringContainsString(
            'max-height: 100%',
            $source,
            'Fluid drawings must be capped by their box, never overflow it.'
        );
    }

    public function test_every_widget_is_a_size_container_and_the_body_reacts_to_it(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('container-type: inline-size', $css);
        $this->assertStringContainsString('container-name: widget', $css);
        $this->assertMatchesRegularExpression(
            '/@container widget \(/',
            $css,
            'The container context exists specifically so widget-level rules can key off it.'
        );

        $grid = $this->widgetView('grid.blade.php');
        $this->assertStringContainsString('widget-body', $grid);
        $this->assertStringContainsString(
            'padding: 1rem',
            $css,
            'A narrow widget (a phone-scale column) tightens its body padding via the container query.'
        );
    }

    public function test_print_shows_whole_charts_not_scroll_crops(): void
    {
        $css = $this->css();

        $start = strpos($css, '@media print');
        $this->assertNotFalse($start);

        $end = strpos($css, '/* Home', $start);
        $print = substr($css, $start, $end === false ? null : $end - $start);

        $this->assertStringContainsString(
            '.dashboard-widget svg',
            $print,
            'Print must scale charts inside their widget instead of cropping them at the screen-only scroll box.'
        );
    }

    public function test_resize_grips_have_a_touch_target(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('@media (pointer: coarse)', $css);
        $coarse = substr($css, (int) strpos($css, '@media (pointer: coarse)'), 600);
        $this->assertStringContainsString('widget-resize-x', $coarse, 'The pointer-coarse block must enlarge the resize grips.');
    }
}
