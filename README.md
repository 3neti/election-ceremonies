# Election Ceremonies

Research source snapshots use `coverage: source_snapshot` and `readiness: research`. Their signed manifest includes POP and CLC catalog hashes, and the ZIP includes `reports/source-snapshot.json`. The verifier checks the report against the manifest. The commissioner refuses research or pilot bundles for election-day runs. National coverage and election-day readiness remain unavailable until a complete reconciliation and authority review workflow is implemented.

`3neti/election-ceremonies` provides a country neutral, offline ceremony runner and signed election bundle format. It depends on `3neti/truth-qr` for QR record projection. Election rules, registry importers, and ceremony profiles belong in a country package; application screens, devices, storage paths, and officer authentication belong in the host.

## Election bundle v1

An election bundle is a ZIP with `election.sqlite`, `profiles/ceremonies.yaml`, `profiles/truth-qr.yaml`, `manifest.json`, and `manifest.sig`. The manifest lists every file's byte length and SHA-256 digest, a logical SQLite digest, election and bundle IDs, and `pilot` or `national` coverage. `manifest.sig` is an Ed25519 signature over the exact manifest bytes. The verifier rejects missing, extra, duplicate, unsafe, or changed entries, checks SQLite integrity and logical content, and validates both profiles. The authority public key must already be trusted on the offline device.

The site assignment is a separately signed JSON document binding the bundle archive digest to one device, role, jurisdiction, permitted sources, and ceremony IDs. Commissioning verifies both signatures and hashes before staging read-only bundle files. A pilot bundle is rejected when the run type is `election_day`.

The bundle is immutable. Corrections require a new bundle ID and signature. Local results, audit events, and ceremony state are outside the bundle. The authority private key is supplied explicitly to the builder and must remain on an offline authority machine; it is never derived from an application key.

## Ceremony pipeline

`CeremonyProfile::fromYaml()` loads named stages and transitions. `CeremonyRunner` checks commissioned bundle and assignment identities, actor authorization, current stage, and idempotency before running registered `CeremonyPipe` implementations in profile order. Every required evidence item must exist before the transition is committed. `FileCeremonyStore` uses a lock and an append-only hash-linked event sequence. Hosts may implement `CeremonyStore` for another durable backend.

`EmlProjectionPipe` uses a host `EmlProjector` and requires schema and signature validation. `TruthQrProjectionPipe` uses Truth QR's `RecordService` and `RecordPayload` contract. When a profile lists `eml_export` before `truth_qr_encode`, the verified EML reference enters the working record before QR encoding. Hosts register both pipes and supply the record, profiles, and signing policies. A printed QR signature and the authority's bundle signature serve distinct purposes.

## Pilot status

The current implementation supports local pilot packaging and verification. Nationwide deployment requires a complete POP/LOC source, reconciled jurisdiction coverage, production ceremony pipes and actor policies, provisioning of authority trust keys, and full device commissioning tests. The Philippine pilot profile is intentionally marked `pilot` and must not be used for election-day commissioning.
