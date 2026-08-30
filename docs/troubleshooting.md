# Troubleshooting & FAQ

### The table renders but nothing is interactive

The core script did not load. Check the browser console and the network tab for
`_dynamic-table/asset/core.js`.

- Behind a strict CSP? `script-src` must allow the module. Or set
  `'assets' => ['inject' => false]` and place `@dynamicTableStyles` /
  `@dynamicTableScripts` yourself.
- Serving the page from a different origin than the routes? The endpoints are
  under the `web` middleware group and expect same-origin cookies.

### Sorting or filtering a column does nothing

That column is computed. Accessors from `$appends` can be displayed and
exported, but not sorted, searched or filtered, because they do not exist in
SQL. Store the value in a column if you need to query it.

### "No DynamicTable is registered for the key [x]"

The class is outside the scanned paths, or the discovery cache is stale.

```bash
php artisan dynamic-table:clear
```

Or register it explicitly in `config('dynamic-table.tables.register')`.

### "The table key [users] is used by both …"

Two classes derive the same key. Set `$tableKey` on one of them.

### Columns I expect are missing

In order of likelihood: the column is in `$model->getHidden()`; its name matches
`config('dynamic-table.security.blocked_columns')`; it is a foreign key that was
replaced by its relationship's label column; it is beyond the first eight and
therefore hidden but available in the column picker; or `$allowedColumns` is set
and does not include it.

### `department.name` shows nothing

The relationship must be singular (`belongsTo`, `hasOne`, `morphOne`) and
declared as a real method with no required arguments. `hasMany` cannot be
flattened into one cell.

If the relationship is nested, raise `$relationDepth`.

### After a migration, the table shows old columns

Metadata is cached for 24 hours.

```bash
php artisan dynamic-table:clear
```

Consider adding it to your deploy script.

### Saved views throw an error

Run `php artisan migrate`. Views are the only feature needing a table. Without
it the feature quietly turns itself off rather than breaking the page — an
explicit error only appears on the view endpoints.

### "You are not allowed to perform this action"

A policy exists for the model and denied the mapped ability
(`view → viewAny`, `edit → update`, `export → viewAny`, `import → create`), or
your `authorize()` hook returned false. Return `null` from `authorize()` to fall
through to policies.

### XLSX export is missing from the format list

Install `openspout/openspout` or `phpoffice/phpspreadsheet`. CSV always works.

### Exports of large tables time out

They should be queueing. Check `config('dynamic-table.excel.queue_threshold')`
and that a queue worker is running. `0` disables queueing entirely.

### Import silently skips rows

In `update` mode, rows with no matching record are skipped by design. Use
`upsert` to create them. Check the error report for rows that failed validation.

### The filter builder is empty

`filters` is disabled, or `$allowedColumns` excludes everything, or every
candidate field is computed.

### The header does not stay visible when I scroll

A sticky header can only stick inside a box that scrolls, and by default the
box that scrolls is the page, not the table. Give the table a height:

```php
protected ?string $maxHeight = '70vh';
```

or globally, in `config/dynamic-table.php`:

```php
'table' => ['max_height' => '70vh'],
```

If you published the config before this option existed, the key is simply
missing — add it. Setting it to `null` or `'none'` restores page-flow height,
and the header then scrolls away with everything else.

### RTL looks wrong in my custom theme

Use logical CSS properties (`margin-inline-start`, `inset-inline-end`,
`text-align: start`) rather than left/right, and keep the structural `dt-*`
classes in your class map — they carry the mirroring.

---

## FAQ

**Does this require Livewire?**
No. It works with or without it. See
[Architecture](architecture.md#2-rendering-why-not-livewire).

**Does it require Tailwind or Bootstrap?**
Neither. Pick a theme, or write a class map, or use `'custom'` and style the
`dt-*` classes yourself.

**Is there a build step?**
No. No npm, no Vite, no publish.

**Can I put more than one table on a page?**
Yes. Each gets its own state; the assets are emitted once.

**Can I use it inside a Livewire component or with Turbo?**
Yes — the core re-boots on `livewire:navigated` and `turbo:load`.

**Can I use a query builder instead of a model?**
Not in v1. `$model` is required; shape the query with `query()`.

**How do I add a row action (edit / delete buttons per row)?**
Use a column with a `render` closure and `'raw' => true`, escaping anything you
interpolate.

**Does it work with multi-tenancy?**
Yes, through `query()` and your global scopes. Views are scoped by user and
table key; add a tenant scope of your own if views must not cross tenants.

**Can users share a view with the team?**
That is what a system view is. It requires the
`manage-dynamic-table-system-views` gate.
