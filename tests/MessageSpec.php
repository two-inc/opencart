<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Message;

/**
 * Merchant notes as language keys: the English for the log, the text in a
 * language from its copy, the stored form of a refund row's outcome, and
 * every key the code names exists.
 */
final class MessageSpec
{
    public static function runAll(): void
    {
        $nb = ['note_confirmed' => 'Two-ordren %s er bekreftet.', 'note_already' => 'Two-ordren %1$s er allerede %2$s.', 'note_cancelled' => 'Kansellert %1$s %2$s.', 'note_refund_form_not_built' => 'Ikke refundert: %s', 'refusal_refund_empty' => 'ingenting å refundere.'];
        $copy = static fn (string $key): string => $nb[$key] ?? $key;
        $cases = [
            ['message' => Message::of('note_confirmed', 'abc'), 'english' => 'Two order abc confirmed.', 'in' => 'Two-ordren abc er bekreftet.', 'description' => 'one parameter'],
            ['message' => Message::of('note_already', 'abc', 'FULFILLED'), 'english' => 'Two order abc is already FULFILLED.', 'in' => 'Two-ordren abc er allerede FULFILLED.', 'description' => 'numbered parameters'],
            ['message' => Message::of('note_cancel_refused_why', 'abc', 'CONFIRMED', 'HTTP 409.'), 'english' => 'Two order abc not cancelled: HTTP 409. Two holds it as CONFIRMED. Save the status again to retry.', 'in' => 'Two order abc not cancelled: HTTP 409. Two holds it as CONFIRMED. Save the status again to retry.', 'description' => 'a key the language lacks reads in English'],
            ['message' => Message::of('note_cancelled', 'abc'), 'english' => 'Two order abc cancelled.', 'in' => 'Two order abc cancelled.', 'description' => 'a translation that wants more parameters than given reads in English'],
            ['message' => Message::of('note_no_company'), 'english' => 'No company was entered.', 'in' => 'No company was entered.', 'description' => 'no parameters'],
            ['message' => Message::of('note_refund_form_not_built', Message::of('refusal_refund_empty')), 'english' => 'Not refunded: nothing to refund. Pick a quantity on a line, or enter an amount.', 'in' => 'Ikke refundert: ingenting å refundere.', 'description' => 'a message inside a message reads in the same language'],
        ];
        foreach ($cases as $case) {
            TinyAssert::same($case['english'], $case['message']->english(), 'english: ' . $case['description']);
            TinyAssert::same($case['in'], $case['message']->in($copy), 'in a language: ' . $case['description']);
            TinyAssert::same($case['english'], Message::decode($case['message']->encode())?->english(), 'stored and read back: ' . $case['description']);
            TinyAssert::same($case['in'], Message::decode($case['message']->encode())?->in($copy), 'stored and read back in a language: ' . $case['description']);
        }

        $stored = [
            ['stored' => 'Two refunded 10.00 NOK on order abc.', 'description' => 'text stored before messages'],
            ['stored' => '{"key":"note_gone","params":[]}', 'description' => 'a key this version does not have'],
            ['stored' => '{"key":"note_confirmed"}', 'description' => 'no parameters list'],
            ['stored' => '', 'description' => 'nothing stored'],
        ];
        foreach ($stored as $case) {
            TinyAssert::same(null, Message::decode($case['stored']), 'not a stored message: ' . $case['description']);
        }

        TinyAssert::throws(static fn () => Message::of('note_gone'), \InvalidArgumentException::class, 'an unknown key is refused when the message is made');

        // Every key the code names literally is one the templates hold.
        $root = dirname(__DIR__);
        foreach (['system', 'catalog', 'admin'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all("~Message::of\\(\\s*'([a-z0-9_]+)'(?!\\s*\\.)~", (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $key) {
                    TinyAssert::true(isset(Message::TEMPLATES[$key]), $file->getFilename() . ' names a known message key: ' . $key);
                }
            }
        }
    }
}
