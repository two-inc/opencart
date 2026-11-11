<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * An order request the plugin will not send: it cannot be built from the
 * shop's order, a shop-match check failed, or a postprocessing subscriber
 * broke it (Build Guide section 21.4). The buyer sees the generic refusal
 * (section 6.4); the reason code and the message go to the log (in English)
 * and the merchant's order history (in the shop's language). The message
 * never carries a credential.
 */
final class OrderRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly Message $note)
    {
        parent::__construct($reason . ': ' . $note->english());
    }

    public static function because(string $reason, Message $note): self
    {
        return new self($reason, $note);
    }
}
