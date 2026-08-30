<?php

namespace Shwaeki\DynamicTable\Metadata;

final class ModelMetadata
{
    /**
     * @param  array<string, FieldMetadata>  $fields
     * @param  array<string, RelationMetadata>  $relations
     */
    public function __construct(
        public readonly string $model,
        public readonly string $table,
        public readonly string $keyName,
        public readonly array $fields,
        public readonly array $relations,
        public readonly bool $usesSoftDeletes = false,
        public readonly ?string $labelColumn = null,
    ) {}

    public function field(string $name): ?FieldMetadata
    {
        return $this->fields[$name] ?? null;
    }

    public function relation(string $name): ?RelationMetadata
    {
        return $this->relations[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Real database columns only (excludes computed accessors).
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        $names = [];

        foreach ($this->fields as $name => $field) {
            if (! $field->computed) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
