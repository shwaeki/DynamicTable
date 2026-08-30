<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache-backed progress for queued exports and imports.
 *
 * Deliberately poll-based: progress must work in an application that has no
 * broadcasting configured, which is most of them.
 */
class TransferProgress
{
    public static function start(string $kind, string $tableKey, int $total = 0): string
    {
        $id = $kind.'_'.Str::lower((string) Str::ulid());

        self::put($id, [
            'id' => $id,
            'kind' => $kind,
            'table' => $tableKey,
            'status' => 'queued',
            'processed' => 0,
            'total' => $total,
            'started_at' => now()->toIso8601String(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    public static function update(string $id, array $attributes): void
    {
        $current = self::get($id);

        if ($current === null) {
            return;
        }

        self::put($id, array_merge($current, $attributes));
    }

    public static function advance(string $id, int $by = 1): void
    {
        $current = self::get($id);

        if ($current === null) {
            return;
        }

        $current['processed'] = ($current['processed'] ?? 0) + $by;
        $current['status'] = 'running';

        self::put($id, $current);
    }

    /** @param array<string, mixed> $attributes */
    public static function finish(string $id, array $attributes = []): void
    {
        self::update($id, array_merge([
            'status' => 'completed',
            'finished_at' => now()->toIso8601String(),
        ], $attributes));
    }

    public static function fail(string $id, string $message): void
    {
        self::update($id, [
            'status' => 'failed',
            'message' => Str::limit($message, 500),
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $value = Cache::store(config('dynamic-table.cache.store'))->get(self::key($id));

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    protected static function put(string $id, array $payload): void
    {
        $payload['percent'] = ($payload['total'] ?? 0) > 0
            ? (int) min(100, round(($payload['processed'] ?? 0) / $payload['total'] * 100))
            : ($payload['status'] === 'completed' ? 100 : 0);

        Cache::store(config('dynamic-table.cache.store'))->put(self::key($id), $payload, now()->addHours(6));
    }

    protected static function key(string $id): string
    {
        return (string) config('dynamic-table.cache.prefix', 'dynamic-table').':transfer:'.$id;
    }
}
