<?php

namespace Shwaeki\DynamicTable\Views;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Events\ViewCreated;
use Shwaeki\DynamicTable\Events\ViewDeleted;
use Shwaeki\DynamicTable\Events\ViewUpdated;
use Shwaeki\DynamicTable\Models\DynamicTableView;
use Shwaeki\DynamicTable\Support\Feature;
use Shwaeki\DynamicTable\Support\TableState;
use Throwable;

/**
 * Saved views: user-private, system-wide, and developer-declared presets.
 *
 * Precedence when a table boots:
 *   user default -> system default -> table preset marked default -> automatic
 */
class ViewEngine
{
    protected ?bool $tableExists = null;

    public function __construct(protected UserDirectory $directory) {}

    /** @return Collection<int, DynamicTableView> */
    public function forTable(DynamicTable $table): Collection
    {
        if (! $this->available($table)) {
            return collect();
        }

        return DynamicTableView::query()
            ->forTable($table->key())
            ->visibleTo($this->userId())
            ->orderByDesc('is_system')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    public function payloadFor(DynamicTable $table): array
    {
        $presets = [];

        foreach ($table->presets() as $name => $configuration) {
            $presets[] = [
                'id' => 'preset:'.$name,
                'name' => (string) ($configuration['name'] ?? Str::headline($name)),
                'system' => true,
                'preset' => true,
                'default' => (bool) ($configuration['default'] ?? false),
                'configuration' => Arr::except($configuration, ['name', 'default']),
            ];
        }

        $views = $this->forTable($table);
        $userId = $this->userId();
        $canManageSystem = $this->canManageSystemViews($table);

        // Resolve every owner's name in one query rather than one per view.
        $owners = $this->directory->names(
            $views->pluck('user_id')->filter()->unique()->all(),
        );

        $payloads = $views->map(function (DynamicTableView $view) use ($userId, $owners, $canManageSystem): array {
            $mine = $view->isOwnedBy($userId);

            return $view->toPayload() + [
                'mine' => $mine,
                // Someone else's private view, shared with me: readable, not editable.
                'sharedWithMe' => ! $mine && ! $view->is_system,
                'owner' => $view->user_id === null ? null : ($owners[(string) $view->user_id] ?? null),
                'canEdit' => $view->is_system ? $canManageSystem : $mine,
                'shareCount' => $mine ? $this->shareCount($view) : 0,
            ];
        })->all();

        return array_merge($presets, $payloads);
    }

    /**
     * Replace the list of people a view is shared with.
     *
     * Only the owner may share, and sharing grants read access alone — which is
     * why there is no "can edit" flag here to get wrong.
     *
     * @param  list<int|string>  $userIds
     */
    public function share(DynamicTable $table, DynamicTableView $view, array $userIds): void
    {
        $this->assertAvailable($table);

        abort_unless($this->sharingEnabled(), 400, 'View sharing is disabled.');
        abort_if($view->is_system, 422, 'A system view is already visible to everyone.');
        abort_unless($view->isOwnedBy($this->userId()), 403);

        $owner = (string) $this->userId();

        $userIds = collect($userIds)
            ->filter(static fn (mixed $id): bool => is_scalar($id) && (string) $id !== '')
            ->map(static fn (mixed $id): string => (string) $id)
            ->reject(static fn (string $id): bool => $id === $owner)
            ->unique()
            ->take(500)
            ->values();

        $existing = $this->shareQuery($view)->pluck('user_id')->map(strval(...));

        $this->shareQuery($view)->whereNotIn('user_id', $userIds->all())->delete();

        $rows = $userIds->diff($existing)->map(static fn (string $id): array => [
            'view_id' => $view->getKey(),
            'user_id' => $id,
            'shared_by' => $owner,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table(DynamicTableView::sharesTable())->insert($rows);
        }

        event(new ViewUpdated($table->key(), $view));
    }

    /**
     * The people a view is currently shared with, with their names.
     *
     * @return list<array{id: string, name: string}>
     */
    public function sharedWith(DynamicTableView $view): array
    {
        $ids = $this->shareQuery($view)->pluck('user_id')->all();
        $names = $this->directory->names($ids);

        return collect($ids)
            ->map(static fn (mixed $id): string => (string) $id)
            ->map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? $id])
            ->all();
    }

    public function shareCount(DynamicTableView $view): int
    {
        return $view->exists ? $this->shareQuery($view)->count() : 0;
    }

    public function sharingEnabled(): bool
    {
        return $this->directory->enabled();
    }

    /** @return Builder */
    protected function shareQuery(DynamicTableView $view)
    {
        return DB::table(DynamicTableView::sharesTable())->where('view_id', $view->getKey());
    }

    public function defaultFor(DynamicTable $table): ?DynamicTableView
    {
        if (! $this->available($table)) {
            return $this->presetDefault($table);
        }

        $userId = $this->userId();

        $view = null;

        if ($userId !== null) {
            $view = DynamicTableView::query()
                ->forTable($table->key())
                ->where('user_id', $userId)
                ->where('is_default', true)
                ->first();
        }

        $view ??= DynamicTableView::query()
            ->forTable($table->key())
            ->where('is_system', true)
            ->where('is_default', true)
            ->first();

        return $view ?? $this->presetDefault($table);
    }

    protected function presetDefault(DynamicTable $table): ?DynamicTableView
    {
        foreach ($table->presets() as $name => $configuration) {
            if (($configuration['default'] ?? false) === true) {
                $view = new DynamicTableView([
                    'table_key' => $table->key(),
                    'name' => (string) ($configuration['name'] ?? $name),
                    'configuration' => Arr::except($configuration, ['name', 'default']),
                    'is_system' => true,
                    'is_default' => true,
                ]);

                $view->id = 'preset:'.$name;

                return $view;
            }
        }

        return null;
    }

    public function find(DynamicTable $table, string $id): ?DynamicTableView
    {
        if (str_starts_with($id, 'preset:')) {
            $name = substr($id, 7);
            $presets = $table->presets();

            if (! isset($presets[$name])) {
                return null;
            }

            $view = new DynamicTableView([
                'table_key' => $table->key(),
                'name' => (string) ($presets[$name]['name'] ?? $name),
                'configuration' => Arr::except($presets[$name], ['name', 'default']),
                'is_system' => true,
            ]);

            $view->id = $id;

            return $view;
        }

        if (! $this->available($table)) {
            return null;
        }

        $view = DynamicTableView::query()
            ->forTable($table->key())
            ->visibleTo($this->userId())
            ->find($id);

        return $view;
    }

    public function create(DynamicTable $table, string $name, TableState $state, bool $system = false): DynamicTableView
    {
        $this->assertAvailable($table);

        if ($system && ! $this->canManageSystemViews($table)) {
            abort(403);
        }

        $limit = (int) config('dynamic-table.views.max_per_user', 100);

        if (! $system && $this->userId() !== null) {
            $count = DynamicTableView::query()
                ->forTable($table->key())
                ->where('user_id', $this->userId())
                ->count();

            abort_if($count >= $limit, 422, __('dynamic-table::table.views.limit_reached'));
        }

        $view = DynamicTableView::create([
            'table_key' => $table->key(),
            'user_id' => $system ? null : $this->userId(),
            'name' => mb_substr($name, 0, 150),
            'configuration' => $state->toViewConfiguration(),
            'version' => 1,
            'is_system' => $system,
            'is_default' => false,
            'created_by' => $this->userId(),
            'updated_by' => $this->userId(),
        ]);

        event(new ViewCreated($table->key(), $view));

        return $view;
    }

    public function update(DynamicTable $table, DynamicTableView $view, ?string $name, ?TableState $state): DynamicTableView
    {
        $this->authorizeWrite($table, $view);

        if ($name !== null) {
            $view->name = mb_substr($name, 0, 150);
        }

        if ($state !== null) {
            $view->configuration = $state->toViewConfiguration();
        }

        $view->updated_by = $this->userId();
        $view->save();

        event(new ViewUpdated($table->key(), $view));

        return $view;
    }

    public function delete(DynamicTable $table, DynamicTableView $view): void
    {
        $this->authorizeWrite($table, $view);

        $id = $view->getKey();
        $view->delete();

        event(new ViewDeleted($table->key(), (string) $id));
    }

    /**
     * Make this view the default, or clear the default when $default is false.
     *
     * A user's default and the system default are independent: setting one
     * never touches the other, because the user's choice simply takes
     * precedence at boot.
     */
    public function setDefault(DynamicTable $table, DynamicTableView $view, bool $default = true): void
    {
        $this->assertAvailable($table);

        if ($view->is_system) {
            abort_unless($this->canManageSystemViews($table), 403);

            DynamicTableView::query()
                ->forTable($table->key())
                ->where('is_system', true)
                ->update(['is_default' => false]);
        } else {
            $this->authorizeWrite($table, $view);

            DynamicTableView::query()
                ->forTable($table->key())
                ->where('user_id', $this->userId())
                ->update(['is_default' => false]);
        }

        $view->is_default = $default;
        $view->save();

        event(new ViewUpdated($table->key(), $view));
    }

    public function canManageSystemViews(DynamicTable $table): bool
    {
        if ($table->authorize('manage-system-views') === true) {
            return true;
        }

        $ability = (string) config('dynamic-table.views.system_ability', 'manage-dynamic-table-system-views');

        return Gate::has($ability)
            ? Gate::allows($ability, $table->key())
            : false;
    }

    protected function authorizeWrite(DynamicTable $table, DynamicTableView $view): void
    {
        if ($view->is_system) {
            abort_unless($this->canManageSystemViews($table), 403);

            return;
        }

        abort_unless($view->isOwnedBy($this->userId()), 403);
    }

    protected function assertAvailable(DynamicTable $table): void
    {
        abort_unless($this->available($table), 400, 'Saved views are not available for this table.');
    }

    public function available(DynamicTable $table): bool
    {
        if (! $table->hasFeature(Feature::VIEWS) || ! config('dynamic-table.views.enabled', true)) {
            return false;
        }

        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable((string) config('dynamic-table.views.table', 'dynamic_table_views'));
            } catch (Throwable) {
                $this->tableExists = false;
            }
        }

        return $this->tableExists;
    }

    public function userId(): int|string|null
    {
        $id = Auth::id();

        return is_scalar($id) ? $id : null;
    }
}
