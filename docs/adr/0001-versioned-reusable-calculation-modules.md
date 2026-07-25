# ADR-0001: Versioned reusable calculation modules

- Status: accepted for contract implementation
- Date: 2026-07-25
- Owner: `prospektweb.calc` (`admincalc`)
- Scope: Stage 0 architecture and compatibility boundary

## Context

The current calculator model is stored in Bitrix information blocks. A preset
links to details, stages, settings, catalog entities, custom fields and global
values. Details recursively link to child details and stages. Stages link to
`CALC_SETTINGS`, while instance-specific wiring and selections live on the
stage itself.

`CALC_SETTINGS.LOGIC_JSON` is a mutable version-1 formula document. `PARAMS`,
`INPUTS`, `OUTPUTS`, `USED_ENTITYS`, `CUSTOM_FIELDS`, defaults, activation
conditions, global assignments and selected catalog entities collectively
define executable behavior. A detail clone physically copies details and
stages and then rewires their Bitrix element links. `InitPayloadService`
materializes this graph as `elementsStore` for `calcconfig` and the runtime.

Production read-only inspection on 2026-07-25 confirmed that the installed
`prospektweb.calc` files match this repository byte-for-byte. Preset 4592,
used by offer 10541, contains nine stages and exercises formula v1, Russian
identifiers, stage aliases, material/operation selections, option mappings and
global formulas. It is the first confirmed golden fixture.

## Decision

### 1. System of record

`admincalc` owns the reusable-module contract and lifecycle. New data is stored
in dedicated D7 ORM tables owned by `prospektweb.calc`, not in required fields
added to the legacy information blocks:

- `b_pw_calc_module_family`
- `b_pw_calc_module_version`
- `b_pw_calc_module_instance`
- `b_pw_calc_module_snapshot`
- `b_pw_calc_module_audit`

The absence of a module-instance record means legacy v1. Legacy presets remain
readable and executable without conversion. `calcconfig` is the authoring
client and `calc-server` is a contract consumer; neither may silently redefine
the canonical schema.

### 2. Version unit

A reusable version is a semantic immutable snapshot of:

- formula logic from `LOGIC_JSON`;
- declared parameters from `PARAMS`;
- entity roles derived from `USED_ENTITYS`;
- custom-field declarations and execution-relevant defaults;
- symbolic input, output and global ports;
- child structure, dependency locks and executable tests.

`AI_CONTEXT_JSON` is included only when a field changes validation or runtime
meaning. Presentation-only hints are not part of executable content.
Publishing never mutates an existing version.

### 3. Module content versus instance binding

Reusable content contains symbolic local node IDs, formulas, ports, entity
roles, child order and dependency declarations. It never contains Bitrix
element IDs or `stage_<numeric-id>` aliases.

The instance binding retains all context-specific data:

- input/output source-path wiring;
- selected material, operation and equipment variants;
- `OPTIONS_MATERIAL`, `OPTIONS_OPERATION` and equivalent variant mappings;
- custom-field values;
- activation condition and global assignments;
- detail/stage placement, order and enabled state;
- preset/product sources and local Bitrix element IDs.

The resolver converts symbolic references to local stable bindings and emits a
fully locked snapshot. Published historical snapshots are never re-resolved.

### 4. Legacy identifiers

Current serialized identifiers include:

- `stage_<Bitrix element id>` in formulas and source paths;
- Bitrix element-link property values for details, stages and settings;
- numeric `variantId` values inside option-mapping JSON;
- stable entity IDs and, for older payloads, collection indexes in
  `elementsStore` paths;
- product and preset element IDs in calculator bindings.

Import extracts these into an instance binding. Export never guesses a mapping
and never rewrites an unresolved legacy index. Local IDs may appear only in the
instance and provenance sections, never in a reusable version.

### 5. Canonicalization and hashes

Contracts use RFC 8785 JSON Canonicalization Scheme (JCS). SHA-256 of the UTF-8
canonical JSON is written as 64 lowercase hexadecimal characters.

Canonicalization does not trim strings, normalize Unicode, reorder arrays,
reinterpret numbers, or rewrite formulas. Object-member order is canonical;
array order is semantic. A content hash covers the reusable module content,
ports, entity roles, dependency declarations and tests. Snapshot hashes cover
the complete resolved snapshot except the `snapshotHash` field itself.

### 6. Port types

The first contract exposes only types already supported consistently by all
three repositories:

- `number`
- `string`
- `boolean`

Bitrix `Y`/`N` normalization is allowed only for a declared boolean port.
Runtime arrays and objects remain internal expression values until separate
cross-runtime tests define their external-port semantics. Unit is optional
metadata in v1 and does not imply automatic conversion.

### 7. Transactions and rollback

Lifecycle writes use a Bitrix database transaction and compare-and-swap on the
expected revision and content hash. Versions, dependency locks, resolved
snapshots and audit rows are append-only.

Applying a module to a legacy preset is a later explicit operation:

1. lock the target instance revision;
2. capture the complete pre-change legacy snapshot;
3. resolve and validate the dependency DAG;
4. write the new materialization;
5. verify the stored hash and graph;
6. commit the transaction.

Any failure rolls the transaction back. A later rollback restores the captured
snapshot; it never recreates history by resolving a newer module version.

### 8. Rights

Legacy editors keep the existing `edit_catalog` behavior. Module lifecycle adds
separate rights for viewing, creating drafts, publishing, binding, migrating
and rolling back. A user who can edit a preset does not automatically gain
permission to publish reusable versions.

### 9. Golden fixtures

Confirmed:

- preset 6297 / offer 6296 — legacy offset-printing fixture with six stages,
  one simple detail (6304) and empty `GLOBAL_VARIABLES`,
  `GLOBAL_CONSTANTS`, `GLOBAL_ASSIGNMENTS` and activation values;
- preset 4592 / offer 10541 — digital sheet product with formula v1, globals,
  selected entities, dynamic material/operation mappings and stored activation
  contracts;
- preset 1095 — real nested brochure fixture. Root binding detail 1090 owns
  assembly stages and child details 1089 (cover), 1091 (block) and 1092
  (backing);
- paper-preparation formula — exact 31-variable numeric fixture executed in
  both `calcconfig` and `calc-server`;
- synthetic nested `BINDING` fixture — preserves child and stage order while
  retaining real-shaped Bitrix IDs only in provenance.

The golden manifest uses 6297 for no-globals offset compatibility, 4592 for
globals, dynamic mappings and conditional-stage compatibility, and 1095 for
nested structure. Stage 3 uses the digital-print logic from 4592 as the first
stage-module candidate. A second product scenario must be selected from the
existing products attached to that preset during the pilot inventory; no
production record is created merely to satisfy the pilot.

## Rejected alternatives

- Adding required version fields to existing information blocks: this risks
  changing legacy reads and installed schema behavior.
- Treating `CALC_SETTINGS` alone as the reusable unit: executable behavior is
  split across settings, stage wiring and instance selections.
- Hashing `JSON.stringify` output: object insertion order is not a portable
  cross-language contract.
- Replacing IDs inside formulas by regular expression: unresolved or quoted
  values can be corrupted silently.
- Floating version ranges in a resolved calculator: historical calculations
  would cease to be reproducible.

## Consequences

Stage 1 can implement schemas, canonicalization, hash validation, DAG
resolution and pure compatibility tests without changing production storage.
Stage 2 may add the D7 tables and lifecycle API. Existing presets continue on
the legacy path until an explicit, reversible migration produces an instance
record and verified resolved snapshot.
