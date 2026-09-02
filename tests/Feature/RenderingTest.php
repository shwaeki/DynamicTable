<?php

use Illuminate\Support\Facades\Blade;
use Shwaeki\DynamicTable\DynamicTable;
use Shwaeki\DynamicTable\Support\TablePayload;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Support\TableState;
use Shwaeki\DynamicTable\Support\Theme;
use Shwaeki\DynamicTable\Tests\Fixtures\FullUsersTable;
use Shwaeki\DynamicTable\Tests\Fixtures\User;
use Shwaeki\DynamicTable\Tests\Fixtures\UsersTable;

beforeEach(fn () => seedUsers());

it('registers the @dynamicTable directive', function (): void {
    expect(Blade::getCustomDirectives())->toHaveKeys(['dynamicTable', 'dynamicTableStyles', 'dynamicTableScripts']);
});

it('renders a full table from a single class reference', function (): void {
    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect($html)
        ->toContain('data-dynamic-table')
        ->toContain('data-table="users"')
        ->toContain('User 01')
        ->toContain('data-dynamic-table-boot');
});

it('renders the first page of data server side so there is no boot request', function (): void {
    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect(substr_count($html, 'data-dynamic-table-row='))->toBe(12);
});

it('emits a boot payload the browser can consume', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    preg_match('/<script type="application\/json" data-dynamic-table-boot>(.*?)<\/script>/s', $html, $matches);
    $boot = json_decode(html_entity_decode($matches[1]), true);

    expect($boot['key'])->toBe('full_users')
        ->and($boot['features'])->toHaveKey('saved_views')
        ->and($boot['columns'])->not->toBeEmpty()
        ->and($boot['data']['total'])->toBe(12)
        ->and($boot['endpoints'])->toHaveKeys(['data', 'fields', 'edit', 'action'])
        ->and($boot['classes'])->toHaveKey('table');
});

it('injects styles and scripts once per response', function (): void {
    $renderer = app(TableRenderer::class);

    $first = $renderer->render(UsersTable::class)->toHtml();
    $second = $renderer->render(FullUsersTable::class)->toHtml();

    expect(substr_count($first, 'dynamic-table.css'))->toBe(1)
        ->and(substr_count($first, 'core.js'))->toBe(1)
        ->and(substr_count($second, 'core.js'))->toBe(0);
});

it('only renders UI for enabled features', function (): void {
    $plain = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect($plain)
        ->toContain('data-dynamic-table-search')
        ->not->toContain('data-dynamic-table-open="views"')
        ->not->toContain('data-dynamic-table-open="export"')
        ->not->toContain('data-dynamic-table-select-all');
});

it('renders every enabled control for a fully featured table', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect($html)
        ->toContain('data-dynamic-table-open="views"')
        ->toContain('data-dynamic-table-open="columns"')
        ->toContain('data-dynamic-table-open="export"')
        ->toContain('data-dynamic-table-open="import"')
        ->toContain('data-dynamic-table-select-all')
        ->toContain('data-dynamic-table-column-search')
        ->toContain('data-dynamic-table-resizer');
});

it('switches direction with the application locale', function (): void {
    app()->setLocale('ar');
    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())->toContain('dir="rtl"');

    app()->setLocale('en');
    app()->forgetInstance(TableRenderer::class);
    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())->toContain('dir="ltr"');
});

it('translates the interface', function (): void {
    app()->setLocale('ar');
    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect($html)->toContain('بحث');
});

it('renders light by default, and follows the viewer only when asked to', function (): void {
    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())
        ->toContain('data-dynamic-table-scheme="light"');

    config()->set('dynamic-table.scheme', 'dark');
    app()->forgetInstance(TableRenderer::class);

    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())
        ->toContain('data-dynamic-table-scheme="dark"');

    // Null is the opt-in: no attribute, so the stylesheet's
    // prefers-color-scheme block decides.
    config()->set('dynamic-table.scheme', null);
    app()->forgetInstance(TableRenderer::class);

    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())
        ->not->toContain('data-dynamic-table-scheme');
});

/**
 * Colour belongs to the package's tokens, not to a theme's class map. If a
 * theme starts contributing colour, light and dark stop agreeing — which is
 * exactly the bug this guards against.
 */
it('keeps colour out of the bundled theme class maps', function (string $theme): void {
    $classes = implode(' ', Theme::classes($theme));

    expect($classes)
        ->not->toContain('dark:')
        ->not->toContain('bg-white')
        ->not->toContain('text-gray-')
        ->not->toContain('table-light');
})->with(['custom', 'tailwind', 'bootstrap']);

it('collapses overflowing columns into a child row by default', function (): void {
    $table = app(FullUsersTable::class);
    $responsive = $table->responsive();

    expect($responsive['mode'])->toBe('collapse')
        // The first column anchors the row, so it never collapses.
        ->and($responsive['fixed'])->toBe(['name'])
        ->and($responsive['breakpoint'])->toBe(640);
});

it('gives columns a collapse priority from their position', function (): void {
    $columns = app(FullUsersTable::class)->resolvedColumns();

    expect($columns['name']->priority)->toBe(1)
        ->and($columns['email']->priority)->toBeGreaterThan($columns['name']->priority)
        ->and($columns['status']->priority)->toBeGreaterThan($columns['email']->priority);
});

it('loads the responsive module only when it needs JavaScript', function (): void {
    $collapsing = app(TablePayload::class)->boot(
        app(UsersTable::class),
        TableState::fromArray([], app(UsersTable::class)),
    );

    expect($collapsing['modules'])->toContain('responsive');

    $scrolling = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'scrolling_users';

        protected ?string $responsive = 'scroll';
    };

    $payload = app(TablePayload::class)->boot($scrolling, TableState::fromArray([], $scrolling));

    // Horizontal scrolling is pure CSS.
    expect($payload['modules'])->not->toContain('responsive')
        ->and($payload['responsive']['mode'])->toBe('scroll');
});

it('switches responsive handling off from the config', function (): void {
    config()->set('dynamic-table.responsive.enabled', false);

    expect(app(FullUsersTable::class)->responsive())->toBeNull();
});

it('takes its default mode from the config', function (): void {
    config()->set('dynamic-table.responsive.mode', 'cards');

    expect(app(FullUsersTable::class)->responsive()['mode'])->toBe('cards');
});

it('switches responsive handling off entirely when asked', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'static_users';

        protected array $features = ['-responsive'];
    };

    expect($table->responsive())->toBeNull();
});

it('labels every cell so collapsed and card layouts can name their values', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect($html)->toContain('data-label="Department"')
        ->and($html)->toContain('data-label="Email"');
});

it('shows panels as centred modals by default', function (): void {
    expect(app(UsersTable::class)->panels())
        ->toBe(['mode' => 'modal', 'side' => 'right', 'width' => '30rem']);
});

it('resolves the offcanvas side against the reading direction', function (): void {
    config()->set('dynamic-table.panels.mode', 'offcanvas');

    // "end" is the right in English…
    expect(app(UsersTable::class)->panels())
        ->toBe(['mode' => 'offcanvas', 'side' => 'right', 'width' => '30rem']);

    // …and the left in Arabic, with no extra configuration.
    app()->setLocale('ar');
    app()->forgetInstance(UsersTable::class);

    expect(app(UsersTable::class)->panels()['side'])->toBe('left');

    // An explicit side is taken literally in either direction.
    config()->set('dynamic-table.panels.side', 'right');
    app()->forgetInstance(UsersTable::class);

    expect(app(UsersTable::class)->panels()['side'])->toBe('right');
});

it('lets a table choose its own panel presentation', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected ?string $tableKey = 'drawer_users';

        protected ?string $panels = 'offcanvas';
    };

    expect($table->panels()['mode'])->toBe('offcanvas')
        // The application default is untouched.
        ->and(app(UsersTable::class)->panels()['mode'])->toBe('modal');
});

it('falls back to a modal for an unknown panel mode', function (): void {
    config()->set('dynamic-table.panels.mode', 'carousel');

    expect(app(UsersTable::class)->panels()['mode'])->toBe('modal');
});

it('uses the bootstrap class map when the theme is bootstrap', function (): void {
    config()->set('dynamic-table.theme', 'bootstrap');

    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect($html)->toContain('table table-hover')
        ->and($html)->not->toContain('divide-gray-200');
});

it('honours a width declared on a column, not only one the reader dragged', function (): void {
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected function columns(): array
        {
            return ['name' => ['width' => 25], 'email'];
        }
    };

    $html = app(TableRenderer::class)->render($table)->toHtml();

    // Without fixed layout the width is a suggestion the header's own label overrules.
    expect($html)
        ->toContain('dynamic-table-sized')
        ->toContain('style="width: 25px"')
        ->toContain('dynamic-table-narrow');
});

it('draws group headers on the first paint, not only after a refresh', function (): void {
    // A remembered state, a URL or a saved view can all arrive with a group
    // already chosen, so the server-rendered rows have to carry the same
    // headers renderRows() would draw.
    request()->merge(['group' => 'status']);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect(substr_count($html, 'dynamic-table-group-row'))->toBe(2)
        ->and($html)->toContain('dynamic-table-group-label');
});

it('opens a group even when the first group value is null', function (): void {
    // The sentinel for "no group yet" has to be something no cell can hold, or
    // a first group of null is silently skipped and its rows lose their header.
    User::query()->update(['salary' => null]);
    request()->merge(['group' => 'salary']);

    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect(substr_count($html, 'dynamic-table-group-row'))->toBe(1);
});

it('gives the footer range and the summary cells different DOM hooks', function (): void {
    // They shared data-dt-summary, and an attribute selector matches on the
    // name alone — so querySelector() found the first tfoot cell and the
    // browser wrote the page range over a column's total on every refresh.
    $table = new class extends DynamicTable
    {
        protected string $model = User::class;

        protected function columns(): array
        {
            return ['name', 'salary' => ['summary' => 'sum']];
        }
    };

    $html = app(TableRenderer::class)->render($table)->toHtml();

    expect($html)
        ->toContain('data-dynamic-table-summary="salary"')
        ->toContain('data-dynamic-table-range');

    // Exactly one element answers the footer's selector, and it is the footer.
    preg_match_all('/data-dynamic-table-range(?![-a-z])/', $html, $ranges);

    expect($ranges[0])->toHaveCount(1);

    // ...and nothing before it answers it, which is what made the collision
    // bite: the tfoot is rendered above the footer.
    expect(strpos($html, 'data-dynamic-table-range'))
        ->toBeGreaterThan(strpos($html, 'data-dynamic-table-summary="salary"'));
});
