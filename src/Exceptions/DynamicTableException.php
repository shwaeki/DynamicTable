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

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $known
     */
    public static function unknownFeatures(array $unknown, array $known, string $class): self
    {
        sort($known);

        return new self(
            'Unknown feature '.(count($unknown) > 1 ? 'names' : 'name').' ['.implode(', ', $unknown)."] on [{$class}].\n".
            "An unrecognised name would otherwise be ignored, silently leaving the feature off.\n".
            'Available: '.implode(', ', $known).'.'
        );
    }

    public static function invalidField(string $field): self
    {
        return new self("The field [{$field}] is not available on this table.");
    }

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $known
     */
    public static function unknownSlots(array $unknown, array $known, string $class): self
    {
        return new self(
            'Unknown slot '.(count($unknown) > 1 ? 'names' : 'name').' ['.implode(', ', $unknown)."] on [{$class}].\n".
            "The markup would otherwise be rendered nowhere, with nothing to say why.\n".
            'Available: '.implode(', ', $known).'.'
        );
    }
}
