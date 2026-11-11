<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the merchant's order postprocessing handlers (Build Guide section
 * 21.2). The OpenCart adapter runs them as the shop's event subscribers;
 * the plugin's own default handler is not one of them.
 */
interface PostprocessingSubscribers
{
    /**
     * The merchant handlers that will run, in order, for the shop-match
     * delegation line. Empty when none is registered and enabled.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * Runs every merchant handler on the payload and returns it as the last
     * handler left it. A handler may replace it with anything, so the result
     * is checked by the caller. A handler's exception propagates.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function dispatch(array $payload, array $context): mixed;
}
