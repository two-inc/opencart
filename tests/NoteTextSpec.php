<?php

declare(strict_types=1);

use Opencart\System\Engine\Config;
use Opencart\System\Engine\Loader;
use Opencart\System\Library\Extension\Two\Core\Message;
use Opencart\System\Library\Extension\Two\Opencart\NoteText;
use Opencart\System\Library\Language;

/**
 * A merchant note renders from the language folder OpenCart runs in: the
 * admin user's for an answer on the admin page, the shop's for a history
 * line. A language pack's default.php `code` is its HTML lang (nb), not its
 * folder (nb-no), so it never picks a notes file.
 */
final class NoteTextSpec
{
    public static function runAll(): void
    {
        $root = dirname(__DIR__);
        $message = Message::of('note_confirmed', 'abc');
        $cases = [
            ['config' => ['config_language_admin' => 'nb-no'], 'pick' => 'adminCode', 'expected' => 'Two-ordren abc er bekreftet.', 'description' => 'a Norwegian admin reads the Norwegian note'],
            ['config' => ['config_language_admin' => 'en-gb'], 'pick' => 'adminCode', 'expected' => 'Two order abc confirmed.', 'description' => 'an English admin reads the English note'],
            ['config' => [], 'pick' => 'adminCode', 'expected' => 'Two order abc confirmed.', 'description' => 'no admin language reads English'],
            ['config' => ['config_language_catalog' => 'sv-se', 'config_language' => 'nb-no'], 'pick' => 'shopCode', 'expected' => 'Two-ordern abc är bekräftad.', 'description' => 'the shop default wins over the request language'],
            ['config' => ['config_language' => 'nb-no'], 'pick' => 'shopCode', 'expected' => 'Two-ordren abc er bekreftet.', 'description' => 'no shop default reads the request language'],
        ];
        foreach ($cases as $case) {
            $config = new Config();
            foreach ($case['config'] as $key => $value) {
                $config->set($key, $value);
            }
            $language = new Language('en-gb', $root . '/admin/language/');
            // What a Norwegian pack's default.php sets: the HTML lang, not the folder.
            $language->set('code', 'nb');
            $code = $case['pick'] === 'adminCode' ? NoteText::adminCode($config) : NoteText::shopCode($config);
            TinyAssert::same($case['expected'], NoteText::render($message, new Loader($language), $language, $code), $case['description']);
        }

        foreach (['admin', 'catalog', 'system/library/opencart'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/language/')) {
                    TinyAssert::true(preg_match('/language->get\(\s*([\'"])code\1\s*\)/', (string) file_get_contents($file->getPathname())) === 0, $file->getFilename() . ': the language folder comes from NoteText::adminCode or shopCode, not default.php\'s HTML lang code');
                }
            }
        }
    }
}
