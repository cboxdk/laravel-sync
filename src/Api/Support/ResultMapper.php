<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Enums\MutationStatus;

/**
 * What a push is allowed to tell the client.
 *
 * Never the receipt, never another device's candidate values, and deliberately
 * never the merged record: the client learns merged state from delta, which
 * already applies the field whitelist. Two read projections means two places to
 * get the whitelist wrong, and the second one is the one nobody tests.
 */
class ResultMapper
{
    /**
     * @param  list<string>  $readableFields
     * @param  array<string, ConflictGroup>  $groups  the touched groups, by id
     * @param  bool  $rowIsReadable  whether the caller's view contains this record
     * @return array<string, mixed>
     */
    public static function toWire(MutationResult $result, array $readableFields, array $groups = [], bool $rowIsReadable = true): array
    {
        // A gap produces no receipt, no commit and no acknowledgement; the
        // engine returns before any of it. Emitting the zero-valued version and
        // a null sequence would have the client record a record that is not
        // there. The resume point is the whole payload.
        if ($result->status === MutationStatus::MutationGap || $result->status === MutationStatus::ReceiptPruned) {
            return [
                'status' => $result->status->value,
                'acknowledged_sequence' => $result->acknowledgedSequence,
                'reason' => $result->reason,
            ];
        }

        $body = [
            'status' => $result->status->value,
            'record_version' => $result->recordVersion->value,
            'commit_sequence' => $result->commitSequence?->value,
            'acknowledged_sequence' => $result->acknowledgedSequence,
            'reason' => $result->reason,
            'conflict_groups' => self::groups($result, $groups),
        ];

        // Accepted versions can carry field knowledge inherited through
        // depends_on, so unlike everything else here it is not already bounded
        // by what this mutation named.
        $accepted = [];
        foreach ($result->acceptedVersions as $field => $version) {
            if (in_array($field, $readableFields, true)) {
                $accepted[$field] = $version->value;
            }
        }
        // An object even when empty: a JSON array would contradict the
        // description, and strict clients refuse it.
        $body['accepted_versions'] = $accepted === [] ? new \stdClass : $accepted;

        $decisions = [];
        foreach ($result->decisions as $field => $decision) {
            $decisions[$field] = $decision->value;
        }
        $body['decisions'] = $decisions === [] ? new \stdClass : $decisions;

        $conflicts = [];
        foreach ($result->conflicts as $field => $conflict) {
            $entry = [
                'field' => $field,
                'field_version' => $conflict->fieldVersion->value,
                'effective_base' => $conflict->effectiveBase->value,
            ];
            // `proposed` is the client's own value coming back; echoing it is
            // pure attack surface. `current` is the server's, and disclosing it
            // needs BOTH permissions: the column whitelist AND the row itself
            // being inside the caller's view. A policy that allows a blind
            // write to a row the caller cannot read would otherwise hand back
            // that row's contents through a conflict.
            if ($rowIsReadable && in_array($field, $readableFields, true)) {
                $entry['current'] = FieldValueCodec::toWire($conflict->current);
            } else {
                $entry['current_hidden'] = true;
            }
            $conflicts[] = $entry;
        }
        $body['conflicts'] = $conflicts;

        if ($result->preconditionFailure !== null) {
            $body['precondition'] = [
                'expected_version' => $result->preconditionFailure->expectedVersion->value,
                'actual_version' => $result->preconditionFailure->actualVersion->value,
            ];
        }

        if ($result->validation !== null && ! $result->validation->isValid()) {
            $failures = [];
            foreach ($result->validation->failures as $failure) {
                // The message is host-authored and can interpolate a value, so
                // it would walk straight past the field whitelist. Code and
                // field are enough for a client to act.
                $failures[] = ['code' => $failure->code, 'field' => $failure->field];
            }
            $body['validation'] = $failures;
        }

        return $body;
    }

    /**
     * A resolve needs the group's revision and the candidate ids, so returning
     * the id alone would leave a client unable to act on its own conflict.
     *
     * Candidate VALUES are not here: they are other devices' proposals, and
     * delivering them is a separately authorized projection this transport does
     * not ship. Ids are opaque and server-issued, and every group named here
     * belongs to a field this mutation wrote, so the write whitelist has
     * already bounded it.
     *
     * @param  array<string, ConflictGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private static function groups(MutationResult $result, array $groups): array
    {
        $wire = [];
        foreach ($result->conflictGroupIds as $id) {
            $group = $groups[$id] ?? null;
            $wire[] = $group === null
                ? ['id' => $id]
                : [
                    'id' => $id,
                    'field' => $group->field,
                    'revision' => $group->revision,
                    'candidate_ids' => array_keys($group->candidates),
                    'open' => $group->isOpen(),
                ];
        }

        return $wire;
    }
}
