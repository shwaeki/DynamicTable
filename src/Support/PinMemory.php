<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\Facades\Session;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Rows this viewer wants kept at the top.
 *
 * The session is the store, for the same reason [[StateMemory]] uses it: it
 * needs no table and no migration, it is private to one person by
 * construction, and it lasts exactly as long as the sitting does. A pin is a
 * working note — "these three while I deal with them" — not a document. An
 * application that wants pins to outlive a session has saved views for the
 * durable, nameable, shareable version of the same idea.
 *
 * There is a cap, because an unbounded list in the session becomes an
 * unbounded IN clause in the query.
 */
class PinMemory
{
    /** Beyond this the list stops being a shortlist and starts being a filter. */
    public const LIMIT = 50;

    /**
     * The ids this viewer has pinned on this table, oldest first.
     *
     * @return list<string>
     */
    public function ids(DynamicTable $table): array
    {
        if (! $this->enabled($table) || ! $this->hasSession()) {
            return [];
        }

        $stored = Session::get($this->key($table));

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * Pin a row, or unpin it if it is already pinned.
     *
     * Returns the list as it now stands, so the caller never has to ask again.
     *
     * @return list<string>
     */
    public function toggle(DynamicTable $table, string $id): array
    {
        $ids = $this->ids($table);
        $index = array_search($id, $ids, true);

        if ($index !== false) {
            unset($ids[$index]);
        } else {
            $ids[] = $id;
        }

        // Oldest out first: a cap that refused new pins would leave the reader
        // pressing a button that does nothing.
        $ids = array_slice(array_values($ids), -self::LIMIT);

        if ($this->hasSession()) {
            Session::put($this->key($table), $ids);
        }

        return $ids;
    }

    public function clear(DynamicTable $table): void
    {
        if ($this->hasSession()) {
            Session::forget($this->key($table));
        }
    }

    public function enabled(DynamicTable $table): bool
    {
        return $table->hasFeature(Feature::PINNED_ROWS);
    }

    protected function key(DynamicTable $table): string
    {
        return 'dynamic-table.pins.'.$table->key();
    }

    /** No session — an API route, a console command, a queued job — means no pins. */
    protected function hasSession(): bool
    {
        return app()->bound('session') && app('session')->isStarted();
    }
}
