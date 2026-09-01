# Saved views

A view is a named snapshot of table state: visible columns, their order and
widths, filters, sort, search, grouping and page size. It is
stored as declarative JSON — never as generated SQL — so it survives schema
changes and can be migrated forward.

Enable it per table:

```php
protected array $features = ['saved_views'];
```

and run `php artisan migrate` once.

## Kinds of view

| Kind | Icon | Owner | Who sees it | Who can edit it |
|---|---|---|---|---|
| **Preset** | ⚙ | the table class | everyone | nobody (it's code) |
| **User view** | 👤 | the signed-in user | only them | only them |
| **Shared view** | 🔗 / 👥 | one user | the owner and the people they shared it with | **only the owner** |
| **System view** | 🌐 | nobody (`user_id` is null) | everyone | anyone passing the system-views gate |

In the manage panel each view carries its icon, and anything that is not yours
shows whose it is — 👥 *Shared with you · by Sara Haddad*.

## Sharing a view with people

The owner of a private view can share it with one person or many:

**Views → Manage views → Share**, then search for people and add them. Removing
someone is one click on their pill.

**Sharing grants read access only.** Recipients can apply the view and see it in
their list; renaming, editing and deleting stay with the owner. Someone who
wants their own version saves a copy, which is a far simpler model to reason
about — and to audit — than per-person permissions.

The package cannot know your user model or what you call a person's name, so
both are configuration:

```php
'views' => [
    'sharing' => [
        'enabled' => true,
        'model' => null,                          // defaults to your auth provider's model
        'name_column' => 'name',
        'search_columns' => ['name', 'email'],
        'max_results' => 20,
    ],
],
```

Shares live in `dynamic_table_view_shares` — a real table rather than a JSON
column, because "which views can this user see" runs on every page load and
needs an index. Deleting a view deletes its shares.

### Security notes

- Only the owner may change who a view is shared with; the endpoint checks
  ownership, not just visibility.
- The people search is reachable **only** by someone who can already share that
  view, so it never becomes a general user-enumeration endpoint. It is always
  limited and always filtered by the search term.
- A recipient calling the update or delete endpoint gets a 403 — read access
  never widens.

## Choosing a default view

Which view opens first is the **user's** choice, not only the developer's.

In the Views menu, "Manage views" opens a panel listing every view the user can
see. Each row has a star:

- click the star to make that view open by default
- click it again to clear the default
- only one default per user; setting a new one clears the previous
- an administrator with the system-views gate can star a **system** view, which
  becomes the default for everyone who has not chosen their own
- presets ship in code, so their star is read-only

There is also a "Open this view by default" checkbox when saving a new view, so
the common case is one step rather than two.

Renaming, sharing and deleting live in the same panel, next to the view they
affect.

### Precedence

```
the user's default view
        ↓
the system default view
        ↓
a preset marked 'default' => true
        ↓
the table's own defaults
```

A user's default and the system default are stored independently: starring your
own view never disturbs the shared one, it simply takes precedence for you.

## Authorising system views

Define a gate:

```php
Gate::define('manage-dynamic-table-system-views', fn (User $user, string $tableKey) =>
    $user->hasRole('admin'));
```

Rename it in `config('dynamic-table.views.system_ability')`, or answer directly
from the table:

```php
public function authorize(string $ability, ?Model $record = null): ?bool
{
    return $ability === 'manage-system-views'
        ? auth()->user()->isAdmin()
        : null;   // null = fall through to policies
}
```

Without a gate, system views are read-only for everyone: they can be selected,
but not created, edited or made default.

## Storage

One table, `dynamic_table_views`, serves every DynamicTable in the application:

| Column | |
|---|---|
| `table_key` | which table the view belongs to |
| `user_id` | null for system views |
| `name`, `icon`, `position` | presentation |
| `configuration` | JSON state, with a `version` field |
| `is_system`, `is_default` | |
| `created_by`, `updated_by` | audit |

Users are capped at `config('dynamic-table.views.max_per_user')` (100) views per
table.

## Versioning and stale fields

Every configuration carries `"version": 1`. When the shape changes in a future
release, old configurations are migrated on read rather than discarded.

A view that references a field which no longer exists degrades gracefully: the
invalid column or condition is dropped, the payload carries a warning the UI
surfaces once, and the table renders normally.

## Events

`ViewCreated`, `ViewUpdated` and `ViewDeleted` are dispatched with the table key
and the model, so an audit package can subscribe without this package depending
on one.

## URL state

With the `url_state` feature, the current search, page, sort, view and filters
are mirrored into the query string as `{tableKey}_search`, `{tableKey}_page` and
so on — so a filtered table is bookmarkable and the back button works.

Precedence at boot: **saved view first, then URL parameters on top.** The URL is
an override of the view, not a replacement for it.
