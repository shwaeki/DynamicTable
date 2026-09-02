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

## State classes

The table puts a handful of classes on its root element to say what it is
currently doing. They carry no styling of their own — they exist so your
stylesheet can react without polling the JavaScript handle:

| Class | On the root element while |
| --- | --- |
| `dynamic-table-is-loading` | a fetch is in flight (`aria-busy="true"` is set too) |
| `dynamic-table-has-sticky` | at least one column is pinned |
| `dynamic-table-has-collapsed` | responsive collapsing is hiding columns |

```css
.dynamic-table-is-loading .dynamic-table-scroller { opacity: .6; }
```

These are part of the public DOM contract, alongside the `data-dynamic-table-*`
hooks — see [UPGRADE.md](../UPGRADE.md#versioning-promise).
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

See [Themes](themes.md). A theme is one array of CSS classes, and the "themes"
key of the config file is the extension point: an entry there starts from the
built-in theme of the same name, and a name of its own starts from `custom`.

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
| Theme | `$theme`, config `themes` |
| Direction | `$direction` |
| Table key | `$tableKey` |
