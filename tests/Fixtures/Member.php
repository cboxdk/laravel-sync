<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Carries the same tenancy column the rows do, which is the convention.
 *
 * @property string $id
 * @property string $team_id
 */
class Member extends Authenticatable
{
    protected $table = 'members';

    public $timestamps = false;

    protected $fillable = ['id', 'team_id'];
}
