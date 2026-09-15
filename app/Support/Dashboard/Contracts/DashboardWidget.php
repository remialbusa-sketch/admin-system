<?php

namespace App\Support\Dashboard\Contracts;

use App\Support\Dashboard\DashboardContext;

/**
 * A dashboard widget factory: turns declarative layout props plus the shared
 * DashboardContext into a Blade view and its view model. Types are registered
 * in config/dashboard.php and factories are resolved through the container,
 * so they may constructor-inject services (e.g. the expression engine).
 */
interface DashboardWidget
{
    /**
     * The layout engine's type key (matches a config dashboard.widgets key).
     */
    public function type(): string;

    /**
     * Widget metadata for the editor: display name/description, the size a
     * freshly added widget gets, its starting props, and the settings form
     * schema rendered in the customize-mode settings modal.
     *
     * Field shape: ['key', 'label', 'type' => text|number|expression|select,
     * 'placeholder' => ?, 'default' => ?, 'required' => bool, 'options' => ?].
     *
     * @return array{title: string, description: string, defaultSize: array{w: int, h: int}, defaultProps: array<string, mixed>, settings: array<int, array<string, mixed>>}
     */
    public function definition(): array;

    /**
     * Resolve the widget into a view + view model.
     *
     * @param  array<string, mixed>  $props
     * @return array{view: string, data: array<string, mixed>}
     *
     * @throws \App\Support\Dashboard\ExpressionSyntaxError on a bad expression
     * @throws \InvalidArgumentException on unknown datasets/metrics
     */
    public function resolve(array $props, DashboardContext $ctx): array;
}
