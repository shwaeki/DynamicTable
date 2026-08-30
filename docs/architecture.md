# Architecture & decisions

This document records the research behind Laravel DynamicTable and the
trade-offs each decision was made against. It is deliberately opinionated: the
point of the package is that you should not have to make these choices yourself.

---

## 1. What already exists

| | Yajra DataTables | Laravel PowerGrid | Dynamics 365 views | **DynamicTable** |
|---|---|---|---|---|
| Definition | Controller + JS config | PHP component class | Admin-designed view | One PHP class |
| Columns | Declared twice (JS + PHP) | `Column::make()->...` per column | Designed in UI | Discovered from the model |
| Transport | jQuery + AJAX | Livewire round trips | Server-rendered | One JSON endpoint |
| Advanced filters | Manual | Per-column filters | Full condition builder | Full condition builder |
| Saved views | — | — | User + system views | User + system views |
| Front-end weight | jQuery + DataTables | Livewire + Alpine | n/a | ~14 KB core, modules on demand |

The gap worth filling: Yajra makes you describe every column twice; PowerGrid
gives you a clean PHP API but still asks for a `Column::make()` chain per field
and re-renders through Livewire; Dynamics has the best *end-user* model (views,
a real filter builder, a column picker) and almost no developer ergonomics
because it is not a library.

**DynamicTable's thesis:** take the Dynamics end-user model, drive it entirely
from Eloquent metadata, and expose it as one class plus one Blade directive.

---

## 2. Rendering: why not Livewire

The brief allowed Livewire "where appropriate" and asked for the performance
implications of the alternatives to be spelled out. They are:

| | Livewire-rendered table | **JSON endpoint + small JS core** |
|---|---|---|
| Payload per interaction | Full component state + rendered HTML diff | ~2 KB of JSON rows |
| State serialisation | Every public property, every request | Nothing; state lives in the browser |
| Server cost per keystroke | Component hydrate → render → diff | One query, one JSON encode |
| Dependencies | livewire + alpine | none |
| Cell edit | Re-renders the component | Patches one `<td>` |
| Works without Livewire installed | No | Yes |

A Livewire component that holds a page of 100 rows with 10 columns serialises
its state on every request, and the "changing one filter should not serialise
unnecessary state" requirement is very hard to satisfy from inside that model.

**Decision:** the core is Livewire-free. It renders server-side on first paint
(so there is no boot request and the table is readable before JS runs) and then
talks to one POST endpoint. Livewire remains fully *compatible* — the directive
works inside a Livewire component, and `livewire:navigated` re-boots tables —
but it is not a dependency. This also means the promise "the developer never
needs to know Livewire is being used" is satisfied trivially: it isn't.

**Consequence to be aware of:** if you want the table's state to drive other
Livewire components, listen for the DOM events the table emits
(`dynamic-table:updated`, `dynamic-table:selection-changed`) rather than
reaching into its internals.

---

## 3. Front-end dependencies

Nothing is bundled and nothing is fetched from a CDN. The core is a
dependency-free ES module served from the package directory through a versioned,
immutable-cached route, so installation needs no `vendor:publish` and no build
step.

Modules load through dynamic `import()` only when their feature is enabled *and*
first used:

```
core.js          always        ~14 KB
  ui.js          with any panel
  filters.js     when the filter builder opens
  columns.js     when the column picker opens
  views.js       when the views menu opens
  transfer.js    when export/import opens
  actions.js     when selection is enabled
  inline-edit.js when inline editing is enabled
```

A plain table therefore downloads one file.

---

## 5. Excel library evaluation

| Library | Licence | Export | Import | Memory | Verdict |
|---|---|---|---|---|---|
| **Laravel Excel** (maatwebsite) | MIT | Yes | Yes | Wraps PhpSpreadsheet | Supported when present, not required |
| **PhpSpreadsheet** | MIT | Yes | Yes | High for large sheets | Supported when present |
| **openspout** | MIT | Yes | Yes | Constant | Preferred when present |
| Built-in CSV | — | Yes | Yes | Constant | **Default** |

**Decision:** CSV is handled natively with a streaming reader/writer, so export
and import work with zero extra dependencies on a fresh install. XLSX is an
adapter that prefers openspout, falls back to PhpSpreadsheet, and reports a
clear message if neither is installed. Nothing in the package's `require` block
depends on an Excel library.

---

## 6. Query strategy

```
Table definition
      ↓  columns()/discovery
Metadata engine  ──►  filter builder, column picker, import mapper, export mapper
      ↓
Table state (validated)
      ↓
Query engine  ──►  one paginated query + one eager load per relation
      ↓
Row formatter  ──►  compact JSON rows
```

Specific choices worth knowing about:

- **Eager loading, not joins.** Relationship columns are loaded with `with()`,
  so a page of *N* rows costs `1 + relations` queries regardless of *N*, and
  rows are never duplicated.
- **Relationship sorting uses a correlated subquery**, not a join, for the same
  reason. Only depth-1 `belongsTo`/`hasOne` are sortable; anything deeper is
  ignored rather than silently generating an expensive plan.
- **Narrowed SELECT** when it is safe. The moment a computed accessor is in
  play the package selects everything, because an accessor may read any
  attribute and guessing its dependencies would be wrong.
- **Relationship filter options are never `Model::all()`.** They are a
  paginated, searchable `DISTINCT` query.
- **Exports chunk.** Memory is flat whether the export is 10 rows or 10 million.

---

## 7. Security model

Everything the browser sends is treated as hostile:

| Input | Defence |
|---|---|
| Table key | Looked up in a registry allowlist; a class name is rejected |
| Sort field | Must resolve to a sortable resolved column; else dropped |
| Filter field | Must resolve through the metadata engine and be exposed; else dropped with a warning |
| Filter operator | Closed enum, intersected with the field type's operator list |
| Filter value | Coerced to the field's type; unusable values drop the whole condition |
| Column list | Intersected with the resolved columns |
| Per page | Must be one of the table's options |
| Selected ids | Re-queried through the table's own base query and filters |
| Edited record | Re-fetched through the table's own base query, so `query()` scoping holds |
| Import file path | HMAC token issued by the analyze step |

No identifier from a request ever reaches SQL. Values are always bound.

---

## 8. Views as data

A saved view stores declarative state — columns, order, widths, filters, sort,
search, grouping — with a `version` field, never generated SQL. A view that
references a field which has since been removed degrades: the invalid parts are
dropped, a warning is surfaced, and the table still renders.

---

## 9. Supported versions

- **PHP 8.2+** — matches the floor of every currently supported Laravel release.
- **Laravel 11, 12 and 13.** Laravel 11 is the floor because the package relies
  on `Schema::getColumns()`, which removed the `doctrine/dbal` requirement for
  schema introspection. Supporting Laravel 10 would mean carrying dbal for one
  release line; that cost is not worth paying.
- Databases: MySQL, MariaDB, PostgreSQL, SQLite. No database-specific SQL is
  emitted; `LIKE` is used for search because it is the portable option, and the
  docs explain when to reach for a real search index instead.

---

## 10. What was deliberately left out of v1

- Real-time updates. The architecture leaves room for broadcasting, but adding
  it now would complicate the core for a feature most tables do not need.
- A REST/JSON public API. The internal endpoint is an implementation detail and
  is documented as such.
- Multi-tenancy. Views are scoped by user and table key; a tenant-aware
  application can scope them further through its own global scopes.
- Non-Eloquent data sources. The query engine is isolated enough that another
  provider could be added, but shipping one speculatively would be waste.
