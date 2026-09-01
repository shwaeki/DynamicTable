<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * A second importable table whose import permission can be withdrawn.
 *
 * The endpoint tests need to separate three refusals that all end in 403: the
 * feature being off, the ability being denied, and the download token being
 * signed for another table. A table that simply lacks the feature is turned
 * away before the ability is ever consulted, so it cannot demonstrate the
 * second — hence the flag, which is flipped between producing a report and
 * fetching it back.
 */
class NoImportUsersTable extends DynamicTable
{
    protected string $model = User::class;

    protected ?string $tableKey = 'no_import_users';

    protected array $features = ['import', 'export'];

    public function authorize(string $ability, ?Model $record = null): ?bool
    {
        return $ability === 'import'
            ? (bool) config('dynamic-table.testing.import', true)
            : true;
    }
}
