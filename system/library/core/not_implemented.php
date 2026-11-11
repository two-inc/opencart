<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Thrown by a core stub whose interface is fixed but whose behaviour lands in
 * a later phase of the rewrite. Callers never catch it: reaching one is a bug.
 */
final class NotImplemented extends \LogicException
{
    public static function inPhase(string $what, string $phase): self
    {
        return new self($what . ' is not implemented yet (rewrite phase ' . $phase . ').');
    }
}
