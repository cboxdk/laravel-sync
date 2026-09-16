<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\ValueObjects\FieldValue;

/**
 * The only place a JSON body becomes a canonical field value.
 *
 * `FieldValue` stores canonical JSON TEXT and compares it exactly, so how the
 * body is decoded decides what gets stored forever.
 */
class FieldValueCodec
{
    /** Matches FieldValue::normalize(), which refuses anything deeper. */
    public const MAX_DEPTH = 64;

    /**
     * Decode a raw request body into objects, never associative arrays.
     *
     * `json_decode('{}', true)` returns `[]`, which is a list, so an empty JSON
     * object would canonicalize to an empty ARRAY and be stored as one. The
     * engine pins `[] !== {}` as a correctness property, and the corruption is
     * consistent - a retry hashes the same way - so no protocol error would
     * ever surface it. This is why the transport cannot read field values
     * through $request->input(), ->json() or ->all().
     */
    public static function decodeBody(string $raw): \stdClass
    {
        if ($raw === '') {
            throw SyncRequestRejected::malformedBody();
        }
        try {
            $decoded = json_decode($raw, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw SyncRequestRejected::malformedBody();
        }
        if (! $decoded instanceof \stdClass) {
            throw SyncRequestRejected::malformedBody();
        }

        return $decoded;
    }

    public static function set(mixed $value, string $field): FieldValue
    {
        try {
            return FieldValue::of($value);
        } catch (InvalidRequest $invalid) {
            throw new SyncRequestRejected('Field "'.$field.'" is not storable JSON: '.$invalid->getMessage(), 'invalid_field_value');
        }
    }

    public static function unset(): FieldValue
    {
        return FieldValue::missing();
    }

    /**
     * A missing field is `{"present": false}`, never an omitted key.
     *
     * A record keeps unset fields as explicit state rather than dropping them,
     * so omitting the key would make "never set" and "explicitly unset"
     * indistinguishable to a client. `null` is a present value and stays one.
     *
     * @return array{present: bool, value?: mixed}
     */
    public static function toWire(FieldValue $value): array
    {
        if (! $value->exists) {
            return ['present' => false];
        }

        return ['present' => true, 'value' => $value->value()];
    }
}
