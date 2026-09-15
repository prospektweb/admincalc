# SPM-04 internal commercial preview

The authenticated documents adapter accepts `previewCommercialPolicy` with documentId/versionId/expectedSourceHash, bundleJson, native values/execution/activation, and an explicit RFC3339 anchor including seconds. It loads the owned version and resources server-side, checks native form/scenario inputs, runs the SPM-03 service, reads pinned orderterms calendar and settings refs and finalizes through the existing PHP calendar and Bitrix rounding. Price bindings come from the version connection; no diagnostic base-price fallback is used. A final source hash check rejects concurrent changes.

This administrator-only preview exposes three variants and provenance. publicationValidated=false and enrollmentEnabled=false remain explicit. No basket/catalog/order mutation, TTL assertion, payment or notification occurs. Generic policy authoring is still saved through CommercialPolicyApplication's immutable library and cannot activate a working policy.
