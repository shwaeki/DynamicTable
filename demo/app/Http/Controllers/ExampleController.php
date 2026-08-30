<?php

namespace App\Http\Controllers;

use App\Support\Example;
use App\Support\ExampleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExampleController extends Controller
{
    public function __construct(private readonly ExampleRegistry $registry) {}

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('examples.show', $this->registry->first()->id);
    }

    public function show(Request $request, string $example): View
    {
        $current = $this->registry->find($example);

        abort_if($current === null, 404);

        return view('examples.show', [
            'current' => $current,
            'nav' => $this->navigation($current),
            'search' => (string) $request->query('q', ''),
        ]);
    }

    /**
     * The sidebar, in the shape the shared layout renders.
     *
     * Both the examples and the documentation feed the same structure, so the
     * chrome — search, grouping, the mobile drawer — is written once.
     */
    private function navigation(Example $current): array
    {
        return $this->registry->grouped()
            ->map(fn ($examples, string $label): array => [
                'label' => $label,
                'items' => $examples->map(fn ($example): array => [
                    'url' => $example->url(),
                    'title' => $example->title(),
                    // Both the translation and the English source, so a
                    // developer can search "filters" while the demo is in Arabic.
                    'search' => Str::lower(implode(' ', [
                        $example->title(), $example->title, $example->description(), ...$example->keywords,
                    ])),
                    'active' => $example->id === $current->id,
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /** Switch the demo locale, which also switches direction for ar/he. */
    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['en', 'ar', 'he', 'ru'], true), 404);

        $request->session()->put('demo_locale', $locale);

        return back();
    }
}
