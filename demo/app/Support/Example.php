<?php

namespace App\Support;

use Illuminate\Support\Str;
use ReflectionClass;
use Shwaeki\DynamicTable\DynamicTable;

/**
 * One entry in the examples index.
 *
 * The source shown on the page is read from the real file through reflection,
 * so the documentation cannot drift from the implementation.
 */
final class Example
{
    /**
     * @param  list<string>  $notes
     * @param  list<string>  $extraFiles  paths relative to the demo app root
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $title,
        public readonly string $description,
        public readonly string $table,
        public readonly array $notes = [],
        public readonly array $extraFiles = [],
        public readonly array $keywords = [],
        /** A Blade partial rendered above the table, for an example whose point is markup. */
        public readonly ?string $partial = null,
        /** Seed command this example needs before it has any rows, if any. */
        public readonly ?string $seedCommand = null,
    ) {}

    /** True when the example's table is still waiting to be seeded. */
    public function needsSeeding(): bool
    {
        if ($this->seedCommand === null) {
            return false;
        }

        return ! $this->instance()->newModel()->newQuery()->exists();
    }

    public function url(): string
    {
        return route('examples.show', $this->id);
    }

    /*
     * Titles, descriptions and category names are translated; the constructor
     * values are the English source and the fallback. Switch the demo to ar,
     * he or ru in the header and the whole index follows.
     */

    public function title(): string
    {
        return $this->translate("examples.items.{$this->id}.title", $this->title);
    }

    public function description(): string
    {
        return $this->translate("examples.items.{$this->id}.description", $this->description);
    }

    public function categoryLabel(): string
    {
        return $this->translate('examples.categories.'.Str::slug($this->category, '_'), $this->category);
    }

    /** @return list<string> */
    public function notes(): array
    {
        $key = "examples.items.{$this->id}.notes";

        if (trans()->has($key)) {
            $translated = __($key);

            if (is_array($translated) && $translated !== []) {
                return array_values($translated);
            }
        }

        return $this->notes;
    }

    /**
     * Whether notes() returned a translation. The page marks untranslated
     * notes as English so they still read correctly inside an RTL layout.
     */
    public function notesAreTranslated(): bool
    {
        return trans()->has("examples.items.{$this->id}.notes");
    }

    private function translate(string $key, string $fallback): string
    {
        return trans()->has($key) ? (string) __($key) : $fallback;
    }

    public function instance(): DynamicTable
    {
        return app($this->table);
    }

    /** The model this example renders, for the "Model" source tab. */
    public function modelClass(): string
    {
        return $this->instance()->modelClass();
    }

    /**
     * Every file worth showing, as tab label => absolute path.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = [];

        $tablePath = (new ReflectionClass($this->table))->getFileName();

        if ($tablePath !== false) {
            $files[class_basename($this->table).'.php'] = $tablePath;
        }

        $modelPath = (new ReflectionClass($this->modelClass()))->getFileName();

        if ($modelPath !== false) {
            $files[class_basename($this->modelClass()).'.php'] = $modelPath;
        }

        foreach ($this->extraFiles as $relative) {
            $path = base_path($relative);

            if (is_file($path)) {
                $files[basename($relative)] = $path;
            }
        }

        return $files;
    }

    /** The Blade snippet that renders this example — always the same shape. */
    public function bladeSnippet(): string
    {
        return '@dynamicTable('.class_basename($this->table).'::class)';
    }

    public function matches(string $term): bool
    {
        $term = Str::lower(trim($term));

        if ($term === '') {
            return true;
        }

        // Search both the English source and the active translation, so a
        // developer can find "filters" even while the demo is in Arabic.
        $haystack = Str::lower(implode(' ', [
            $this->id,
            $this->title,
            $this->title(),
            $this->category,
            $this->categoryLabel(),
            $this->description,
            $this->description(),
            implode(' ', $this->keywords),
        ]));

        return str_contains($haystack, $term);
    }
}
