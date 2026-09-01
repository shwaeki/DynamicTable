# Themes

A theme is **one array of CSS classes**. There is a single Blade template and a
single JavaScript renderer, both reading the same class map, so a complete
visual theme does not require touching a folder of partials.

```php
// config/dynamic-table.php
'theme' => 'tailwind',   // 'tailwind' | 'bootstrap' | 'minimal' | 'bordered' | your own
```

Per table:

```php
protected ?string $theme = 'bootstrap';
```

Bootstrap users are never served Tailwind classes, and vice versa. The package
never assumes your application has either.

## The four themes that ship

| Theme | Needs | Looks like |
|---|---|---|
| `tailwind` | Tailwind on the page | The default: airy, rounded, focus rings. |
| `bootstrap` | Bootstrap 5 on the page | Bootstrap's own components — `btn`, `form-control`, `card`, `table`. |
| `minimal` | **nothing** | Quiet and airy, no outer border, rules only between rows. |
| `bordered` | **nothing** | The same base, ruled like a spreadsheet: dense rows, a border on every cell. |

`minimal` and `bordered` are the ready-to-use answer to "I do not have a CSS
framework, and I do not want to write a theme". Every class they name is styled
by the package's own stylesheet, on the same tokens as everything else — so
they are readable in light and dark, they obey `data-dynamic-table-scheme`,
and they need
no build step:

```php
protected ?string $theme = 'minimal';   // or 'bordered'
```

`bordered` is `minimal` plus one class, which is a fair description of what a
theme is at all.

## Writing a theme

Publish the config once, and every theme you write lives under its `themes`
key:

```bash
php artisan vendor:publish --tag=dynamic-table-config
```

```php
// config/dynamic-table.php
'themes' => [
    'brand' => [
        'wrapper' => 'rounded-2xl border shadow',
        'toolbar' => 'dynamic-table-toolbar flex items-center gap-2 p-4 border-b',
        'button' => 'btn btn-sm',
        'buttonPrimary' => 'btn btn-sm btn-primary',
        'cell' => 'dynamic-table-cell px-3 py-2',
        // …anything you leave out keeps the structural default
    ],
],
```

```php
protected ?string $theme = 'brand';
```

That is the whole workflow: one file — the same one that holds `'theme'`, so
the name you select and the theme you wrote sit a few lines apart — no service
provider, no Blade files, no build step. Every slot is optional, so a theme can
be three lines.

Two rules, and only two:

1. **Keep the structural `dynamic-table-*` classes.** They carry behaviour — sticky
   header, resize handles, dialog layout, RTL mirroring — not looks.
2. **Do not put colour in the map.** Surfaces, text and borders come from the
   CSS tokens, which is what keeps every theme legible in light and dark and
   obedient to `data-dynamic-table-scheme`. Set the tokens in your own stylesheet:

   ```css
   .dynamic-table-brand { --dynamic-table-accent: #7c3aed; --dynamic-table-radius: 14px; }
   ```

### Building on a theme that already works

An admin template usually needs one or two slots changed, not thirty. Name the
theme you are starting from:

```php
// config/dynamic-table.php
'themes' => [
    'metronic' => [
        'extends' => 'bootstrap',
        'badge' => 'badge badge-light-{tone}',
        'button' => 'btn btn-sm btn-light',
    ],
],
```

Everything else stays Bootstrap's, including whatever the package changes about
it later.

`{tone}` is the one placeholder a slot understands, and only `badge` uses it: it
is where the package writes the tone of a [badge](columns.md#badges) — the
`success` of `badge badge-light-success`. Leave it out and the tone arrives as
`dynamic-table-badge-success` alongside your class, which the package stylesheet paints.

### Registering one from code

Still supported, for a theme that has to be computed — from a tenant's brand
settings, say. For anything static, prefer the file above.

```php
// AppServiceProvider::boot()
use Shwaeki\DynamicTable\Support\Theme;

Theme::register('brand', [
    'wrapper'       => 'rounded-2xl border border-slate-200 bg-white shadow',
    'toolbar'       => 'dynamic-table-toolbar flex items-center gap-2 p-4 border-b',
    'search'        => 'input input-sm w-64',
    'button'        => 'btn btn-sm',
    'buttonPrimary' => 'btn btn-sm btn-primary',
    'buttonDanger'  => 'btn btn-sm btn-error',
    'input'         => 'input input-sm w-full',
    'select'        => 'select select-sm',
    'table'         => 'dynamic-table-table table w-full',
    'thead'         => 'dynamic-table-thead bg-slate-50',
    'th'            => 'dynamic-table-th text-xs uppercase tracking-wide text-slate-500',
    'row'           => 'dynamic-table-row hover:bg-slate-50',
    'rowSelected'   => 'dynamic-table-row-selected bg-indigo-50',
    'cell'          => 'dynamic-table-cell px-3 py-2',
    'footer'        => 'dynamic-table-footer flex items-center justify-between p-3 border-t',
    'empty'         => 'dynamic-table-empty py-16 text-center text-slate-400',
    'badge'         => 'badge',
    'menu'          => 'dynamic-table-menu absolute z-40 rounded-lg border bg-white p-1 shadow-lg',
    'menuItem'      => 'dynamic-table-menu-item w-full rounded px-2 py-1.5 text-start hover:bg-slate-100',
    'modalBox'      => 'dynamic-table-modal-box w-full max-w-2xl rounded-xl bg-white p-4 shadow-2xl',
    'chip'          => 'dynamic-table-chip badge badge-primary',
]);
```

```php
'theme' => 'brand',
```

Note the colours in that older example: `bg-white`, `text-slate-500`. They work,
but they are the reason a theme can look wrong in dark mode — prefer the tokens.

Resolution order, if a name is defined in more than one place: a theme
registered in code wins, then `config('dynamic-table.themes')`, then a
`config/dynamic-table-themes.php` left over from an older version, then the
built-ins.

> **Upgrading?** Themes used to live in a second file,
> `config/dynamic-table-themes.php`. They are part of `config/dynamic-table.php`
> now, under `themes`. A file you published earlier is still read, so nothing
> breaks — move its contents under the `themes` key when convenient and delete
> it.

## Keep the `dynamic-table-*` classes

Every slot has a structural default like `dynamic-table-table` or
`dynamic-table-row`. Those classes
carry *behaviour* from the package stylesheet — sticky headers, resize handles,
dialog layout, loading overlay, RTL mirroring — and they
are what the colour tokens paint. Keep them in your values (as above) and add
your own alongside.

The package's own rules are written with `:where()`, so they have the
specificity of `.dynamic-table` alone: anything you put in the class map wins without
needing `!important`.

## Slots

`root` · `wrapper` · `toolbar` · `toolbarStart` · `toolbarEnd` · `search` ·
`button` · `buttonPrimary` · `buttonDanger` · `input` · `select` · `scroller` ·
`table` · `thead` · `headRow` · `th` · `thSortable` · `resizer` · `filterRow` ·
`tbody` · `row` · `rowSelected` · `cell` · `cellEditing` · `cellInvalid` ·
`footer` · `pagination` · `empty` · `loading` · `panel` · `menu` · `menuItem` ·
`modal` · `modalBox` · `chip` · `badge` · `group`

## Going further than classes

If a theme genuinely needs different markup, publish the template:

```bash
php artisan dynamic-table:install --views
```

and put your version at
`resources/views/vendor/dynamic-table/themes/{name}/table.blade.php`. It is
preferred automatically over the shared template. This is rarely necessary —
prefer the class map, which keeps you on the upstream markup and its fixes.

## Custom CSS only

```php
'theme' => 'custom',
```

You get the structural `dynamic-table-*` classes and nothing else. Style them
yourself; the package stylesheet is namespaced under `.dynamic-table` and will
not touch the rest of your
application.

## Light and dark

Colour comes from the package's own CSS custom properties, not from the theme's
class map. That is deliberate, and it is why both bundled themes look right in
both schemes:

```css
--dynamic-table-ink        --dynamic-table-muted
--dynamic-table-border     --dynamic-table-surface
--dynamic-table-surface-2  --dynamic-table-hover
--dynamic-table-selected   --dynamic-table-accent
--dynamic-table-accent-ink --dynamic-table-danger
--dynamic-table-success    --dynamic-table-warning
--dynamic-table-info       --dynamic-table-radius
```

By default a table follows the viewer's operating system
(`prefers-color-scheme`). Force one instead:

```php
protected ?string $scheme = 'dark';    // per table
```

```php
'scheme' => 'light',                   // application-wide, config
```

`null` means "follow the system". The choice is rendered as
`data-dynamic-table-scheme` on the table, so it can also be flipped at runtime:

```js
document.querySelectorAll('[data-dynamic-table]')
    .forEach(el => el.setAttribute('data-dynamic-table-scheme', 'dark'));
```

> **Why not Tailwind's `dark:` variants?** Under Tailwind's default *media*
> strategy they follow the operating system and cannot be overridden per
> element — so a table inside a light-only application became unreadable when
> the viewer's OS was dark (light text on a white card), and an explicit
> per-table scheme was impossible. Driving colour from tokens fixes both. If you
> want your own theme to use `dark:` variants, you can — just set both the
> foreground and background in the same variant so they can never disagree.

For Bootstrap, the stylesheet maps `--bs-table-*`, `.card` and `.form-control`
onto the same tokens, so a Bootstrap table is readable whether or not your page
sets `data-bs-theme`. Setting `data-bs-theme` is still recommended, since it
recolours Bootstrap's own dropdowns and buttons.

A theme that deliberately commits to a single look can simply hard-code its
colours in the class map and ignore all of this.

## Responsive

The `responsive` feature (on by default) gives horizontal scrolling with a
sticky header. Add `dynamic-table-responsive-cards` to the wrapper class to switch to a
card layout under 640px, and filters move into a drawer on small screens.
