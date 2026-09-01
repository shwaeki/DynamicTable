# Inline editing and bulk actions

## Inline editing

```php
protected array $features = ['inline_edit'];
```

Double-click a cell (or focus it and press `Enter`/`F2`). `Enter` saves and
moves down, `Tab` saves and moves across, `Escape` cancels.

The control matches the column type: a select for enums and booleans, a date or
datetime picker for temporal columns, a number input for numerics.

### What is editable

Everything except computed accessors, relationship columns and the primary key.
Override per column:

```php
'status' => ['editable' => false],
'notes'  => ['editable' => true],
```

### Validation

Declare rules keyed by field path; anything you don't declare gets rules derived
from the column metadata (nullability, type, enum cases, string length):

```php
public function rules(): array
{
    return [
        'name'   => ['required', 'string', 'max:100'],
        'price'  => ['required', 'numeric', 'between:1,1000'],
    ];
}

public function validationMessages(): array
{
    return ['price.between' => 'Price must be between 1 and 1000.'];
}
```

Errors come back keyed by row and column, and are shown on the offending cell.

### Authorisation

Every row is checked individually with `update` before it is saved — through
your model policy, or your `authorize()` hook. A row that fails is reported and
the rest of the batch still saves.

### Scope safety

The record is re-fetched through the table's own base query. A table scoped with
`query()` cannot be used to edit a row outside that scope, whatever id the
browser sends.

### Events

`RowUpdated` carries the table key, the model and the dirty attributes.

---

## Inline create

```php
protected array $features = [Feature::INLINE_CREATE];   // implies inline_edit
```

"New" in the toolbar opens a blank row at the top of the table. It uses the
same controls, the same column metadata and the same `rules()` as inline
editing — the difference is that nothing is written until you save, so a
half-typed record never reaches the database.

`newRecordDefaults()` supplies the columns the blank row does not ask for:

```php
public function newRecordDefaults(): array
{
    return ['status' => ProductStatus::Draft, 'is_featured' => false];
}
```

The endpoint checks the `create` ability before anything else.

## Bulk edit

```php
protected array $features = [Feature::BULK_EDIT];   // implies selection
```

Select rows, choose "Edit selected", tick the fields to change and apply. Only
columns marked editable are offered, and only ticked fields are sent — a bulk
edit of `status` cannot silently blank a note.

The values are validated once, then applied **record by record** in chunks of
500. That is deliberate: each row is authorised on its own and saved through
the model, so policies apply, observers fire and audit trails see the change.
Records the viewer may not update are skipped rather than failing the whole
operation.

Because the selection is a mode rather than a list of ids, "select all
matching" works here too, and a bulk edit can cover far more rows than the page
shows.

## Toolbar actions

```php
public function toolbar(): array
{
    return [
        ToolbarAction::make('sync')->label('Sync')->handle(fn () => 'Synced.'),
        ToolbarAction::link('report', route('reports.orders')),
    ];
}
```

A toolbar action concerns the table rather than a row. Declared `fields()` are
collected in a dialog and validated on the server; `ability()` and `visible()`
decide whether the button exists, and the endpoint re-checks before running.

## Row actions

Buttons on every row.

```php
protected array $features = ['row_actions'];
```

```php
use Shwaeki\DynamicTable\Actions\RowAction;

public function rowActions(): array
{
    return [
        // A link — goes wherever you point it.
        RowAction::make('edit')
            ->label('Edit')
            ->icon('✏')
            ->url(fn (Product $product) => route('products.edit', $product)),

        // A handler — posted back to Laravel and run on the server.
        RowAction::make('publish')
            ->icon('✔')
            ->visible(fn (Product $product) => $product->status === Status::Draft)
            ->ability('update')
            ->handle(fn (Product $product) => $product->update(['status' => Status::Active])),

        // A real button, in your own framework's clothes.
        RowAction::make('invoice')
            ->label('Invoice')
            ->icon('<i class="far fa-file-lines"></i>')
            ->class('btn btn-sm btn-light')
            ->withLabel()
            ->route('products.invoice'),

        RowAction::delete(),
    ];
}
```

| Method | |
|---|---|
| `label`, `icon` | The icon is shown when present, with the label as its tooltip. Icon-font markup works too — `->icon('<i class="far fa-edit"></i>')` is rendered as markup, anything else as text. `->icon(new HtmlString(...))` says it is markup outright |
| `withLabel()` | Draw the label beside the icon, instead of only in the tooltip. An action with no icon shows its label either way |
| `class('btn btn-sm')` | Your own classes on the element — the package then stops painting it, see below |
| `url(fn, $target)` | Makes it a link; the closure receives the record |
| `route('products.edit')` | The same for a named route, with the record as its parameter |
| `handle(fn)` | Makes it a server action; receives the record and any input |
| `visible(fn)` | Per record — hide an action that makes no sense for that row |
| `ability('update')` | Runs through the table's `can()`, so policies apply |
| `authorize(fn)` | Full control, receives the table and the record |
| `confirm('…')` | Browser confirmation before running |
| `destructive()` | Styles it as dangerous |
| `withoutRefresh()` | Skip the reload when the action changes nothing visible |

### Link, button, icon or text

The element follows the method rather than a setting: `url()` and `route()`
render an `<a href>`, `handle()` renders a `<button>`. Both carry
`dynamic-table-row-action` and, by default, look the same — a quiet icon, because a column
of loud buttons is a wall.

`->class()` is the way out of that default. An action that brings its own
classes also gets `dynamic-table-row-action-custom`, and the package's button
styling steps
aside for it: only the layout stays (inline-flex, the gap between icon and
label, the spacing between buttons), so your framework's `.btn` is never fought
over. `->withLabel()` decides whether the label is drawn beside the icon or left
as the tooltip.

```php
RowAction::make('edit')->label('Edit')->icon('✏')->route('products.edit')                // icon only
RowAction::make('edit')->label('Edit')->route('products.edit')                            // text only
RowAction::make('edit')->label('Edit')->icon('✏')->withLabel()->route('products.edit')   // both
RowAction::make('edit')->label('Edit')->class('btn btn-sm btn-primary')->withLabel()->route('products.edit')
```

Toolbar actions take `->class()` too, appended after the theme's button class.

### What the server does

The record is re-fetched through the table's own base query, and the action's
`visible` and authorisation checks run **again** against that record. A button
having been rendered is never treated as permission — and per-record visibility
means a row only ever shows the actions that row actually allows.

After a handler runs the row is repainted from the saved record. If the record
was deleted, or no longer matches the filters, the page reloads instead of
patching around a row that is gone.

`RowActionExecuted` is dispatched with the table key, action name and record.

## HTML inside a cell

A render closure can return markup — a badge, a thumbnail, a link:

```php
'stock' => [
    'align' => 'end',
    'render' => fn (int $stock) => new HtmlString(
        '<span class="stock stock-'.($stock > 100 ? 'ok' : 'low').'">'.e($stock).'</span>'
    ),
],
```

Returning an `HtmlString` (or anything `Htmlable`, including a rendered Blade
view) is an explicit statement that the value is already safe HTML, so no `raw`
flag is needed. Returning a plain string keeps the default escaping.

> **Escaping is still yours.** `e()` everything you interpolate. The package
> escapes by default precisely so that opting out is a visible, deliberate act.

## Bulk actions

```php
protected array $features = ['bulk-actions'];   // implies selection
```

```php
use Shwaeki\DynamicTable\Actions\BulkAction;

public function actions(): array
{
    return [
        BulkAction::make('activate')
            ->label('Activate')
            ->ability('update')
            ->handle(fn ($query) => $query->update(['is_active' => true])),

        BulkAction::update('mark_pending', ['status' => 'pending'])
            ->label('Mark pending'),

        BulkAction::make('assign')
            ->label('Change department')
            ->fields([
                'department_id' => [
                    'label' => 'Department',
                    'options' => [/* value/label pairs */],
                    'rules' => ['required', 'exists:departments,id'],
                ],
            ])
            ->handle(fn ($query, $input) => $query->update($input)),

        BulkAction::delete(),
    ];
}
```

The handler receives an **Eloquent builder** restricted to the selection, not a
collection of ids — so you decide whether to `update()` in one statement or
iterate with `chunkById()` to fire model events.

### Selection model

"Select all matching" is stored as a mode, not a list:

```
mode: 'exclude', ids: [17, 42]      →  everything matching the current filters, except 17 and 42
```

The browser never holds or sends millions of ids, and the server rebuilds the
set from the same filters that produced the visible page — so an id the user
could not see cannot be smuggled in.

### Authorisation

`->ability('delete')` runs the ability through the table's `can()`, which
consults `authorize()` first and then the model policy. `->authorize(fn ($table,
$record) => …)` gives you full control. Unauthorised actions are not rendered
*and* are rejected server-side.

`BulkActionExecuted` is dispatched with the action name and the affected count.
