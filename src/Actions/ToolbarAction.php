<?php

namespace Shwaeki\DynamicTable\Actions;

use Closure;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * A button in the table's own toolbar — "New product", "Sync catalogue".
 *
 * This is the answer to "how do I put my own button next to Filters and
 * Columns". Like a row action it is either a link or a server-side handler, but
 * it concerns the table as a whole rather than one record, so nothing is passed
 * to it except the input you ask for.
 */
final class ToolbarAction
{
    private ?Closure $handler = null;

    private ?Closure $url = null;

    private ?Closure $visible = null;

    private ?Closure $authorize = null;

    private ?string $ability = null;

    private ?string $label = null;

    private ?string $icon = null;

    private ?string $confirm = null;

    private ?string $target = null;

    private string $align = 'end';

    private string $style = 'default';

    private bool $refresh = true;

    /** @var array<string, mixed> */
    private array $fields = [];

    private function __construct(public readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** A link, e.g. to your own create form. */
    public static function link(string $name, string $url, ?string $target = null): self
    {
        return self::make($name)->url(static fn (): string => $url, $target);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** @param Closure(): string $url */
    public function url(Closure $url, ?string $target = null): self
    {
        $this->url = $url;
        $this->target = $target;

        return $this;
    }

    /** @param Closure(DynamicTable, array<string, mixed>): mixed $handler */
    public function handle(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Inputs collected before the action runs.
     *
     * @param  array<string, mixed>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function confirm(?string $message = null): self
    {
        $this->confirm = $message ?? __('dynamic-table::table.actions.confirm');

        return $this;
    }

    /** Render at the start of the toolbar, beside search, rather than the end. */
    public function alignStart(): self
    {
        $this->align = 'start';

        return $this;
    }

    public function primary(): self
    {
        $this->style = 'primary';

        return $this;
    }

    public function danger(): self
    {
        $this->style = 'danger';

        return $this;
    }

    public function withoutRefresh(): self
    {
        $this->refresh = false;

        return $this;
    }

    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /** @param Closure(DynamicTable): bool $callback */
    public function visible(Closure $callback): self
    {
        $this->visible = $callback;

        return $this;
    }

    /** @param Closure(DynamicTable): bool $callback */
    public function authorize(Closure $callback): self
    {
        $this->authorize = $callback;

        return $this;
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }

    public function refreshes(): bool
    {
        return $this->refresh;
    }

    public function isAvailable(DynamicTable $table): bool
    {
        if ($this->visible !== null && ! ($this->visible)($table)) {
            return false;
        }

        if ($this->authorize !== null) {
            return (bool) ($this->authorize)($table);
        }

        return $this->ability === null || $table->can($this->ability);
    }

    /** @param array<string, mixed> $input */
    public function run(DynamicTable $table, array $input = []): mixed
    {
        return $this->handler === null ? null : ($this->handler)($table, $input);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $field) {
            if (isset($field['rules'])) {
                $rules[$name] = $field['rules'];
            }
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label ?? Str::headline($this->name),
            'icon' => $this->icon,
            'confirm' => $this->confirm,
            'link' => $this->isLink(),
            'href' => $this->url === null ? null : (string) ($this->url)(),
            'target' => $this->target,
            'align' => $this->align,
            'style' => $this->style,
            'fields' => $this->fields ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
    }
}
