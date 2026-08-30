<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\Facades\Session;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * "Leave it as I left it."
 *
 * Most people do not want to name and save a view. They want the table to look
 * the way it looked when they walked away from it — the same columns, the same
 * sort, the same page size, the same filters. This remembers exactly that, per
 * user and per table, with no UI at all.
 *
 * It is *not* saved views, and the difference is deliberate:
 *
 *   - A saved view is a document: named, shareable, versioned, and it survives
 *     because someone decided it should.
 *   - Remembered state is a habit: unnamed, private, and it is only ever the
 *     last thing you did.
 *
 * The session is the store, so it needs no table and no migration, and it
 * disappears when the session does — which is the right lifetime for a habit.
 * A visitor with no session keeps the table's defaults.
 *
 * Only presentation is remembered. The page number is not: coming back to a
 * table on page 47 is disorienting, and the reason you were there is usually
 * gone. The selection is not either — acting on a selection you made yesterday
 * is the kind of thing that makes people distrust software.
 */
class StateMemory
{
    /** The parts of the state worth carrying between visits. */
    protected const REMEMBERED = ['search', 'columnSearch', 'filters', 'sort', 'perPage', 'columns', 'widths', 'group', 'trashed', 'view'];

    public function enabled(DynamicTable $table): bool
    {
        return $table->hasFeature(Feature::REMEMBER_STATE);
    }

    /**
     * What this viewer last had on screen, if anything.
     *
     * @return array<string, mixed>
     */
    public function recall(DynamicTable $table): array
    {
        if (! $this->enabled($table) || ! $this->hasSession()) {
            return [];
        }

        $stored = Session::get($this->key($table));

        return is_array($stored) ? $stored : [];
    }

    /**
     * Remember the state this request rendered.
     *
     * Stored from the state *object*, not from the request, so what comes back
     * has already been through every allowlist — a remembered state can never
     * reintroduce a column or a filter the table would reject today.
     */
    public function remember(DynamicTable $table, TableState $state): void
    {
        if (! $this->enabled($table) || ! $this->hasSession()) {
            return;
        }

        $remembered = array_intersect_key($state->toArray(), array_flip(self::REMEMBERED));

        // Nothing worth remembering is nothing worth storing: an untouched
        // table should not fill the session with its own defaults.
        Session::put($this->key($table), array_filter(
            $remembered,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        ));
    }

    public function forget(DynamicTable $table): void
    {
        if ($this->hasSession()) {
            Session::forget($this->key($table));
        }
    }

    protected function key(DynamicTable $table): string
    {
        return 'dynamic-table.state.'.$table->key();
    }

    /**
     * Sessions are not guaranteed: an API route, a console command or a
     * queued job has none, and asking for one there would be an error rather
     * than a missing preference.
     */
    protected function hasSession(): bool
    {
        return app()->bound('session') && app('session')->isStarted();
    }
}
