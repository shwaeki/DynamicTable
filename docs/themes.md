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
they are readable in light and dark, they obey `data-dt-scheme`, and they need
no build step:

```php
protected ?string $theme = 'minimal';   // or 'bordered'
```

`bordered` is `minimal` plus one class, which is a fair description of what a
theme is at all.

## Writing a theme

```php
// AppServiceProvider::boot()
use Shwaeki\DynamicTable\Support\Theme;

Theme::register('brand', [
    'wrapper'       => 'rounded-2xl border border-slate-200 bg-white shadow',
    'toolbar'       => 'dt-toolbar flex items-center gap-2 p-4 border-b',
    'search'        => 'input input-sm w-64',
    'button'        => 'btn btn-sm',
    'buttonPrimary' => 'btn btn-sm btn-primary',
    'buttonDanger'  => 'btn btn-sm btn-error',
    'input'         => 'input input-sm w-full',
    'select'        => 'select select-sm',
    'table'         => 'dt-table table w-full',
    'thead'         => 'dt-thead bg-slate-50',
    'th'            => 'dt-th text-xs uppercase tracking-wide text-slate-500',
    'row'           => 'dt-row hover:bg-slate-50',
    'rowSelected'   => 'dt-row-selected bg-indigo-50',
    'cell'          => 'dt-cell px-3 py-2',
    'footer'        => 'dt-footer flex items-center justify-between p-3 border-t',
    'empty'         => 'dt-empty py-16 text-center text-slate-400',
    'badge'         => 'badge',
    'menu'          => 'dt-menu absolute z-40 rounded-lg border bg-white p-1 shadow-lg',
    'menuItem'      => 'dt-menu-item w-full rounded px-2 py-1.5 text-start hover:bg-slate-100',
    'modalBox'      => 'dt-modal-box w-full max-w-2xl rounded-xl bg-white p-4 shadow-2xl',
    'chip'          => 'dt-chip badge badge-primary',
]);
```

```php
'theme' => 'brand',
```

Or without any code, in the config file:

```php
'themes' => [
    'brand' => [ /* the same map */ ],
],
```

## Keep the `dt-*` classes

Every slot has a structural default like `dt-table` or `dt-row`. Those classes
carry *behaviour* from the package stylesheet — sticky headers, resize handles,
dialog layout, loading overlay, RTL mirroring, spreadsheet selection — and they
are what the colour tokens paint. Keep them in your values (as above) and add
your own alongside.

The package's own rules are written with `:where()`, so they have the
specificity of `.dt` alone: anything you put in the class map wins without
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

You get the structural `dt-*` classes and nothing else. Style them yourself; the
package stylesheet is namespaced under `.dt` and will not touch the rest of your
application.

## Light and dark

Colour comes from the package's own CSS custom properties, not from the theme's
class map. That is deliberate, and it is why both bundled themes look right in
both schemes:

```css
--dt-ink  --dt-muted  --dt-border  --dt-surface  --dt-surface-2
--dt-hover  --dt-selected  --dt-accent  --dt-accent-ink
--dt-danger  --dt-success  --dt-warning
```

By default a table follows the viewer's operating system
(`prefers-color-scheme`). Force one instead:

```php
protected ?string $scheme = 'dark';    // per table
```

```php
'scheme' => 'light',                   // application-wide, config
```

`null` means "follow the system". The choice is rendered as `data-dt-scheme` on
the table, so it can also be flipped at runtime:

```js
document.querySelectorAll('[data-dynamic-table]')
    .forEach(el => el.setAttribute('data-dt-scheme', 'dark'));
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
sticky header. Add `dt-responsive-cards` to the wrapper class to switch to a
card layout under 640px, and filters move into a drawer on small screens.
