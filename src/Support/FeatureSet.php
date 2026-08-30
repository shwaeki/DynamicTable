<?php

namespace Shwaeki\DynamicTable\Support;

/**
 * Resolves a table's declared feature list into an immutable, validated set.
 *
 * Declaration rules:
 *   ['views', 'export']   => defaults + views + export
 *   ['-search']           => defaults minus search
 *   ['only:basic']        => nothing but the listed features
 */
final class FeatureSet
{
    /** @var array<string, true> */
    private array $enabled;

    /** @param list<string> $declared */
    public function __construct(array $declared = [])
    {
        $only = false;
        $add = [];
        $remove = [];

        foreach ($declared as $feature) {
            if (! is_string($feature)) {
                continue;
            }

            $feature = trim($feature);

            if ($feature === 'only' || $feature === 'minimal') {
                $only = true;

                continue;
            }

            if (str_starts_with($feature, '-') || str_starts_with($feature, '!')) {
                $remove[] = Feature::normalize(substr($feature, 1));

                continue;
            }

            $add[] = Feature::normalize($feature);
        }

        $base = $only ? [] : Feature::DEFAULTS;
        $set = array_fill_keys(array_merge($base, $add), true);

        // Expand implications until stable.
        do {
            $before = count($set);

            foreach (array_keys($set) as $feature) {
                foreach (Feature::IMPLIES[$feature] ?? [] as $implied) {
                    $set[$implied] = true;
                }
            }
        } while (count($set) !== $before);

        foreach ($remove as $feature) {
            unset($set[$feature]);
        }

        $set = array_intersect_key($set, array_fill_keys(Feature::ALL, true));

        ksort($set);

        $this->enabled = $set;
    }

    public function has(string $feature): bool
    {
        return isset($this->enabled[Feature::normalize($feature)]);
    }

    public function any(string ...$features): bool
    {
        foreach ($features as $feature) {
            if ($this->has($feature)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->enabled);
    }

    /**
     * JavaScript modules required by the enabled features, deduplicated.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $modules = [];

        foreach (array_keys($this->enabled) as $feature) {
            foreach (Feature::MODULES[$feature] ?? [] as $module) {
                $modules[$module] = true;
            }
        }

        return array_keys($modules);
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return array_map(static fn (): bool => true, $this->enabled);
    }
}
