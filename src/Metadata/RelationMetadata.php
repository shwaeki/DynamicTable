<?php

namespace Shwaeki\DynamicTable\Metadata;

final class RelationMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $relatedModel,
        public readonly ?string $foreignKey = null,
        public readonly ?string $ownerKey = null,
    ) {}

    /** Single-record relations can be flattened into a column; plural ones cannot. */
    public function isSingular(): bool
    {
        return in_array($this->type, ['BelongsTo', 'HasOne', 'MorphOne', 'HasOneThrough'], true);
    }

    public function isPlural(): bool
    {
        return ! $this->isSingular();
    }

    /** Relations we can safely traverse for filtering (morphTo has no fixed target). */
    public function isTraversable(): bool
    {
        return $this->relatedModel !== null && $this->type !== 'MorphTo';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'singular' => $this->isSingular(),
        ];
    }
}
