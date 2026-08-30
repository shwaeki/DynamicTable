<?php

namespace Shwaeki\DynamicTable\Views;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves the people a view can be shared with.
 *
 * The package cannot know your user model or what you call a person's name, so
 * both are configuration. By default it uses the model behind Laravel's own
 * auth provider and its "name" column, which is right for most applications.
 */
class UserDirectory
{
    /** @var array<string, string> */
    protected array $names = [];

    public function enabled(): bool
    {
        return (bool) config('dynamic-table.views.sharing.enabled', true)
            && $this->modelClass() !== null;
    }

    /** @return class-string<Model>|null */
    public function modelClass(): ?string
    {
        $configured = config('dynamic-table.views.sharing.model')
            ?? config('auth.providers.users.model');

        return is_string($configured) && class_exists($configured) && is_a($configured, Model::class, true)
            ? $configured
            : null;
    }

    /**
     * Search for people to share with.
     *
     * Always limited and always filtered by the search term: a directory of a
     * hundred thousand users must never be sent to the browser in one go.
     *
     * @return list<array{id: string, name: string}>
     */
    public function search(string $term, int|string|null $exclude = null): array
    {
        $class = $this->modelClass();

        if ($class === null) {
            return [];
        }

        /** @var Model $model */
        $model = new $class;
        $columns = (array) config('dynamic-table.views.sharing.search_columns', ['name', 'email']);
        $limit = (int) config('dynamic-table.views.sharing.max_results', 20);

        $query = $model->newQuery();

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

            $query->where(function ($nested) use ($columns, $escaped, $model): void {
                foreach ($columns as $column) {
                    $nested->orWhere($model->qualifyColumn((string) $column), 'like', '%'.$escaped.'%');
                }
            });
        }

        if ($exclude !== null) {
            $query->whereKeyNot($exclude);
        }

        try {
            return $query->limit($limit)->get()
                ->map(fn (Model $user): array => [
                    'id' => (string) $user->getKey(),
                    'name' => $this->nameOf($user),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Display names for a set of ids, in one query.
     *
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            return [];
        }

        $missing = array_values(array_diff($ids, array_keys($this->names)));

        if ($missing !== [] && ($class = $this->modelClass()) !== null) {
            try {
                /** @var Model $model */
                $model = new $class;

                $model->newQuery()->whereKey($missing)->get()->each(function (Model $user): void {
                    $this->names[(string) $user->getKey()] = $this->nameOf($user);
                });
            } catch (Throwable) {
                // A missing or unreadable user simply has no name.
            }
        }

        return Collection::make($ids)
            ->mapWithKeys(fn (string $id): array => [$id => $this->names[$id] ?? ''])
            ->filter()
            ->all();
    }

    public function name(int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $this->names([$id])[(string) $id] ?? null;
    }

    protected function nameOf(Model $user): string
    {
        $column = (string) config('dynamic-table.views.sharing.name_column', 'name');

        $name = $user->getAttribute($column);

        return is_scalar($name) && (string) $name !== ''
            ? (string) $name
            : (string) $user->getKey();
    }
}
