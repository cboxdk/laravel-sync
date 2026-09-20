<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;

class MutationMapper
{
    /**
     * Turn a request body into a mutation the engine can process.
     *
     * The client chooses two identifiers the engine honours without knowing who
     * sent them, so both are namespaced under the principal here and nowhere
     * else. `depends_on` gets the same treatment, since it refers to one of the
     * caller's own earlier mutation ids.
     *
     * @param  list<string>  $writableFields
     */
    public static function fromWire(\stdClass $body, SyncPrincipal $principal, string $entityType, string $space, array $writableFields, int $maxOperations): Mutation
    {
        $kind = MutationKind::tryFrom(Payload::string($body, 'kind'))
            ?? throw SyncRequestRejected::missing('kind');

        $operations = [];
        $raw = isset($body->operations) ? Payload::list($body, 'operations') : [];
        if (count($raw) > $maxOperations) {
            throw new SyncRequestRejected('Too many operations in one mutation', 'too_many_operations');
        }
        foreach ($raw as $entry) {
            if (! $entry instanceof \stdClass) {
                throw SyncRequestRejected::missing('operations');
            }
            $operations[] = self::operation($entry, $writableFields);
        }

        try {
            return new Mutation(
                IdentityBinding::mutationId($principal, Payload::string($body, 'mutation_id')),
                // A create is named by the server; the id in the body is only
                // a handle the device made up for itself. An update already
                // knows the real name and sends it.
                new EntityKey($space, $entityType, $kind === MutationKind::Create
                    ? IdentityBinding::entityId($principal, Payload::string($body, 'mutation_id'))
                    : Payload::string($body, 'id')),
                IdentityBinding::replica($principal, Payload::string($body, 'replica')),
                new MutationSequence(Payload::int($body, 'sequence')),
                $kind,
                new RecordVersion(Payload::int($body, 'base_version')),
                $operations,
                Payload::bool($body, 'atomic', true),
                self::dependsOn($body, $principal),
                self::resolution($body),
                self::expectedVersion($body),
            );
        } catch (InvalidRequest $invalid) {
            throw new SyncRequestRejected($invalid->getMessage(), 'invalid_request');
        }
    }

    /** @param list<string> $writableFields */
    private static function operation(\stdClass $entry, array $writableFields): FieldOperation
    {
        $field = Payload::string($entry, 'field');
        if (! in_array($field, $writableFields, true)) {
            throw SyncRequestRejected::unwritableField($field);
        }
        // `from` reaches nothing in the engine except the fingerprint, so
        // accepting it would let a client change a mutation's identity without
        // changing its effect - turning a legitimate retry into a terminal
        // protocol error it can never recover from.
        if (property_exists($entry, 'from')) {
            throw new SyncRequestRejected('Operation "from" is not accepted', 'invalid_request');
        }

        $op = Payload::string($entry, 'op');
        if ($op === 'unset') {
            return new FieldOperation($field, FieldValueCodec::unset());
        }
        if ($op !== 'set') {
            throw new SyncRequestRejected('Unknown operation: '.$op, 'invalid_request');
        }
        if (! property_exists($entry, 'value')) {
            throw SyncRequestRejected::missing('value');
        }

        return new FieldOperation($field, FieldValueCodec::set($entry->value, $field));
    }

    private static function dependsOn(\stdClass $body, SyncPrincipal $principal): ?string
    {
        $value = Payload::optionalString($body, 'depends_on');

        return $value === null ? null : IdentityBinding::mutationId($principal, $value);
    }

    private static function resolution(\stdClass $body): ?Resolution
    {
        $value = $body->resolution ?? null;
        if ($value === null) {
            return null;
        }
        if (! $value instanceof \stdClass) {
            throw SyncRequestRejected::missing('resolution');
        }

        return new Resolution(
            Payload::string($value, 'group_id'),
            Payload::int($value, 'group_revision'),
            Payload::stringList($value, 'candidate_ids'),
        );
    }

    private static function expectedVersion(\stdClass $body): ?RecordVersion
    {
        $value = Payload::optionalInt($body, 'expected_version');

        return $value === null ? null : new RecordVersion($value);
    }
}
