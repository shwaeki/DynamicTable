<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A row's version, for detecting an edit written against a stale copy.
 *
 * Two people open the same list, both change the same cell, and the second save
 * silently overwrites the first — nobody is told, and the first person's change
 * is simply gone. That is the problem this solves, and the whole of it: a short
 * token travels with the row, comes back with the edit, and an edit whose token
 * no longer matches is refused rather than applied.
 *
 * The token is derived from `updated_at`, because Eloquent already maintains it
 * on every save. A model without timestamps has no version and is edited the
 * way it always was — the alternative would be a version column this package
 * asks every application to add, which is a migration to buy a guarantee most
 * tables do not need.
 *
 * It is hashed rather than sent as the timestamp itself for one reason: the
 * token is a fact about the row's state, not a fact about the record, and
 * shipping a modification time to a browser that was not shown the column would
 * be leaking a field the table deliberately did not select.
 */
final class Version
{
    /** The version of a record, or null when the model does not keep one. */
    public static function of(Model $record): ?string
    {
        if (! $record->usesTimestamps()) {
            return null;
        }

        $column = $record->getUpdatedAtColumn();

        if ($column === null || ! array_key_exists($column, $record->getAttributes())) {
            return null;
        }

        $value = $record->getAttributes()[$column];

        // A row that has never been written has no version to compare, which is
        // not the same as "any version will do" — but it is also not something
        // an edit can get wrong, because the first save gives it one.
        if ($value === null) {
            return null;
        }

        return substr(sha1((string) $value), 0, 12);
    }

    /**
     * Was this edit written against the row as it is now?
     *
     * An absent claim passes. Clients that never send one — an application
     * calling the endpoint itself, or a page rendered before this existed —
     * keep working exactly as they did; the check is a guarantee for those who
     * opt into it by sending the token, not a new requirement for everyone.
     */
    public static function matches(Model $record, mixed $claim): bool
    {
        if (! is_string($claim) || $claim === '') {
            return true;
        }

        $current = self::of($record);

        return $current === null || hash_equals($current, $claim);
    }
}
