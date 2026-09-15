<?php

namespace App\Support\Dashboard;

use Throwable;

/**
 * Orchestrates the pipeline: raw layout JSON → normalizer → widget factories
 * → view + view model per widget. A widget whose configuration is broken
 * (bad expression, unknown dataset) degrades to a per-widget error card —
 * one bad formula can never take down the dashboard, and the failure is
 * reported like any other app error.
 *
 * @phpstan-type BuiltWidget array{id: string, type: string, w: int, h: int, props: array<string, mixed>, view: string, data: array<string, mixed>}
 */
final class DashboardLayoutEngine
{
    public function __construct(
        private readonly GridLayoutNormalizer $normalizer,
        private readonly WidgetRegistry $registry,
    ) {}

    /**
     * @param  mixed  $rawLayout  user-saved layout array or the config default
     * @return array{version: int, widgets: array<int, BuiltWidget>}
     */
    public function build(mixed $rawLayout, DashboardContext $ctx): array
    {
        $layout = $this->normalizer->normalize($rawLayout);
        $widgets = [];

        foreach ($layout['widgets'] as $widget) {
            try {
                $factory = $this->registry->make($widget['type']);
                $resolved = $factory->resolve($widget['props'], $ctx);

                $widgets[] = $widget + [
                    'view' => $resolved['view'],
                    'data' => $resolved['data'],
                ];
            } catch (Throwable $e) {
                report($e);

                $widgets[] = $widget + [
                    'view' => 'components.dashboard.widgets.error',
                    'data' => [
                        'message' => $e->getMessage(),
                        'widget' => $widget['type'],
                    ],
                ];
            }
        }

        return ['version' => $layout['version'], 'widgets' => $widgets];
    }
}
