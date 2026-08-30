<?php

namespace App\DynamicTables;

use App\Models\User;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * Images, links and emails are detected from the column name and cast:
 * avatar_url renders a thumbnail, email a mailto link, a *_url column an
 * external link. No configuration required — but each is overridable with
 * 'type' if the guess is wrong.
 */
class MediaTable extends DynamicTable
{
    protected string $model = User::class;

    protected int $relationDepth = 2;

    protected function columns(): array
    {
        return [
            'avatar_url' => ['label' => '', 'width' => 60, 'sortable' => false],
            'name',
            'email',
            'phone',
            'department.company.website' => 'Company site',
        ];
    }
}
