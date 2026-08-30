# Security

Everything a browser sends is treated as hostile. This page is the checklist a
reviewer should be able to verify.

## Never trusted, always re-derived

| The browser sends | What the server does |
|---|---|
| A table key | Looks it up in the registry allowlist. A class name is rejected with 400. |
| A sort field | Must resolve to a **sortable resolved column**; otherwise dropped and the table default is used. |
| A filter field | Must resolve through the metadata engine *and* be exposed by this table; otherwise dropped with a warning. |
| A filter operator | Closed PHP enum, intersected with the field type's operator list. |
| A filter value | Coerced to the field's type. Unusable values drop the condition. |
| A column list | Intersected with the table's resolved columns. |
| A page size | Must be one of the table's `perPageOptions`. |
| Selected ids | Re-queried through the table's own base query **and** the active filters. |
| A record to edit | Re-fetched through the table's base query, then authorised individually. |
| An import file path | Must carry the HMAC token issued by the analyse step. |
| An asset filename | Matched against a fixed allowlist of eight files. |

**No identifier from a request ever reaches SQL.** Values are always bound
parameters, and `LIKE` patterns are escaped so `%` and `_` from a user cannot
widen a match.

## Column exposure

Automatic discovery skips, by default:

- anything in `$model->getHidden()`
- anything matching `config('dynamic-table.security.blocked_columns')` —
  `password`, `remember_token`, `*token*`, `*secret*`, `api_key`,
  `private_key`, `two_factor`, `otp`

```php
protected array $hiddenColumns = ['internal_notes'];   // never exposed
protected array $allowedColumns = ['name', 'email'];   // exhaustive allowlist
```

`$allowedColumns` is enforced in one place and therefore everywhere: columns,
filter builder, column picker, sorting, search, options, import and export.

## Scope is a hard boundary

```php
public function query(Builder $query): Builder
{
    return $query->where('tenant_id', auth()->user()->tenant_id);
}
```

`query()` runs before anything the user can influence, and it is re-applied for
data, exports, bulk actions and inline edits. A forged id for another tenant's
row resolves to "not found", not to that row.

## Authorisation

```php
public function authorize(string $ability, ?Model $record = null): ?bool
{
    // return true/false to decide, null to fall through
    return null;
}
```

Resolution order: `authorize()` → the model's policy → allow.

Abilities map to policy methods: `view → viewAny`, `edit/inline-edit → update`,
`bulk-delete → delete`, `export → viewAny`, `import → create`.

Works with Gates, Policies and `spatie/laravel-permission` (through your own
gate definitions) — none of which are required.

Authorisation is enforced **server-side**, not by hiding UI. Unauthorised
controls are also not rendered, but that is a courtesy, not the control.

## Mass assignment

Inline edits and imports write through `fill()`/`forceFill()` only after the
attribute list has been reduced to columns this table declares editable and
validated. A column the table does not expose cannot be written even if the
model is unguarded.

## Output escaping

Server-rendered cells and the JavaScript renderer both escape by default. The
boot payload is JSON-encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
JSON_HEX_QUOT`, so data cannot break out of its `<script>` tag.

Raw HTML requires explicit opt-in per column (`'raw' => true`), and it is then
your responsibility to escape what you interpolate.

## CSV injection

Exported values beginning with `=`, `+`, `-`, `@` or a control character are
prefixed with an apostrophe.

## Limits

```php
'security' => [
    'max_filters' => 40,
    'max_filter_depth' => 4,
    'max_relation_depth' => 3,
],
```

Plus: at most 3 sort columns, 500 changes per edit request, 5,000 ids per
selection payload, 500 values in an `in` filter, 50 MB uploads.

## Saved views

Users can only read their own views plus system views, and can only modify their
own. Creating or editing a system view requires the
`manage-dynamic-table-system-views` gate; without it, system views are
read-only for everyone.

Views store declarative configuration, never SQL, so a crafted view cannot smuggle
a query fragment.

## Endpoints

All endpoints are POST under `config('dynamic-table.route.prefix')` with the
`web` middleware group — so CSRF and session auth apply. Add your own:

```php
'route' => ['middleware' => ['web', 'auth', 'verified']],
```

The asset route is deliberately outside the session middleware so it stays
cacheable; it serves eight static files from the package directory and nothing
else.

## Reporting

Please report security issues privately to shwaeki98@gmail.com rather than in a
public issue.
