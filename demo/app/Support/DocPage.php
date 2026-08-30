<?php

namespace App\Support;

use Illuminate\Support\Str;

/** One documentation page, backed by a Markdown file in the package's docs/. */
final class DocPage
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $group,
        public readonly string $path,
    ) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function url(): string
    {
        return route('docs.show', $this->slug);
    }

    public function markdown(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path) : '';
    }

    /** The first paragraph, used as the page description and in search. */
    public function summary(): string
    {
        foreach (preg_split('/\R/', $this->markdown()) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '>')) {
                continue;
            }

            return Str::limit(trim(strip_tags(Str::markdown($line))), 160);
        }

        return '';
    }

    public function matches(string $term): bool
    {
        $term = Str::lower(trim($term));

        if ($term === '') {
            return true;
        }

        return str_contains(Str::lower($this->title.' '.$this->group.' '.$this->slug.' '.$this->markdown()), $term);
    }
}
