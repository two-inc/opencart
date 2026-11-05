<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\EventSubscribers;

/**
 * Build Guide section 21.7: no order request bypasses the choke point. Only
 * the listed files call the Client; order requests come from OrderFlow
 * and OrderLifecycle alone, and every request there that changes an order
 * goes through Postprocessing::apply (directly or through
 * OrderFlow::payload) in the same method first. Also pins which event
 * triggers OpenCart would run for the hook.
 */
final class CallSiteAllowlistSpec
{
    /** Files that may call Client::request, and whether they may send order requests. */
    private const CALLERS = [
        'system/library/core/order_flow.php' => true,
        'system/library/core/order_lifecycle.php' => true,
        'system/library/core/order_refunds.php' => true,
        'system/library/core/order_edit.php' => true,
        'system/library/core/record_provider.php' => false,
        'system/library/core/api_key_verdict.php' => false,
        'system/library/core/company_lookup.php' => false,
        'system/library/core/fx_rates.php' => false,
        'system/library/core/fee_quote.php' => false,
        'system/library/core/merchant_fee_rates.php' => false,
        'system/library/core/tax_code_list.php' => false,
    ];

    /** Methods that send a body built by their callers, with those callers. */
    private const SHARED_SENDERS = ['system/library/core/order_refunds.php' => ['post' => ['send', 'retry']]];

    public static function runAll(): void
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (['system', 'catalog', 'admin'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($files);
        foreach ($files as $path => $code) {
            $calls = preg_match_all('~(?<!this)->request\(~', $code);
            TinyAssert::true($calls === 0 || isset(self::CALLERS[$path]), $path . ' calls the Client but is not on the allowlist');
            if (isset(self::CALLERS[$path]) && !self::CALLERS[$path]) {
                TinyAssert::true(!str_contains($code, '/v1/order'), $path . ' sends no order request');
            }
        }

        $expected = [
            'system/library/core/order_flow.php' => [4, 'OrderFlow sends order intent, create, confirm and cancel'],
            'system/library/core/order_lifecycle.php' => [1, 'OrderLifecycle sends fulfil (its cancel is OrderFlow\'s)'],
            'system/library/core/order_refunds.php' => [1, 'OrderRefunds sends every refund through one method'],
            'system/library/core/order_edit.php' => [1, 'OrderEdit sends the edit'],
        ];
        foreach ($expected as $path => [$count, $description]) {
            $posts = 0;
            $methods = preg_split('~\n    (?:public|private) (?:static )?function ~', $files[$path]) ?: [];
            foreach ($methods as $method) {
                $post = preg_match("~client->request\\('(POST|PUT)'~", $method, $write, PREG_OFFSET_CAPTURE) === 1 ? $write[0][1] : false;
                if ($post === false) {
                    continue;
                }
                $posts++;
                $name = (string) strtok($method, '(');
                if (isset(self::SHARED_SENDERS[$path][$name])) {
                    self::checkSharedSender($path, $name, $methods);
                    continue;
                }
                $hook = preg_match('~postprocessing->apply\(|this->payload\(~', $method, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] : false;
                TinyAssert::true($hook !== false && $hook < $post, 'every order request in ' . $path . ' passes the hook first: ' . strtok($method, '('));
            }
            TinyAssert::same($count, $posts, $description);
        }
        $flow = $files['system/library/core/order_flow.php'];
        TinyAssert::true(preg_match('~function payload\([^{]*\{[^}]*postprocessing->apply\(~s', $flow) === 1, 'OrderFlow::payload runs the hook');

        self::testTriggers();
        self::testOrderStore();
        self::testAdminWrites();
        self::testLoadable();
    }

    /**
     * A method that sends a body its callers built: each caller either runs
     * the hook before calling it, or resends a body stored after the hook ran
     * (a retry under the same key must send exactly what was sent).
     *
     * @param list<string> $methods
     */
    private static function checkSharedSender(string $path, string $name, array $methods): void
    {
        $callers = 0;
        foreach ($methods as $method) {
            $call = strpos($method, '$this->' . $name . '(');
            if ($call === false) {
                continue;
            }
            $callers++;
            $hook = strpos($method, 'postprocessing->apply(');
            $stored = strpos($method, "json_decode(\$row['payload']");
            TinyAssert::true(($hook !== false && $hook < $call) || ($stored !== false && $stored < $call), $path . ': ' . strtok($method, '(') . ' runs the hook, or resends the stored hooked body, before ' . $name);
        }
        TinyAssert::same(count(self::SHARED_SENDERS[$path][$name]), $callers, $path . ': ' . $name . ' has only its listed callers');
    }

    /**
     * Settings, the API key among them, are kept per store: every admin
     * action on one order reads Two with the order's own store.
     */
    private static function testOrderStore(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/payment/two.php');
        $methods = [];
        foreach (preg_split('~\n    (?:public|private) (?:static )?function ~', $code) ?: [] as $method) {
            $methods[(string) strtok($method, '(')] = $method;
        }
        $cases = [
            ['panelHtml', 'the Two tab and its read of the Two order'],
            ['refund', 'the partial refund'],
            ['refundRetry', 'the retry'],
            ['creditNote', 'the credit note'],
            ['invoice', 'the invoice'],
        ];
        foreach ($cases as [$name, $description]) {
            $body = $methods[$name] ?? '';
            TinyAssert::true(preg_match('~new Services\(\$this->db, \$this->log, \(int\) \(?\$order\[\'store_id\'\]~', $body) === 1, 'order store: ' . $description . ' (' . $name . ') uses the order\'s store');
            TinyAssert::same(1, substr_count($body, 'new Services('), 'order store: ' . $description . ' (' . $name . ') builds its services once');
        }
    }

    /**
     * The order postprocessing subscribers are registered on the store
     * front's events only (OpenCart's admin registers admin/ and system/
     * triggers), so every write the admin sends runs on the order's store
     * instance's events. The table lists the admin methods that write; any
     * other admin method that calls a write fails the second check.
     */
    private static function testAdminWrites(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__) . '/admin/controller/payment/two.php');
        $methods = [];
        foreach (preg_split('~\n    (?:public|private) (?:static )?function ~', $code) ?: [] as $method) {
            $methods[(string) strtok($method, '(')] = $method;
        }
        $writes = [
            'refund' => 'the partial refund',
            'refundRetry' => 'the retry (it resends the body the hook returned, on the same events)',
        ];
        foreach ($writes as $name => $description) {
            $body = $methods[$name] ?? '';
            TinyAssert::true(str_contains($body, '$this->catalogEvent((int) $order[\'store_id\'])'), 'admin writes: ' . $description . ' (' . $name . ') runs on the order\'s store front events');
            TinyAssert::true(!str_contains($body, '($this->event)'), 'admin writes: ' . $description . ' (' . $name . ') never uses the admin\'s own events');
        }
        foreach ($methods as $name => $body) {
            $writesHere = preg_match('~->(partial|retry|remainder|fulfil|cancel|sync)\(~', $body) === 1;
            TinyAssert::true(!$writesHere || isset($writes[$name]), 'admin writes: ' . $name . ' sends a write but is not in the table');
        }
        TinyAssert::true(preg_match('~function catalogEvent\([^{]*\{[^}]*createStoreInstance\(~s', $code) === 1, 'admin writes: catalogEvent is the store instance\'s event registry');
        $order = $methods['order'] ?? '';
        TinyAssert::true(str_contains($order, '_order_tab') && preg_match('~panelHtml|new Services|orderRefunds|->read\(~', $order) === 0, 'order page: the Two tab renders only its shell and never asks Two inline');
        $shell = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/payment/two_order_tab.twig');
        TinyAssert::true(str_contains($shell, 'show.bs.tab') && str_contains($shell, 'panel.load(') && str_contains($shell, "data('error')"), 'order page: the shell loads two.panel when the tab is opened and shows an error when it fails');
        TinyAssert::true(str_contains($methods['catalogEvent'] ?? '','$config->set(\'config_store_id\', $storeId);'), 'admin writes: the store instance holds the order\'s store id, as OpenCart\'s own order edit sets it');
    }

    private static function testLoadable(): void
    {
        eval('namespace Opencart\\Catalog\\Controller\\Extension\\Acme\\Module; final class OrderFix { public function handle(): void {} private function hidden(): void {} }');
        $cases = [
            ['action' => 'extension/acme/module/order_fix.handle', 'loadable' => true, 'description' => 'a public method on a loadable controller'],
            ['action' => 'extension/acme/module/order_fix.hidden', 'loadable' => false, 'description' => 'a private method'],
            ['action' => 'extension/acme/module/order_fix.missing', 'loadable' => false, 'description' => 'a method that does not exist'],
            ['action' => 'extension/acme/module/order_fix.__construct', 'loadable' => false, 'description' => 'a magic method, which OpenCart refuses'],
            ['action' => 'extension/acme/module/gone.handle', 'loadable' => false, 'description' => 'a controller that does not load'],
            ['action' => '', 'loadable' => false, 'description' => 'an empty action'],
        ];
        foreach ($cases as $case) {
            TinyAssert::same($case['loadable'], EventSubscribers::loadable($case['action']), 'event action: ' . $case['description']);
        }
    }

    private static function testTriggers(): void
    {
        $cases = [
            ['trigger' => 'catalog/extension/two/order_postprocessing', 'runs' => true, 'description' => 'the exact event'],
            ['trigger' => 'catalog/extension/two/*', 'runs' => true, 'description' => 'a wildcard over the extension'],
            ['trigger' => 'catalog/extension/two/order_post?rocessing', 'runs' => true, 'description' => 'a single-character wildcard'],
            ['trigger' => 'catalog/extension/two/order', 'runs' => true, 'description' => 'a prefix, as OpenCart matches it'],
            ['trigger' => 'admin/extension/two/order_postprocessing', 'runs' => false, 'description' => 'another area'],
            ['trigger' => 'extension/two/order_postprocessing', 'runs' => false, 'description' => 'no area prefix'],
            ['trigger' => 'catalog/extension/other/order_postprocessing', 'runs' => false, 'description' => 'another extension'],
        ];
        foreach ($cases as $case) {
            TinyAssert::same($case['runs'], EventSubscribers::matches($case['trigger']), 'event trigger: ' . $case['description']);
        }
    }
}
