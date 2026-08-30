<?php

use Shwaeki\DynamicTable\Support\Icon;
use Shwaeki\DynamicTable\Support\TableRenderer;
use Shwaeki\DynamicTable\Tests\Fixtures\MarkupUsersTable;

beforeEach(fn () => seedUsers());

it('renders an icon-font row action as markup, and a glyph as text', function (): void {
    $html = app(TableRenderer::class)->render(MarkupUsersTable::class)->toHtml();

    expect($html)
        ->toContain('<i class="far fa-edit"></i>')
        ->not->toContain('&lt;i class=&quot;far fa-edit&quot;&gt;')
        ->and($html)->toContain('🗄');
});

it('escapes an icon that is not markup', function (): void {
    expect(Icon::html('a < b')->toHtml())->toBe('a &lt; b')
        ->and(Icon::html('<i class="fa"></i>')->toHtml())->toBe('<i class="fa"></i>')
        ->and(Icon::isMarkup('🗄'))->toBeFalse();
});

it('keeps a column the model has no attribute for, and gives its closure the record', function (): void {
    $table = app(MarkupUsersTable::class);
    $column = $table->column('avatar');

    expect($column)->not->toBeNull()
        ->and($column->isComputed())->toBeTrue()
        ->and($column->sortable)->toBeFalse()
        ->and($column->searchable)->toBeFalse();

    $html = app(TableRenderer::class)->render(MarkupUsersTable::class)->toHtml();

    expect($html)->toMatch('/<img src="\/avatars\/\d+\.png" alt="avatar">/');
});

it('renders an untyped closure that returns an Htmlable as markup', function (): void {
    $html = app(TableRenderer::class)->render(MarkupUsersTable::class)->toHtml();

    expect($html)
        ->toContain('<b>user1@example.com</b>')
        // A closure returning a plain string is still escaped.
        ->toContain('&lt;not markup&gt;');
});
