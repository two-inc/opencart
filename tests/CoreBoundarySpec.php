<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\NotImplemented;

/**
 * The core (system/library/core) is platform-neutral: it holds the rules and
 * never calls OpenCart, so an OpenCart 3.0 adapter or an offline test can use
 * it unchanged. This spec pins that boundary:
 *
 * - every core type the rewrite plan names loads through OpenCart 4's
 *   library file-name rule, and the directory holds nothing else;
 * - no core file, comments aside, uses any of the OpenCart or I/O surfaces in
 *   FORBIDDEN, each of which proves it catches its own samples.
 */
final class CoreBoundarySpec
{
    private const NAMESPACE = 'Opencart\\System\\Library\\Extension\\Two\\Core\\';

    /** The plan's ten core classes plus the key verdict heartbeat, then the ports and value objects they share. */
    private const CORE_TYPES = [
        'Client', 'Environment', 'RecordProvider', 'SettingsProvider', 'Availability', 'ApiKeyVerdict', 'BuyerCountries', 'MinimumOrder', 'CompanyLookup', 'CompanyCapture', 'RequestToken', 'BuyerFields', 'RateLimiter',
        'OrderBuilder', 'Postprocessing', 'ErrorMapper', 'CustomHeaders', 'Brand', 'OrderFlow', 'OrderLifecycle', 'OrderRefunds', 'OrderEdit', 'IdempotencyKey', 'PaymentTerms', 'Surcharge', 'FxRates', 'FeeQuote', 'MerchantFeeRates', 'TaxCodes', 'TaxCodeList', 'ErrorLog', 'PendingCheck',
        'SettingsStore', 'SettingsOverlay', 'SettingsSave', 'ProductPage', 'Transport', 'Logger', 'Message', 'HttpResponse', 'NotImplemented',
        'OrderRecords', 'OrderLock', 'RefundRecords', 'OrderRefused', 'PostprocessingSubscribers',
    ];

    /** @var list<array{pattern: string, samples: list<string>, description: string}> */
    private const FORBIDDEN = [
        ['pattern' => '~\$this->(load|db|config|registry|request|response|session|url|language|document|cart|customer|user|event|log|cache|model_\w+)\b~', 'samples' => ['$this->load->model("x");'], 'description' => 'OpenCart registry access'],
        ['pattern' => '~Opencart\\\\(Admin|Catalog|Install|Extension|System\\\\(?!Library\\\\Extension\\\\Two\\\\Core\b))~', 'samples' => ['class X extends \Opencart\System\Engine\Controller {}', 'new \Opencart\System\Library\Cart\Tax($registry);', 'use Opencart\System\Library\Extension\Two\Opencart\DbSettingsStore;'], 'description' => 'an OpenCart class (engine, library such as Cart\\Tax or Log, admin, catalog) or the adapter'],
        ['pattern' => '~\boc_\w+\s*\(~', 'samples' => ['$n = oc_strlen($name);'], 'description' => 'an OpenCart oc_*() helper'],
        ['pattern' => '~\$registry\b|\bRegistry\b~', 'samples' => ['$db = $registry->get("db");'], 'description' => 'the OpenCart registry'],
        ['pattern' => '~\b(DB_[A-Z]+|DIR_[A-Z]+|HTTPS?_(SERVER|CATALOG)|VERSION)\b~', 'samples' => ['$t = DB_PREFIX . "order";'], 'description' => 'an OpenCart config constant'],
        ['pattern' => '~\b(mysqli\w*|PDO)\b~i', 'samples' => ['$db = new mysqli();'], 'description' => 'direct database access'],
        ['pattern' => '~\b(curl_\w+|file_get_contents|fopen|fsockopen|stream_context_create)\s*\(~', 'samples' => ['curl_init();'], 'description' => 'direct network or file I/O (use the Transport port)'],
        ['pattern' => '~(\bgetenv\s*\(|\$_(GET|POST|SERVER|COOKIE|SESSION|REQUEST|ENV|FILES)\b)~', 'samples' => ['$a = $_POST["x"];'], 'description' => 'process or request globals (the adapter passes values in)'],
    ];

    public static function runAll(): void
    {
        self::testEveryCoreTypeLoadsByOpenCartFileName();
        self::testCoreHoldsOnlyTheListedTypes();
        self::testEveryForbiddenPatternCatchesItsSample();
        self::testNoCoreFileUsesAForbiddenSurface();
        self::testStubsSayWhichPhaseImplementsThem();
    }

    private static function testEveryCoreTypeLoadsByOpenCartFileName(): void
    {
        foreach (self::CORE_TYPES as $type) {
            $fqcn = self::NAMESPACE . $type;
            $loaded = class_exists($fqcn) || interface_exists($fqcn);
            TinyAssert::true($loaded, $type . ' loads from ' . self::fileFor($type));
        }
    }

    private static function testCoreHoldsOnlyTheListedTypes(): void
    {
        $expected = array_map([self::class, 'fileFor'], self::CORE_TYPES);
        sort($expected);
        $actual = array_map('basename', self::coreFiles());
        sort($actual);
        TinyAssert::same($expected, $actual, 'core directory holds exactly the listed types');
    }

    private static function testEveryForbiddenPatternCatchesItsSample(): void
    {
        foreach (self::FORBIDDEN as $rule) {
            foreach ($rule['samples'] as $sample) {
                TinyAssert::same(1, preg_match($rule['pattern'], $sample), 'rule "' . $rule['description'] . '" catches ' . $sample);
            }
        }
    }

    private static function testNoCoreFileUsesAForbiddenSurface(): void
    {
        foreach (self::coreFiles() as $file) {
            $code = self::codeWithoutComments($file);
            foreach (self::FORBIDDEN as $rule) {
                $found = preg_match($rule['pattern'], $code, $match);
                TinyAssert::same(0, $found, basename($file) . ' uses ' . $rule['description'] . ($found ? ' ("' . $match[0] . '")' : ''));
            }
        }
    }

    private static function testStubsSayWhichPhaseImplementsThem(): void
    {
        TinyAssert::same(
            'Example::run is not implemented yet (rewrite phase P9).',
            NotImplemented::inPhase('Example::run', 'P9')->getMessage(),
            'a stub names the phase that implements it'
        );
    }

    /** OpenCart 4's rule: CamelCase split by underscores, lower-cased. */
    private static function fileFor(string $type): string
    {
        return strtolower((string) preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', $type)) . '.php';
    }

    /** @return list<string> */
    private static function coreFiles(): array
    {
        return glob(dirname(__DIR__) . '/system/library/core/*') ?: [];
    }

    private static function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
