# Explicit document migration commands

These commands are CLI-only and must stay outside the public document root.
They do not expose an admin HTTP API or switch the existing pilot publication.

- `export-pilot.php`: independent source backup (see actual CLI arguments).
- `install-pilot-draft.php`: inspect or create the initial unpublished rev1;
  refuses an existing different document. Not an updater.
- `verify-pilot-core.php <site-root> <private-import-directory> --verify`:
  sign four technical preview cases with the installed site's existing signer,
  compare complete local/live results, verify unsigned/replay rejection and
  the unchanged source publication hash. Required input files are canonical
  document, resourceSnapshots.json and checks.json from Calc Server scripts.
- `--save-revision-2`: only after the same live checks succeed, CAS-save the
  pinned replacement of the unpublished initial draft. Exact document ID,
  source hash, old hash and new hash are pinned in the command. The old revision
  remains in SQL history and a verified private file backup. All other current
  states reject; repeated identical rev2 is a no-op. It does not publish.

No secret, signature or complete resource payload is printed. HTTPS uses
certificate verification, no redirects and a fixed existing service origin.
Keep the import, checks, report and SHA-256 evidence private. Core verification
is technical-stage acceptance, not commercial-price or editor acceptance.
