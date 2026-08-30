<?php

use App\Support\DocPage;
use App\Support\DocRegistry;

function docs(): array
{
    return app(DocRegistry::class)->all()->all();
}

it('redirects the docs index to the first page', function (): void {
    $this->get('/dynamic-table/docs')->assertRedirect();
});

it('renders every documentation page', function (DocPage $page): void {
    $this->get($page->url())
        ->assertOk()
        ->assertSee($page->title)
        ->assertSee('demo-prose', escape: false);
})->with(fn () => collect(docs())->map(fn (DocPage $p) => [$p->slug => $p])->collapse()->all());

it('reads the package’s own markdown rather than a copy', function (): void {
    foreach (docs() as $page) {
        expect($page->exists())->toBeTrue()
            ->and($page->path)->toContain('docs')
            // The demo must not hold its own copy of the documentation.
            ->and(str_starts_with(realpath($page->path), realpath(base_path())))->toBeFalse();
    }
});

it('rewrites links between documents to demo routes', function (): void {
    $registry = app(DocRegistry::class);
    $html = $registry->render($registry->find('columns'));

    expect($html)->toContain('/dynamic-table/docs/')
        // No raw markdown links should survive.
        ->and($html)->not->toContain('.md"');
});

it('gives headings ids so the contents can link to them', function (): void {
    $registry = app(DocRegistry::class);
    $page = $registry->find('columns');

    $html = $registry->render($page);
    $outline = $registry->outline($page);

    expect($outline)->not->toBeEmpty();

    foreach ($outline as $heading) {
        expect($html)->toContain('id="'.$heading['id'].'"');
    }
});

it('offers both sections from every page', function (): void {
    $html = $this->get(app(DocRegistry::class)->first()->url())->getContent();

    expect($html)->toContain('/dynamic-table/examples')
        ->and($html)->toContain('/dynamic-table/docs');
});

it('rejects an unknown documentation page', function (): void {
    $this->get('/dynamic-table/docs/does-not-exist')->assertNotFound();
});
