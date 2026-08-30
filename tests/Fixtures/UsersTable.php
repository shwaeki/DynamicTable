<?php

namespace Shwaeki\DynamicTable\Tests\Fixtures;

use Shwaeki\DynamicTable\DynamicTable;

/** The zero-configuration case the package is designed around. */
class UsersTable extends DynamicTable
{
    protected string $model = User::class;
}
