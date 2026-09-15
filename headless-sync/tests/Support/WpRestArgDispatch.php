<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

/**
 * WordPress's request-argument gate, as WordPress 7.1 implements it, so a behaviour test can
 * prove that a registered constraint actually BITES rather than merely being declared.
 *
 * WHY THIS EXISTS. FLAG-RESTARGDRIFT-1 was not a missing constraint — `per_page` declared
 * `minimum: 1, maximum: 100` for as long as the endpoint existed. It was a constraint that no
 * code path consulted, so a test asserting "the arg declares maximum 100" passed throughout the
 * defect. Metadata assertions therefore cannot close this flag: something has to answer "what
 * does a request DO", and the unit suite has no WordPress.
 *
 * WHAT IT IS, EXACTLY. The two methods WP_REST_Server calls before a handler runs, in that order:
 *
 *   1. WP_REST_Request::has_valid_params() — required-parameter check, then every arg's
 *      `validate_callback`, if any. An arg with NO validate_callback is not validated here.
 *   2. WP_REST_Request::sanitize_params() — runs each arg's `sanitize_callback`; and where an arg
 *      has a `type` and NO `sanitize_callback` KEY, installs WordPress's validating default
 *      `rest_parse_request_arg` (validate, then sanitize). This default is the door HSP closed on
 *      48 of its 50 args by declaring sanitizers, which is the whole root cause of the flag.
 *
 * The ORDER is the load-bearing part: validation sees the RAW value, so `absint('abc') === 0`
 * cannot launder an invalid request into a valid one before the bounds are checked.
 *
 * The schema evaluation covers the keyword subset HSP actually declares — `type`
 * (integer/string/boolean), `minimum`, `maximum`, `enum`, `pattern` — mirroring
 * rest_validate_value_from_schema() and its per-type helpers, including `rest_is_integer`'s
 * canonical-integer-string rule and `rest_is_boolean`'s closed lexical set.
 *
 * FIDELITY, AND ITS LIMIT. This is a faithful re-implementation, not WordPress, so it is not the
 * only evidence: the REAL WordPress behaviour of every rule below is verified against the live
 * site (post-deploy, read-only) and those results are recorded with the flag. What this harness
 * buys is that the proof runs in CI on every commit instead of once by hand.
 *
 * It is deliberately NOT a route dispatcher: no URL matching, no permission callbacks, no
 * handler invocation, no response shaping. Those belong to WordPress.
 */
final class WpRestArgDispatch
{
    /**
     * Evaluate a request against one route's registered args.
     *
     * @param array<string,array<string,mixed>> $args   the route's `args` array, as registered
     * @param array<string,mixed>               $params the request parameters
     * @return array{0:bool,1:string,2:string} [accepted, top-level error code, first detail code]
     */
    public static function evaluate(array $args, array $params): array
    {
        // --- 1. has_valid_params(): required first (it is cheaper), then validate_callbacks ---
        $missing = [];
        foreach ($args as $name => $spec) {
            if (($spec['required'] ?? false) === true && ! array_key_exists($name, $params)) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            return [false, 'rest_missing_callback_param', ''];
        }

        foreach ($args as $name => $spec) {
            if (! array_key_exists($name, $params) || $params[$name] === null) {
                continue;
            }
            if (empty($spec['validate_callback'])) {
                continue;
            }

            $outcome = self::runValidateCallback($spec, $name, $params[$name]);
            if ($outcome !== null) {
                return [false, 'rest_invalid_param', $outcome];
            }
        }

        // --- 2. sanitize_params(): the implicit validating default, where no sanitizer exists ---
        foreach ($args as $name => $spec) {
            if (! array_key_exists($name, $params) || $params[$name] === null) {
                continue;
            }
            if (array_key_exists('sanitize_callback', $spec) || empty($spec['type'])) {
                continue;
            }

            // rest_parse_request_arg == validate, then sanitize.
            $error = self::validateAgainstSchema($spec, $params[$name]);
            if ($error !== null) {
                return [false, 'rest_invalid_param', $error];
            }
        }

        return [true, '', ''];
    }

    /**
     * One arg's `validate_callback`.
     *
     * `'rest_validate_request_arg'` is evaluated as the generic schema validator. A CLOSURE is a
     * module validator that owns extra domain semantics; it is invoked, because the point of the
     * test is what it actually decides. WordPress wraps whatever it returns into one
     * `rest_invalid_param`, carrying the inner code as a diagnostic detail.
     *
     * @param array<string,mixed> $spec
     * @return string|null the inner error code, or null when the value is accepted
     */
    private static function runValidateCallback(array $spec, string $name, mixed $value): ?string
    {
        $callback = $spec['validate_callback'];

        if ($callback === 'rest_validate_request_arg') {
            return self::validateAgainstSchema($spec, $value);
        }

        if ($callback instanceof \Closure) {
            // Carrying the route attributes, because a module validator may delegate to
            // rest_validate_request_arg(), which reads the parameter's schema back out of
            // them — exactly as WP_REST_Server::dispatch() arranges.
            $request = new \WP_REST_Request([$name => $value]);
            $request->set_attributes(['args' => [$name => $spec]]);
            $result  = $callback($value, $request, $name);

            if ($result instanceof \WP_Error) {
                return $result->code;
            }

            return $result === false ? 'rest_invalid_param' : null;
        }

        return null;
    }

    /**
     * rest_validate_value_from_schema(), for the keywords HSP declares.
     *
     * @param array<string,mixed> $spec
     * @return string|null the WordPress error code, or null when valid
     */
    public static function validateAgainstSchema(array $spec, mixed $value): ?string
    {
        $type = (string) ($spec['type'] ?? 'string');

        // Numeric bounds are evaluated for both integer and number, exactly as
        // rest_validate_integer_value_from_schema() delegates to the number validator FIRST.
        if ($type === 'integer' || $type === 'number') {
            if (! is_numeric($value)) {
                return 'rest_invalid_type';
            }
            $numeric = $value + 0;
            if (isset($spec['minimum']) && $numeric < $spec['minimum']) {
                return 'rest_out_of_bounds';
            }
            if (isset($spec['maximum']) && $numeric > $spec['maximum']) {
                return 'rest_out_of_bounds';
            }
            if ($type === 'integer' && ! self::isInteger($value)) {
                return 'rest_invalid_type';
            }
        }

        if ($type === 'boolean' && ! self::isBoolean($value)) {
            return 'rest_invalid_type';
        }

        if ($type === 'string') {
            if (! is_string($value)) {
                return 'rest_invalid_type';
            }
            // rest_validate_json_schema_pattern() wraps the pattern as `#pattern#u` and adds NO
            // anchors of its own, so an unanchored pattern would match anywhere in the value.
            if (isset($spec['pattern'])
                && preg_match('#' . str_replace('#', '\\#', (string) $spec['pattern']) . '#u', $value) !== 1
            ) {
                return 'rest_invalid_pattern';
            }
            if (($spec['format'] ?? null) === 'date-time' && ! self::isDateTime($value)) {
                return 'rest_invalid_date';
            }
        }

        if (! empty($spec['enum']) && ! in_array($value, $spec['enum'], false)) {
            return 'rest_not_in_enum';
        }

        return null;
    }

    /**
     * rest_is_integer(): a canonical integer string of any magnitude passes WITHOUT float
     * conversion; other numeric forms pass iff the float has no fractional part. So `"10"` and
     * `"1e2"` are integers, `"1.5"` and `"abc"` and `""` are not.
     */
    private static function isInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value) && preg_match('/^\s*[+-]?[0-9]+\s*$/', $value) === 1) {
            return true;
        }
        if (! is_numeric($value)) {
            return false;
        }
        $float = (float) $value;

        return floor($float) === $float;
    }

    /** rest_is_boolean(): a CLOSED lexical set — `yes`/`on`/`off` are NOT booleans. */
    private static function isBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['false', 'true', '0', '1'], true);
        }

        return is_int($value) && in_array($value, [0, 1], true);
    }

    /**
     * rest_parse_date(): a full date-time. The timezone is OPTIONAL in WordPress's own grammar,
     * which is precisely why `published_after` layers a module check for the explicit offset on
     * top of it.
     */
    private static function isDateTime(string $value): bool
    {
        return preg_match(
            '#^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}(?::\d{2})?)?$#',
            $value
        ) === 1;
    }
}
