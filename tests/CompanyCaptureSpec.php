<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CompanyCapture;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\RateLimiter;
use Opencart\System\Library\Extension\Two\Core\RequestToken;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The company search's captures on the shop (TWO-26721): what a save may
 * hold, what a saved address keeps, what the checkout reads back, the
 * session reload around a slow relay call, the relay's token, its answers'
 * shapes and the tile placement rule.
 */
final class CompanyCaptureSpec
{
    private const ACME = ['company_name' => 'Acme Ltd', 'organization_number' => '0123', 'country_code' => 'GB', 'source' => 'search'];

    public static function runAll(): void
    {
        self::testFromPost();
        self::testKeep();
        self::testForAddress();
        self::testTopUp();
        self::testRelay();
        self::testIntentRelay();
        self::testRequestToken();
        self::testShapes();
        self::testTilePlacement();
    }

    private static function testFromPost(): void
    {
        $post = ['company_name' => 'Acme Ltd', 'organization_number' => '0123', 'country' => 'gb', 'source' => 'search'];
        $cases = [
            [$post, self::ACME, 'a pick, the country upper cased'],
            [['source' => 'manual'] + $post, array_replace(self::ACME, ['organization_number' => '', 'source' => 'manual']), 'manual entry is the name only: a posted number is dropped'],
            [['source' => 'typed'] + $post, null, 'an unknown source'],
            [['country' => 'GBR'] + $post, null, 'a country that is not ISO-2'],
            [['company_name' => "Acme\nLtd"] + $post, null, 'a control character'],
            [['organization_number' => str_repeat('1', 65)] + $post, null, 'a number longer than the order record holds'],
            [['company_name' => ''] + $post, array_replace(self::ACME, ['company_name' => '']), 'an empty name, which clears the role'],
        ];
        foreach ($cases as [$given, $expected, $description]) {
            TinyAssert::same($expected, CompanyCapture::fromPost($given), 'fromPost: ' . $description);
        }
    }

    private static function testKeep(): void
    {
        $beta = ['company_name' => 'Beta AS', 'organization_number' => '9876', 'country_code' => 'NO', 'source' => 'search'];
        $numberless = array_replace(self::ACME, ['organization_number' => '']);
        $storedAcme = ['customer_id' => 7, 'custom_field' => ['1' => 'x', CompanyCapture::KEY => self::ACME]];
        $roles = ['payment', 'shipping', 'account'];
        $cases = [
            [[CompanyCapture::KEY => self::ACME, '1' => 'x'], 'Acme Ltd', [], [], 7, ['1' => 'x'], 'a capture posted with the form is dropped: the form cannot forge one'],
            [[], 'Acme Ltd', ['payment' => self::ACME], [], 7, [CompanyCapture::KEY => self::ACME], 'a new address keeps the session capture of the same name'],
            [[], 'Acme Ltd', ['shipping' => $beta, 'account' => self::ACME], [], 7, [CompanyCapture::KEY => self::ACME], 'the first role whose capture has the address\'s name'],
            [[], 'Other Ltd', ['payment' => self::ACME], [], 7, [], 'a capture of another name is not kept'],
            [[], '', ['payment' => self::ACME], [], 7, [], 'an address with no company keeps nothing'],
            [['1' => 'x'], 'Acme Ltd', [], $storedAcme, 7, ['1' => 'x', CompanyCapture::KEY => self::ACME], 'an edit with no fresh capture keeps the stored one'],
            [['1' => 'x'], 'Renamed Ltd', [], $storedAcme, 7, ['1' => 'x'], 'an edit that renames the company drops it'],
            [['1' => 'x'], 'Acme Ltd', [], ['customer_id' => 8] + $storedAcme, 7, ['1' => 'x'], 'another customer\'s address keeps nothing of its own'],
            [[], 'Acme Ltd', ['payment' => $numberless], $storedAcme, 7, [CompanyCapture::KEY => self::ACME], 'a capture saved before its number arrived takes it from the stored one'],
            [[], 'Acme Ltd', ['payment' => $numberless, 'account' => self::ACME], [], 7, [CompanyCapture::KEY => self::ACME], 'or from another role\'s capture of the same name'],
            [[], 'Acme Ltd', ['account' => array_replace(self::ACME, ['source' => 'manual', 'organization_number' => ''])], $storedAcme, 7, [CompanyCapture::KEY => array_replace(self::ACME, ['organization_number' => '', 'source' => 'manual'])], 'a manual entry stays name only'],
        ];
        $sweden = array_replace(self::ACME, ['organization_number' => '5560001111', 'country_code' => 'SE']);
        $soleTrader = array_replace(self::ACME, ['organization_number' => 'TWO:ST1', 'source' => 'sole_trader']);
        foreach ([['Smith & Sons Ltd', 'Smith &amp; Sons Ltd', 'an ampersand'], ['O\'Neil "Bros" Ltd', 'O&#039;Neil &quot;Bros&quot; Ltd', 'quotes'], ['A<B>C Ltd', 'A&lt;B&gt;C Ltd', 'angle brackets']] as [$plain, $escaped, $what]) {
            $capture = array_replace(self::ACME, ['company_name' => $plain]);
            $cases[] = [[], $escaped, ['payment' => $capture], [], 7, [CompanyCapture::KEY => $capture], 'OpenCart posts the company HTML-escaped: ' . $what . ' still matches'];
        }
        $cases[] = [[], 'Acme Ltd', ['payment' => $numberless, 'account' => $sweden], [], 7, [CompanyCapture::KEY => $numberless], 'a GB capture never takes a Swedish company\'s number of the same name'];
        $cases[] = [[], 'Acme Ltd', ['payment' => $numberless, 'account' => $soleTrader], [], 7, [CompanyCapture::KEY => $numberless], 'nor a sole trader\'s'];
        foreach ($cases as [$posted, $company, $captures, $stored, $customer, $expected, $description]) {
            TinyAssert::same($expected, CompanyCapture::keep($posted, $company, $captures, $roles, $stored, $customer), 'keep: ' . $description);
        }
    }

    private static function testForAddress(): void
    {
        $saved = static fn (?array $capture): array => ['address_id' => 5, 'custom_field' => $capture === null ? [] : [CompanyCapture::KEY => $capture]];
        $row = static fn (?array $capture): array => $capture === null ? ['1' => 'x'] : ['1' => 'x', CompanyCapture::KEY => $capture];
        $numberless = array_replace(self::ACME, ['organization_number' => '']);
        // OpenCart's session copy of a saved address: what was posted, so no capture.
        $posted = ['address_id' => 5, 'custom_field' => ['1' => 'x']];
        $cases = [
            [['address_id' => 0], self::ACME, null, self::ACME, 'a new address: the session\'s capture'],
            [null, null, null, null, 'nothing anywhere'],
            [$posted, null, $row(self::ACME), self::ACME, 'registered at checkout: the stored row has the capture the session copy lacks'],
            [$saved(self::ACME), null, $row(null), null, 'the stored row is the truth: a session copy is never read'],
            [$saved(self::ACME), self::ACME, null, null, 'no row for this customer: no capture at all'],
            [$posted, self::ACME, $row(null), null, 'a saved address that keeps nothing: never a capture made on another form'],
            [$posted, self::ACME, $row($numberless), self::ACME, 'its missing number from the same-named session capture'],
            [$posted, array_replace(self::ACME, ['company_name' => 'Other']), $row($numberless), $numberless, 'but not from another company'],
            [$posted, array_replace(self::ACME, ['organization_number' => '5560001111', 'country_code' => 'SE']), $row($numberless), $numberless, 'nor from a same-named company in another country'],
            [$posted, array_replace(self::ACME, ['organization_number' => 'TWO:ST1', 'source' => 'sole_trader']), $row($numberless), $numberless, 'nor from a sole trader of the same name'],
            [$posted, array_replace($numberless, ['source' => 'manual']), $row(self::ACME), array_replace($numberless, ['source' => 'manual']), 'Enter manually on the same name disowns the number the row kept (TWO-26775)'],
            [$posted, array_replace($numberless, ['company_name' => 'Other', 'source' => 'manual']), $row(self::ACME), self::ACME, 'a manual capture of another name leaves the row alone'],
        ];
        foreach ($cases as [$address, $session, $stored, $expected, $description]) {
            TinyAssert::same($expected, CompanyCapture::forAddress($address, $session, $stored), 'forAddress: ' . $description);
        }
        // The address edit form: the edited address's own capture, or none.
        $form = [
            [$saved(self::ACME), self::ACME, 'the address being edited keeps one'],
            [$saved(null), null, 'it keeps none: the form starts empty'],
            [[], null, 'a new address'],
            [$saved(['company_name' => ' ']), null, 'a blank one is none'],
        ];
        foreach ($form as [$address, $expected, $description]) {
            TinyAssert::same($expected, CompanyCapture::ofAddress($address), 'edit form seed: ' . $description);
        }
    }

    private static function testTopUp(): void
    {
        $numberless = [CompanyCapture::KEY => array_replace(self::ACME, ['organization_number' => ''])];
        $cases = [
            [$numberless, self::ACME, [CompanyCapture::KEY => self::ACME], 'the number a later save supplied'],
            [[CompanyCapture::KEY => self::ACME], array_replace(self::ACME, ['organization_number' => '999']), null, 'a number already held is never replaced'],
            [$numberless, array_replace(self::ACME, ['company_name' => 'Other']), null, 'another company'],
            [[], self::ACME, null, 'an address that keeps nothing'],
            [$numberless, array_replace(self::ACME, ['organization_number' => '5560001111', 'country_code' => 'SE']), null, 'a GB capture with an SE number: never written onto the GB address'],
            [[CompanyCapture::KEY => array_replace(self::ACME, ['organization_number' => '', 'source' => 'sole_trader'])], self::ACME, null, 'a numberless sole trader never takes a registered company\'s number'],
        ];
        foreach ($cases as [$stored, $capture, $expected, $description]) {
            TinyAssert::same($expected, CompanyCapture::topUp($stored, $capture), 'topUp: ' . $description);
        }
    }

    /**
     * OpenCart writes a request's whole session back when it ends. The two
     * races the review found, replayed over a stored session and two
     * requests' copies of it: a slow call's write-back must not undo a save
     * that landed while it was out.
     */
    private static function testRelay(): void
    {
        $cases = [
            [['companies' => ['payment' => self::ACME]], ['companies' => []], 'sole_trader', ['country' => 'NO', 'types' => ['SOLE_TRADER']], 'a country change with a slow sole-trader answer: the cleared capture stays cleared'],
            [['companies' => []], ['companies' => ['payment' => self::ACME]], null, null, 'a search that lands after a pick: the pick stays'],
        ];
        foreach ($cases as [$before, $saved, $key, $kept, $description]) {
            $store = $before;
            $request = $store;
            $answer = CompanyCapture::relay(
                static function () use (&$store, $saved): string {
                    $store = $saved;

                    return 'answer';
                },
                static function () use (&$request, &$store): void {
                    $request = $store;
                },
                $key === null ? null : static function (string $answer) use (&$request, $key, $kept): void {
                    $request[$key] = $kept;
                },
            );
            $store = $request;
            $expected = $key === null ? $saved : $saved + [$key => $kept];
            TinyAssert::same(['answer', $expected], [$answer, $store], 'relay: ' . $description);
        }
    }

    /**
     * The order intent can wait on Two for seconds while OpenCart saves a
     * shipping or address change: that change stands, and this request's
     * intent stamp is kept beside any another request stored.
     */
    private static function testIntentRelay(): void
    {
        $store = ['shipping_method' => 'flat', 'two_checkout' => ['intent_stamps' => [100]]];
        $request = $store;
        $ours = [100, 160];
        $request['two_checkout']['intent_stamps'] = $ours;
        $answer = CompanyCapture::relay(
            static function () use (&$store): string {
                $store['shipping_method'] = 'pickup';
                $store['two_checkout']['intent_stamps'] = [100, 150];

                return 'approved';
            },
            static function () use (&$request, &$store): void {
                $request = $store;
            },
            static function (string $answer) use (&$request, $ours): void {
                $request['two_checkout']['intent_stamps'] = RateLimiter::keepStamps($request['two_checkout']['intent_stamps'], $ours, 120);
            },
        );
        TinyAssert::same(['approved', 'pickup', [100, 150, 160]], [$answer, $request['shipping_method'], $request['two_checkout']['intent_stamps']], 'intent: the change saved meanwhile stands, and both stamps count');
        TinyAssert::same([150, 160], RateLimiter::keepStamps([10, 150], [160, 160], 170), 'intent stamps: once each, the window applied');
    }

    private static function testRequestToken(): void
    {
        $cases = [
            [false, false, 'GET', [], ['two_token' => 'tok'], 'tok', true, 'a read with the token on the query string'],
            [false, false, 'GET', [], ['two_token' => 'bad'], 'tok', false, 'a read with the wrong token'],
            [true, false, 'POST', ['two_token' => 'tok'], [], 'tok', true, 'a write with the token in the body'],
            [true, false, 'POST', [], ['two_token' => 'tok'], 'tok', false, 'a write does not take the token from the query string'],
            [true, true, 'POST', [], ['two_token' => 'tok'], 'tok', true, 'the sole-trader mint takes it from the query string'],
            [true, true, 'GET', [], ['two_token' => 'tok'], 'tok', false, 'the mint is still POST only'],
            [true, true, 'POST', ['two_token' => 'tok'], [], 'tok', false, 'and only from the query string'],
            [false, false, 'GET', [], ['two_token' => ''], '', false, 'a session with no token yet accepts nothing'],
            [false, false, 'GET', [], ['two_token' => ['tok']], 'tok', false, 'an array is not a token'],
            [false, false, 'GET', [], ['two_token' => 'tok'], null, false, 'no stored token'],
        ];
        foreach ($cases as [$write, $query, $method, $post, $get, $stored, $expected, $description]) {
            TinyAssert::same($expected, RequestToken::accepts($write, $query, $method, $post, $get, $stored), 'request token: ' . $description);
        }
    }

    private static function testShapes(): void
    {
        TinyAssert::same(['ok' => true, 'status' => 200, 'body' => ['supported_countries' => ['GB', 'NO']]], CompanyLookup::countriesEnvelope(['GB', 'NO']), 'countries: the envelope the shared component reads');
        TinyAssert::same(['ok' => false, 'status' => 502, 'body' => ['supported_countries' => []]], CompanyLookup::countriesEnvelope(null), 'countries: Two did not answer, so the component fails open');
        $types = [
            [[self::json(200, ['supported_company_types' => ['LIMITED_COMPANY', 'sole_trader', 5, ' ']])], 'gb', ['LIMITED_COMPANY', 'SOLE_TRADER'], 1, 'the types upper cased, junk dropped'],
            [[self::json(503, [])], 'GB', null, 1, 'no answer from the registry'],
            [[self::json(200, ['other' => []])], 'GB', null, 1, 'a malformed answer'],
            [[], 'GBR', [], 0, 'a country that is not one: nothing asked'],
        ];
        foreach ($types as [$responses, $country, $expected, $calls, $description]) {
            $transport = new ScriptedTransport($responses);
            $settings = new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox']));
            $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, new RecordingLogger(), '1.0.0');
            $lookup = new CompanyLookup($client, new Environment(false), $settings);
            TinyAssert::same([$expected, $calls], [$lookup->supportedCompanyTypes($country), count($transport->calls)], 'company types: ' . $description);
        }
    }

    private static function testTilePlacement(): void
    {
        $cases = [
            [[], false, 'a fresh install: the search is in the address forms, not the tile'],
            [['company_search_in_address' => '1'], false, 'address placement'],
            [['company_search_in_address' => '0'], true, 'tile placement'],
        ];
        foreach ($cases as [$stored, $expected, $description]) {
            TinyAssert::same($expected, (new SettingsProvider(new MemoryStore($stored)))->companyControlInTile(), 'tile placement: ' . $description);
        }
        // The rule takes no capture at all: what the buyer captured cannot move the control.
        $method = new ReflectionMethod(SettingsProvider::class, 'companyControlInTile');
        TinyAssert::same(0, $method->getNumberOfParameters(), 'tile placement: decided by the setting alone');
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }
}
