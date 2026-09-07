# Document persistence

Foundation checkpoint, 2026-09-07. Not yet the storage used by the public editor.

`DocumentRepository` stores canonical calculator documents, immutable revisions
and compiled publications. It does not depend on Bitrix. `SqlConnection` is the
infrastructure boundary; `BitrixConnection` uses the existing CMS connection,
while `PdoConnection` supports standalone MySQL and SQLite transactional tests.

Three InnoDB tables:

- `b_pw_calc_document`: indexed list metadata and current/active pointers;
- `b_pw_calc_revision`: immutable document bodies, hashes and author metadata;
- `b_pw_calc_publication`: immutable compiled snapshots with fixed resources.

Writes own their transaction, lock the document and require an expected
revision. Publication additionally requires the expected active pointer and
the hash of the document used by the compiler. Reads verify stored hashes.
No-op saves do not create artificial revisions. Lists never load graph bodies.

The repository is deliberately NOT an HTTP API, permission system or domain
validator. Callers must authenticate, authorize, validate/compile using the
shared core, and pass trusted scope/actor values. There is currently no general
write endpoint. CLI import only accepts the explicitly pinned pilot document.
Own tables prevent ordinary iblock administration from editing internal nodes;
they do not prevent a privileged database administrator from changing data.

Install explicitly with `DocumentSchema::install`; never invoke DDL from an
ordinary page request. Initial schema installation is additive and idempotent.
Future schema versions require explicit migrations. No uninstall/drop procedure
is supplied: site data must not be deleted implicitly with module code.

Local transactional test (including two concurrent writer processes):

```text
php -d extension=pdo_sqlite tests/document_repository_test.php
```

For the pilot, `tools/document-migration/export-pilot.php` takes a read-only source
backup outside the web root. The Node importer validates and canonicalizes it.
`install-pilot-draft.php --inspect` reports targets; `--apply` imports only a
hash-pinned draft. It does not publish the new document or edit/delete the old
iblock graph. An existing different document is never overwritten automatically.

Still required: application-command/ACL integration, compiler/publication
integration, editor switch, regular module installer integration and removal of
obsolete iblock code after end-to-end acceptance. The standalone PDO adapter
shows storage portability, not completion of integration with another CMS.
