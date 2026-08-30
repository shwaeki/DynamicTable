<?php

namespace Shwaeki\DynamicTable\Exceptions;

use RuntimeException;

class DynamicTableException extends RuntimeException
{
    public static function unknownTable(string $key): self
    {
        return new self("No DynamicTable is registered for the key [{$key}].");
    }

    public static function duplicateKey(string $key, string $existing, string $incoming): self
    {
        return new self(
            "The table key [{$key}] is used by both [{$existing}] and [{$incoming}]. ".
            'Set a unique $tableKey on one of them.'
        );
    }

    public static function missingModel(string $class): self
    {
        return new self("[{$class}] must define a \$model property or override query().");
    }

    public static function invalidField(string $field): self
    {
        return new self("The field [{$field}] is not available on this table.");
    }

    public static function featureDisabled(string $feature): self
    {
        return new self("The [{$feature}] feature is not enabled for this table.");
    }
}
