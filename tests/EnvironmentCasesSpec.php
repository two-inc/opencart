<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Environment;

/**
 * Runs every case of the shared environment table against Environment. The
 * table is byte-identical in the sibling plugins (Build Guide section 14.6),
 * so its hash is pinned here: a local edit fails this spec, and a change to
 * the table lands in all four repos together.
 */
final class EnvironmentCasesSpec
{
    private const FIXTURE = __DIR__ . '/fixtures/environment-cases.json';
    private const SHA256 = '268b84f4f0c79d3a1afe17349fa4e31f2304e92440fa9b0144e5efb3972fd67d';

    public static function runAll(): void
    {
        TinyAssert::same(self::SHA256, hash_file('sha256', self::FIXTURE), 'environment-cases.json is byte-identical to the siblings');
        $table = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        TinyAssert::true(count($table['resolve']) > 0 && count($table['save']) > 0, 'the table has resolve and save cases');

        foreach ($table['resolve'] as $case) {
            $environment = new Environment(Environment::flagEnabled($case['flag']));
            $label = 'resolve: ' . $case['description'];
            TinyAssert::same($case['options'], $environment->options($case['stored']), $label . ' (options)');
            TinyAssert::same($case['effective'], $environment->resolve($case['stored']), $label . ' (effective)');
            TinyAssert::same($case['api_host'], $environment->apiBaseUrl($case['stored']), $label . ' (api host)');
            TinyAssert::same($case['checkout_host'], $environment->checkoutBaseUrl($case['stored']), $label . ' (checkout host)');
            TinyAssert::same($case['health_ok'], $environment->isHealthy($case['stored']), $label . ' (health)');
        }

        foreach ($table['save'] as $case) {
            $environment = new Environment(Environment::flagEnabled($case['flag']));
            TinyAssert::same($case['accepted'], $environment->acceptsSave($case['submitted'], $case['stored']), 'save: ' . $case['description']);
        }
    }
}
