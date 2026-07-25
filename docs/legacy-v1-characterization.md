# Legacy v1 storage and reference inventory

Date: 2026-07-25  
Mode: read-only code and production inspection

## Physical storage

The installer owns these Bitrix information blocks:

| Information block | Role | Outgoing element links |
| --- | --- | --- |
| `CALC_PRESETS` | Product-specific materialized calculator | stages, settings, materials and variants, operations and variants, equipment, details, custom fields |
| `CALC_DETAILS` | `DETAIL` or `BINDING` structure node | ordered stages and ordered child details |
| `CALC_STAGES` | Executable stage instance | one settings element, selected material/operation/equipment variants |
| `CALC_SETTINGS` | Mutable formula and parameter definition | used entities and custom-field declarations |
| `CALC_MATERIALS` | Material family | material variants |
| `CALC_MATERIALS_VARIANTS` | Material variant | catalog-backed values |
| `CALC_OPERATIONS` | Operation family | operation variants |
| `CALC_OPERATIONS_VARIANTS` | Operation variant | catalog-backed values |
| `CALC_EQUIPMENT` | Equipment | catalog-backed values |
| `CALC_CUSTOM_FIELDS` | Additional field declaration | no calculator-structure link |

Product and offer information blocks link a product to `CALC_PRESET`; the
calculator page starts from offer IDs, resolves their common product and then
loads its preset.

`PresetEnrichmentService` traverses details, stages and settings and writes the
flattened links on the preset. `DetailHandler::cloneDetail` recursively copies
details and stages and rewires `CALC_STAGES` and `DETAILS`. This is isolation by
physical copy, not reuse by reference.

## Executable property boundary

### Preset

- `CALC_STAGES`
- `CALC_SETTINGS`
- `CALC_MATERIALS`
- `CALC_MATERIALS_VARIANTS`
- `CALC_OPERATIONS`
- `CALC_OPERATIONS_VARIANTS`
- `CALC_EQUIPMENT`
- `CALC_DETAILS`
- `CALC_CUSTOM_FIELDS`
- `GLOBAL_VARIABLES`
- `GLOBAL_CONSTANTS`

### Detail

- `TYPE` (`DETAIL` or `BINDING`)
- ordered `CALC_STAGES`
- ordered recursive `DETAILS`
- described `PARAMETRS`

### Stage

- `CALC_SETTINGS`
- paired described `INPUTS` and `OUTPUTS`
- `SCHEME_PARAMETR_VALUES`
- versioned HTML `GLOBAL_ASSIGNMENTS`
- versioned `ACTIVATION_CONDITION`
- `OPERATION_VARIANT`
- `EQUIPMENT`
- `MATERIAL_VARIANT`
- described `CUSTOM_FIELDS_VALUE`
- serialized `OPTIONS_OPERATION`
- serialized `OPTIONS_MATERIAL`

### Settings

- version-1 HTML `LOGIC_JSON`
- described `PARAMS`
- `USED_ENTITYS`
- `CUSTOM_FIELDS`
- execution defaults
- `AI_CONTEXT_JSON` when it constrains a generated executable contract

## Reference forms

1. Bitrix element properties store decimal element IDs.
2. Formulas and source paths store stage aliases such as `stage_6298`.
3. Option mappings store numeric `variantId` values in JSON strings.
4. Current `elementsStore` paths can select by stable entity ID.
5. Older paths can contain collection indexes; migration keeps an unresolved
   index unchanged and never guesses its target.
6. Inputs may use `offer.*`, `product.*`, `globals.*`, another stage output, a
   custom-field description slot or a typed literal.
7. Output descriptions map formula symbols to stage result fields.
8. Global assignments refer to preset global codes and execute in stage order.

## Characterization fixtures

### Offset without globals

- preset: 6297, “Приглашения | Мелованная бумага | Офсет | Резка в формат”
- offer: 6296
- root detail: 6304 (`DETAIL`)
- stage IDs: 6298–6303
- global variables/constants: empty
- observed global assignments and activation values: empty

### Digital with globals and dynamic entities

- preset: 4592, “Листовая продукция с цифровой печатью и резкой в размер”
- observed offer: 10541
- nine stages, including paper preparation and digital printing
- material mapping selects variants by paper density
- operation mapping selects variants by density and color scheme
- activation contracts are present on stages 12628–12634
- global formulas use real `stage_<id>` aliases

### Nested binding

- preset: 1095, “Офсетная печать брошюры на пружине”
- binding detail: 1090, with its own assembly stages
- ordered children: 1089 (cover), 1091 (block), 1092 (backing)

### Cross-runtime numeric baseline

The paper-preparation logic evaluates 31 formula variables. The same fixture is
executed in `calcconfig` and `calc-server` and asserts, among other values:

- 24 items per print sheet;
- 43 production and 2 make-ready sheets;
- 45 total print sheets;
- 23 source sheets and 46 prepared sheets;
- 9 mm stack height and 2030.4 g stack weight;
- 460 purchasing cost and 644 base cost.

## Installed-copy evidence

The active installed files under `/bitrix/modules/prospektweb.calc` were
downloaded read-only and SHA-256-compared on 2026-07-25. The inspected
`InitPayloadService`, `ElementDataService`, `DetailHandler`,
`PresetEnrichmentService`, admin page and integration files exactly matched
this repository. The live calculator uses `/local/apps/prospektweb.calc` and
`/local/js/prospektweb.calc/integration.js` as installed module assets.

No runtime file, information-block schema or production record was changed
during Stage 0.
