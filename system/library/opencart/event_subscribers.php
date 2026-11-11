<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\PostprocessingSubscribers;

/**
 * The order postprocessing hook (Build Guide section 21) as an OpenCart
 * event. A merchant subscribes the way OpenCart extensions subscribe to any
 * event: a row in the event table (Extensions > Events) whose trigger is
 * `catalog/extension/two/order_postprocessing` and whose action takes the
 * payload by reference, chained by the event's sort order:
 *
 *     public function handle(array &$payload, array $context): void
 *
 * The plugin fires the event itself and reads the event table for the
 * subscribers' names, so the default handler knows when to stand down.
 */
final class EventSubscribers implements PostprocessingSubscribers
{
    /** The event name, as fired. Registered triggers carry the `catalog/` area prefix. */
    public const EVENT = 'extension/two/order_postprocessing';
    public const AREA = 'catalog/';

    public function __construct(
        private readonly \Opencart\System\Library\DB $db,
        private readonly \Opencart\System\Engine\Event $event,
    ) {
    }

    public function names(): array
    {
        $query = $this->db->query('SELECT `code`, `trigger`, `action` FROM `' . DB_PREFIX . "event` WHERE `status` = '1' ORDER BY `sort_order` ASC, `event_id` ASC");
        $names = [];
        foreach (is_object($query) ? $query->rows : [] as $row) {
            if (self::matches((string) $row['trigger']) && self::loadable((string) $row['action'])) {
                $names[] = $row['code'] . ' (' . $row['action'] . ')';
            }
        }

        return $names;
    }

    public function dispatch(array $payload, array $context): mixed
    {
        $result = $payload;
        $this->event->trigger(self::EVENT, [&$result, $context]);

        return $result;
    }

    /**
     * Whether OpenCart can run the action: its controller class and public
     * method exist, resolved by OpenCart's own rule (Action and Factory).
     * OpenCart drops an action it cannot load without a word, so counting
     * one would stand the shop-match checks down for a handler that never
     * runs.
     */
    public static function loadable(string $action): bool
    {
        $action = (string) preg_replace('/[^a-zA-Z0-9_|\/\.]/', '', $action);
        $pos = strrpos($action, '.');
        $route = $pos === false ? $action : substr($action, 0, $pos);
        $method = $pos === false ? 'index' : substr($action, $pos + 1);
        $class = 'Opencart\\Catalog\\Controller\\' . str_replace(['_', '/'], ['', '\\'], ucwords($route, '_/'));
        if ($route === '' || str_starts_with($method, '__') || !class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic();
    }

    /** OpenCart's own rule (Event::trigger): the trigger, with * and ? as wildcards, matches the start of the event name. */
    public static function matches(string $trigger): bool
    {
        if (!str_starts_with($trigger, self::AREA)) {
            return false;
        }
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], preg_quote(substr($trigger, strlen(self::AREA)), '/'));

        return preg_match('/^' . $pattern . '/', self::EVENT) === 1;
    }
}
