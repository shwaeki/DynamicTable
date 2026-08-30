<?php

namespace App\Http\Controllers;

use App\Support\DocPage;
use App\Support\DocRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocController extends Controller
{
    public function __construct(private readonly DocRegistry $docs) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('docs.show', $this->docs->first()->slug);
    }

    public function show(string $page): View
    {
        $current = $this->docs->find($page);

        abort_if($current === null, 404);

        return view('docs.show', [
            'current' => $current,
            'nav' => $this->navigation($current),
            'html' => $this->docs->render($current),
            'outline' => $this->docs->outline($current),
            'neighbours' => $this->docs->neighbours($current),
        ]);
    }

    /** The same sidebar shape the examples use, so the chrome is shared. */
    private function navigation(DocPage $current): array
    {
        return $this->docs->grouped()
            ->map(fn ($pages, string $label): array => [
                'label' => $label,
                'items' => $pages->map(fn (DocPage $page): array => [
                    'url' => $page->url(),
                    'title' => $page->title,
                    // The whole page is searchable, not just its title.
                    'search' => Str::lower($page->title.' '.$page->slug.' '.$page->markdown()),
                    'active' => $page->slug === $current->slug,
                ])->all(),
            ])
            ->values()
            ->all();
    }
}
