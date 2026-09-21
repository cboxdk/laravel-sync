<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Tests\Fixtures;

use Cbox\Sync\Laravel\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Deliberately shares a table with Note, which is how two types collide. */
class SecondNote extends Model
{
    use Syncable;

    protected $table = 'notes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['body'];
}
