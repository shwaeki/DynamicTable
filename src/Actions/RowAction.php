<?php

namespace Shwaeki\DynamicTable\Actions;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Icon;

/**
 * A button on a single row.
 *
 * Two kinds, and the difference matters:
 *
 *   ->url(...)     a link. Rendered as <a href>, goes wherever you point it.
 *   ->handle(...)  a callback. Posted back to Laravel, authorised per record,
 *                  run on the server, and the row is repainted from the result.
 *
 * Visibility and authorisation are evaluated per record, so a row only ever
 * offers the actions that record actually allows — and the server checks again
 * before running anything.
 */
final class RowAction
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

    private bool $destructive = false;

    private bool $refresh = true;

    private function __construct(public readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** The built-in delete action, wired to the model policy. */
    public static function delete(string $name = 'delete'): self
    {
        return self::make($name)
            ->label(__('dynamic-table::table.actions.delete'))
            ->icon('🗑')
            ->ability('delete')
            ->destructive()
            ->confirm(__('dynamic-table::table.actions.confirm_delete_row'))
            ->handle(static fn (Model $record) => $record->delete());
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The button's icon.
     *
     * A glyph, an emoji, or the markup of an icon font — `<i class="far
     * fa-edit"></i>` — which is rendered as markup. Pass an Htmlable
     * (`new HtmlString(...)`) to say so outright.
     */
    public function icon(string|Htmlable $icon): self
    {
        $this->icon = Icon::html($icon)->toHtml();

        return $this;
    }

    /**
     * Turn this into a link.
     *
     * @param  Closure(Model): string  $url
     */
    public function url(Closure $url, ?string $target = null): self
    {
        $this->url = $url;
        $this->target = $target;

        return $this;
    }

    /**
     * Run something on the server for this record.
     *
     * @param  Closure(Model, array<string, mixed>): mixed  $handler
     */
    public function handle(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function confirm(?string $message = null): self
    {
        $this->confirm = $message ?? __('dynamic-table::table.actions.confirm');

        return $this;
    }

    public function destructive(bool $destructive = true): self
    {
        $this->destructive = $destructive;

        return $this;
    }

    /** Skip the refresh when the action changes nothing the table shows. */
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

    /** @param Closure(Model): bool $callback */
    public function visible(Closure $callback): self
    {
        $this->visible = $callback;

        return $this;
    }

    /** @param Closure(DynamicTable, Model): bool $callback */
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

    public function isVisibleFor(Model $record): bool
    {
        return $this->visible === null || (bool) ($this->visible)($record);
    }

    public function isAuthorized(DynamicTable $table, ?Model $record = null): bool
    {
        if ($this->authorize !== null) {
            return (bool) ($this->authorize)($table, $record);
        }

        if ($this->ability !== null) {
            return $table->can($this->ability, $record);
        }

        return true;
    }

    /** Whether this record should show this action at all. */
    public function appliesTo(DynamicTable $table, Model $record): bool
    {
        return $this->isVisibleFor($record) && $this->isAuthorized($table, $record);
    }

    /** @param array<string, mixed> $input */
    public function run(Model $record, array $input = []): mixed
    {
        return $this->handler === null ? null : ($this->handler)($record, $input);
    }

    /**
     * The shape the browser needs, without anything record-specific.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label ?? Str::headline($this->name),
            'icon' => $this->icon,
            'confirm' => $this->confirm,
            'destructive' => $this->destructive,
            'link' => $this->isLink(),
            'target' => $this->target,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
    }

    /** The per-record part: whether it applies, and where it points. */
    public function forRecord(Model $record): ?string
    {
        return $this->url === null ? null : (string) ($this->url)($record);
    }
}
