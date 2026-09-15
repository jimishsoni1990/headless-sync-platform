#!/usr/bin/env node
/*
 * OpenAPI 3.1 meta-schema validator — the authoritative ADR-055 (f)(2) gate (OAPI-S1).
 *
 * WHY NODE / ajv (ruling D, v1.29): opis/json-schema 2.6.0 has two reproduced JSON Schema 2020-12
 * conformance defects that make it unable to validate real OpenAPI 3.1 documents against the
 * official meta-schema — (1) it does not index the `$dynamicAnchor: "meta"` so `$dynamicRef "#meta"`
 * mis-resolves to the document root; (2) `unevaluatedProperties` reports schema-declared property
 * names that are absent from the instance. `schema-base` does not avoid `$dynamicRef` (it overrides
 * the anchor, so defect #1 persists), and no conformant 2020-12 PHP validator exists. The Node
 * toolchain is already a sanctioned dev/CI dependency (DECISION W (a)); ajv-2020 is differential-
 * verified against a conformant reference implementation on valid and invalid documents.
 *
 * PINNED FIXTURE (never fetched at runtime): tests/fixtures/openapi-3.1-meta-schema-pinned.json —
 * the official OAI 3.1 meta-schema ($id https://spec.openapis.org/oas/3.1/schema/2022-10-07,
 * source OAI/OpenAPI-Specification tag 3.1.1) with exactly FOUR semantics-preserving edits:
 * every `{"$dynamicRef": "#meta"}` → `{"$ref": "#/$defs/schema"}`. Equivalent because the fixture
 * validates as its own root resource, so no outer dynamic scope can retarget the `meta` anchor —
 * `$dynamicRef "#meta"` can only ever resolve to `$defs/schema`, which the `$ref` names directly.
 *
 * USAGE:
 *   node validate-openapi.mjs <document.json>     # validate a document at a path
 *   node validate-openapi.mjs                     # or read the document from stdin
 *   node validate-openapi.mjs --schema <schema.json> <instance.json>
 *                                                 # CCF-003: validate ONE response body against a
 *                                                 # schema taken from the generated document
 * EXIT:
 *   0 = document is VALID.
 *   1 = document is INVALID (ajv errors printed as JSON to stdout).
 *   2 = INFRASTRUCTURE error — missing deps (ajv not installed / `npm install` not run), missing
 *       fixture, unreadable/malformed input (message to stderr). Distinct from 1 so the caller can
 *       treat "gate could not run" as unavailable (skip/fail per the env contract), never as an
 *       invalid document.
 */

const EXIT_VALID = 0;
const EXIT_INVALID = 1;
const EXIT_INFRA = 2;

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

// The pinned meta-schema lives under tests/fixtures/ (…/tools/openapi-validator → …/tests/fixtures).
const METASCHEMA_PATH = resolve(__dirname, '..', '..', 'tests', 'fixtures', 'openapi-3.1-meta-schema-pinned.json');

class InfraError extends Error {}

function readStdin() {
  try {
    return readFileSync(0, 'utf8');
  } catch {
    return '';
  }
}

function loadJson(path, label) {
  let raw;
  try {
    raw = path ? readFileSync(path, 'utf8') : readStdin();
  } catch (err) {
    throw new InfraError('cannot read ' + label + ': ' + err.message);
  }
  if (!raw.trim()) {
    throw new InfraError('no ' + label + ' provided (pass a path or pipe JSON on stdin)');
  }
  try {
    return JSON.parse(raw);
  } catch (err) {
    throw new InfraError(label + ' is not valid JSON: ' + err.message);
  }
}

function loadDocument() {
  return loadJson(process.argv[2], 'document');
}

async function loadAjv() {
  // Dynamic import so a missing dependency (npm install not run) is an INFRA error (exit 2), not a
  // document-invalid result (exit 1).
  try {
    const { default: Ajv2020 } = await import('ajv/dist/2020.js');
    const { default: addFormats } = await import('ajv-formats');
    return { Ajv2020, addFormats };
  } catch (err) {
    throw new InfraError(
      "ajv not resolvable — run `npm install` in tools/openapi-validator/ (" + err.message + ')',
    );
  }
}

/**
 * INSTANCE MODE (CCF-003) — `--schema <schema.json> <instance.json>`.
 *
 * A SEPARATE question from the meta-schema gate, and deliberately kept so. The meta-schema gate
 * asks "is openapi.json a valid OpenAPI 3.1 document?"; this asks "does this actual response body
 * conform to the schema that document publishes for that operation and status?". Documenting an
 * idealised error envelope the runtime does not emit is precisely the failure CCF-003 forbids, so
 * the runtime payload is validated against the GENERATED schema rather than against a fixture.
 *
 * Same ajv, same exit codes; the meta-schema path is untouched and neither weakens the other.
 */
async function validateInstance() {
  const schemaPath = process.argv[3];
  const instancePath = process.argv[4];

  if (!schemaPath) {
    throw new InfraError('--schema requires a schema path');
  }

  const schema = loadJson(schemaPath, 'schema');
  const instance = loadJson(instancePath, 'instance');
  const { Ajv2020, addFormats } = await loadAjv();

  const ajv = new Ajv2020({ strict: false, allErrors: true });
  addFormats(ajv);

  const validate = ajv.compile(schema);

  if (validate(instance)) {
    process.exit(EXIT_VALID);
  }

  process.stdout.write(JSON.stringify(validate.errors ?? [], null, 2) + '\n');
  process.exit(EXIT_INVALID);
}

async function main() {
  if (process.argv[2] === '--schema') {
    return validateInstance();
  }

  let metaSchema;
  try {
    metaSchema = JSON.parse(readFileSync(METASCHEMA_PATH, 'utf8'));
  } catch (err) {
    throw new InfraError('cannot read pinned meta-schema fixture: ' + err.message);
  }

  const document = loadDocument();
  const { Ajv2020, addFormats } = await loadAjv();

  // strict:false — the OAS meta-schema uses keywords (e.g. example, discriminator) ajv would warn
  // about under strict mode; we validate against the schema as published, not ajv's stricter dialect.
  const ajv = new Ajv2020({ strict: false, allErrors: true });
  addFormats(ajv);
  // The OAS meta-schema references a "media-range" format ajv-formats does not define; register it
  // as permissive so ajv does not emit an "unknown format ignored" warning to stderr.
  ajv.addFormat('media-range', true);

  const validate = ajv.compile(metaSchema);
  const valid = validate(document);

  if (valid) {
    process.exit(EXIT_VALID);
  }

  process.stdout.write(JSON.stringify(validate.errors ?? [], null, 2) + '\n');
  process.exit(EXIT_INVALID);
}

main().catch((err) => {
  // Any error reaching here is infrastructural (bad input, missing deps/fixture) — exit 2 so the
  // caller treats "the gate could not run" as unavailable, never as an invalid document.
  const message = err && err.message ? err.message : String(err);
  process.stderr.write('validate-openapi: ' + message + '\n');
  process.exit(EXIT_INFRA);
});
