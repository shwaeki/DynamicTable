# Responsive tables

A wide table on a narrow screen has three sensible answers, and DynamicTable
ships all three. The default is the one Yajra tables get from DataTables
Responsive and the one PowerGrid's responsive feature provides: hide what does
not fit and let the user expand it.

## Turning it on and off

Three independent switches, from widest scope to narrowest:

```php
// config/dynamic-table.php — the master switch for the whole application
'responsive' => [
    'enabled' => true,       // false disables it everywhere
    'mode' => 'collapse',    // the default mode for every table
    'breakpoint' => 640,
],
```

```php
protected array $features = ['-responsive'];   // off for this table
protected ?string $responsive = 'none';        // also off for this table
protected ?string $responsive = 'scroll';      // this table overrides the mode
```

Leave `$responsive` as `null` (the default) and the table follows the config.
Set `DYNAMIC_TABLE_RESPONSIVE=false` in `.env` to switch it off without touching
code.

## Modes

```php
protected ?string $responsive = 'collapse';   // config default
// 'scroll' | 'cards' | 'none'
```

| Mode | Behaviour | JavaScript |
|---|---|---|
| `collapse` | Hides the columns that do not fit; each row gets a **+** control that expands a child row listing them as label/value pairs | one small module |
| `scroll` | Keeps every column and scrolls horizontally, header stays put | none |
| `cards` | Below a breakpoint, each row stacks into a labelled block | one small module |
| `none` | No handling at all | none |

## Collapse mode

The table is **measured**, not guessed at. There is no "hide these columns under
768px" rule, because how many columns fit depends on how many there are and how
wide their content is: three short columns fit on a phone, fifteen do not fit on
a laptop. On every resize the module hides the fewest columns that make the
table fit its container.

### Deciding what goes first

Order is controlled by **priority** — lower survives longer:

```php
protected function columns(): array
{
    return [
        'reference' => ['label' => 'Order'],                    // priority 1 (first column)
        'customer.name' => ['label' => 'Customer', 'priority' => 2],
        'total' => ['priority' => 3],
        'items_count' => ['priority' => 40],
        'placed_at' => ['priority' => 60],
    ];
}
```

Without explicit priorities, declaration order decides: the first column gets
priority 1 and the rest follow left to right. That is usually right, because the
leftmost column is normally the one that identifies the row.

### Pinning columns

```php
protected array $responsiveFixed = ['reference', 'status'];
```

Fixed columns never collapse. If you declare none, the first visible column is
pinned automatically — a row that collapsed *everything* would have no way to
identify itself.

### The child row

The expanded row clones the real cells, so badges, links, thumbnails and custom
`render` output look identical inside it. The control is a real `<button>` with
`aria-expanded`, so it works from the keyboard and reads correctly to a screen
reader.

Expanded rows survive paging and re-renders; changing the visible column set
closes them, since what "details" means has changed.

## Cards mode

```php
protected string $responsive = 'cards';
```

```php
// config/dynamic-table.php
'responsive' => ['breakpoint' => 640],
```

Every cell carries a `data-label`, which the stylesheet renders beside the
value. Use this when every field matters and there is no obvious hierarchy — a
contact list rather than an orders table.

## Scroll mode

```php
protected string $responsive = 'scroll';
```

The classic behaviour: the grid stays intact and the container scrolls
sideways, with the header sticky. It costs nothing — no module is loaded.

## Other small-screen behaviour

Independently of the mode above:

- The filter builder, column picker and every other panel become full-width
  dialogs on small screens.
- The demo's example navigation collapses into a drawer; your own chrome is
  yours to handle.
- Column resizing is pointer-based and works with touch (`touch-action: none`
  on the handle).
- The toolbar wraps rather than overflowing.

## Comparison

| | Yajra + DataTables Responsive | PowerGrid | **DynamicTable** |
|---|---|---|---|
| Setup | Load the extension, set `responsivePriority` per column | `Responsive::make()->fixedColumns(...)` | On by default |
| Strategy | Measure and collapse into a child row | Collapse into a detail row | Measure and collapse into a child row |
| Priorities | `responsivePriority` | fixed columns | `priority` + `$responsiveFixed` |
| Card layout | — | — | `'cards'` mode |
| Extra JS | DataTables + the extension | Alpine/Livewire | ~4 KB module, only when the mode needs it |
