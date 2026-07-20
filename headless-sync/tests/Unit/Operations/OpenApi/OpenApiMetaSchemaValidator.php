<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

/**
 * OAPI-S1 / ADR-055 (f)(2) meta-schema validation support (NOT a test case — a helper the
 * OpenApiDriftGuardTest uses).
 *
 * Two layers, in order:
 *
 *   1. Structural pre-check (PHP, ALWAYS runs) — asserts the OpenAPI 3.1 invariants the HSP
 *      generator emits, with human-readable failure messages so a broken generator fails with a
 *      precise diagnostic before the (opaque) meta-schema validator runs. Permanent fast-fail.
 *
 *   2. Meta-schema gate (AUTHORITATIVE) — validates the document against the OFFICIAL OpenAPI 3.1
 *      meta-schema via the Node ajv validator (tools/openapi-validator/validate-openapi.mjs) over
 *      the pinned fixture. This is the assertion ADR-055 (f)(2) requires; the structural pre-check
 *      does not replace it.
 *
 * WHY NODE / ajv (ruling D, v1.29): opis/json-schema 2.6.0 has two reproduced 2020-12 conformance
 * defects (dynamic-anchor indexing; unevaluatedProperties false-positives) and no conformant PHP
 * 2020-12 validator exists; the Node toolchain is already a sanctioned dev/CI dependency
 * (DECISION W (a)). The gate shells out to node; the environment contract for a missing node
 * runtime is enforced by the caller (see gateStatus()).
 */
final class OpenApiMetaSchemaValidator
{
    /** @var list<string> valid OpenAPI Path Item Object HTTP method keys. */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** Meta-schema gate outcomes. */
    public const GATE_VALID   = 'valid';
    public const GATE_INVALID = 'invalid';
    public const GATE_SKIPPED = 'skipped'; // node unavailable AND HSP_REQUIRE_NODE_GATE unset

    public function __construct(
        private readonly string $validatorScript,
    ) {}

    /**
     * Structural pre-check (layer 1). Returns a list of human-readable violations; empty = pass.
     *
     * @param array<string,mixed> $document
     * @return list<string>
     */
    public function structuralViolations(array $document): array
    {
        $errors = [];

        $openapi = $document['openapi'] ?? null;
        if (! is_string($openapi) || preg_match('/^3\.1\.\d+$/', $openapi) !== 1) {
            $errors[] = 'openapi must match 3.1.x, got: ' . var_export($openapi, true);
        }

        if (! isset($document['info']) || ! is_array($document['info'])) {
            $errors[] = 'info object is required';
        } else {
            foreach (['title', 'version'] as $field) {
                if (! isset($document['info'][$field]) || ! is_string($document['info'][$field])) {
                    $errors[] = "info.{$field} is required and must be a string";
                }
            }
        }

        if (! array_key_exists('paths', $document) || ! is_array($document['paths'])) {
            $errors[] = 'paths object is required';

            return $errors;
        }

        /** @var array<string,mixed> $paths */
        $paths = $document['paths'];
        foreach ($paths as $path => $pathItem) {
            if (! is_string($path) || ! str_starts_with($path, '/')) {
                $errors[] = "path key must start with '/': " . var_export($path, true);
            }
            if (! is_array($pathItem)) {
                $errors[] = "path item for '{$path}' must be an object";
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (! in_array((string) $method, self::HTTP_METHODS, true)) {
                    $errors[] = "path '{$path}' has invalid method key '{$method}'";
                    continue;
                }
                $errors = [...$errors, ...$this->operationViolations($path, (string) $method, $operation)];
            }
        }

        return $errors;
    }

    /**
     * Meta-schema gate (layer 2 — authoritative). Runs the Node ajv validator against the pinned
     * fixture. Returns one of GATE_VALID / GATE_INVALID / GATE_SKIPPED.
     *
     * Environment contract:
     *   - node available            → run the gate → GATE_VALID or GATE_INVALID (errors in $error).
     *   - node missing, HSP_REQUIRE_NODE_GATE unset → GATE_SKIPPED (caller emits a warning).
     *   - node missing, HSP_REQUIRE_NODE_GATE=1     → the caller FAILs; this method reports
     *     GATE_SKIPPED and the CI-required flag turns that into a failure at the assertion site.
     *
     * @param array<string,mixed> $document
     */
    public function gateStatus(array $document, ?string &$error = null): string
    {
        if (! $this->nodeAvailable()) {
            $error = 'node runtime not found on PATH';

            return self::GATE_SKIPPED;
        }

        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin — the document
            1 => ['pipe', 'w'], // stdout — ajv errors on failure
            2 => ['pipe', 'w'], // stderr — usage/IO errors
        ];

        $process = proc_open(
            ['node', $this->validatorScript],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            $error = 'failed to launch the node validator process';

            return self::GATE_SKIPPED;
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        // Validator exit codes: 0 = valid, 1 = invalid document, 2 = infrastructure (ajv not
        // installed / bad input / missing fixture) → gate could not run → treat as SKIPPED, never
        // as an invalid document.
        if ($exit === 0) {
            return self::GATE_VALID;
        }

        if ($exit === 2) {
            $error = trim($stderr) !== '' ? trim($stderr) : 'meta-schema gate infrastructure error';

            return self::GATE_SKIPPED;
        }

        $error = trim($stdout . "\n" . $stderr);

        return self::GATE_INVALID;
    }

    /** True when a `node` runtime is invocable (the meta-schema gate can run). */
    public function nodeAvailable(): bool
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = @proc_open(['node', '--version'], $descriptors, $pipes);

        if (! is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /**
     * @param mixed $operation
     * @return list<string>
     */
    private function operationViolations(string $path, string $method, mixed $operation): array
    {
        $errors = [];
        $where  = "{$method} {$path}";

        if (! is_array($operation)) {
            return ["operation {$where} must be an object"];
        }

        if (! isset($operation['responses']) || ! is_array($operation['responses']) || $operation['responses'] === []) {
            $errors[] = "operation {$where} must define a non-empty responses object";
        } else {
            foreach (array_keys($operation['responses']) as $status) {
                $status = (string) $status;
                if ($status !== 'default' && preg_match('/^[1-5](\d\d|XX)$/', $status) !== 1) {
                    $errors[] = "operation {$where} has invalid response status key '{$status}'";
                }
            }
        }

        if (isset($operation['parameters'])) {
            if (! is_array($operation['parameters'])) {
                $errors[] = "operation {$where} parameters must be an array";
            } else {
                foreach ($operation['parameters'] as $i => $param) {
                    if (! is_array($param) || ! isset($param['name'], $param['in'])) {
                        $errors[] = "operation {$where} parameter #{$i} must have name and in";
                        continue;
                    }
                    if (! in_array($param['in'], ['path', 'query', 'header', 'cookie'], true)) {
                        $errors[] = "operation {$where} parameter '{$param['name']}' has invalid in '{$param['in']}'";
                    }
                    if ($param['in'] === 'path' && ($param['required'] ?? false) !== true) {
                        $errors[] = "operation {$where} path parameter '{$param['name']}' must be required:true";
                    }
                }
            }
        }

        return $errors;
    }
}
