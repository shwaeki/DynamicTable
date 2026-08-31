<?php

namespace Shwaeki\DynamicTable\Columns;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Metadata\FieldMetadata;
use Shwaeki\DynamicTable\Metadata\FieldType;
use Shwaeki\DynamicTable\Metadata\MetadataEngine;
use Shwaeki\DynamicTable\Support\Feature;

/**
 * Turns a table definition into the final ordered column list.
 *
 * When columns() is empty the resolver picks a useful default set:
 * skip the primary key and housekeeping timestamps, and replace foreign keys
 * with their related label column so "department_id" shows up as the
 * department's name instead of a meaningless integer.
 */
class ColumnResolver
{
    /** Columns that are never auto-selected even though they are queryable. */
    protected const AUTO_SKIP = ['updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'];

    protected const AUTO_LIMIT = 8;

    public function __construct(protected MetadataEngine $metadata) {}

    /**
     * @return array<string, ColumnDefinition> keyed by column key
     */
    public function resolve(DynamicTable $table): array
    {
        $declared = $table->columnDefinitions();
        $model = $table->modelClass();

        $entries = $declared === []
            ? $this->autoColumns($table)
            : $this->normalizeDeclared($declared);

        $allowed = $table->allowedColumnPaths();
        $hidden = array_flip($table->hiddenColumnPaths());

        $columns = [];
        $position = 0;

        foreach ($entries as $path => $options) {
            if (isset($hidden[$path])) {
                continue;
            }

            if ($allowed !== [] && ! in_array($path, $allowed, true)) {
                continue;
            }

            $field = $this->metadata->resolve($model, $path)
                ?? $this->virtual($path, $options);

            if ($field === null) {
                continue;
            }

            $column = $this->build($table, $field, $options, $position < self::AUTO_LIMIT, $position);
            $columns[$column->key] = $column;
            $position++;
        }

        return $columns;
    }

    /**
     * A column the model has no field for.
     *
     * "image" is not an attribute, but a table that declares how to draw it —
     * a thumbnail built from the record, a computed badge — still means it as
     * a column. It is computed by definition: there is nothing to sort, filter
     * or search on, and marking it computed keeps the SELECT wide enough for
     * the closure to read whatever it needs off the record.
     *
     * Without a render closure it stays a typo, and is dropped as before.
     *
     * @param  array<string, mixed>  $options
     */
    protected function virtual(string $path, array $options): ?FieldMetadata
    {
        $declared = ($options['render'] ?? null) instanceof Closure || ($options['virtual'] ?? false);

        if (! $declared || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $path)) {
            return null;
        }

        return new FieldMetadata(
            path: $path,
            name: $path,
            label: (string) ($options['label'] ?? Str::headline($path)),
            type: isset($options['type']) && is_string($options['type'])
                ? FieldType::from($options['type'])
                : FieldType::String,
            computed: true,
        );
    }

    /**
     * One column, for a field the table did not declare.
     *
     * The column picker can add anything the metadata engine reaches, so those
     * columns have to be built somewhere. They get the same treatment as a
     * declared one — type, alignment, sortability, formatting all derived —
     * because a column added from the picker should be indistinguishable from a
     * column that was written down.
     *
     * @param  array<string, mixed>  $options
     */
    public function one(DynamicTable $table, FieldMetadata $field, array $options = []): ColumnDefinition
    {
        return $this->build($table, $field, $options, true, 99);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function build(
        DynamicTable $table,
        FieldMetadata $field,
        array $options,
        bool $defaultVisible,
        int $position = 0,
    ): ColumnDefinition {
        $type = isset($options['type']) && is_string($options['type'])
            ? FieldType::from($options['type'])
            : $field->type;

        $features = $table->features();

        // "Editable" means writable, whichever way the writing happens: inline
        // editing, the blank create row and bulk edit all go through the same
        // normalisation, so they agree on which columns may be set.
        $writable = $features->any(Feature::INLINE_EDIT, Feature::BULK_EDIT, Feature::CREATE);

        $editable = (bool) ($options['editable'] ?? false);

        if ($editable && (! $writable || $field->computed || $field->isRelational())) {
            $editable = false;
        }

        if (! isset($options['editable']) && $writable) {
            $editable = ! $field->computed && ! $field->isRelational() && ! $field->primary;
        }

        $render = $options['render'] ?? null;

        return new ColumnDefinition(
            key: $this->keyFor($field->path),
            field: $field,
            label: (string) ($options['label'] ?? $table->labelFor($field->path) ?? $field->label),
            type: $type,
            visible: (bool) ($options['visible'] ?? $defaultVisible),
            sortable: (bool) ($options['sortable'] ?? ($features->has(Feature::SORTING) && $field->isSortable())),
            searchable: (bool) ($options['searchable'] ?? $field->isSearchable()),
            filterable: (bool) ($options['filterable'] ?? ($features->has(Feature::FILTERS) && $field->isFilterable())),
            editable: $editable,
            exportable: (bool) ($options['exportable'] ?? true),
            format: isset($options['format']) ? (string) $options['format'] : null,
            align: isset($options['align']) ? (string) $options['align'] : null,
            width: isset($options['width']) ? (int) $options['width'] : null,
            minWidth: isset($options['minWidth']) ? (int) $options['minWidth'] : null,
            maxWidth: isset($options['maxWidth']) ? (int) $options['maxWidth'] : null,
            wrap: (bool) ($options['wrap'] ?? false),
            raw: (bool) ($options['raw'] ?? $this->rendersHtml($options, $render)),
            summary: $this->summaryFor($options['summary'] ?? null),
            class: isset($options['class']) ? (string) $options['class'] : null,
            // Declaration order is a good proxy for importance: the leftmost
            // column identifies the row, so it collapses last.
            priority: isset($options['priority']) ? (int) $options['priority'] : ($position === 0 ? 1 : 10 + $position),
            render: $render instanceof Closure ? $render : null,
            badges: $this->badgesFor($options['badges'] ?? []),
            placeholder: isset($options['empty']) ? (string) $options['empty'] : null,
            meta: (array) ($options['meta'] ?? []),
        );
    }

    /**
     * The badges option, kept in whichever shape it was written.
     *
     * @return array<array-key, mixed>|Closure|bool
     */
    protected function badgesFor(mixed $badges): array|Closure|bool
    {
        if ($badges instanceof Closure || is_bool($badges)) {
            return $badges;
        }

        return is_array($badges) ? $badges : [];
    }

    /**
     * Does this column produce markup rather than text?
     *
     * Three ways it can: a render closure typed to return an Htmlable, one of
     * the built-in cell renderers, or badges — all markup by definition. Either
     * way the cell is written out as HTML rather than escaped.
     *
     * @param  array<string, mixed>  $options
     */
    protected function rendersHtml(array $options, mixed $render): bool
    {
        $format = isset($options['format']) ? explode(':', (string) $options['format'], 2)[0] : null;

        if ($format !== null && in_array($format, CellRenderers::FORMATS, true)) {
            return true;
        }

        $badges = $options['badges'] ?? [];

        if ($badges !== [] && $badges !== false) {
            return true;
        }

        return $render instanceof Closure && $this->returnsHtml($render);
    }

    /**
     * The aggregate a column asked for, if it is one we support.
     *
     * A closed list, because it ends up in SQL. `true` is accepted as a
     * shorthand for the obvious choice — you almost always mean a sum.
     */
    protected function summaryFor(mixed $value): ?string
    {
        if ($value === true) {
            return 'sum';
        }

        return is_string($value) && in_array($value, ['sum', 'avg', 'min', 'max', 'count'], true)
            ? $value
            : null;
    }

    /**
     * Does this render closure declare that it returns HTML?
     *
     * Returning an Htmlable is an explicit statement that the value is already
     * safe markup, so a column that declares that return type does not also
     * need the raw flag. Anything untyped, or typed as string, still does.
     */
    protected function returnsHtml(Closure $render): bool
    {
        $type = (new \ReflectionFunction($render))->getReturnType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $name = $type->getName();

        return $name === Htmlable::class || is_subclass_of($name, Htmlable::class);
    }

    /**
     * A stable, URL-safe key for a dotted path.
     */
    public function keyFor(string $path): string
    {
        return str_replace('.', '__', $path);
    }

    public function pathFor(string $key): string
    {
        return str_replace('__', '.', $key);
    }

    /**
     * @param  array<int|string, mixed>  $declared
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeDeclared(array $declared): array
    {
        $entries = [];

        foreach ($declared as $key => $value) {
            if (is_int($key)) {
                if (is_string($value)) {
                    $entries[$value] = [];
                }

                continue;
            }

            if (is_string($value)) {
                $entries[$key] = ['label' => $value];

                continue;
            }

            if ($value instanceof Closure) {
                $entries[$key] = ['render' => $value];

                continue;
            }

            if (is_array($value)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function autoColumns(DynamicTable $table): array
    {
        $model = $table->modelClass();
        $meta = $this->metadata->for($model);
        $entries = [];

        // Map foreign keys to their relation so we can substitute a readable label.
        $foreignKeys = [];

        foreach ($meta->relations as $name => $relation) {
            if ($relation->isSingular() && $relation->isTraversable() && $relation->foreignKey !== null) {
                $foreignKeys[$relation->foreignKey] = $name;
            }
        }

        foreach ($meta->fields as $name => $field) {
            if ($field->primary || in_array($name, self::AUTO_SKIP, true)) {
                continue;
            }

            if (isset($foreignKeys[$name])) {
                $relationName = $foreignKeys[$name];
                $related = $meta->relation($relationName)?->relatedModel;
                $labelColumn = $related !== null ? $this->metadata->for($related)->labelColumn : null;

                if ($labelColumn !== null) {
                    $entries[$relationName.'.'.$labelColumn] = ['label' => $this->metadata->labelFor($relationName)];

                    continue;
                }
            }

            if ($field->type === FieldType::Json) {
                // Available, but not shown by default — JSON blobs make poor columns.
                $entries[$name] = ['visible' => false];

                continue;
            }

            $entries[$name] = [];
        }

        return $entries;
    }
}
