<?php

namespace Shwaeki\DynamicTable\Actions;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\Icon;

/**
 * A named operation applied to a selection.
 *
 *     BulkAction::make('activate')->handle(fn ($records) => $records->each->activate())
 *     BulkAction::delete()
 */
final class BulkAction
{
    private ?Closure $handler = null;

    private ?Closure $authorize = null;

    private ?string $ability = null;

    private ?string $label = null;

    private ?string $icon = null;

    private ?string $confirm = null;

    private bool $destructive = false;

    /** @var array<string, mixed> */
    private array $fields = [];

    private int $chunk = 500;

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
            ->ability('delete')
            ->destructive()
            ->confirm(__('dynamic-table::table.actions.confirm_delete'))
            ->handle(static function (Builder $query): int {
                $deleted = 0;

                $query->chunkById(500, static function ($records) use (&$deleted): void {
                    foreach ($records as $record) {
                        $record->delete();
                        $deleted++;
                    }
                });

                return $deleted;
            });
    }

    /** Set one or more attributes on every selected record. */
    public static function update(string $name, array $attributes): self
    {
        return self::make($name)
            ->ability('update')
            ->handle(static function (Builder $query) use ($attributes): int {
                $updated = 0;

                $query->chunkById(500, static function ($records) use ($attributes, &$updated): void {
                    foreach ($records as $record) {
                        $record->forceFill($attributes)->save();
                        $updated++;
                    }
                });

                return $updated;
            });
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

    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    public function authorize(Closure $callback): self
    {
        $this->authorize = $callback;

        return $this;
    }

    /**
     * @param  Closure(Builder, array<string, mixed>): mixed  $handler
     */
    public function handle(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Extra inputs collected before the action runs, keyed by name.
     * Each entry: ['type' => 'select'|'text'|..., 'label' => ..., 'options' => [...], 'rules' => '...']
     *
     * @param  array<string, mixed>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function chunk(int $size): self
    {
        $this->chunk = max(1, $size);

        return $this;
    }

    public function chunkSize(): int
    {
        return $this->chunk;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
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

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $input
     */
    public function run(Builder $query, array $input = []): mixed
    {
        if ($this->handler === null) {
            return null;
        }

        return ($this->handler)($query, $input);
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
            'destructive' => $this->destructive,
            'fields' => $this->fields ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
    }
}
