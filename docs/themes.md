# Themes

A theme is **one array of CSS classes**. There is a single Blade template and a
single JavaScript renderer, both reading the same class map, so a complete
visual theme does not require touching a folder of partials.

```php
// config/dynamic-table.php
'theme' => 'bootstrap',   // 'custom' | 'bootstrap' | 'tailwind'
```

Per table:

```php
protected ?string $theme = 'custom';
```

Bootstrap users are never served Tailwind classes, and vice versa. The package
never assumes your application has either.

## The three themes that ship

| Theme | Needs | Looks like |
|---|---|---|
| `custom` | **nothing** | The package's own: a card, a quiet header band, comfortable rows. |
| `bootstrap` | Bootstrap 5 on the page | Bootstrap's own components — `btn`, `form-control`, `card`, `table`. |
| `tailwind` | Tailwind on the page | Tailwind utilities: airy, rounded, focus rings. |

Three, and only three. A name the package does not recognise resolves to
`custom` — the one theme that cannot look half-finished for want of a
framework.

### `custom`

The answer to "I do not have a CSS framework, and I do not want to write a
theme":

```php
protected ?string $theme = 'custom';
```

Every class it names is painted by the package's own stylesheet, from the same
tokens as everything else — so it is readable in light and dark, it obeys
`data-dynamic-table-scheme`, and it needs no build step. It is a finished look,
not a skeleton to fill in: card, border, one soft shadow, uppercase muted
headers, an accent on the primary buttons, focus rings on the fields, a tinted
chip for the current page.

Its own knobs are CSS custom properties, so a project that wants the same
theme in its own colours changes tokens rather than classes:

```css
.dynamic-table-custom {
    --dynamic-table-accent: #7c3aed;
    --dynamic-table-custom-card-radius: 4px;   /* squarer card */
    --dynamic-table-custom-gutter: 0.6rem;     /* denser rows */
}
```

## Changing a theme

Publish the config once, and everything you change lives under its `themes`
key:

```bash
php artisan vendor:publish --tag=dynamic-table-config
```

An entry there **starts from the built-in theme of the same name**, so changing
one slot means naming one slot:

```php
// config/dynamic-table.php
'themes' => [
    'bootstrap' => [
        'badge' => 'badge badge-light-{tone}',
        'button' => 'btn btn-sm btn-light',
    ],
],
```

Everything else stays Bootstrap's, including whatever the package changes about
it later. That is usually all an admin template needs.

A name of your own **starts from `custom`**, which is already a complete theme,
so your own theme is as long as the list of slots you actually want to change:

```php
'themes' => [
    'brand' => [
        'root' => 'dynamic-table dynamic-table-brand',
        'button' => 'btn btn-sm',
        'buttonPrimary' => 'btn btn-sm btn-primary',
        'badge' => 'badge badge-{tone}',
    ],
],
```

```php
protected ?string $theme = 'brand';
```

That is the whole workflow: one file — the same one that holds `'theme'`, so
the name you select and the theme you wrote sit a few lines apart — no service
provider, no Blade files, no build step.

Two rules, and only two:

1. **Keep the structural `dynamic-table-*` classes.** They carry behaviour —
   sticky header, resize handles, dialog layout, RTL mirroring — not looks.
2. **Do not put colour in the map.** Surfaces, text and borders come from the
   CSS tokens, which is what keeps every theme legible in light and dark and
   obedient to `data-dynamic-table-scheme`. Set the tokens in your own
   stylesheet:

   ```css
   .dynamic-table-brand { --dynamic-table-accent: #7c3aed; --dynamic-table-radius: 14px; }
   ```

`{tone}` is the one placeholder a slot understands, and only `badge` uses it: it
is where the package writes the tone of a [badge](columns.md#badges) — the
`success` of `badge badge-light-success`. Leave it out and the tone arrives as
`dynamic-table-badge-success` alongside your class, which the package
stylesheet paints.

## Keep the `dynamic-table-*` classes

Every slot has a structural default like `dynamic-table-table` or
`dynamic-table-row`. Those classes carry *behaviour* from the package
stylesheet — sticky headers, resize handles, dialog layout, loading overlay,
RTL mirroring — and they are what the colour tokens paint. Keep them in your
values (as above) and add your own alongside.

The package's own rules are written with `:where()`, so they have the
specificity of `.dynamic-table` alone: anything you put in the class map wins
without needing `!important`.

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

## Light and dark

**Tables are light by default.** A table is part of an application's chrome,
and following the viewer's operating system meant the same page rendered light
or dark depending on whose laptop it was opened on.

```php
'scheme' => 'light',   // config: the default
'scheme' => 'dark',    // dark for every theme alike
'scheme' => null,      // follow the viewer's OS (prefers-color-scheme)
```

```php
protected ?string $scheme = 'dark';    // per table
```

Colour comes from the package's own CSS custom properties, not from the theme's
class map. That is deliberate, and it is why all three themes look right in
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

The choice is rendered as `data-dynamic-table-scheme` on the table, so it can
also be flipped at runtime:

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
sticky header. Add `dynamic-table-responsive-cards` to the wrapper class to
switch to a card layout under 640px, and filters move into a drawer on small
screens.
