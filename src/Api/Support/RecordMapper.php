<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\EntityRecord;

/**
 * The only thing that turns a record into a wire array.
 *
 * The whitelist is a required argument on purpose. A view filters ROWS and has
 * no opinion about fields, so a record reaching this point carries every one -
 * and each field's origin carries the provenance of whoever wrote it, actor id
 * included. Serializing a record generically therefore discloses both the
 * values and their authorship, and it is the single easiest mistake to make
 * here.
 *
 * @phpstan-type WireRecord array{id: string, type: string, version: int, fields: array<string, array{present: bool, value?: mixed}>}
 */
class RecordMapper
{
    /**
     * @param  list<string>  $readableFields
     * @return array{id: string, type: string, version: int, fields: array<string, array{present: bool, value?: mixed}>|\stdClass}
     */
    public static function toWire(EntityRecord $record, array $readableFields): array
    {
        $fields = [];
        foreach ($readableFields as $field) {
            $state = $record->fields[$field] ?? null;
            $fields[$field] = $state === null
                ? ['present' => false]
                : FieldValueCodec::toWire($state->value);
        }

        return [
            'id' => $record->entity->id,
            'type' => $record->entity->type,
            'version' => $record->version->value,
            // An object even when empty, as the description says.
            'fields' => $fields === [] ? new \stdClass : $fields,
        ];
    }
}
