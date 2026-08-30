<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Exceptions\DynamicTableException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Maps a stable table key to its class.
 *
 * The data endpoint resolves tables through this registry only, so a request
 * can never name an arbitrary class. Discovery scans the configured paths once
 * and caches the map.
 */
class TableRegistry
{
    /** @var array<string, class-string<DynamicTable>>|null */
    protected ?array $map = null;

    /** @var array<string, DynamicTable> */
    protected array $instances = [];

    /** @return array<string, class-string<DynamicTable>> */
    public function all(): array
    {
        return $this->map ??= $this->build();
    }

    /** @param class-string<DynamicTable> $class */
    public function register(string $class, ?string $key = null): void
    {
        $map = $this->all();
        $key ??= $this->keyFor($class);

        if (isset($map[$key]) && $map[$key] !== $class) {
            throw DynamicTableException::duplicateKey($key, $map[$key], $class);
        }

        $map[$key] = $class;
        $this->map = $map;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return class-string<DynamicTable> */
    public function classFor(string $key): string
    {
        $class = $this->all()[$key] ?? null;

        // A table added since the discovery cache was written renders fine
        // through the directive but would 404 on its own endpoint. Rescan once
        // before giving up, so adding a class never needs a cache clear.
        if ($class === null) {
            $this->refresh();
            $class = $this->all()[$key] ?? null;
        }

        if ($class === null) {
            throw DynamicTableException::unknownTable($key);
        }

        return $class;
    }

    /** Rebuild the map from disk, bypassing the cache. */
    public function refresh(): void
    {
        Cache::store(config('dynamic-table.cache.store'))->forget($this->cacheKey());

        $this->map = null;
        $this->all();
    }

    /**
     * Resolve a table by key, or by class when called from the Blade directive.
     *
     * @param  class-string<DynamicTable>|string  $keyOrClass
     */
    public function resolve(string $keyOrClass): DynamicTable
    {
        if (isset($this->instances[$keyOrClass])) {
            return $this->instances[$keyOrClass];
        }

        $class = is_subclass_of($keyOrClass, DynamicTable::class)
            ? $keyOrClass
            : $this->classFor($keyOrClass);

        /** @var DynamicTable $table */
        $table = app($class);

        // Register on first use so directive-only tables are reachable from
        // the data endpoint without any configuration.
        $key = $table->key();
        $map = $this->all();

        if (isset($map[$key]) && $map[$key] !== $class) {
            throw DynamicTableException::duplicateKey($key, $map[$key], $class);
        }

        $this->map[$key] = $class;

        return $this->instances[$keyOrClass] = $this->instances[$key] = $table;
    }

    /** @param class-string<DynamicTable> $class */
    public function keyFor(string $class): string
    {
        return app($class)->key();
    }

    public function flush(): void
    {
        $this->map = null;
        $this->instances = [];
        Cache::store(config('dynamic-table.cache.store'))->forget($this->cacheKey());
    }

    /** @return array<string, class-string<DynamicTable>> */
    protected function build(): array
    {
        $map = [];

        foreach ((array) config('dynamic-table.tables.register', []) as $key => $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, DynamicTable::class)) {
                continue;
            }

            $map[is_string($key) ? $key : app($class)->key()] = $class;
        }

        foreach ($this->discover() as $class) {
            // The cached list can name a class that has since been deleted or
            // renamed. That is an ordinary thing to do, and it must not take
            // every *other* table down with it while the cache is stale — the
            // entry is skipped, and the next rebuild forgets it.
            if (! class_exists($class)) {
                continue;
            }

            $key = app($class)->key();

            if (isset($map[$key]) && $map[$key] !== $class) {
                throw DynamicTableException::duplicateKey($key, $map[$key], $class);
            }

            $map[$key] = $class;
        }

        return $map;
    }

    /** @return list<class-string<DynamicTable>> */
    protected function discover(): array
    {
        $paths = array_values(array_filter(
            (array) config('dynamic-table.tables.paths', []),
            static fn (mixed $path): bool => is_string($path) && is_dir($path),
        ));

        if ($paths === []) {
            return [];
        }

        $resolve = fn (): array => $this->scan($paths);

        if (! config('dynamic-table.cache.metadata', true)) {
            return $resolve();
        }

        return Cache::store(config('dynamic-table.cache.store'))->remember(
            $this->cacheKey(),
            (int) config('dynamic-table.cache.ttl', 86400),
            $resolve,
        );
    }

    /**
     * @param  list<string>  $paths
     * @return list<class-string<DynamicTable>>
     */
    protected function scan(array $paths): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in($paths)->name('*.php') as $file) {
            $class = $this->classFromFile($file);

            if ($class !== null && is_subclass_of($class, DynamicTable::class)) {
                $reflection = new \ReflectionClass($class);

                if (! $reflection->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    protected function classFromFile(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            return null;
        }

        if (preg_match('/^\s*namespace\s+([^;{\s]+)/mi', $contents, $namespaceMatch) !== 1) {
            return null;
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)/mi', $contents, $classMatch) !== 1) {
            return null;
        }

        $class = trim($namespaceMatch[1]).'\\'.$classMatch[1];

        return class_exists($class) ? $class : null;
    }

    protected function cacheKey(): string
    {
        $prefix = (string) config('dynamic-table.cache.prefix', 'dynamic-table');

        return $prefix.':registry:'.Str::substr(md5(json_encode(config('dynamic-table.tables')) ?: ''), 0, 12);
    }
}
