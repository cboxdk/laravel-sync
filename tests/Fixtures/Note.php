<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Cbox\Sync\Laravel\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * A host model with nothing declared for sync beyond the trait.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $title
 * @property string|null $body
 * @property string|null $status
 */
class Note extends Model
{
    use Syncable;

    protected $table = 'notes';

    public $timestamps = true;

    protected $fillable = ['title', 'body', 'status'];
}
