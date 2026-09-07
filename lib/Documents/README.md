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

## HTTP access

`/bitrix/tools/prospektweb.calc/documents.php` accepts admin-only POST requests
with the normal Bitrix `sessid` and `payload` form fields. Payload is
`{siteId, command}`. The server checks the site; actor identity is never accepted
from the request. Commands reject unknown fields; writes require exact CAS.
This is an internal adapter, not an unauthenticated third-party API.

## Persistence invariants

Three InnoDB tables hold indexed metadata/current and active pointers
(`b_pw_calc_document`), immutable canonical bodies (`b_pw_calc_revision`), and
immutable resource-fixed snapshots (`b_pw_calc_publication`). Reads check
stored hashes. No-op saves do not manufacture revisions; lists do not read
graph bodies. A database administrator is still privileged to alter data:
own tables protect against ordinary iblock editing, not against root access.

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

An immutable **core snapshot is not public-site activation**. FrontCalc,
catalog assignments and basket price/provenance authority still use the old
publication. Do not enable a public cutover or delete service iblocks until
those consumers and their locked authority checks have been migrated together.
The UI states this limitation explicitly. No compatibility fallback is used
inside the new core.

Run all `tests/*test.php` in separate PHP processes with `pdo_sqlite` enabled.
The document tests cover concurrent writes, immutable publications, scoping,
CAS after remote compilation, recoverable archive/restore and adapter modes.
