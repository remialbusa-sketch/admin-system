<?php

namespace App\Services;

use App\ColumnTypes\Contracts\ColumnType;
use App\ColumnTypes\Types\CheckboxColumnType;
use App\ColumnTypes\Types\DateColumnType;
use App\ColumnTypes\Types\DropdownColumnType;
use App\ColumnTypes\Types\EmailColumnType;
use App\ColumnTypes\Types\FilesColumnType;
use App\ColumnTypes\Types\FormulaColumnType;
use App\ColumnTypes\Types\LinkColumnType;
use App\ColumnTypes\Types\LocationColumnType;
use App\ColumnTypes\Types\LongTextColumnType;
use App\ColumnTypes\Types\NumberColumnType;
use App\ColumnTypes\Types\PersonColumnType;
use App\ColumnTypes\Types\PhoneColumnType;
use App\ColumnTypes\Types\StatusColumnType;
use App\ColumnTypes\Types\TextColumnType;
use InvalidArgumentException;

class ColumnTypeRegistry
{
    /**
     * @var array<string, ColumnType>
     */
    private array $types;

    public function __construct()
    {
        $types = [
            new TextColumnType,
            new LongTextColumnType,
            new NumberColumnType,
            new StatusColumnType,
            new CheckboxColumnType,
            new DateColumnType,
            new EmailColumnType,
            new PhoneColumnType,
            new LinkColumnType,
            new LocationColumnType,
            new DropdownColumnType,
            new PersonColumnType,
            new FilesColumnType,
            new FormulaColumnType,
        ];

        $this->types = [];

        foreach ($types as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    public function resolve(string $key): ColumnType
    {
        if (! isset($this->types[$key])) {
            throw new InvalidArgumentException("Unsupported column type [{$key}].");
        }

        return $this->types[$key];
    }

    /**
     * @return array<string, ColumnType>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }
}
