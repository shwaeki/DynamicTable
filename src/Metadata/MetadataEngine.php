<?php

namespace Shwaeki\DynamicTable\Metadata;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Shwaeki\DynamicTable\Support\SchemaIntrospector;
use Throwable;
use UnitEnum;

/**
 * The single source of truth about what a model exposes.
 *
 * Everything else — the column resolver, the filter builder, the column picker,
 * the import mapper and the export mapper — reads from here so that a field is
 * described exactly once.
 */
class MetadataEngine
{
    /** @var array<string, ModelMetadata> */
    protected array $memo = [];

    /** @var array<string, FieldMetadata|false> */
    protected array $pathMemo = [];

    protected SchemaIntrospector $schema;

    /** Method names on Eloquent/base classes that must never be probed as relations. */
    protected const SKIP_METHODS = [
        'newQuery', 'newModelQuery', 'newQueryWithoutRelationships', 'newCollection',
        'getAttributes', 'getOriginal', 'getChanges', 'getDirty', 'getRelations',
        'getConnection', 'getKey', 'getTable', 'getRouteKey', 'getIncrementing',
        'getMorphClass', 'getQueueableId', 'getQueueableRelations', 'getQueueableConnection',
        'getForeignKey', 'getKeyName', 'getKeyType', 'getFillable', 'getGuarded',
        'getHidden', 'getVisible', 'getCasts', 'getDates', 'getDateFormat',
        'getPerPage', 'getRouteKeyName', 'getObservableEvents', 'getGlobalScopes',
        'getTouchedRelations', 'getRelationValue', 'getAttribute', 'toArray', 'toJson',
        'jsonSerialize', 'fresh', 'refresh', 'replicate', 'delete', 'save', 'push',
        'getConnectionName', 'usesTimestamps', 'trashed', 'resolveChildRouteBinding',
        'resolveRouteBinding', 'getDeletedAtColumn', 'getQualifiedDeletedAtColumn',
        'getQualifiedKeyName', 'query', 'on', 'onWriteConnection', 'all', 'with',
    ];

    public function __construct(protected ?string $storeName = null, ?SchemaIntrospector $schema = null)
    {
        $this->schema = $schema ?? new SchemaIntrospector;
    }

    /**
     * @param  class-string<Model>|Model  $model
     */
    public function for(string|Model $model): ModelMetadata
    {
        $class = $model instanceof Model ? $model::class : $model;

        if (isset($this->memo[$class])) {
            return $this->memo[$class];
        }

        $instance = $model instanceof Model ? $model : new $class;

        $payload = $this->remember($class, fn (): array => $this->introspect($instance));

        return $this->memo[$class] = $this->hydrate($payload);
    }

    /**
     * Resolve a dotted field path such as "department.manager.name" against a root model.
     *
     * Returns null when any segment is unknown, when a relation cannot be
     * traversed, or when the configured depth limit is exceeded — callers
     * treat null as "not allowed" rather than throwing, so a stale saved view
     * degrades instead of breaking the page.
     *
     * @param  class-string<Model>  $model
     */
    public function resolve(string $model, string $path): ?FieldMetadata
    {
        $path = trim($path);

        if ($path === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path)) {
            return null;
        }

        // The depth limit is part of the key: the same path can be allowed or
        // rejected depending on configuration, and a rejection must not stick.
        $memoKey = $model.'|'.$path.'|'.config('dynamic-table.security.max_relation_depth', 3);

        if (array_key_exists($memoKey, $this->pathMemo)) {
            return $this->pathMemo[$memoKey] ?: null;
        }

        $result = $this->resolvePath($model, $path);

        $this->pathMemo[$memoKey] = $result ?? false;

        return $result;
    }

    /** @param class-string<Model> $model */
    protected function resolvePath(string $model, string $path): ?FieldMetadata
    {
        $segments = explode('.', $path);
        $maxDepth = (int) config('dynamic-table.security.max_relation_depth', 3);

        if (count($segments) - 1 > $maxDepth) {
            return null;
        }

        $current = $model;
        $relationPath = [];
        $relationType = null;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            $meta = $this->for($current);
            $relation = $meta->relation($segment);

            if ($relation === null || ! $relation->isTraversable()) {
                return null;
            }

            $relationPath[] = $segment;
            $relationType = $relation->type;
            $current = $relation->relatedModel;
        }

        $name = $segments[0];
        $meta = $this->for($current);
        $field = $meta->field($name);

        if ($field === null) {
            /*
             * "orders_count", "orders_sum_total" — an aggregate over a plural
             * relation, spelled the way Eloquent spells the attribute it would
             * add for it. Tried only after the real columns, so a denormalised
             * counter cache actually named orders_count still wins: it is a
             * column, it is indexed, and reading it is free.
             */
            if ($relationPath === []) {
                $aggregate = $this->aggregateField($current, $name);

                if ($aggregate !== null) {
                    return $aggregate;
                }
            }

            // "department" on its own resolves to the relation's label column.
            $relation = $meta->relation($name);

            if ($relation !== null && $relation->isTraversable()) {
                $relatedMeta = $this->for($relation->relatedModel);
                $labelColumn = $relatedMeta->labelColumn;

                if ($labelColumn === null) {
                    return null;
                }

                return $this->rebase(
                    $relatedMeta->field($labelColumn),
                    $path,
                    array_merge($relationPath, [$name]),
                    $relation->type,
                    $relation->relatedModel,
                    $this->labelFor($path),
                );
            }

            return null;
        }

        if ($relationPath === []) {
            return $field;
        }

        return $this->rebase($field, $path, $relationPath, $relationType, $current, $this->labelFor($path));
    }

    /**
     * An aggregate over one of the model's plural relations, or null.
     *
     * The spelling is Eloquent's own — orders_count, orders_exists,
     * orders_sum_total, orders_avg_total, orders_min_x, orders_max_x — because
     * those are exactly the attribute names withCount() and withAggregate()
     * produce, so the name a developer writes is the name the query answers
     * with, and nobody has to learn a second syntax.
     *
     * Singular relations are excluded: "the customer's name" is a column
     * reached through a relation, which the package already does far better as
     * a join-free eager load.
     *
     * @param  class-string<Model>  $model
     */
    protected function aggregateField(string $model, string $name): ?FieldMetadata
    {
        $meta = $this->for($model);

        foreach ($meta->relations as $relationName => $relation) {
            if (! $relation->isTraversable() || $relation->isSingular() || $relation->relatedModel === null) {
                continue;
            }

            $prefix = Str::snake($relationName).'_';

            if (! str_starts_with($name, $prefix)) {
                continue;
            }

            $rest = substr($name, strlen($prefix));

            if ($rest === 'count' || $rest === 'exists') {
                return new FieldMetadata(
                    path: $name,
                    name: $name,
                    label: __('dynamic-table::table.aggregate.'.$rest, ['relation' => $this->labelFor($relationName)]),
                    type: $rest === 'count' ? FieldType::Integer : FieldType::Boolean,
                    // Both coalesce: no rows is zero, or false, never null.
                    nullable: false,
                    aggregate: $rest,
                    aggregateRelation: $relationName,
                );
            }

            foreach (['sum', 'avg', 'min', 'max'] as $function) {
                if (! str_starts_with($rest, $function.'_')) {
                    continue;
                }

                $column = substr($rest, strlen($function) + 1);
                $field = $this->for($relation->relatedModel)->field($column);

                if ($field === null || $field->computed) {
                    continue;
                }

                // Summing a date or a name is a question with no answer. Min
                // and max are meaningful on anything that orders, so they are
                // left to the column's own type.
                if (in_array($function, ['sum', 'avg'], true)
                    && ! in_array($field->type, [FieldType::Integer, FieldType::Decimal], true)) {
                    continue;
                }

                /*
                 * "Total · sum" rather than "Orders Sum Total".
                 *
                 * The relation is already the group heading, so repeating it in
                 * every label is noise; and a date's extremes are earliest and
                 * latest, which is what people say, rather than lowest and
                 * highest.
                 */
                $dated = in_array($field->type, [FieldType::Date, FieldType::DateTime], true);
                $wording = $dated && $function === 'min' ? 'earliest' : ($dated && $function === 'max' ? 'latest' : $function);

                return new FieldMetadata(
                    path: $name,
                    name: $name,
                    label: __('dynamic-table::table.aggregate.'.$wording, [
                        'field' => $this->labelFor($column),
                        'relation' => $this->labelFor($relationName),
                    ]),
                    type: $function === 'avg' ? FieldType::Decimal : $field->type,
                    // A sum over no rows is zero; a min over no rows is nothing.
                    nullable: in_array($function, ['min', 'max'], true),
                    aggregate: $function,
                    aggregateRelation: $relationName,
                    aggregateColumn: $column,
                );
            }
        }

        return null;
    }

    protected function rebase(
        ?FieldMetadata $field,
        string $path,
        array $relationPath,
        ?string $relationType,
        ?string $relatedModel,
        string $label,
    ): ?FieldMetadata {
        if ($field === null) {
            return null;
        }

        return new FieldMetadata(
            path: $path,
            name: $field->name,
            label: $label,
            type: $field->type,
            nullable: true,
            computed: $field->computed,
            relationPath: $relationPath,
            relationType: $relationType,
            relatedModel: $relatedModel,
            options: $field->options,
            enumClass: $field->enumClass,
            column: $field->column,
            length: $field->length,
            primary: false,
            indexed: $field->indexed,
        );
    }

    /**
     * The field tree used by the filter builder and column picker.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $blocked  dotted paths to omit
     * @return list<array{key: string, label: string, fields: list<array<string, mixed>>}>
     */
    public function tree(string $model, int $depth = 1, array $blocked = []): array
    {
        $groups = [];
        $blocked = array_flip($blocked);

        // Kept apart from $groups until the end: a relation's aggregates belong
        // after the model's own fields and its singular relations, not wedged
        // between them.
        $aggregateGroups = [];

        $walk = function (string $class, array $prefix, int $level, array $seen = []) use (&$walk, &$groups, &$aggregateGroups, $depth, $blocked): void {
            // Stop the walk from circling back: order -> invoice -> order adds
            // a group that is redundant and confusing in the filter builder.
            $seen[$class] = true;

            $meta = $this->for($class);
            $key = implode('.', $prefix);
            $fields = [];

            foreach ($meta->fields as $name => $field) {
                $path = $prefix === [] ? $name : $key.'.'.$name;

                if (isset($blocked[$path])) {
                    continue;
                }

                $resolved = $prefix === []
                    ? $field
                    : $this->rebase($field, $path, $prefix, null, $class, $this->labelFor($name));

                if ($resolved !== null) {
                    $fields[] = $resolved->toArray();
                }
            }

            /*
             * The aggregates of each plural relation, as a group of their own.
             *
             * Their own group rather than mixed into the model's fields: a
             * customer has a name and a country, and "the sum of their orders'
             * totals" is a different kind of thing. Listed among the columns it
             * would bury them; under a heading of its own it reads as what it
             * is, and the picker already draws one heading per group.
             */
            if ($prefix === [] && $depth >= 1) {
                foreach ($meta->relations as $relationName => $relation) {
                    $group = $this->aggregateGroup($class, $relationName, $blocked);

                    if ($group !== null) {
                        $aggregateGroups[] = $group;
                    }
                }
            }

            if ($fields !== []) {
                $groups[] = [
                    'key' => $key,
                    'label' => $prefix === [] ? class_basename($class) : $this->labelFor($key),
                    'fields' => $fields,
                ];
            }

            if ($level >= $depth) {
                return;
            }

            foreach ($meta->relations as $name => $relation) {
                if (! $relation->isTraversable() || ! $relation->isSingular()) {
                    continue;
                }

                $path = $prefix === [] ? $name : $key.'.'.$name;

                if (isset($blocked[$path]) || isset($seen[$relation->relatedModel])) {
                    continue;
                }

                $walk($relation->relatedModel, array_merge($prefix, [$name]), $level + 1, $seen);
            }
        };

        $walk($model, [], 0);

        return array_merge($groups, $aggregateGroups);
    }

    /**
     * One plural relation's aggregates, as a catalogue group.
     *
     * Everything the database can answer about the relation without leaving the
     * row: how many, whether any, and — for the columns where it means
     * something — the sum, the average, the lowest and the highest.
     *
     * What is offered is decided by type, not by a list somebody maintains:
     * summing a name or a foreign key is not a question, so only real numeric
     * columns get sum and average, and only things that order get lowest and
     * highest. Keys are excluded from both — the average of an id is a number
     * with no meaning.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, int>  $blocked
     * @return array{key: string, label: string, fields: list<array<string, mixed>>}|null
     */
    protected function aggregateGroup(string $model, string $relationName, array $blocked): ?array
    {
        $meta = $this->for($model);
        $relation = $meta->relation($relationName);

        if ($relation === null || ! $relation->isTraversable() || $relation->isSingular() || $relation->relatedModel === null) {
            return null;
        }

        $prefix = Str::snake($relationName);
        $related = $this->for($relation->relatedModel);
        $paths = [$prefix.'_count', $prefix.'_exists'];

        // Foreign keys look numeric and are never worth summing, so they are
        // recognised by name rather than by type — the alternative is a
        // catalogue full of "Average of category_id".
        $keys = [$related->keyName];

        foreach (array_keys($related->fields) as $name) {
            if (str_ends_with((string) $name, '_id')) {
                $keys[] = (string) $name;
            }
        }

        foreach ($related->fields as $name => $field) {
            if ($field->computed || in_array((string) $name, $keys, true)) {
                continue;
            }

            if (in_array($field->type, [FieldType::Integer, FieldType::Decimal], true)) {
                array_push($paths, $prefix.'_sum_'.$name, $prefix.'_avg_'.$name, $prefix.'_min_'.$name, $prefix.'_max_'.$name);

                continue;
            }

            // A date has no sum, but "earliest" and "latest" are two of the
            // most useful questions on this whole list.
            if (in_array($field->type, [FieldType::Date, FieldType::DateTime], true)) {
                array_push($paths, $prefix.'_min_'.$name, $prefix.'_max_'.$name);
            }
        }

        $limit = max(2, (int) config('dynamic-table.security.max_aggregate_fields', 40));
        $fields = [];

        foreach ($paths as $path) {
            if (count($fields) >= $limit) {
                break;
            }

            // A real column of the same name wins, here as everywhere else, and
            // it is already in the model's own group.
            if (isset($blocked[$path]) || isset($meta->fields[$path])) {
                continue;
            }

            $field = $this->aggregateField($model, $path);

            if ($field !== null) {
                $fields[] = $field->toArray();
            }
        }

        if ($fields === []) {
            return null;
        }

        return [
            'key' => $prefix,
            'label' => __('dynamic-table::table.aggregate.group', ['relation' => $this->labelFor($relationName)]),
            'fields' => $fields,
        ];
    }

    public function labelFor(string $path): string
    {
        $segments = explode('.', $path);

        $segments = array_map(static function (string $segment): string {
            $segment = preg_replace('/_id$/', '', $segment) ?: $segment;

            return Str::headline($segment);
        }, $segments);

        return implode(' ', $segments);
    }

    public function flush(?string $model = null): void
    {
        if ($model !== null) {
            unset($this->memo[$model]);
            Cache::store($this->store())->forget($this->cacheKey($model));

            return;
        }

        /*
         * Everything, including what is in the cache store.
         *
         * This used to clear only the in-process memo, which meant
         * "php artisan dynamic-table:clear" — the command the documentation
         * sends people to after adding a column — cleared nothing that
         * outlived the request, and the new column stayed invisible until
         * somebody ran cache:clear.
         *
         * The index is how: tags are not available on the file or database
         * stores, so the keys that were written are remembered instead.
         */
        $cache = Cache::store($this->store());

        foreach ((array) $cache->get($this->indexKey(), []) as $key) {
            if (is_string($key)) {
                $cache->forget($key);
            }
        }

        $cache->forget($this->indexKey());

        $this->memo = [];
        $this->pathMemo = [];
    }

    /* ------------------------------------------------------------------ */
    /* Introspection */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    protected function introspect(Model $model): array
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName();
        $casts = $model->getCasts();
        $hidden = array_flip($model->getHidden());
        $blocked = (array) config('dynamic-table.security.blocked_columns', []);

        $columns = [];

        try {
            $columns = $this->schema->columns($connection, $table);
        } catch (Throwable) {
            $columns = [];
        }

        $indexed = [];

        try {
            foreach ($this->schema->indexes($connection, $table) as $index) {
                foreach ($index['columns'] as $column) {
                    $indexed[$column] = true;
                }
            }
        } catch (Throwable) {
            // Index information is an optimisation hint only.
        }

        $fields = [];

        foreach ($columns as $column) {
            $name = $column['name'];

            if (isset($hidden[$name]) || $this->isBlocked($name, $blocked)) {
                continue;
            }

            $type = $this->detectType($name, $column, $casts[$name] ?? null);

            $fields[$name] = [
                'path' => $name,
                'name' => $name,
                'label' => $this->labelFor($name),
                'type' => $type->value,
                'nullable' => (bool) $column['nullable'],
                'computed' => false,
                'options' => $this->enumOptions($casts[$name] ?? null),
                'enumClass' => $this->enumClass($casts[$name] ?? null),
                'column' => $name,
                'length' => $this->lengthOf($column),
                'primary' => $name === $model->getKeyName(),
                'indexed' => isset($indexed[$name]) || $name === $model->getKeyName(),
            ];
        }

        foreach ($this->appendedAttributes($model) as $name) {
            if (isset($fields[$name]) || isset($hidden[$name]) || $this->isBlocked($name, $blocked)) {
                continue;
            }

            $type = $this->detectType($name, [], $casts[$name] ?? null);

            $fields[$name] = [
                'path' => $name,
                'name' => $name,
                'label' => $this->labelFor($name),
                'type' => $type->value,
                'nullable' => true,
                'computed' => true,
                'options' => $this->enumOptions($casts[$name] ?? null),
                'enumClass' => $this->enumClass($casts[$name] ?? null),
                'column' => null,
                'length' => null,
                'primary' => false,
                'indexed' => false,
            ];
        }

        return [
            'model' => $model::class,
            'table' => $table,
            'keyName' => $model->getKeyName(),
            'fields' => $fields,
            'relations' => $this->discoverRelations($model),
            'usesSoftDeletes' => in_array(SoftDeletes::class, class_uses_recursive($model), true),
            'labelColumn' => $this->detectLabelColumn(array_keys($fields)),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function discoverRelations(Model $model): array
    {
        $relations = [];
        $reflection = new ReflectionClass($model);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $name = $method->getName();

            if (str_starts_with($name, '__') || in_array($name, self::SKIP_METHODS, true)) {
                continue;
            }

            $declaring = $method->getDeclaringClass()->getName();

            if (str_starts_with($declaring, 'Illuminate\\')) {
                continue;
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                $typeName = $returnType->getName();

                if (! is_a($typeName, Relation::class, true)) {
                    continue;
                }
            } elseif ($returnType !== null) {
                continue;
            }

            try {
                $result = $model->{$name}();
            } catch (Throwable) {
                continue;
            }

            if (! $result instanceof Relation) {
                continue;
            }

            $related = null;

            try {
                $related = $result->getRelated()::class;
            } catch (Throwable) {
                $related = null;
            }

            $relations[$name] = [
                'name' => $name,
                'label' => $this->labelFor($name),
                'type' => class_basename($result),
                'relatedModel' => $related,
                'foreignKey' => method_exists($result, 'getForeignKeyName')
                    ? $this->safeCall($result, 'getForeignKeyName')
                    : null,
                'ownerKey' => method_exists($result, 'getOwnerKeyName')
                    ? $this->safeCall($result, 'getOwnerKeyName')
                    : null,
            ];
        }

        ksort($relations);

        return $relations;
    }

    protected function safeCall(object $object, string $method): ?string
    {
        try {
            $value = $object->{$method}();

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $column */
    protected function detectType(string $name, array $column, mixed $cast): FieldType
    {
        if (is_string($cast)) {
            $base = strtolower(explode(':', $cast)[0]);

            $fromCast = match ($base) {
                'bool', 'boolean' => FieldType::Boolean,
                'int', 'integer' => FieldType::Integer,
                'real', 'float', 'double', 'decimal' => FieldType::Decimal,
                'date', 'immutable_date' => FieldType::Date,
                'datetime', 'immutable_datetime', 'timestamp', 'custom_datetime' => FieldType::DateTime,
                'array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:json' => FieldType::Json,
                'string' => FieldType::String,
                default => null,
            };

            if ($fromCast !== null) {
                return $fromCast;
            }

            if (enum_exists($cast)) {
                return FieldType::Enum;
            }
        }

        $dbType = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

        // MySQL reports "tinyint" as the type name and keeps the display width
        // in the full type, so the boolean convention only shows up there.
        $fullType = strtolower((string) ($column['type'] ?? $dbType));

        $fromDb = match (true) {
            $dbType === '' => null,
            str_contains($dbType, 'bool') || $dbType === 'bit'
                || str_starts_with($fullType, 'tinyint(1)') || str_starts_with($fullType, 'bit(1)') => FieldType::Boolean,
            str_contains($dbType, 'datetime') || str_contains($dbType, 'timestamp') => FieldType::DateTime,
            $dbType === 'date' => FieldType::Date,
            str_contains($dbType, 'time') => FieldType::Time,
            str_contains($dbType, 'json') || str_contains($dbType, 'jsonb') => FieldType::Json,
            str_contains($dbType, 'uuid') => FieldType::Uuid,
            str_contains($dbType, 'int') || $dbType === 'serial' || $dbType === 'bigserial' => FieldType::Integer,
            str_contains($dbType, 'decimal') || str_contains($dbType, 'numeric')
                || str_contains($dbType, 'float') || str_contains($dbType, 'double')
                || str_contains($dbType, 'money') => FieldType::Decimal,
            str_contains($dbType, 'text') || str_contains($dbType, 'blob') => FieldType::Text,
            str_contains($dbType, 'enum') => FieldType::Enum,
            default => FieldType::String,
        };

        $type = $fromDb ?? FieldType::String;

        // Name-based refinements only sharpen the display, never the SQL semantics.
        if ($type === FieldType::String || $type === FieldType::Text) {
            if ($name === 'email' || str_ends_with($name, '_email')) {
                return FieldType::Email;
            }

            if ($name === 'url' || str_ends_with($name, '_url') || str_ends_with($name, '_link')) {
                return FieldType::Url;
            }

            if (preg_match('/(avatar|photo|image|thumbnail|logo|picture)(_path|_url)?$/', $name) === 1) {
                return FieldType::Image;
            }

            if ($name === 'uuid' || str_ends_with($name, '_uuid')) {
                return FieldType::Uuid;
            }
        }

        // Flag columns Laravel did not cast and the schema did not narrow —
        // a plain int used as a yes/no, spelled the way flags usually are.
        if ($type === FieldType::Integer && (
            str_starts_with($name, 'is_')
            || str_starts_with($name, 'has_')
            || in_array($name, ['active', 'enabled', 'disabled', 'published', 'visible', 'archived', 'blocked', 'verified', 'confirmed', 'featured', 'default'], true)
        )) {
            return FieldType::Boolean;
        }

        return $type;
    }

    /** @return array<int, array{value: string|int, label: string}> */
    protected function enumOptions(mixed $cast): array
    {
        $class = $this->enumClass($cast);

        if ($class === null) {
            return [];
        }

        $options = [];

        foreach ($class::cases() as $case) {
            $value = $case instanceof BackedEnum ? $case->value : $case->name;

            $options[] = [
                'value' => $value,
                'label' => method_exists($case, 'label')
                    ? (string) $case->label()
                    : Str::headline((string) $value),
            ];
        }

        return $options;
    }

    /** @return class-string<UnitEnum>|null */
    protected function enumClass(mixed $cast): ?string
    {
        if (! is_string($cast)) {
            return null;
        }

        $class = explode(':', $cast)[0];

        return enum_exists($class) ? $class : null;
    }

    /** @return list<string> */
    protected function appendedAttributes(Model $model): array
    {
        try {
            $reflection = new ReflectionClass($model);

            if (! $reflection->hasProperty('appends')) {
                return [];
            }

            $property = $reflection->getProperty('appends');
            $property->setAccessible(true);

            return array_values(array_filter((array) $property->getValue($model), 'is_string'));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $column */
    protected function lengthOf(array $column): ?int
    {
        if (preg_match('/\((\d+)/', (string) ($column['type'] ?? ''), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @param list<string> $blocked */
    protected function isBlocked(string $name, array $blocked): bool
    {
        $name = strtolower($name);

        foreach ($blocked as $needle) {
            if ($needle !== '' && str_contains($name, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $fields */
    protected function detectLabelColumn(array $fields): ?string
    {
        foreach (['name', 'title', 'label', 'display_name', 'full_name', 'code', 'slug', 'email', 'reference'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Caching */
    /* ------------------------------------------------------------------ */

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    protected function remember(string $class, callable $callback): array
    {
        if (! config('dynamic-table.cache.metadata', true)) {
            return $callback();
        }

        $ttl = (int) config('dynamic-table.cache.ttl', 86400);
        $cache = Cache::store($this->store());
        $key = $this->cacheKey($class);
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $value = $callback();

        $cache->put($key, $value, $ttl);

        // Written on a miss only, which is where the cost belongs: a warm
        // cache does not pay for the bookkeeping that lets it be cleared.
        $this->index($key, $ttl);

        return $value;
    }

    /** Remember that a key was written, so flush() can find it again. */
    protected function index(string $key, int $ttl): void
    {
        $cache = Cache::store($this->store());
        $keys = (array) $cache->get($this->indexKey(), []);

        if (in_array($key, $keys, true)) {
            return;
        }

        $keys[] = $key;

        // Outlives the entries it points at: an index that expired first would
        // leave keys nothing could clear.
        $cache->put($this->indexKey(), array_values($keys), $ttl * 2);
    }

    protected function indexKey(): string
    {
        $prefix = (string) config('dynamic-table.cache.prefix', 'dynamic-table');

        return $prefix.':meta:index:'.md5((string) config('app.env'));
    }

    protected function cacheKey(string $class): string
    {
        $prefix = (string) config('dynamic-table.cache.prefix', 'dynamic-table');

        return $prefix.':meta:'.md5($class.'|'.(string) config('app.env'));
    }

    protected function store(): ?string
    {
        return $this->storeName ?? config('dynamic-table.cache.store');
    }

    /** @param array<string, mixed> $payload */
    protected function hydrate(array $payload): ModelMetadata
    {
        $fields = [];

        foreach ($payload['fields'] as $name => $field) {
            $fields[$name] = new FieldMetadata(
                path: $field['path'],
                name: $field['name'],
                label: $field['label'],
                type: FieldType::from($field['type']),
                nullable: $field['nullable'],
                computed: $field['computed'],
                relationPath: [],
                relationType: null,
                relatedModel: null,
                options: $field['options'],
                enumClass: $field['enumClass'],
                column: $field['column'],
                length: $field['length'],
                primary: $field['primary'],
                indexed: $field['indexed'],
            );
        }

        $relations = [];

        foreach ($payload['relations'] as $name => $relation) {
            $relations[$name] = new RelationMetadata(
                name: $relation['name'],
                label: $relation['label'],
                type: $relation['type'],
                relatedModel: $relation['relatedModel'],
                foreignKey: $relation['foreignKey'],
                ownerKey: $relation['ownerKey'],
            );
        }

        return new ModelMetadata(
            model: $payload['model'],
            table: $payload['table'],
            keyName: $payload['keyName'],
            fields: $fields,
            relations: $relations,
            usesSoftDeletes: $payload['usesSoftDeletes'],
            labelColumn: $payload['labelColumn'],
        );
    }
}
