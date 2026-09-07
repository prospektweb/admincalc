# Explicit sheet-print pilot import (2026-09-07)

These CLI commands are **not installer seeds** and are not generic migrations.
They operate only on the isolated 72.56.248.195 site, `/home/bitrix/www`,
with its own `/etc/prospekt-calc-stage/service.env` and private input directory.
Never copy that isolation marker to production or run these commands there.

Prerequisites: native AdminCalc/FrontCalc install, six empty resource catalogs,
catalog pair 14/15, isolated signed calculation service at
`https://127.0.0.1:3443`, a verified private database/files backup.
Source JSON is intentionally not committed. The import checks exact hashes of
`pilot-document.json`, `pilot-resources.json`, `pilot-extra-equipment.json`;
the product setup checks `pilot-integration.json`.

Run with `php <command> /home/bitrix/www <private-directory>`:

1. `import-staging-pilot.php`: explicitly imports 68 execution resources,
   four suppliers and the extra 3070L compatibility reference; remaps native IDs,
   validates the document and creates it without overwriting an existing draft.
2. `verify-staging-pilot.php`: compares signed preview/execute against three
   accepted base-price cases and checks invalid geometry and unchanged document.
3. `prepare-staging-product.php`: creates one test section/product and saves the
   site connection. Publication remains an explicit administrative UI action.
4. `prepare-staging-prices.php`: adds the independently verified native catalog
   rounding rules (up to 10 RUB from 100 RUB) for mapped price groups 1/9/10/11.
   Refuses conflicting existing rules; repeat execution adds nothing.

The ID maps and diagnostic reports stay outside the web root. Database
transactions protect each catalog batch, but filesystem map creation follows
commit: interruption between commit and map write requires manual reconciliation.
Do not clear data or retry through the guards. Resources are reference content;
their import does not establish user-authored administrative UI acceptance.

The public golden case is 90 × 50 mm, digital 4+0, matte coated paper 150 g/m²,
one layout, strict deadline: 100 → 200 → 100 copies = 610 → 800 → 610 RUB.
Rounding is currently owned by the Bitrix catalog adapter, not the portable core.
