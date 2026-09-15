<?php

namespace App\Support\Dashboard;

use App\Support\Dashboard\Contracts\DashboardWidget;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Type registry for dashboard widget factories. Populated from
 * config/dashboard.php at boot; factories are resolved lazily through the
 * container so they can constructor-inject services.
 */
final class WidgetRegistry
{
    /** @var array<string, class-string<DashboardWidget>> */
    private array $factories = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<DashboardWidget>  $factoryClass
     */
    public function register(string $type, string $factoryClass): void
    {
        $this->factories[$type] = $factoryClass;
    }

    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @throws InvalidArgumentException when no factory is registered
     */
    public function make(string $type): DashboardWidget
    {
        $class = $this->factories[$type]
            ?? throw new InvalidArgumentException("No widget factory registered for type '{$type}'.");

        $factory = $this->container->make($class);

        assert($factory instanceof DashboardWidget);

        return $factory;
    }

    /**
     * Display metadata for every registered type — powers the add-widget
     * picker in the grid editor.
     *
     * @return array<string, array{title: string, description: string}>
     */
    public function definitions(): array
    {
        $out = [];

        foreach ($this->factories as $type => $class) {
            $definition = $this->container->make($class)->definition();

            $out[$type] = [
                'title' => $definition['title'],
                'description' => $definition['description'],
            ];
        }

        return $out;
    }
}
