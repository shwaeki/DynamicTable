<?php

namespace Shwaeki\DynamicTable\Columns;

use Closure;
use Shwaeki\DynamicTable\Metadata\FieldMetadata;
use Shwaeki\DynamicTable\Metadata\FieldType;

/**
 * A resolved column: metadata plus the table's presentation overrides.
 *
 * Developers never construct these directly. They come from automatic
 * discovery, optionally refined by the array returned from columns().
 */
final class ColumnDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly FieldMetadata $field,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly bool $visible = true,
        public readonly bool $sortable = true,
        public readonly bool $searchable = false,
        public readonly bool $filterable = true,
        public readonly bool $editable = false,
        public readonly bool $exportable = true,
        public readonly ?string $format = null,
        public readonly ?string $align = null,
        public readonly ?int $width = null,
        public readonly ?int $minWidth = null,
        public readonly ?int $maxWidth = null,
        public readonly bool $wrap = false,
        public readonly bool $raw = false,
        public readonly ?string $class = null,
        /** Lower survives longer when columns are collapsed on a narrow screen. */
        public readonly int $priority = 100,
        public readonly ?Closure $render = null,
        public readonly array $badges = [],
        public readonly array $meta = [],
    ) {}

    public function path(): string
    {
        return $this->field->path;
    }

    public function isComputed(): bool
    {
        return $this->field->computed;
    }

    public function isRelational(): bool
    {
        return $this->field->isRelational();
    }

    /** @return array<string, mixed> The client-side description of this column. */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'path' => $this->field->path,
            'label' => $this->label,
            'type' => $this->type->value,
            'visible' => $this->visible,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'editable' => $this->editable,
            'align' => $this->align ?? $this->defaultAlign(),
            'width' => $this->width,
            'minWidth' => $this->minWidth,
            'maxWidth' => $this->maxWidth,
            'wrap' => $this->wrap,
            'class' => $this->class,
            'priority' => $this->priority,
            'format' => $this->format,
            'raw' => $this->raw,
            'input' => $this->type->input(),
            'options' => $this->field->options ?: null,
            'relation' => $this->field->relationKey(),
        ], static fn (mixed $value): bool => $value !== null && $value !== false && $value !== []);
    }

    public function defaultAlign(): string
    {
        return match (true) {
            $this->type->isNumeric() => 'end',
            $this->type === FieldType::Boolean => 'center',
            default => 'start',
        };
    }

    /** @param array<string, mixed> $overrides */
    public function merge(array $overrides): self
    {
        return new self(
            key: $overrides['key'] ?? $this->key,
            field: $overrides['field'] ?? $this->field,
            label: $overrides['label'] ?? $this->label,
            type: $overrides['type'] ?? $this->type,
            visible: $overrides['visible'] ?? $this->visible,
            sortable: $overrides['sortable'] ?? $this->sortable,
            searchable: $overrides['searchable'] ?? $this->searchable,
            filterable: $overrides['filterable'] ?? $this->filterable,
            editable: $overrides['editable'] ?? $this->editable,
            exportable: $overrides['exportable'] ?? $this->exportable,
            format: $overrides['format'] ?? $this->format,
            align: $overrides['align'] ?? $this->align,
            width: $overrides['width'] ?? $this->width,
            minWidth: $overrides['minWidth'] ?? $this->minWidth,
            maxWidth: $overrides['maxWidth'] ?? $this->maxWidth,
            wrap: $overrides['wrap'] ?? $this->wrap,
            raw: $overrides['raw'] ?? $this->raw,
            class: $overrides['class'] ?? $this->class,
            priority: $overrides['priority'] ?? $this->priority,
            render: $overrides['render'] ?? $this->render,
            badges: $overrides['badges'] ?? $this->badges,
            meta: $overrides['meta'] ?? $this->meta,
        );
    }
}
