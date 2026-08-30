<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Column and index introspection that works on every supported Laravel release.
 *
 * Laravel 11 added `Schema::getColumns()` and `Schema::getIndexes()`, which read
 * the schema natively instead of through doctrine/dbal. Laravel 10 has neither,
 * so this class falls back to the same driver queries Laravel 11 runs — the
 * package never needs dbal, on any version.
 *
 * The fallback returns the Laravel 11 array shape (a subset of it: the keys this
 * package actually consumes), so callers stay version-agnostic.
 */
class SchemaIntrospector
{
    /**
     * Columns of a table, as `['name' => ..., 'type_name' => ..., 'type' => ..., 'nullable' => ...]`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function columns(?string $connection, string $table): array
    {
        $builder = Schema::connection($connection);

        if (method_exists($builder, 'getColumns')) {
            return $builder->getColumns($table);
        }

        $database = DB::connection($connection);
        $prefixed = $database->getTablePrefix().$table;

        return match ($database->getDriverName()) {
            'sqlite' => $this->sqliteColumns($database, $prefixed),
            'mysql', 'mariadb' => $this->mysqlColumns($database, $prefixed),
            'pgsql' => $this->postgresColumns($database, $prefixed),
            'sqlsrv' => $this->sqlServerColumns($database, $prefixed),
            default => [],
        };
    }

    /**
     * Indexes of a table, as `['name' => ..., 'columns' => [...], 'unique' => ..., 'primary' => ...]`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function indexes(?string $connection, string $table): array
    {
        $builder = Schema::connection($connection);

        if (method_exists($builder, 'getIndexes')) {
            return $builder->getIndexes($table);
        }

        $database = DB::connection($connection);
        $prefixed = $database->getTablePrefix().$table;

        return match ($database->getDriverName()) {
            'sqlite' => $this->sqliteIndexes($database, $prefixed),
            'mysql', 'mariadb' => $this->mysqlIndexes($database, $prefixed),
            'pgsql' => $this->postgresIndexes($database, $prefixed),
            'sqlsrv' => $this->sqlServerIndexes($database, $prefixed),
            default => [],
        };
    }

    /* ------------------------------------------------------------------ */
    /* SQLite */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    protected function sqliteColumns(Connection $connection, string $table): array
    {
        $columns = [];

        foreach ($connection->select('pragma table_info('.$this->quote($table).')') as $row) {
            $columns[] = $this->column(
                (string) $row->name,
                (string) $row->type,
                ! $row->notnull,
            );
        }

        return $columns;
    }

    /** @return array<int, array<string, mixed>> */
    protected function sqliteIndexes(Connection $connection, string $table): array
    {
        $indexes = [];

        // An `integer primary key` column is the rowid alias and never appears in
        // index_list, so the primary key is read from the column list instead.
        $primary = [];

        foreach ($connection->select('pragma table_info('.$this->quote($table).')') as $row) {
            if ((int) $row->pk > 0) {
                $primary[(int) $row->pk] = (string) $row->name;
            }
        }

        if ($primary !== []) {
            ksort($primary);

            $indexes[] = [
                'name' => 'primary',
                'columns' => array_values($primary),
                'type' => null,
                'unique' => true,
                'primary' => true,
            ];
        }

        foreach ($connection->select('pragma index_list('.$this->quote($table).')') as $index) {
            $name = (string) $index->name;
            $columns = [];

            foreach ($connection->select('pragma index_info('.$this->quote($name).')') as $column) {
                if ($column->name !== null) {
                    $columns[] = (string) $column->name;
                }
            }

            $indexes[] = [
                'name' => $name,
                'columns' => $columns,
                'type' => null,
                'unique' => (bool) $index->unique,
                'primary' => ($index->origin ?? null) === 'pk',
            ];
        }

        return $indexes;
    }

    /* ------------------------------------------------------------------ */
    /* MySQL / MariaDB */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    protected function mysqlColumns(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select column_name as name, column_type as full_type, data_type as type_name, '
            .'is_nullable as nullable from information_schema.columns '
            .'where table_schema = database() and table_name = ? order by ordinal_position',
            [$table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = $this->column(
                (string) $row->name,
                (string) $row->full_type,
                $this->nullable($row->nullable),
                (string) $row->type_name,
            );
        }

        return $columns;
    }

    /** @return array<int, array<string, mixed>> */
    protected function mysqlIndexes(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select index_name as name, column_name as column_name, non_unique as non_unique '
            .'from information_schema.statistics '
            .'where table_schema = database() and table_name = ? order by index_name, seq_in_index',
            [$table],
        );

        return $this->group($rows, fn (object $row): bool => ! (bool) $row->non_unique);
    }

    /* ------------------------------------------------------------------ */
    /* PostgreSQL */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    protected function postgresColumns(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select column_name as name, udt_name as type_name, is_nullable as nullable, '
            .'character_maximum_length as length from information_schema.columns '
            .'where table_schema = current_schema() and table_name = ? order by ordinal_position',
            [$table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $type = (string) $row->type_name;

            $columns[] = $this->column(
                (string) $row->name,
                $row->length !== null ? $type.'('.(int) $row->length.')' : $type,
                $this->nullable($row->nullable),
                $type,
            );
        }

        return $columns;
    }

    /** @return array<int, array<string, mixed>> */
    protected function postgresIndexes(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select ic.relname as name, a.attname as column_name, ix.indisunique as is_unique, '
            .'ix.indisprimary as is_primary from pg_index ix '
            .'join pg_class c on c.oid = ix.indrelid '
            .'join pg_class ic on ic.oid = ix.indexrelid '
            .'join pg_attribute a on a.attrelid = c.oid and a.attnum = any(ix.indkey) '
            .'where c.relname = ? and c.relnamespace = (select oid from pg_namespace where nspname = current_schema())',
            [$table],
        );

        return $this->group(
            $rows,
            fn (object $row): bool => (bool) $row->is_unique,
            fn (object $row): bool => (bool) $row->is_primary,
        );
    }

    /* ------------------------------------------------------------------ */
    /* SQL Server */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    protected function sqlServerColumns(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select column_name as name, data_type as type_name, is_nullable as nullable, '
            .'character_maximum_length as length from information_schema.columns '
            .'where table_name = ? order by ordinal_position',
            [$table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $type = (string) $row->type_name;
            $length = $row->length !== null && (int) $row->length > 0 ? (int) $row->length : null;

            $columns[] = $this->column(
                (string) $row->name,
                $length !== null ? $type.'('.$length.')' : $type,
                $this->nullable($row->nullable),
                $type,
            );
        }

        return $columns;
    }

    /** @return array<int, array<string, mixed>> */
    protected function sqlServerIndexes(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            'select i.name as name, col.name as column_name, i.is_unique as is_unique, '
            .'i.is_primary_key as is_primary from sys.indexes i '
            .'join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id '
            .'join sys.columns col on col.object_id = ic.object_id and col.column_id = ic.column_id '
            .'where i.object_id = object_id(?) order by i.name, ic.key_ordinal',
            [$table],
        );

        return $this->group(
            $rows,
            fn (object $row): bool => (bool) $row->is_unique,
            fn (object $row): bool => (bool) $row->is_primary,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Shared */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    protected function column(string $name, string $type, bool $nullable, ?string $typeName = null): array
    {
        $type = strtolower(trim($type));

        return [
            'name' => $name,
            'type_name' => strtolower(trim($typeName ?? explode('(', $type)[0])),
            'type' => $type,
            'nullable' => $nullable,
        ];
    }

    /**
     * Collapse one row per index column into one entry per index.
     *
     * @param  array<int, object>  $rows
     * @param  (callable(object): bool)|null  $unique
     * @param  (callable(object): bool)|null  $primary
     * @return array<int, array<string, mixed>>
     */
    protected function group(array $rows, ?callable $unique = null, ?callable $primary = null): array
    {
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;

            $indexes[$name] ??= [
                'name' => $name,
                'columns' => [],
                'type' => null,
                'unique' => $unique !== null && $unique($row),
                'primary' => $primary !== null
                    ? $primary($row)
                    : strtolower($name) === 'primary',
            ];

            if ($row->column_name !== null) {
                $indexes[$name]['columns'][] = (string) $row->column_name;
            }
        }

        return array_values($indexes);
    }

    /** Information-schema nullability is the string `YES`/`NO`; SQL Server reports a bit. */
    protected function nullable(mixed $value): bool
    {
        if (is_string($value)) {
            return strtoupper($value) === 'YES';
        }

        return (bool) $value;
    }

    /** Quote a table or index name for a PRAGMA, which takes no bindings. */
    protected function quote(string $name): string
    {
        return "'".str_replace("'", "''", $name)."'";
    }
}
