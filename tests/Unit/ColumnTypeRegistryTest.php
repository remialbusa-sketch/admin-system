<?php

namespace Tests\Unit;

use App\Services\ColumnTypeRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class ColumnTypeRegistryTest extends TestCase
{
    public function test_registry_contains_all_planned_column_types(): void
    {
        $registry = app(ColumnTypeRegistry::class);

        $this->assertSame([
            'text',
            'long_text',
            'number',
            'status',
            'checkbox',
            'date',
            'email',
            'phone',
            'link',
            'location',
            'dropdown',
            'person',
            'files',
            'formula',
        ], array_keys($registry->all()));
    }

    public function test_registry_normalizes_values_and_shadow_fields(): void
    {
        $registry = app(ColumnTypeRegistry::class);

        $text = $registry->resolve('text');
        $this->assertSame(['text' => 'Service record'], $text->validate('Service record'));
        $this->assertSame(['value_text' => 'Service record'], $text->toShadowFields(['text' => 'Service record']));

        $number = $registry->resolve('number');
        $this->assertSame(['number' => 12.35], $number->validate('12.345', ['precision' => 2]));
        $this->assertSame(['value_number' => 12.35], $number->toShadowFields(['number' => 12.35]));

        $status = $registry->resolve('status');
        $statusValue = $status->validate('Done', [
            'options' => [
                ['index' => 0, 'label' => 'New'],
                ['index' => 1, 'label' => 'Done'],
            ],
        ]);
        $this->assertSame(['index' => 1, 'label' => 'Done'], $statusValue);
        $this->assertSame([
            'value_text' => 'Done',
            'value_number' => 1,
        ], $status->toShadowFields($statusValue));

        $date = $registry->resolve('date');
        $dateValue = $date->validate(['date' => '2026-08-20', 'time' => '09:30'], ['include_time' => true]);
        $this->assertSame(['date' => '2026-08-20', 'time' => '09:30:00'], $dateValue);
        $this->assertSame(['value_date' => '2026-08-20'], $date->toShadowFields($dateValue));

        $checkbox = $registry->resolve('checkbox');
        $this->assertSame(['checked' => true], $checkbox->validate('yes'));
        $this->assertSame(['value_number' => 1], $checkbox->toShadowFields(['checked' => true]));

        $dropdown = $registry->resolve('dropdown');
        $dropdownValue = $dropdown->validate(['selected' => ['Urgent', 'Blocked']], [
            'multi' => true,
            'options' => [
                ['index' => 0, 'label' => 'Urgent'],
                ['index' => 1, 'label' => 'Blocked'],
            ],
        ]);
        $this->assertSame(['selected' => [0, 1], 'labels' => ['Urgent', 'Blocked']], $dropdownValue);
        $this->assertSame(['value_text' => 'Urgent, Blocked'], $dropdown->toShadowFields($dropdownValue));
    }

    public function test_structured_types_and_formula_require_valid_values(): void
    {
        $registry = app(ColumnTypeRegistry::class);

        $this->assertSame(
            ['email' => 'person@example.com'],
            $registry->resolve('email')->validate('person@example.com'),
        );
        $this->assertSame(
            ['url' => 'https://example.com', 'text' => 'Example'],
            $registry->resolve('link')->validate(['url' => 'https://example.com', 'text' => 'Example']),
        );
        $this->assertSame(
            ['lat' => 14.83, 'lng' => 120.28, 'address' => 'Pampanga'],
            $registry->resolve('location')->validate(['lat' => '14.83', 'lng' => '120.28', 'address' => 'Pampanga']),
        );
        $this->assertSame(
            ['user_ids' => [12, 18]],
            $registry->resolve('person')->validate(['user_ids' => [12, 18]]),
        );
        $this->assertSame(
            ['file_ids' => [55, 56]],
            $registry->resolve('files')->validate(['file_ids' => [55, 56]]),
        );
        $this->assertSame(
            ['number' => 42.0, 'expression' => '{Budget}-{Spent}'],
            $registry->resolve('formula')->validate(42, ['expression' => '{Budget}-{Spent}']),
        );

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('email')->validate('not-an-email');
    }

    public function test_unknown_types_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ColumnTypeRegistry::class)->resolve('unknown');
    }
}
