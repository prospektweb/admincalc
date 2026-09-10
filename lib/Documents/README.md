# Calculator documents

The application stores one portable authoring document per immutable revision.
`DocumentRepository` is scoped by a trusted site and actor. Its SQL adapter has
no iblock dependency; `PdoConnection` is also used by the concurrency tests.

`DocumentApplication` owns create/save/history/restore/archive/preview/publish
commands. Validation and compilation run through the shared core. Compilation
and catalog snapshots happen outside the write transaction; publication then
checks both the authoring revision and active pointer under a row lock.

`BitrixResourceProvider` snapshots explicitly referenced, active resource
catalogs in a repeatable-read transaction. It translates legacy resource field
encodings and PRC/MRG price modes only at this boundary. The executor never
reads iblocks. `BitrixCoreGateway` is the authenticated HTTPS adapter.

## Native catalog input and mutation helpers

`BitrixDocumentCatalogWritePort` composes the real property snapshot, native
input builder and state writer. It captures the immutable site publication,
module registration, complete FrontCalc settings/revision, provider, active
site, catalog pair, exact V1/V2 SKU parent, native product/presentation binding,
owned price groups and required currency/rate rows on the same connection.
The shared mapping validator and builder consume only that captured data.
Apply uses locking reads, including missing-value/range gaps, and requires
InnoDB across the authority chain. Source fingerprints exclude intended owned
catalog changes, but retain unrelated price/product state. The coordinator
still owns authentication, remote calculations, rollback and receipts.

The admin-only document HTTP endpoint exposes `previewCatalogWrite` and
`applyCatalogWrite` through the real port on one shared connection. The
trusted site, actor and provider never come from command fields. The original
UI dialog is not yet connected. The port's standalone SQLite integration
tests use real SQL and the real capture/write/coordinator code, with injected
input/settings/mutation infrastructure seams; stage guard checks must not be
misrepresented as successful pilot SKU writes. Connect the original preview/
apply dialog only after the application boundary and real pilot acceptance.

`BitrixCatalogPropertySnapshot` captures exact mapped input properties in bounded
SQL batches, independent of the number of selected elements (up to 100 per
scope). It supports iblock V1, V2 single and V2 multiple storage, list choices
and registered Highload directories. Property identity, active dates, schema,
enum XML_IDs, directory registration/fields and raw input rows contribute to a
fresh fingerprint. Invalid, stale or ambiguous values fail closed; genuinely
empty sources retain their schema. Its projection feeds the existing mapping
validator and `DocumentCatalogInputBuilder` without introducing a preset ID.
Locked capture uses the same repeatable-write transaction and FOR UPDATE,
including empty ranges; every participating table must be InnoDB. It never
starts/ends a transaction, uses a cached property API, or changes site data.
The caller still owns catalog pair/SKU membership, settings, publication,
currency/price authority and the eventual preview/apply wiring.

`BitrixCatalogStateWriter` is the mutation half of the native catalog write port.
It requires an outer coordinator transaction and, for the real Bitrix API path,
the exact default connection in explicit repeatable-write mode. It locks and
reads product/price rows directly, validates every target before the first API
call, writes only purchasing price, four dimensions and explicitly owned price
types, then performs readback. Unowned prices, stock, measure and existing range
IDs/metadata are protected. No-op targets do not invoke write APIs. Failure must
propagate to the outer coordinator, which rolls back the whole batch and receipt.
The helper never commits or starts a transaction. Ordinary document connections
retain the host isolation default; only the opt-in catalog connection changes the
next write transaction to repeatable read, not the session default.

This helper is not a standalone authorization boundary. The catalog port must
still validate and lock publication/product membership, source properties,
enum/directory schema, settings, currencies and price-type authority. That full
read port and HTTP wiring are implemented, but pilot write/UI QA remains in
progress; do not remove the native
`writeback_unavailable` warning merely because a mutation helper exists.

## HTTP access

`/bitrix/tools/prospektweb.calc/documents.php` accepts admin-only POST requests
with the normal Bitrix `sessid` and `payload` form fields. Payload is
`{siteId, command}`. The server checks the site; actor identity is never accepted
from the request. Commands reject unknown fields; writes require exact CAS.
This is an internal adapter, not an unauthenticated third-party API.

## Persistence invariants

The InnoDB tables hold indexed metadata/default-head and core pointers
(`b_pw_calc_document`), immutable canonical bodies (`b_pw_calc_revision`), and
immutable resource-fixed snapshots (`b_pw_calc_publication`). Reads check
stored hashes. No-op saves do not manufacture revisions; lists do not read
graph bodies. A database administrator is still privileged to alter data:
own tables protect against ordinary iblock editing, not against root access.

`b_pw_calc_version` stores named branch metadata and immutable revision heads.
Cloning shares immutable body/connection bytes until the first edit. A common
document row lock serializes global revision allocation; each save compares
only its branch head, so independent versions can be edited concurrently.
Registry mutations compare `versions_revision`; renaming does not change body
hashes. Version numbers are never reused. Deletion tombstones the branch; old
revisions/publications remain available for audit and existing calculations.
The deployed branch cannot be hidden or deleted. `current_revision` is the
default branch head, not the largest revision across every branch.

`b_pw_calc_site_publication` contains immutable full site snapshots; the
`b_pw_calc_site_active` pointer records both publication and named version.
Different versions sharing identical bytes still have one explicit active
version. Activation checks branch head, registry revision and publication
pointer under one lock, then updates product bindings atomically. Its time
and actor describe the activation event, not the creation of a reused snapshot.
`b_pw_calc_product_binding` is a rebuildable projection; `b_pw_calc_site_identity`
holds a stable numeric registry/public route ID, never an iblock element ID.
New calculators receive it in their creation transaction, before publication.
Schema v8 fills only missing identities in creation order; issued IDs and UUID
bindings remain unchanged. Registry search accepts an exact numeric ID or a name.
Registry activity means enabled with an existing site publication. An unpublished
or disabled calculator is inactive; status filters use the same rule.

`b_pw_calc_catalog` and `b_pw_calc_section` own scope-local tree metadata and
its independent CAS revision. Placement edits do not mutate calculator bodies.

`DocumentSchema::install` runs explicitly in the module installer, never from
an ordinary page request. It is additive/idempotent. Future versions need
explicit migrations. Uninstall must not implicitly drop site data.

The original `export-pilot.php` / `install-pilot-draft.php` commands remain
historical one-time import tools with pinned hashes and private backups. They
are not read-time compatibility adapters. The workbench verifier similarly
refuses an unknown revision rather than overwriting the user's edits.

## Release boundary (2026-09-07)

The new workbench is available in the authorized Control Center with
`?document_preview=Y`. Normal entry remains on the existing workbench unless
`DOCUMENT_EDITOR_ENABLED=Y` is configured. Configure `DOCUMENT_SITE_ID` and
`DOCUMENT_RESOURCE_PROVIDER` before use; these are adapter settings, not
document storage.

An immutable **core snapshot is not public-site activation**. Native FrontCalc
uses the explicit site snapshot through the document adapter. The stage pilot
has migrated public bindings and calculation authority; this does not authorize
production cutover. Version UI uses `versions/loadVersion/createVersion`,
`saveVersion/saveVersionConnection/restoreVersionRevision/previewVersion`, and
`renameVersion/archiveVersion/deleteVersion/activateVersion`, without calling
legacy iblock mutation endpoints. Full editor UI parity is still in progress.
No compatibility fallback is used inside the new core.

Run all `tests/*test.php` in separate PHP processes with `pdo_sqlite` enabled.
The document tests cover concurrent writes, immutable publications, scoping,
CAS after remote compilation, recoverable archive/restore and adapter modes.

## Native catalog write boundary (in progress, 2026-09-08)

`DocumentCatalogWriteService` owns a server-only preview/apply protocol. Its
request contains a document UUID, exact site publication, offer IDs and (for
apply) the preview fingerprint. It never accepts client prices, calculation
inputs, provider settings or actor identity. Remote `executeBatch` requests
are bounded to 20 offers and run outside SQL transactions. Results for every
target must succeed before one atomic catalog write can begin.

`DocumentCatalogWritePlan` preserves the original seven output mappings and
six visible diff fields. Catalog purchase cost is the quote's `basePrice`, as
in the existing site adapter, not its direct `purchasingPrice`. Price amounts
use the confirmed Bitrix DECIMAL(26,8) boundary. All bound price types/ranges
and four dimensions must be complete; unrelated price types are preserved.
Names and arbitrary iblock properties are not write targets.

The schema-v5 `b_pw_calc_catalog_write` table contains immutable operation
receipts, not editable calculator entities. The receipt and catalog values
commit together only after exact readback. Receipts retain native publication
identity, resolved inputs/execution, result hashes and before/after diffs.
Replay verifies the current publication, inputs and catalog state under the
same locks and never performs a duplicate write. Reinstall preserves receipts.

The **Bitrix catalog port and HTTP commands are implemented; UI wiring and
pilot write QA are not connected/completed yet**.
`DocumentCatalogWritePort` specifies the adapter obligations:
fresh provider/catalog identity, exact product/offer relation and active
binding, semantic input mappings/defaults/conditions, all source/schema/enum
and price insertion-gap locks, no cached locked reads, and the same SQL
connection for every write. The SQLite fixture proves coordinator rollback,
CAS, scope, replay, batch completeness and readback behavior; it does not
prove the Bitrix adapter. Do not remove `writeback_unavailable` or enable the
UI's write action until that adapter and authenticated catalog QA are complete.
