<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;

/** Type-narrowing reads off a decoded body. Inline and manual, like the rest of the ecosystem. */
class Payload
{
    public static function object(\stdClass $body, string $key): \stdClass
    {
        $value = $body->{$key} ?? null;

        return $value instanceof \stdClass ? $value : throw SyncRequestRejected::missing($key);
    }

    public static function string(\stdClass $body, string $key): string
    {
        $value = $body->{$key} ?? null;

        return is_string($value) && $value !== '' ? $value : throw SyncRequestRejected::missing($key);
    }

    public static function optionalString(\stdClass $body, string $key): ?string
    {
        $value = $body->{$key} ?? null;
        if ($value === null) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : throw SyncRequestRejected::missing($key);
    }

    public static function int(\stdClass $body, string $key): int
    {
        $value = $body->{$key} ?? null;

        return is_int($value) ? $value : throw SyncRequestRejected::missing($key);
    }

    public static function optionalInt(\stdClass $body, string $key): ?int
    {
        $value = $body->{$key} ?? null;
        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : throw SyncRequestRejected::missing($key);
    }

    public static function bool(\stdClass $body, string $key, bool $default): bool
    {
        $value = $body->{$key} ?? null;
        if ($value === null) {
            return $default;
        }

        return is_bool($value) ? $value : throw SyncRequestRejected::missing($key);
    }

    /** @return list<mixed> */
    public static function list(\stdClass $body, string $key): array
    {
        $value = $body->{$key} ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw SyncRequestRejected::missing($key);
        }

        return $value;
    }

    /** @return list<string> */
    public static function stringList(\stdClass $body, string $key): array
    {
        $values = [];
        foreach (self::list($body, $key) as $value) {
            $values[] = is_string($value) && $value !== '' ? $value : throw SyncRequestRejected::missing($key);
        }

        return $values;
    }
}
