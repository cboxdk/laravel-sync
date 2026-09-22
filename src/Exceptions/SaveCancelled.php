<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Exceptions;

/**
 * @internal Unwinds a save an observer cancelled, so the transaction around it
 *           rolls back anything already recorded. Never leaves Syncable::save().
 */
final class SaveCancelled extends \RuntimeException {}
