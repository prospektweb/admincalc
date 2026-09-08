# Reusable price templates

`PriceTemplateApplication` restores the shared-template operations of the price
editor without service iblocks or module-option JSON. The HTTP adapter is still
`tools/documents.php`: POST, authenticated administrator, CSRF and existing site
scope are required before any command. Scope and actor cannot be supplied in a
command. Calculator/version revisions and publications are not changed by these
commands.

## Contract

`prospektweb.calculator/price-template-v1` contains `currency`, `mode`, `types`
and `ranges`. Types use portable codes, not document UUIDs or Bitrix price IDs.
Every type has the same non-overlapping run-count intervals; one type is base.
The percent mode must agree with the template mode, while fixed-currency cells
may coexist. Invalid currencies, intervals, limits and margins are rejected.

Applying a template belongs to the authoring adapter: resolve the codes against
the target document, validate compatibility and copy a snapshot into its draft.
Updating, renaming or deleting the library record must never silently mutate
that snapshot. The UI's native-template integration is a separate delivery gate;
this backend alone does not prove former price-editor parity.

## Commands and concurrency

| Action | Fields in addition to `action` |
| --- | --- |
| `priceTemplates` | none |
| `loadPriceTemplate` | `id`, `expectedRevision` |
| `createPriceTemplate` | `expectedCatalogRevision`, `name`, `templateJson` |
| `savePriceTemplate` | `id`, `expectedRevision`, `expectedCatalogRevision`, `templateJson` |
| `renamePriceTemplate` | `id`, `expectedRevision`, `expectedCatalogRevision`, `name` |
| `deletePriceTemplate` | `id`, `expectedRevision`, `expectedCatalogRevision` |

All fields are exact and typed. A stale catalog or record produces conflict
409; the caller must refresh and let the operator decide before retrying.
Listings are bounded metadata-only repeatable-read snapshots; bodies are loaded
on demand. Mutations return the current catalog and affected immutable record.
An identical save is a no-op. Delete appends a tombstone; existing revisions
remain available to trusted recovery code, not to the normal current-load API.

`DocumentLibrary` owns transactions and serializes writers on `(scope, kind)`.
Schema v6 adds three module-owned InnoDB tables: library catalog counters,
record metadata and immutable revisions. Installation is explicit/idempotent;
requests never create tables. Records include actor, revision and body SHA-256.
This is an integrity check, not protection against a privileged database writer.

## Verification

Run with PHP's SQLite PDO extension enabled:

```
php -d extension=pdo_sqlite tests/document_price_template_test.php
php -d extension=pdo_sqlite tests/document_price_template_concurrency_test.php
php tests/document_endpoint_static_test.php
```

The tests cover portable validation, metadata-only listing, scope/kind isolation,
strict command authority, history, no-op saves, rollback, corruption rejection,
repeat installation and real concurrent-process CAS. Stage acceptance must also
verify the MySQL adapter, backup/integrity and authenticated HTTP boundary.
