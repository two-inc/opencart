<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ErrorLog;

/**
 * The error log view's line picker: each case is the tail of the shop's
 * error log, the limit, and the lines shown.
 */
final class ErrorLogSpec
{
    public static function runAll(): void
    {
        $a = '2026-11-04 10:00:00 - Two error: order 7 not placed';
        $b = '2026-11-04 10:00:01 - Two: payment method hidden (currency): GBP';
        $c = '2026-11-04 10:00:02 - Two debug: Two API GET /v1/merchant {"x":1}';
        $shop = '2026-11-04 10:00:03 - PHP Warning:  Undefined index in /x.php on line 4';
        $cases = [
            ['', 100, [], 'an empty log shows nothing'],
            [implode("\n", [$a, $shop, $b, $c]), 100, [$a, $b, $c], 'only the extension\'s lines, of every level, oldest first'],
            [implode("\n", [$a, $b, $c]), 2, [$b, $c], 'the last lines up to the limit'],
            ['ror: order 6 not placed' . "\n" . $a, 100, [$a], 'a line cut by the tail read is dropped'],
            [implode("\r\n", [$a, $b]) . "\r\n", 100, [$a, $b], 'Windows line endings'],
            ['2026-11-04 10:00:04 - Twofold: a shop line' . "\n" . 'x - Two error: no date', 100, [], 'a shop line that only starts with the word, or has no date, is not ours'],
            [$a, 0, [], 'a zero limit shows nothing'],
        ];
        foreach ($cases as [$text, $limit, $expected, $description]) {
            TinyAssert::same($expected, ErrorLog::twoLines($text, $limit), $description);
        }
        $many = implode("\n", array_map(static fn (int $i): string => '2026-11-04 10:00:00 - Two: line ' . $i, range(1, 150)));
        $shown = ErrorLog::twoLines($many);
        TinyAssert::same([100, '2026-11-04 10:00:00 - Two: line 51', '2026-11-04 10:00:00 - Two: line 150'], [count($shown), $shown[0], $shown[99]], 'the default shows the last 100');
    }
}
