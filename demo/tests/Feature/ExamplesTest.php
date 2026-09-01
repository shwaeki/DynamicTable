<?php

use App\Support\Example;
use App\Support\ExampleRegistry;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\AssetManager;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
});

function examples(): array
{
    return app(ExampleRegistry::class)->all()->all();
}

it('redirects the index to the first example', function (): void {
    $this->get('/dynamic-table/examples')->assertRedirect();
    $this->get('/')->assertRedirect('/dynamic-table/examples');
});

it('renders every example page without an exception', function (Example $example): void {
    $response = $this->get($example->url());

    $response->assertOk()
        ->assertSee($example->title)
        ->assertSee('data-dynamic-table', escape: false)
        ->assertSee('data-dynamic-table-boot', escape: false);
})->with(fn () => collect(examples())->map(fn (Example $e) => [$e->id => $e])->collapse()->all());

it('renders real rows in every example', function (Example $example): void {
    $html = $this->get($example->url())->getContent();

    if ($example->seedCommand !== null) {
        // Large datasets are opt-in; the page must instead explain how to seed.
        expect($html)->toContain($example->seedCommand);

        return;
    }

    expect(substr_count($html, 'data-dynamic-table-row='))->toBeGreaterThan(0);
})->with(fn () => collect(examples())->map(fn (Example $e) => [$e->id => $e])->collapse()->all());

it('points every example at a real table class', function (): void {
    foreach (examples() as $example) {
        expect(class_exists($example->table))->toBeTrue()
            ->and(is_subclass_of($example->table, DynamicTable::class))->toBeTrue();
    }
});

it('shows the real source file for every example', function (): void {
    foreach (examples() as $example) {
        $files = $example->files();

        expect($files)->not->toBeEmpty();

        foreach ($files as $path) {
            expect(is_file($path))->toBeTrue();
        }
    }
});

it('gives every example a unique stable id', function (): void {
    $ids = collect(examples())->pluck('id');

    expect($ids->duplicates())->toBeEmpty()
        ->and($ids->every(fn (string $id): bool => (bool) preg_match('/^[a-z0-9-]+$/', $id)))->toBeTrue();
});

it('serves the package assets the examples reference', function (): void {
    foreach (['core.js', 'dom.js', 'ui.js', 'filters.js', 'columns.js', 'views.js', 'actions.js', 'inline-edit.js', 'transfer.js', 'responsive.js', 'header-menu.js', 'detail.js', 'sticky.js', 'dynamic-table.css'] as $file) {
        $this->get(app(AssetManager::class)->url($file))->assertOk();
    }
});

it('keeps relationship examples free of N+1 queries', function (): void {
    $url = app(ExampleRegistry::class)->find('relationships')->url();

    // Warm the metadata cache and the session first; we are measuring the
    // steady state, not the first request of a cold process.
    $this->get($url)->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get($url)->assertOk();

    $selects = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select'));

    DB::disableQueryLog();

    // The N+1 guarantee: each related table is queried exactly once for the
    // whole page, no matter how many rows it contains.
    foreach (['"customers"', '"invoices"'] as $table) {
        expect($selects->filter(fn (string $sql): bool => str_contains($sql, 'from '.$table))->count())
            ->toBe(1);
    }
});

it('switches locale and direction', function (): void {
    $this->get(route('examples.locale', 'ar'))->assertRedirect();

    $html = $this->get(app(ExampleRegistry::class)->find('basic')->url())->getContent();

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain('بحث');
});

it('translates the whole demo, not only the table', function (): void {
    $this->get(route('examples.locale', 'ar'));

    $html = $this->get(app(ExampleRegistry::class)->find('basic')->url())->getContent();

    expect($html)
        ->toContain('أقصى وظائف بأقل إعداد')      // the header tagline
        ->toContain('البداية')                     // the sidebar category
        ->toContain('جدول أساسي')                  // the example title
        ->toContain('خاصية واحدة فقط')             // the example description
        ->toContain('الكود المصدري للمثال');       // the source section
});

it('falls back to English for a language with no translation file', function (): void {
    $this->get(route('examples.locale', 'en'));

    $html = $this->get(app(ExampleRegistry::class)->find('basic')->url())->getContent();

    expect($html)->toContain('Basic table')
        ->and($html)->toContain('Getting started');
});

it('localises dates inside the table', function (): void {
    $this->get(route('examples.locale', 'ru'));

    $html = $this->get(app(ExampleRegistry::class)->find('date-filters')->url())->getContent();

    // Carbon follows the application locale, so month names are translated.
    expect($html)->toMatch('/(янв|фев|мар|апр|мая|июн|июл|авг|сен|окт|ноя|дек)/iu');
});

it('only enables the features an example declares', function (): void {
    $basic = $this->get(app(ExampleRegistry::class)->find('basic')->url())->getContent();

    expect($basic)
        ->toContain('data-dynamic-table-search')
        ->not->toContain('data-dynamic-table-open="export"')
        ->not->toContain('data-dynamic-table-select-all');

    $everything = $this->get(app(ExampleRegistry::class)->find('everything')->url())->getContent();

    expect($everything)
        ->toContain('data-dynamic-table-open="export"')
        ->toContain('data-dynamic-table-open="views"')
        ->toContain('data-dynamic-table-select-all');
});

it('rejects an unknown example id', function (): void {
    $this->get('/dynamic-table/examples/does-not-exist')->assertNotFound();
});
