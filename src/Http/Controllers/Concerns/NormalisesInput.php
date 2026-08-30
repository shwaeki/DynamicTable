<?php

namespace Shwaeki\DynamicTable\Http\Controllers\Concerns;

use Shwaeki\DynamicTable\Columns\ColumnDefinition;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Metadata\FieldType;

/**
 * Turning a bag of column keys and raw values into validated attributes.
 *
 * Shared by inline editing, inline creating and bulk editing so all three agree
 * on which columns may be written, how a value is coerced, and what rules apply
 * when none were declared. A value rejected in one is rejected in all of them.
 */
trait NormalisesInput
{
    /**
     * @param  array<string, mixed>  $fields  keyed by column key
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, ColumnDefinition>}
     */
    protected function prepare(DynamicTable $table, array $fields): array
    {
        $attributes = [];
        $rules = [];
        $columns = [];
        $declared = $table->rules();

        foreach ($fields as $key => $value) {
            $column = $table->column((string) $key);

            // Only columns the table itself declares editable are writable, so
            // an unexposed attribute cannot be set even on an unguarded model.
            if ($column === null || ! $column->editable) {
                continue;
            }

            $name = (string) ($column->field->column ?? $column->field->name);
            $attributes[$name] = $this->normalize($value, $column);
            $columns[$name] = $column;
            $rules[$name] = $declared[$column->path()] ?? $declared[$name] ?? $this->autoRules($column);
        }

        return [$attributes, $rules, $columns];
    }

    protected function normalize(mixed $value, ColumnDefinition $column): mixed
    {
        if ($value === '' && $column->field->nullable) {
            return null;
        }

        return match ($column->type) {
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            FieldType::Integer => is_numeric($value) ? (int) $value : $value,
            FieldType::Decimal => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }

    /** @return list<string> */
    protected function autoRules(ColumnDefinition $column): array
    {
        $rules = [$column->field->nullable ? 'nullable' : 'required'];

        $rules[] = match ($column->type) {
            FieldType::Integer => 'integer',
            FieldType::Decimal => 'numeric',
            FieldType::Boolean => 'boolean',
            FieldType::Date, FieldType::DateTime => 'date',
            FieldType::Email => 'email',
            FieldType::Url => 'url',
            FieldType::Json => 'array',
            default => 'string',
        };

        if ($column->type === FieldType::Enum && $column->field->options !== []) {
            $rules[] = 'in:'.implode(',', array_column($column->field->options, 'value'));
        }

        if ($column->field->length !== null && $column->type->isTextual()) {
            $rules[] = 'max:'.$column->field->length;
        }

        return $rules;
    }

    /**
     * Translate attribute-keyed validation errors back into column keys so the
     * browser can highlight the right cell.
     *
     * @param  array<string, list<string>>  $messages
     * @param  array<string, ColumnDefinition>  $columns
     * @return array<string, list<string>>
     */
    protected function keyErrors(array $messages, array $columns): array
    {
        $mapped = [];

        foreach ($messages as $attribute => $errors) {
            $key = $columns[$attribute]->key ?? $attribute;
            $mapped[$key] = $errors;
        }

        return $mapped;
    }
}
