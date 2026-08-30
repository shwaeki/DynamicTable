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
        ->toContain('data-dt-boot');
});

it('renders the first page of data server side so there is no boot request', function (): void {
    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect(substr_count($html, 'data-dt-row='))->toBe(12);
});

it('emits a boot payload the browser can consume', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    preg_match('/<script type="application\/json" data-dt-boot>(.*?)<\/script>/s', $html, $matches);
    $boot = json_decode(html_entity_decode($matches[1]), true);

    expect($boot['key'])->toBe('full_users')
        ->and($boot['features'])->toHaveKey('views')
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
        ->toContain('data-dt-search')
        ->not->toContain('data-dt-open="views"')
        ->not->toContain('data-dt-open="export"')
        ->not->toContain('data-dt-select-all');
});

it('renders every enabled control for a fully featured table', function (): void {
    $html = app(TableRenderer::class)->render(FullUsersTable::class)->toHtml();

    expect($html)
        ->toContain('data-dt-open="views"')
        ->toContain('data-dt-open="columns"')
        ->toContain('data-dt-open="export"')
        ->toContain('data-dt-open="import"')
        ->toContain('data-dt-select-all')
        ->toContain('data-dt-column-search')
        ->toContain('data-dt-resizer');
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

it('follows the viewer’s colour scheme unless one is forced', function (): void {
    $html = app(TableRenderer::class)->render(UsersTable::class)->toHtml();

    expect($html)->not->toContain('data-dt-scheme');

    config()->set('dynamic-table.scheme', 'dark');
    app()->forgetInstance(TableRenderer::class);

    expect(app(TableRenderer::class)->render(UsersTable::class)->toHtml())
        ->toContain('data-dt-scheme="dark"');
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
})->with(['tailwind', 'bootstrap']);

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
