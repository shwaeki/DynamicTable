<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shwaeki\DynamicTable\Support\SchemaIntrospector;

/**
 * The Laravel 10 fallback has to describe a table the same way Laravel 11's
 * native `Schema::getColumns()` does, so the metadata engine cannot tell which
 * framework version it is running on.
 */
class LegacySchemaIntrospector extends SchemaIntrospector
{
    /** @return array<int, array<string, mixed>> */
    public function legacyColumns(string $table): array
    {
        return $this->sqliteColumns(DB::connection(), $table);
    }

    /** @return array<int, array<string, mixed>> */
    public function legacyIndexes(string $table): array
    {
        return $this->sqliteIndexes(DB::connection(), $table);
    }
}

it('describes columns without the native schema methods', function (): void {
    $columns = collect((new LegacySchemaIntrospector)->legacyColumns('users'))
        ->keyBy('name');

    expect($columns)->toHaveKey('id')
        ->and($columns['id']['type_name'])->toBe('integer')
        ->and($columns['id']['nullable'])->toBeFalse()
        ->and($columns['name']['type_name'])->toBe('varchar')
        ->and($columns['name']['type'])->toBe('varchar')
        ->and($columns['name']['nullable'])->toBeFalse();
});

it('matches the native column list on this Laravel version', function (): void {
    if (! method_exists(Schema::connection(null), 'getColumns')) {
        $this->markTestSkipped('Laravel 10 has no native getColumns() to compare against.');
    }

    $native = collect(Schema::getColumns('users'))->pluck('type_name', 'name')->all();
    $legacy = collect((new LegacySchemaIntrospector)->legacyColumns('users'))->pluck('type_name', 'name')->all();

    expect($legacy)->toBe($native);
});

it('reports indexed columns without the native schema methods', function (): void {
    $columns = collect((new LegacySchemaIntrospector)->legacyIndexes('users'))
        ->flatMap(fn (array $index): array => $index['columns'])
        ->unique()
        ->values();

    expect($columns)->toContain('id')->toContain('email');
});
