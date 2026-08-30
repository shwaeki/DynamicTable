<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A cheap, approximate row count for a table.
 *
 * Used only to decide whether counting the real result set is affordable, so
 * being approximate is the point: asking COUNT(*) in order to find out whether
 * COUNT(*) is too slow would defeat the exercise. Every driver has a statistics
 * view that answers instantly; when one is unavailable the answer is 0, which
 * means "small", which means the normal counted pagination.
 */
class TableEstimator
{
    /** @var array<string, int> */
    protected array $memo = [];

    public function rows(Model $model): int
    {
        $connection = $model->getConnection();
        $table = $model->getTable();
        $key = $connection->getName().':'.$table;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->remember($key, function () use ($connection, $table): int {
            try {
                return match ($connection->getDriverName()) {
                    'mysql', 'mariadb' => (int) ($connection->selectOne(
                        'select table_rows as n from information_schema.tables where table_schema = database() and table_name = ?',
                        [$table],
                    )->n ?? 0),

                    'pgsql' => (int) ($connection->selectOne(
                        'select reltuples::bigint as n from pg_class where oid = to_regclass(?)',
                        [$table],
                    )->n ?? 0),

                    // SQLite keeps no statistics, so fall back to a real count.
                    // It is only ever used by the demo and by test suites.
                    'sqlite' => (int) $connection->table($table)->count(),

                    default => 0,
                };
            } catch (Throwable) {
                return 0;
            }
        });
    }

    /** @param callable(): int $callback */
    protected function remember(string $key, callable $callback): int
    {
        if (! config('dynamic-table.cache.metadata', true)) {
            return $callback();
        }

        $prefix = (string) config('dynamic-table.cache.prefix', 'dynamic-table');

        // Short-lived: the answer only decides which paginator to use, so it may
        // lag reality by a few minutes without any visible consequence.
        return (int) Cache::store(config('dynamic-table.cache.store'))->remember(
            $prefix.':rows:'.md5($key),
            now()->addMinutes(10),
            $callback,
        );
    }
}
