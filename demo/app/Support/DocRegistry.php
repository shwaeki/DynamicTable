<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The documentation, read from the package's own docs/ directory.
 *
 * The same principle as the examples reading real source files: there is one
 * copy of the documentation, it lives in the repository where it is reviewed in
 * pull requests, and this page renders that. It cannot drift.
 */
class DocRegistry
{
    /**
     * Slug => [title, group]. The order here is the order of the sidebar, which
     * is a reading order rather than an alphabetical one.
     */
    private const PAGES = [
        'quick-start' => ['Quick start', 'Getting started'],
        'installation' => ['Installation', 'Getting started'],
        'tables' => ['The table class', 'Getting started'],
        'configuration' => ['Configuration', 'Getting started'],
        'features' => ['All features', 'Getting started'],

        'columns' => ['Columns', 'Building tables'],
        'filters' => ['Search and filters', 'Building tables'],
        'views' => ['Saved views', 'Building tables'],
        'editing' => ['Editing and actions', 'Building tables'],
        'export-import' => ['Export and import', 'Building tables'],

        'themes' => ['Themes', 'Presentation'],
        'responsive' => ['Responsive', 'Presentation'],
        'localization' => ['Localization and RTL', 'Presentation'],

        'performance' => ['Performance', 'Operating it'],
        'security' => ['Security', 'Operating it'],
        'testing' => ['Testing', 'Operating it'],
        'troubleshooting' => ['Troubleshooting & FAQ', 'Operating it'],

        'architecture' => ['Architecture & decisions', 'Going deeper'],
        'extending' => ['Extending', 'Going deeper'],
    ];

    private ?MarkdownConverter $converter = null;

    /** @return Collection<int, DocPage> */
    public function all(): Collection
    {
        return collect(self::PAGES)
            ->map(fn (array $meta, string $slug): DocPage => new DocPage(
                slug: $slug,
                title: $meta[0],
                group: $meta[1],
                path: $this->pathFor($slug),
            ))
            ->filter(fn (DocPage $page): bool => $page->exists())
            ->values();
    }

    public function find(string $slug): ?DocPage
    {
        return $this->all()->firstWhere('slug', $slug);
    }

    public function first(): DocPage
    {
        return $this->all()->first();
    }

    /** @return Collection<string, Collection<int, DocPage>> */
    public function grouped(): Collection
    {
        return $this->all()->groupBy('group');
    }

    /** The page before and after this one, for the reading-order footer links. */
    public function neighbours(DocPage $page): array
    {
        $pages = $this->all()->values();
        $index = $pages->search(fn (DocPage $candidate): bool => $candidate->slug === $page->slug);

        return [
            'previous' => $index > 0 ? $pages[$index - 1] : null,
            'next' => $pages[$index + 1] ?? null,
        ];
    }

    public function pathFor(string $slug): string
    {
        return base_path('../docs/'.$slug.'.md');
    }

    /**
     * Render a page to HTML.
     *
     * Links between documents are rewritten to demo routes, and headings get
     * ids so the on-page contents can link to them.
     */
    public function render(DocPage $page): string
    {
        $html = (string) $this->converter()->convert($page->markdown());

        // "columns.md" and "performance.md#counting" become demo routes.
        $html = preg_replace_callback(
            '/href="(?!https?:|#)([a-z0-9-]+)\.md(#[^"]*)?"/i',
            static fn (array $m): string => 'href="'.route('docs.show', $m[1]).($m[2] ?? '').'"',
            $html,
        );

        // Anchors for every h2/h3.
        return preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            static function (array $m): string {
                $id = Str::slug(strip_tags($m[2]));

                return '<h'.$m[1].' id="'.$id.'">'
                    .'<a class="demo-anchor" href="#'.$id.'" aria-hidden="true">#</a>'
                    .$m[2].'</h'.$m[1].'>';
            },
            $html,
        );
    }

    /**
     * The h2 headings of a page, for the on-page table of contents.
     *
     * @return list<array{id: string, title: string}>
     */
    public function outline(DocPage $page): array
    {
        preg_match_all('/^## (.+)$/m', $page->markdown(), $matches);

        return collect($matches[1] ?? [])
            ->map(static fn (string $title): array => [
                'id' => Str::slug(strip_tags($title)),
                'title' => trim(strip_tags($title)),
            ])
            ->all();
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new AutolinkExtension);

        return $this->converter = new MarkdownConverter($environment);
    }
}
