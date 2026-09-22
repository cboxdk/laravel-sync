<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

/** The application's ordinary policy. Sync asks this and nothing else. */
class NotePolicy
{
    public function viewAny(Member $user): bool
    {
        return $user->team_id !== 'outsiders';
    }

    /** A private note is its author's alone - here, nobody outside the owners team sees one. */
    public function view(Member $user, Note $note): bool
    {
        return $note->status !== 'private' || $user->team_id === 'owners-admins';
    }

    public function create(Member $user): bool
    {
        return $user->team_id !== 'readers';
    }

    public function update(Member $user, Note $note): bool
    {
        // locked_by is not synced: the policy reads the real row, not only
        // what devices can see.
        return $user->team_id !== 'readers' && $note->status !== 'locked' && $note->getAttribute('locked_by') === null;
    }

    public function delete(Member $user, Note $note): bool
    {
        return $user->team_id === 'owners';
    }
}
