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
protected array $features = [Feature::CREATE];   // implies inline_edit
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

## Spreadsheet mode

```php
protected array $features = ['spreadsheet'];   // implies inline_edit + selection
```

Adds a cell cursor and range selection on top of inline editing:

| | |
|---|---|
| Arrow keys | move the cursor |
| Shift + arrows | extend the selection |
| `Ctrl/Cmd + C` | copy the range as TSV |
| `Ctrl/Cmd + V` | paste a range, starting at the cursor |
| `Ctrl/Cmd + D` | fill down from the first row of the selection |
| `Ctrl/Cmd + Z` | undo an unsaved change |
| `Delete` | clear the selection |
| `Ctrl/Cmd + S` | save every pending change in one request |

Pending changes are highlighted and counted in a save bar; nothing reaches the
database until you save. Every pasted value goes through the same validation and
authorisation as a single inline edit — the browser is never the source of
truth.

To swap in a third-party grid, define an adapter before the core loads:

```html
<script>
window.DynamicTableSpreadsheetAdapter = (table) => {
    // mount your grid against table.root, read table.data.rows,
    // and post changes to table.endpoints.edit
    return { save() {}, undo() {} };
};
</script>
```

See [Architecture](architecture.md#4-spreadsheet-engine-evaluation) for why the
built-in implementation exists.

---

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

        RowAction::delete(),
    ];
}
```

| Method | |
|---|---|
| `label`, `icon` | The icon is shown when present, with the label as its tooltip |
| `url(fn, $target)` | Makes it a link; the closure receives the record |
| `handle(fn)` | Makes it a server action; receives the record and any input |
| `visible(fn)` | Per record — hide an action that makes no sense for that row |
| `ability('update')` | Runs through the table's `can()`, so policies apply |
| `authorize(fn)` | Full control, receives the table and the record |
| `confirm('…')` | Browser confirmation before running |
| `destructive()` | Styles it as dangerous |
| `withoutRefresh()` | Skip the reload when the action changes nothing visible |

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
