<?php

namespace Shwaeki\DynamicTable\Support;

use Illuminate\Support\HtmlString;

/**
 * Serves the package's CSS/JS straight from the package directory through a
 * versioned route, so installing the package needs no publish or build step.
 *
 * Only the core module is ever loaded up front. Feature modules are imported
 * lazily by the core the first time a feature is actually used.
 */
class AssetManager
{
    protected bool $stylesRendered = false;

    protected bool $scriptsRendered = false;

    /** @var array<string, string> */
    protected const FILES = [
        'core.js' => 'application/javascript; charset=utf-8',
        'dom.js' => 'application/javascript; charset=utf-8',
        'ui.js' => 'application/javascript; charset=utf-8',
        'filters.js' => 'application/javascript; charset=utf-8',
        'views.js' => 'application/javascript; charset=utf-8',
        'columns.js' => 'application/javascript; charset=utf-8',
        'inline-edit.js' => 'application/javascript; charset=utf-8',
        'actions.js' => 'application/javascript; charset=utf-8',
        'transfer.js' => 'application/javascript; charset=utf-8',
        'responsive.js' => 'application/javascript; charset=utf-8',
        'header-menu.js' => 'application/javascript; charset=utf-8',
        'detail.js' => 'application/javascript; charset=utf-8',
        'sticky.js' => 'application/javascript; charset=utf-8',
        'dynamic-table.css' => 'text/css; charset=utf-8',
    ];

    public function isKnown(string $file): bool
    {
        return isset(self::FILES[$file]);
    }

    public function mimeFor(string $file): string
    {
        return self::FILES[$file] ?? 'text/plain';
    }

    public function pathFor(string $file): ?string
    {
        if (! $this->isKnown($file)) {
            return null;
        }

        $path = str_ends_with($file, '.css')
            ? __DIR__.'/../../resources/css/'.$file
            : __DIR__.'/../../resources/js/'.$file;

        return is_file($path) ? $path : null;
    }

    public function version(): string
    {
        $configured = config('dynamic-table.assets.version');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        static $version = null;

        if ($version === null) {
            // Every asset contributes, so changing any one of them produces a
            // new directory and no browser can pair a fresh core with a stale
            // module.
            $stamps = '';

            foreach (array_keys(self::FILES) as $name) {
                $path = $this->pathFor($name);
                $stamps .= $name.':'.($path !== null ? filemtime($path) : '0').'|';
            }

            $version = substr(md5($stamps), 0, 8);
        }

        return $version;
    }

    public function url(string $file): string
    {
        return route('dynamic-table.asset', [
            'version' => $this->version(),
            'file' => $file,
        ]);
    }

    public function styles(): HtmlString
    {
        if ($this->stylesRendered) {
            return new HtmlString('');
        }

        $this->stylesRendered = true;

        return new HtmlString(
            '<link rel="stylesheet" href="'.e($this->url('dynamic-table.css')).'">'
        );
    }

    public function scripts(): HtmlString
    {
        if ($this->scriptsRendered) {
            return new HtmlString('');
        }

        $this->scriptsRendered = true;

        return new HtmlString(
            '<script type="module" src="'.e($this->url('core.js')).'"></script>'
        );
    }

    /** Everything needed for a table to work, emitted once per response. */
    public function head(): HtmlString
    {
        if (! config('dynamic-table.assets.inject', true)) {
            return new HtmlString('');
        }

        return new HtmlString(
            $this->styles()->toHtml().$this->scripts()->toHtml()
        );
    }

    public function reset(): void
    {
        $this->stylesRendered = false;
        $this->scriptsRendered = false;
    }
}
