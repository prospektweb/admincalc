# Commercial policies v1 (SPM-03)

The commercial route is opt-in by contract, not a reinterpretation of an old
calculator or quote. Working enrollment is disabled. Old documents, history,
preparation keys, deadline effort percentages and `prepareQuote` retain their
original reader and arithmetic.

## Wire and authority

`prospektweb.orderterms.policy-bundle/v1` binds a document SHA-256, ownerVersionId,
calculator policy, storefront policy, scenario-policy references and typed global
passport. Policies use `prospektweb.orderterms.authoring-policy/v1`. Every scalar
has `{mode:inherit}` or `{mode:own,value:ValueSource}`; windows and price grids are
whole-table overrides. `parentRef` pins id/revision/bodyHash. `bodyHash` hashes the
canonical body excluding that field. Canonical object keys are sorted; list order
is retained. No reads perform an upgrade.

ValueSource is literal or globalRef with explicit duration/minute, percent/percent,
or money/ISO currency. Global references pin stable globalId, code and owner version.
The passport binds native number/boolean types and a production phase. Globals have
to exist and be produced in this execution: a declared but unexecuted stage output
cannot masquerade as the legacy engine's initial zero. Numeric execution remains
the explicitly bounded legacy-number adapter; monetary wire values are decimal
strings, including very small values, not exponent strings. Final rounding belongs
to Bitrix. The positional multiplication is decimal and happens after rounding.

The shared portable resolver is `calc-server/src/core/commercialPolicy.ts`, mirrored
byte-for-byte to `calcconfig/src/lib/orderterms-policy.ts`. It contains no calendar,
clock or expression evaluator. `commercialExecution.ts` compiles and runs the
existing technical engine. It never runs the legacy deadline/price profile processor.

## Scenarios

The existing presentation scenario registry remains the only condition registry.
v1 is unchanged. v2 adds an explicit `phase:form|postProduction`; a postProduction
scenario must have an empty field_patches object. Its condition may reference
`global.<globalId>` in the pinned passport. Form conditions and patches keep their
existing semantics; the projector skips postProduction rules.

PHP `StorefrontScenarios::matchedCommercialContext` uses the same
CalculatorConditionResolver as the form. Its input is the reconciled map of stable
field/option IDs and server-produced globals. Policy writers only refer to registry
IDs and must match its enabled/priority metadata. Multiple form matches remain
composable. The highest-priority commercial writer is unique; duplicate priorities
are rejected at compilation. Migrated writers carry sourceProfileId; multiple
matching legacy profiles remain a conflict, even with different priorities.

`CommercialPolicyService::executeContext` captures capabilities/fingerprint, obtains
production globals, evaluates the registry, then executes the commercial result.
Both technical runs use the same frozen source/resources/inputs; technicalHash,
bundleHash and runtimeFingerprint must agree. No desired date or commercial price
is inserted back into the production context. This currently costs two deterministic
technical executions; it avoids retaining private execution state between requests.

## Commands and preview

Existing HMAC-only `/v1/calculators/commands` adds commercialCapabilities,
commercialValidate, commercialProduction, commercialExecute and commercialUpgrade.
Production/execute require expectedRuntimeFingerprint. Errors carry a specific
code/path. Unknown contracts/fields fail closed. The fingerprint covers actual
executable files, package-lock bytes and Node version; Node version is returned for
reproduction from the delivered LF build.

CommercialPolicyQuote finalizes three variants using the single orderterms PHP
Calendar. It verifies the pinned calendar, uses explicit anchor/asOf, rejects an
archived calendar, and rechecks the rounded working-minute window. Disabled/NO_SLOT
variants have no price. It filters server-allowed catalog groups, invokes the native
Bitrix rounder for one run, snapshots its rules and multiplies layoutCount once.
The quote-preview fingerprint pins policies, globals/input hashes, runtime, calendar
results, rounding rules, type bindings and the result. This is preliminary preview,
not a basket command, order promise, client consent or expiring public quote.

CommercialPolicyCatalogAdapter projects PRICE=roundedPerRun and QUANTITY=layoutCount
under an exact quote fingerprint. It produces a disabled write plan; no catalog or
basket mutation is performed. Public enrollment/CAS/TTL/consent belong to later
program stages. B01–B10 remain unset.

## Explicit migration

commercialUpgrade returns a receipt containing exact source/sourceHash, a separate
candidate, full copied grid, source profiles, unresolved paths and receiptHash.
There is no effortPercent-to-duration conversion. The caller supplies the complete
new terms and calendar/start refs. Nullable or mismatched money currencies require
an explicit binding. Templates are copied, never followed as mutable references.

Profiles require a reviewed map to existing v2 postProduction registry IDs and
typed boolean globals. Conditions/enabled state must match; unresolved or ambiguous
maps are not ready. After review, compile and preview the new version before an
explicit save. A receipt does not itself publish or alter source/history.

## QA publication and rollback

The authenticated admin documents endpoint also exposes `commercialPolicies`,
`loadCommercialPolicy(id,revision)`, `createCommercialPolicy` and
`saveCommercialPolicy`. Mutations require documentId/versionId/expectedSourceHash,
bundleJson and expectedCatalogRevision; create adds name, save adds id and
expectedRevision. Identity/site scope come from the adapter. The server loads the
owned version, freezes resources and validates the bundle through the HMAC core.
DocumentLibrary kind `commercial-policy` stores immutable policy-record/v1 bodies
containing exact sourceJson, sourceRevision/hash, bundle, resources and validation.
CAS rejects concurrent writes. Ownership cannot change on save. Historical reads
return stored bytes without compiler/resource calls. A later source edit does not
change this pinned revision; callers must explicitly author a new revision.
This adds no schema, active pointer or working percentages. SPM-04 can bind its
editor to this API; site publication must continue to respect the disabled cohort.

`/bitrix/admin/prospektweb_calc_commercial_policy.php` is admin-only, POST+CSRF for
actions. It can preview a JSON request and store a separate immutable QA publication.
The server rebuilds validation/preview before save. QA IDs start spm03_qa_; the record
pins the exact packet bytes and retains original actor/time on retries. Changed
content under an existing ID is rejected. The QA store is deliberately limited to
60 KB and is not a replacement for the normal calculator version store.

The diagnostic fixture is explicitly synthetic. It references its own calendar,
never the archived SPM-02 calendars, and binds the existing base catalog group
without creating or changing a price type. No product, basket, order or payment is
created. The common full editor in SPM-04 should call the service with actual owned
version data and authoritative form mapping, not expose these diagnostic assumptions
as working defaults.

Rollback stops new use and restores only owned code with verified backups. Keep QA
records and additive calendar versions for audit; do not mutate legacy history.
Once later stages create new-generation baskets/consents, retain compatible readers
and follow the program's generation-aware rollback instead of mass legacy conversion.
