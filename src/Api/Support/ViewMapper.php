<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api\Support;

use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\Views\BootstrapPage;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\DeltaPage;
use Cbox\Sync\Views\ViewChangeKind;
use Cbox\Sync\Views\ViewCursor;

class ViewMapper
{
    /**
     * The wire cursor is a position and a context FINGERPRINT, never the
     * context itself.
     *
     * A `CursorContext` names its space. Accepting one from a client would let
     * the client choose which tenant's commits it reads, so the server rebuilds
     * the whole context from the session and the client round-trips only the
     * fingerprint - which is compared against the server-derived one and never
     * used to look anything up, so it carries no authority. It still has to
     * make the trip: without it a client whose view definition changed would
     * silently keep applying deltas onto stale local state.
     *
     * @return array{position: int, context: string}
     */
    public static function cursorToWire(ViewCursor $cursor): array
    {
        return ['position' => $cursor->position->value, 'context' => $cursor->context->fingerprint()];
    }

    /**
     * The full context, sent only OUTWARDS.
     *
     * A client needs it to key its own local state, and telling it which space
     * and view it is already reading discloses nothing it does not have.
     * Accepting one is the opposite: that would let the client choose the
     * space, which is why requests carry only the fingerprint.
     *
     * @return array<string, string>
     */
    public static function contextToWire(CursorContext $context): array
    {
        return [
            'space' => $context->space,
            'view_id' => $context->viewId,
            'filter_version' => $context->filterVersion,
            'filter_signature' => $context->filterSignature,
            'schema_version' => $context->schemaVersion,
            'epoch' => $context->epoch,
            'fingerprint' => $context->fingerprint(),
        ];
    }

    public static function cursorFromWire(\stdClass $body, CursorContext $context): ViewCursor
    {
        $wire = Payload::object($body, 'cursor');
        $fingerprint = Payload::string($wire, 'context');
        if (! hash_equals($context->fingerprint(), $fingerprint)) {
            throw SyncRequestRejected::badCursor();
        }
        $position = Payload::int($wire, 'position');
        if ($position < 0) {
            throw SyncRequestRejected::badCursor();
        }

        return new ViewCursor($context, new CommitSequence($position));
    }

    /**
     * @param  list<string>  $readableFields
     * @return array<string, mixed>
     */
    public static function bootstrapToWire(BootstrapPage $page, array $readableFields): array
    {
        $records = [];
        foreach ($page->records as $record) {
            $records[] = RecordMapper::toWire($record, $readableFields);
        }

        return [
            'context' => self::contextToWire($page->context),
            // The token that produced this page. A client keys its
            // out-of-order and replay guards on it, and on the first call it
            // has no other way to learn which token the server opened.
            'token' => $page->token->value,
            'records' => $records,
            'offset' => $page->offset,
            'next_token' => $page->nextToken?->value,
            'cursor' => $page->cursor === null ? null : self::cursorToWire($page->cursor),
            'complete' => $page->isComplete(),
        ];
    }

    /**
     * @param  list<string>  $readableFields
     * @return array<string, mixed>
     */
    public static function deltaToWire(DeltaPage $page, array $readableFields): array
    {
        $commits = [];
        foreach ($page->commits as $commit) {
            $changes = [];
            foreach ($commit->changes as $change) {
                $entry = [
                    'ordinal' => $change->ordinal,
                    'kind' => $change->kind->value,
                    'id' => $change->entity->id,
                    'type' => $change->entity->type,
                    'version' => $change->recordVersion->value,
                ];
                // Only an upsert carries a record; a removal or a tombstone
                // deliberately carries none. Provenance is never emitted: it
                // names the actor who made the change, and that actor can be
                // someone this client cannot otherwise see.
                if ($change->kind === ViewChangeKind::Upsert && $change->record !== null) {
                    $entry['record'] = RecordMapper::toWire($change->record, $readableFields);
                }
                $changes[] = $entry;
            }
            $commits[] = ['sequence' => $commit->sourceSequence->value, 'changes' => $changes];
        }

        return [
            'context' => self::contextToWire($page->cursor->context),
            'commits' => $commits,
            'previous_cursor' => self::cursorToWire($page->previousCursor),
            'cursor' => self::cursorToWire($page->cursor),
            'has_more' => $page->hasMore,
        ];
    }
}
