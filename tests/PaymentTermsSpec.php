<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;

/**
 * Payment terms (Build Guide section 4): the record's terms narrowed by the
 * merchant's settings, the default-term chain, the stored block, and the
 * Payment terms tab's save rules.
 */
final class PaymentTermsSpec
{
    private const NOW = 1_800_000_000;

    public static function runAll(): void
    {
        self::testOffered();
        self::testDefault();
        self::testSelected();
        self::testAccept();
        self::testBlock();
        self::testSave();
    }

    private static function testOffered(): void
    {
        $cases = [
            [['available_terms' => [60, 30, '14', 30, 0, -7, 'x', 2.5]], ['payment_terms' => '[14,30,60]'], [14, 30, 60], 'the record\'s terms: positive whole days, unique, ascending'],
            [['available_terms' => [14, 30, 60, 90]], ['payment_terms' => null], [30, 60, 90], 'a shop with nothing stored offers the fresh-install ticks: 30, 60 and 90 days'],
            [['available_terms' => [14, 45]], ['payment_terms' => null], [], 'none of the fresh-install ticks offered: no term'],
            [[], [], [], 'a record with no terms offers none'],
            [null, [], [], 'no record offers none'],
            [['available_terms' => [14, 30, 60]], ['payment_terms' => '[30,60]'], [30, 60], 'narrowed to the ticked terms'],
            [['available_terms' => [14, 30]], ['payment_terms' => '[30,90]'], [30], 'a ticked term the record withdrew is not offered'],
            [['available_terms' => [14, 30, 45, 60, 90]], ['payment_term_type' => 'EOM', 'payment_terms' => '[14,30,45,60,90]'], [30, 45, 60], 'end of month offers 30, 45 and 60 only'],
            [['available_terms' => [14, 30]], ['payment_terms' => 'junk'], [], 'an unreadable ticked list ticks none'],
            [['available_terms' => [14, 30]], ['payment_terms' => '[]'], [], 'nothing ticked offers nothing'],
        ];
        foreach ($cases as [$record, $settings, $expected, $description]) {
            TinyAssert::same($expected, self::terms($record, $settings)->offered(), 'offered: ' . $description);
        }
    }

    private static function testDefault(): void
    {
        $cases = [
            [['available_terms' => [45]], ['default_payment_term' => '30'], 45, 'a single offered term wins outright'],
            [['available_terms' => [14, 30, 60]], ['default_payment_term' => '60'], 60, 'the merchant\'s own default when offered'],
            [['available_terms' => [14, 30, 60], 'due_in_days' => 14], ['default_payment_term' => '90'], 14, 'then the record\'s due_in_days when offered'],
            [['available_terms' => [14, 30, 60], 'due_in_days' => 90], [], 30, 'then 30 days when offered'],
            [['available_terms' => [14, 60]], [], 14, 'then the shortest offered term'],
            [['available_terms' => []], ['default_payment_term' => '30'], null, 'nothing offered has no default'],
        ];
        foreach ($cases as [$record, $settings, $expected, $description]) {
            TinyAssert::same($expected, self::terms($record, $settings)->defaultTerm(), 'default term: ' . $description);
        }
    }

    private static function testSelected(): void
    {
        $terms = self::terms(['available_terms' => [14, 30, 60]], []);
        foreach ([[60, 60, 'an offered choice stands'], [90, 30, 'a choice the record does not offer falls back to the default'], [null, 30, 'no choice is the default']] as [$chosen, $expected, $description]) {
            TinyAssert::same($expected, $terms->selected($chosen), 'selected: ' . $description);
        }
    }

    /** The tile's two.term: only a whole number of days the record offers is kept. */
    private static function testAccept(): void
    {
        $terms = self::terms(['available_terms' => [14, 30, 60]], ['payment_terms' => '[14,30]']);
        $cases = [['30', 30, 'an offered term'], [30, 30, 'an offered term as a number'], [' 14 ', 14, 'padded'], ['60', null, 'a record term the merchant did not tick'], ['90', null, 'a term the record does not offer'], ['30.0', null, 'not whole days'], ['-30', null, 'negative'], ['', null, 'empty'], [null, null, 'absent'], [['30'], null, 'a list']];
        foreach ($cases as [$posted, $expected, $description]) {
            TinyAssert::same($expected, $terms->accept($posted), 'accept: ' . $description);
        }
    }

    private static function testBlock(): void
    {
        $cases = [
            [30, false, '{"type":"NET_TERMS","duration_days":30}', 'standard'],
            [45, true, '{"type":"NET_TERMS","duration_days":45,"duration_days_calculated_from":"END_OF_MONTH"}', 'end of month'],
        ];
        foreach ($cases as [$days, $eom, $json, $description]) {
            TinyAssert::same($json, json_encode(PaymentTerms::block($days, $eom)), 'block: ' . $description);
            TinyAssert::same(PaymentTerms::block($days, $eom), PaymentTerms::decode($json), 'block read back: ' . $description);
        }
        foreach (['', 'junk', '{"duration_days":0}', '{"duration_days":"30"}'] as $stored) {
            TinyAssert::same(null, PaymentTerms::decode($stored), 'block read back: "' . $stored . '" is none');
        }
    }

    private static function testSave(): void
    {
        $cases = [
            [['payment_terms' => ['14', '30']], ['payment_terms' => '[14,30]'], [], 'ticked terms the record offers are saved'],
            [['payment_terms' => ['', '30']], ['payment_terms' => '[30]'], [], 'the form\'s empty placeholder is ignored'],
            [['payment_terms' => ['']], [], ['payment_terms' => SettingsSave::TERMS_NONE], 'no ticked term is refused'],
            [['payment_terms' => ['30', '90']], [], ['payment_terms' => SettingsSave::TERMS_UNKNOWN], 'a term the record does not offer is refused'],
            [['payment_term_type' => 'EOM', 'payment_terms' => ['14']], ['payment_term_type' => 'EOM'], ['payment_terms' => SettingsSave::TERMS_NONE], 'end of month with only a term it cannot offer is refused'],
            [['payment_term_type' => 'NET'], [], ['payment_term_type' => SettingsSave::TERM_TYPE_UNKNOWN], 'an unknown term type is refused'],
            [['payment_terms' => ['14', '30'], 'default_payment_term' => '30'], ['payment_terms' => '[14,30]', 'default_payment_term' => '30'], [], 'a default among the ticked terms'],
            [['payment_terms' => ['14'], 'default_payment_term' => '30'], ['payment_terms' => '[14]'], ['default_payment_term' => SettingsSave::DEFAULT_TERM_UNKNOWN], 'a default that is not ticked is refused'],
            [['default_payment_term' => ''], ['default_payment_term' => ''], [], 'a blank default is automatic'],
        ];
        foreach ($cases as [$submitted, $written, $errors, $description]) {
            [$save, $store] = self::save(['available_terms' => [14, 30, 60]]);
            $result = $save->save($submitted, [], 0);
            TinyAssert::same($errors, $result['errors'], 'save errors: ' . $description);
            TinyAssert::same($written, array_intersect_key($store->values, $written + array_flip(['payment_terms', 'payment_term_type', 'default_payment_term'])), 'save writes: ' . $description);
        }
    }

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, string|null> $settings
     */
    private static function terms(?array $record, array $settings): PaymentTerms
    {
        return PaymentTerms::from($record, new SettingsProvider(new MemoryStore($settings + ['payment_terms' => '[14,30,45,60,90]'])));
    }

    /**
     * @param array<string, mixed> $record
     * @return array{0: SettingsSave, 1: MemoryStore}
     */
    private static function save(array $record): array
    {
        $store = new MemoryStore(['api_key' => 'k']);
        $provider = new SettingsProvider($store);
        $store->values[RecordProvider::CACHE_KEY] = (string) json_encode(['fingerprint' => $provider->credentialFingerprint(), 'fetched_at' => self::NOW - 60, 'record' => $record + ['id' => 'm1']]);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $provider, new CustomHeaders(), new ScriptedTransport(), $logger, '1.0.0');
        $clock = static fn (): int => self::NOW;
        $verdict = new ApiKeyVerdict($client, $provider, $clock);
        $values = $store->values;
        $save = new SettingsSave($provider, new Environment(false), $verdict, new RecordProvider($client, $provider, $verdict, $logger, $clock));
        $store->values = $values;

        return [$save, $store];
    }
}
