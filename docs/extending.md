# Extending DynamicTable

## Public, internal and experimental API

**Public — safe to depend on, semver-protected:**

- `Shwaeki\DynamicTable\DynamicTable` and its documented properties/methods
- `Shwaeki\DynamicTable\Actions\BulkAction`
- `Shwaeki\DynamicTable\Support\Theme`
- The events under `Shwaeki\DynamicTable\Events`
- `Shwaeki\DynamicTable\Models\DynamicTableView`
- The `@dynamicTable`, `@dynamicTableStyles`, `@dynamicTableScripts` directives
- The config file
- The DOM events described below

**Internal — may change in a minor release:**

- `MetadataEngine`, `QueryEngine`, `FilterEngine`, `ColumnResolver`,
  `TableState`, `TablePayload`, `TableRenderer`, `ViewEngine`
- The JSON endpoints and their payload shape
- The JavaScript module layout

**Experimental:**

- The spreadsheet adapter contract
- `SpreadsheetWriter` / `SpreadsheetReader`

Do not build on internal classes. If you need something they do, open an issue —
the intent is that the public surface stays small enough to be stable.

## Events

```php
use Shwaeki\DynamicTable\Events\RowUpdated;

Event::listen(RowUpdated::class, function (RowUpdated $event) {
    activity()->performedOn($event->record)->withProperties($event->changes)->log('inline edit');
});
```

| Event | Payload |
|---|---|
| `RowUpdated` | table key, model, changed attributes |
| `BulkActionExecuted` | table key, action name, affected count, input |
| `ViewCreated` / `ViewUpdated` | table key, view model |
| `ViewDeleted` | table key, view id |
| `ExportStarted` / `ExportCompleted` | table key, job id, context |
| `ImportStarted` / `ImportCompleted` / `ImportFailed` | table key, job id, summary |

Export and import events extend `TransferEvent`, so listening to the base class
observes every transfer. No audit package is required or assumed.

## DOM events

Every table dispatches bubbling `CustomEvent`s from its root element:

```js
document.addEventListener('dynamic-table:updated', (event) => {
    const { table, payload } = event.detail;
    console.log(table.key, payload.data.total);
});
```

`ready` · `updated` · `error` · `rows-rendered` · `header-rendered` ·
`selection-changed` · `row-saved` · `escape`

## The JavaScript handle

```js
const table = window.DynamicTable.find('users');

table.state.search = 'ada';
table.refresh({ resetPage: true });

table.applyView({ columns: ['name', 'email'] });
table.setColumns(['name', 'email', 'status']);
table.selectionCount();
```

This is intentionally minimal and is documented so you can integrate, not so you
can rebuild the UI. Prefer the PHP API where both exist.

## Custom themes

See [Themes](themes.md). A theme is one array; `Theme::register()` is the
extension point.

## Spreadsheet engine

```js
window.DynamicTableSpreadsheetAdapter = (table) => {
    // mount your grid over table.root
    return { save() {}, undo() {} };
};
```

Defined before the core loads, this replaces the built-in implementation
entirely. The server side is unchanged: post changes to `table.endpoints.edit`
and they go through the same validation and authorisation.

## Excel adapters

Implement `SpreadsheetWriter` or `SpreadsheetReader` and bind it in the
container. Both are streaming contracts — `open()`, `writeRow()`, `close()` —
so an adapter that buffers the whole sheet defeats the point.

```php
$this->app->bind(SpreadsheetWriter::class, MyOdsWriter::class);
```

## Adding a column type

Field types are a closed enum (`FieldType`), because each one implies an
operator set, an input widget and a formatter. Rather than adding a case, use a
column's `type`, `format` and `render` options — that covers presentation
without changing query semantics. If you need genuinely new *query* semantics,
open an issue; that belongs upstream.

## Overriding automatic behaviour

Every automatic decision has an explicit override:

| Automatic | Override |
|---|---|
| Label | `$labels`, or a `fields` translation |
| Type | `'type' => …` |
| Visibility | `'visible' => …` |
| Sortability | `'sortable' => …` |
| Searchability | `$searchable` |
| Filterability | `'filterable' => …` |
| Editability | `'editable' => …` |
| Formatting | `'format' => …` or `'render' => …` |
| Column set | `columns()` |
| Base query | `query()`, `$scopes` |
| Eager loading | `$with` |
| Authorisation | `authorize()` |
| Theme | `$theme`, `Theme::register()` |
| Direction | `$direction` |
| Table key | `$tableKey` |
