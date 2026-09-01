<?php

namespace Shwaeki\DynamicTable\Support;

use Shwaeki\DynamicTable\Exceptions\DynamicTableException;

/**
 * Resolves a table's declared feature list into an immutable, validated set.
 *
 * Declaration rules:
 *   ['saved_views', 'export']  => defaults + saved views + export
 *   ['-search']                => defaults minus search
 *   ['only', 'sorting']        => nothing but the listed features
 *
 * A name that is not a feature is refused, so a typo cannot quietly leave
 * something switched off.
 */
final class FeatureSet
{
    /** @var array<string, true> */
    private array $enabled;

    /**
     * @param  list<string>  $declared
     * @param  string  $owner  the table class, named in the error when a
     *                         declared feature does not exist
     */
    public function __construct(array $declared = [], string $owner = 'a table')
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

        /*
         * A name that is not a feature is a mistake, and a silent one: it would
         * simply be dropped below, leaving the author sure they had switched
         * something on. Cheaper to say so than to debug a missing panel.
         */
        $unknown = array_values(array_diff(
            array_unique(array_merge($add, $remove)),
            Feature::ALL,
        ));

        if ($unknown !== []) {
            throw DynamicTableException::unknownFeatures($unknown, Feature::ALL, $owner);
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
