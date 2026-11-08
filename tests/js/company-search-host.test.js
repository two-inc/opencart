/**
 * The OpenCart host for the shared company search (TWO-26721): placement,
 * the tile rule, the transport, saved captures, manual entry and the address
 * autofill, over the vendored modules as they ship.
 */
require('../../catalog/view/javascript/two/company-identity.js');
require('../../catalog/view/javascript/two/company-search-panel.js');
require('../../catalog/view/javascript/two/sole-trader.js');
require('../../catalog/view/javascript/two/company-capture-component.js');
const { Api } = require('../../catalog/view/javascript/two_checkout.js');
const { mount, PANEL_COPY } = require('../../catalog/view/javascript/two/company-search-host.js');

const I18N = {
    chip_registered: 'Registrert firma', chip_sole_trader: 'Enkeltpersonforetak', chip_manual: 'Skriv inn manuelt',
    search_aria: 'Søk', search_too_short: 'Minst 3', no_matches: 'Ingen treff', search_unavailable: 'Nede',
    select_country: 'Velg land', results_one: '1 treff', results_many: '%d treff', company_number: 'Org.nr',
    select_different_sole_trader: 'Velg en annen', sole_trader_signup: 'Registrer deg', sole_trader_error: 'Feil',
    address_unavailable: 'Fant ikke adressen'
};

function config(overrides) {
    return Object.assign({
        token: 'tok',
        in_address: true,
        address_lookup: true,
        checkout_api_url: 'https://api.example', checkout_page_url: 'https://checkout.example',
        urls: { search: 'index.php?route=s', countries: 'index.php?route=c', sole_trader: 'index.php?route=st', sole_trader_tokens: 'index.php?route=stt', save_company: 'index.php?route=save', company_details: 'index.php?route=d' },
        countries: { 222: 'GB', 160: 'NO' },
        companies: {},
        i18n: I18N
    }, overrides || {});
}

/** A shop answering by route; every call is recorded as [route, params]. An answer of { status } is that HTTP status. */
function shop(answers) {
    const calls = [];
    const fetchImpl = jest.fn((url, init) => {
        const query = new URLSearchParams(String(url).split('?')[1]);
        const params = init && typeof init.body === 'string' && init.body[0] !== '{' ? Object.fromEntries(new URLSearchParams(init.body)) : Object.fromEntries(query);
        const route = query.get('route');
        delete params.route;
        calls.push([route, params]);
        const fallback = { c: { ok: true, status: 200, body: { supported_countries: ['GB', 'NO'] } }, st: ['LIMITED_COMPANY'], save: { ok: true } }[route];
        const answer = route in answers ? (typeof answers[route] === 'function' ? answers[route](params) : answers[route]) : fallback;
        const status = answer && answer.status && Object.keys(answer).length === 1 ? answer.status : 200;
        return Promise.resolve({ ok: status === 200, status: status, headers: { get: () => null }, json: () => Promise.resolve(answer === undefined ? {} : answer) });
    });
    window.fetch = fetchImpl;
    return { calls, fetchImpl };
}

const ADDRESS = (role) => '<form id="form-' + role + '-address">'
    + '<div><input id="input-' + role + '-company"></div>'
    + '<input id="input-' + role + '-address-1"><input id="input-' + role + '-address-2"><input id="input-' + role + '-city"><input id="input-' + role + '-postcode">'
    + '<select id="input-' + role + '-country"><option value="222">UK</option><option value="160">Norway</option></select>'
    + '<select id="input-' + role + '-zone"><option value="1">Select</option><option value="2">Greater London</option></select></form>';

const TILE = '<form id="form-two"><div><input id="input-two-company" data-two-country="GB"></div></form>';

const flush = async () => {
    for (let i = 0; i < 5; i += 1) await new Promise((resolve) => setTimeout(resolve, 0));
};

let live = [];

function start(cfg, answers) {
    const recorded = shop(answers || {});
    const companies = [];
    const hosts = mount({ config: cfg, api: new Api(cfg, recorded.fetchImpl), doc: document, alert: () => {}, onCompany: (role, company) => companies.push([role, company]) });
    live = live.concat(Object.values(hosts));
    return Object.assign(recorded, { hosts, companies });
}

const wrapped = (id) => document.getElementById(id).parentElement.classList.contains('two-company-field-wrap');

afterEach(() => {
    live.forEach((host) => host.stop());
    live = [];
    document.body.innerHTML = '';
    jest.useRealTimers();
});

describe('placement', () => {
    test.each([
        [true, ['payment', 'shipping'], { 'input-payment-company': true, 'input-shipping-company': true, 'input-two-company': false }, 'address placement: one control per address form, the tile field left plain'],
        [false, ['tile'], { 'input-payment-company': false, 'input-shipping-company': false, 'input-two-company': true }, 'tile placement: the tile field only, the address fields left plain'],
    ])('in_address %s', async (inAddress, roles, mounted, description) => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping') + TILE;
        const { hosts } = start(config({ in_address: inAddress }));
        await flush();
        const seen = Object.fromEntries(Object.keys(mounted).map((id) => [id, wrapped(id)]));
        expect([Object.keys(hosts), seen, description]).toEqual([roles, mounted, description]);
    });

    test.each([
        [{ payment: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } }, 'a capture in the address forms'],
        [{}, 'nothing captured anywhere'],
    ])('the tile control follows the setting alone: %s', async (companies, description) => {
        document.body.innerHTML = ADDRESS('payment') + TILE;
        start(config({ in_address: true, companies: companies }));
        await flush();
        expect([wrapped('input-two-company'), description]).toEqual([false, description]);
    });

    test.each([
        [true, ['registered', 'manual'], 'address placement offers manual entry'],
        [false, ['registered'], 'the tile never does'],
    ])('chips with in_address %s', async (inAddress, chips, description) => {
        document.body.innerHTML = ADDRESS('payment') + TILE;
        start(config({ in_address: inAddress }));
        await flush();
        const field = document.getElementById(inAddress ? 'input-payment-company' : 'input-two-company');
        field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        field.focus();
        await flush();
        const panel = field.parentElement.querySelector('.two-company-dropdown');
        const visible = Array.from(panel.querySelectorAll('.two-company-mode-chip')).filter((chip) => !chip.classList.contains('two-hidden'));
        expect([visible.map((chip) => chip.getAttribute('data-two-chip')), visible.map((chip) => chip.textContent), description])
            .toEqual([chips, chips.map((mode) => ({ registered: 'Registrert firma', manual: 'Skriv inn manuelt' })[mode]), description]);
    });

    test('the address edit form gets one control on its own company field', async () => {
        document.body.innerHTML = '<form id="form-address"><div><input id="input-company"></div><select id="input-country"><option value="222">UK</option></select></form>';
        const { hosts } = start(config({ roles: ['account'] }));
        await flush();
        expect([Object.keys(hosts), wrapped('input-company'), hosts.account.country()]).toEqual([['account'], true, 'GB']);
    });
});

describe('transport', () => {
    const term = (host, value, country) => host.transport.searchCompanies({ term: value, getCountryCode: () => country });

    test.each([
        [{ items: [{ name: 'Acme Ltd', organization_number: '0123', highlight: '<mark>Acme</mark> Ltd', lookup_id: 'L1' }] }, 'GB', { items: [{ text: 'Acme Ltd', html: '<mark>Acme</mark> Ltd (0123)', companyId: '0123', lookupId: 'L1' }] }, 'a row carries its number in brackets'],
        [{ items: [{ name: 'Jo Bloggs', organization_number: 'TWO:ST1', highlight: '', lookup_id: '' }] }, 'GB', { items: [{ text: 'Jo Bloggs', html: 'Jo Bloggs', companyId: 'TWO:ST1', lookupId: '' }] }, 'a TWO: number is never shown'],
        [{ error: 'unavailable' }, 'GB', { unavailable: true }, 'the relay failing reads as unavailable, not as no match'],
        [{ items: [], degraded: true }, 'GB', { unavailable: true }, 'a degraded answer is unavailable'],
        [{ error: 'Too many', code: 'rate_limited' }, 'GB', { unavailable: true }, 'the shop\'s rate limit is unavailable'],
        [{ items: [] }, '', { items: [] }, 'no country: nothing sent'],
    ])('%#', async (answer, country, expected, description) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config(), { s: answer });
        await flush();
        expect([await term(hosts.payment, 'acme', country), description]).toEqual([expected, description]);
        expect(calls.filter(([route]) => route === 's').length).toBe(country ? 1 : 0);
    });

    test('a 429 parks this instance alone, and an answer is cached per country and term', async () => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping');
        let status = 429;
        const { hosts, calls } = start(config(), { s: () => (status === 429 ? { error: 'Too many', code: 'rate_limited' } : { items: [] }) });
        await flush();
        await term(hosts.payment, 'acme', 'GB');
        status = 200;
        expect(await term(hosts.payment, 'acme', 'GB')).toEqual({ unavailable: true });
        await term(hosts.shipping, 'acme', 'GB');
        await term(hosts.shipping, 'acme', 'GB');
        expect(calls.filter(([route]) => route === 's').length).toBe(2);
    });

    test('a search in flight holds back the save a pick makes, so its write-back cannot undo the pick', async () => {
        document.body.innerHTML = ADDRESS('payment');
        let answer;
        const { hosts, calls } = start(config({ address_lookup: false }), { s: () => new Promise((resolve) => { answer = resolve; }) });
        await flush();
        const searching = term(hosts.payment, 'acme', 'GB');
        await flush();
        hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: '' });
        await flush();
        const order = () => calls.map(([route]) => route).filter((route) => route === 's' || route === 'save');
        expect(order()).toEqual(['s']);
        answer({ items: [] });
        await searching;
        await hosts.payment.flush();
        expect(order()).toEqual(['s', 'save']);
    });

    test.each([
        ['GB', 'Ingen treff'],
        ['', 'Velg land'],
    ])('the empty answer in country "%s" reads "%s"', async (country, message) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config({ countries: country ? { 222: 'GB' } : {} }));
        await flush();
        expect(hosts.payment.transport.noResultsMessage()).toBe(message);
    });

    test.each(Object.keys(PANEL_COPY))('the panel string "%s" is translated', (text) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config());
        expect(hosts.payment.translate(text)).not.toBe(text);
    });
});

describe('selection, saved captures and manual entry', () => {
    const pick = (host, item) => host.component.selectCompany(Object.assign({ text: 'Acme Ltd', companyId: '0123', lookupId: '' }, item));

    test('a pick is saved for its role only, with its number and country', async () => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping');
        const { hosts, calls, companies } = start(config({ address_lookup: false }));
        await flush();
        pick(hosts.payment);
        await hosts.payment.flush();
        await flush();
        expect(calls.filter(([route]) => route === 'save')).toEqual([['save', { two_token: 'tok', role: 'payment', company_name: 'Acme Ltd', organization_number: '0123', country: 'GB', source: 'search' }]]);
        expect(companies.pop()[0]).toBe('payment');
    });

    test('a row with no number takes it from the details call', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config({ address_lookup: false }), { d: { organization_number: '999', addresses: [] } });
        await flush();
        pick(hosts.payment, { companyId: '', lookupId: 'L1' });
        await flush();
        await hosts.payment.flush();
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Acme Ltd', organization_number: '999' });
    });

    test('a capture the session holds is restored into the field and the identity', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config({ companies: { payment: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } } }));
        await flush();
        await hosts.payment.flush();
        expect([document.getElementById('input-payment-company').value, hosts.payment.identity.companyId(), calls.filter(([route]) => route === 'save').length]).toEqual(['Acme Ltd', '0123', 0]);
        expect(document.querySelector('.two-company-id-text').textContent).toBe('0123');
    });

    test('manual entry is name only: the typed name is saved with no number', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config());
        await flush();
        hosts.payment.component.manualEntryMode();
        const field = document.getElementById('input-payment-company');
        field.value = 'Typed Ltd';
        field.dispatchEvent(new Event('input', { bubbles: true }));
        await hosts.payment.flush();
        await flush();
        expect(field.getAttribute('role')).toBe(null);
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Typed Ltd', organization_number: '', source: 'manual' });
    });

    test('Enter manually after a pick keeps the name on screen and saves it at once, with no number (TWO-26775)', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config({ address_lookup: false }));
        await flush();
        pick(hosts.payment);
        await hosts.payment.flush();
        hosts.payment.component.manualEntryMode();
        // No flush of the debounce: an address saved straight after must already see the manual capture.
        await flush();
        expect([document.getElementById('input-payment-company').value, calls.filter(([route]) => route === 'save').pop()[1]])
            .toEqual(['Acme Ltd', expect.objectContaining({ company_name: 'Acme Ltd', organization_number: '', source: 'manual' })]);
    });

    test.each([
        ['the buyer keeps the name', ''],
        ['the buyer types a new name first', 'Typed Ltd'],
    ])('a details answer landing after Enter manually writes no number, even back in search (TWO-26775): %s', async (why, typed) => {
        document.body.innerHTML = ADDRESS('payment');
        let answer;
        const pending = new Promise((resolve) => { answer = resolve; });
        const { hosts, calls } = start(config(), { d: () => pending });
        await flush();
        pick(hosts.payment, { companyId: '', lookupId: 'L1' });
        await flush();
        hosts.payment.component.manualEntryMode();
        if (typed) {
            const field = document.getElementById('input-payment-company');
            field.value = typed;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
        answer({ organization_number: '999', addresses: [] });
        await flush();
        hosts.payment.component.registeredMode();
        await hosts.payment.flush();
        await flush();
        expect([why, hosts.payment.identity.companyId(), calls.filter(([route]) => route === 'save').pop()[1].organization_number])
            .toEqual([why, '', '']);
    });

    test.each([
        ['from the pick, the answer still writes its number', [], '999'],
        ['from Enter manually, the answer writes nothing', [(host) => host.component.manualEntryMode()], ''],
    ])('a details answer landing while a Sole trader click is pending, then a hand-close (TWO-26775): %s', async (why, before, expectedId) => {
        document.body.innerHTML = ADDRESS('payment');
        let answer;
        const pending = new Promise((resolve) => { answer = resolve; });
        const { hosts, calls } = start(config(), { d: () => pending });
        await flush();
        pick(hosts.payment, { companyId: '', lookupId: 'L1' });
        await flush();
        before.forEach((act) => act(hosts.payment));
        hosts.payment.component.soleTraderMode();
        answer({ organization_number: '999', addresses: [] });
        await flush();
        hosts.payment.component.abandonSoleTrader({ handClosed: true });
        await hosts.payment.flush();
        await flush();
        expect([why, hosts.payment.identity.companyId(), calls.filter(([route]) => route === 'save').pop()[1].organization_number])
            .toEqual([why, expectedId, expectedId]);
    });

    test.each([
        [{ company_name: 'Acme Ltd', organization_number: '0123', source: 'manual' }, ['Acme Ltd', '', 'manual', false], 'a manual capture never brings a number back'],
        [{ company_name: 'Acme Ltd', organization_number: '0123', source: 'search' }, ['Acme Ltd', '0123', 'registered', false], 'a registered capture keeps its number'],
        [{ company_name: 'Ola Trader', organization_number: '777', source: 'sole_trader' }, ['Ola Trader', '777', 'soletrader', true], 'only a saved sole trader is restored as adopted'],
    ])('a reload restores, case %#', async (saved, expected, description) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config({ companies: { payment: Object.assign({ country_code: 'GB' }, saved) } }), { st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        const identity = hosts.payment.identity;
        expect([[document.getElementById('input-payment-company').value, identity.companyId(), identity.captureMode(), identity.soleTraderAdopted()], description])
            .toEqual([expected, description]);
    });
});

describe('address autofill', () => {
    const DETAILS = { organization_number: '0123', addresses: [{ type: 'POSTAL', street_address: 'PO Box 1', city: 'Nowhere', postal_code: 'X1' }, { type: 'REGISTERED', street_address: '1 High St', city: 'London', postal_code: 'EC1A 1AA', region: 'greater london' }] };
    const fields = (role) => ['address-1', 'city', 'postcode', 'zone'].map((suffix) => document.getElementById('input-' + role + '-' + suffix).value);

    test.each([
        [true, true, ['1 High St', 'London', 'EC1A 1AA', '2'], 'on: the registered address goes into this form, the zone matched by name'],
        [true, false, ['', '', '', '1'], 'the merchant switched it off'],
        [false, true, null, 'the tile has no address to fill'],
    ])('in_address %s, address_lookup %s', async (inAddress, lookup, expected, description) => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping') + TILE;
        const { hosts } = start(config({ in_address: inAddress, address_lookup: lookup }), { d: DETAILS });
        await flush();
        const host = hosts.payment || hosts.tile;
        await host.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        expect([expected ? fields('payment') : fields('payment').concat(fields('shipping')), description])
            .toEqual([expected || ['', '', '', '1', '', '', '', '1'], description]);
        expect(fields('shipping')).toEqual(['', '', '', '1']);
    });

    // PrestaShop's rules for a sole trader's record (TWO-26775). Each row starts from a field holding `before`, written by the plugin or not.
    test.each([
        ['city', 'Old Town', true, { city: 'Bergen' }, 'Bergen', 'a value overwrites the plugin\'s'],
        ['city', 'Typed Town', false, { city: 'Bergen' }, 'Bergen', 'a value overwrites the buyer\'s'],
        ['city', 'Old Town', true, { city: '   ' }, '', 'a blank clears the plugin\'s value'],
        ['city', 'Typed Town', false, { city: '   ' }, 'Typed Town', 'a blank keeps the buyer\'s value'],
        ['city', 'Old Town', true, { postal_code: '5003' }, '', 'left out: the plugin\'s value is cleared'],
        ['city', 'Typed Town', false, { postal_code: '5003' }, 'Typed Town', 'left out: the buyer\'s value stays'],
        ['zone', '', false, { region: 'greater london' }, '2', 'zone unanswered: filled'],
        ['zone', '2', false, { region: 'select' }, '2', 'zone the buyer chose: kept'],
        ['zone', '1', true, { region: 'greater london' }, '2', 'zone the plugin wrote: overwritten'],
    ])('a sole trader\'s address, %s case %#', async (suffix, before, ours, address, after, description) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config());
        await flush();
        const input = document.getElementById('input-payment-' + suffix);
        // OpenCart's own unanswered zone option.
        if (input.tagName === 'SELECT') input.insertAdjacentHTML('afterbegin', '<option value="">--- Please Select ---</option>');
        input.value = before;
        if (ours) input.setAttribute('data-two-autofilled-value', before);
        // As the sole-trader flow hands it over: blanks already dropped.
        hosts.payment.component.host().applyBuyerAddress(Object.fromEntries(Object.entries(address).filter(([, value]) => value.trim())));
        expect([input.value, description]).toEqual([after, description]);
    });

    test.each([
        ['+47 1', false, '+47 2', '+47 2', 'a number overwrites'],
        ['+47 1', true, '', '', 'a blank clears the plugin\'s number'],
        ['+47 1', false, '', '+47 1', 'a blank keeps the buyer\'s number'],
    ])('a sole trader\'s phone, case %#', async (before, ours, phone, after, description) => {
        document.body.innerHTML = ADDRESS('payment') + '<input id="input-telephone">';
        const { hosts } = start(config());
        await flush();
        const input = document.getElementById('input-telephone');
        input.value = before;
        if (ours) input.setAttribute('data-two-autofilled-value', before);
        hosts.payment.component.host().applyTelephone(phone);
        expect([input.value, description]).toEqual([after, description]);
    });

    // The registry's phone from a pick with autofill, on the buyer's own form (TWO-27110).
    test.each([
        [['payment'], 'payment', true, '+44 20 7946 0000', '', false, '+44 20 7946 0000', 'billing: the registry phone is written'],
        [['payment'], 'payment', true, undefined, '+44 1', true, '', 'no registry phone: the plugin\'s earlier number is cleared'],
        [['payment'], 'payment', true, undefined, '+44 1', false, '+44 1', 'no registry phone: the buyer\'s number stays'],
        [['shipping'], 'shipping', true, '+44 20 7946 0000', '', false, '+44 20 7946 0000', 'shipping, with no billing form on the page: the buyer\'s form'],
        [['payment', 'shipping'], 'shipping', true, '+44 20 7946 0000', '+44 1', false, '+44 1', 'shipping beside a billing form: not the buyer\'s phone'],
        [['payment'], 'payment', false, '+44 20 7946 0000', '+44 1', false, '+44 1', 'autofill switched off: nothing written'],
    ])('a pick\'s registry phone, case %#', async (forms, role, lookup, phone, before, ours, after, description) => {
        document.body.innerHTML = forms.map(ADDRESS).join('') + '<input id="input-telephone">';
        const address = Object.assign({ type: 'REGISTERED', street_address: '1 High St', city: 'London', postal_code: 'EC1A 1AA' }, phone === undefined ? {} : { phone_number: phone });
        const { hosts } = start(config({ address_lookup: lookup }), { d: { organization_number: '0123', addresses: [address] } });
        await flush();
        const input = document.getElementById('input-telephone');
        input.value = before;
        if (ours) input.setAttribute('data-two-autofilled-value', before);
        await hosts[role].component.selectCompany({ text: 'Acme Ltd', companyId: '', lookupId: 'L1' });
        await flush();
        expect([input.value, description]).toEqual([after, description]);
    });

    test('a details failure shows the address notice under the field', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config(), { d: { error: 'unavailable' } });
        await flush();
        await hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        const notice = document.querySelector('.two-company-address-notice');
        expect([notice.getAttribute('role'), notice.textContent]).toEqual(['alert', 'Fant ikke adressen']);
    });

    test('a country change clears only the fields still holding what was written', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config(), { d: DETAILS });
        await flush();
        await hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        document.getElementById('input-payment-city').value = 'Edited';
        const country = document.getElementById('input-payment-country');
        country.value = '160';
        country.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
        expect([fields('payment'), hosts.payment.identity.companyName()]).toEqual([['', 'Edited', '', ''], '']);
    });

    // The sole trader's verified phone (TWO-26775) is a plugin write too, so the same revert clears it (TWO-27110).
    test.each([
        ['leave', null, '', 'leaving sole-trader mode for a registered search clears it'],
        ['country', null, '', 'a country change clears it'],
        ['country', '+47 22 00 00 00', '+47 22 00 00 00', 'a phone the buyer typed since is kept'],
    ])('a sole trader\'s verified phone on %s, case %#', async (how, typed, after, description) => {
        document.body.innerHTML = ADDRESS('payment') + '<input id="input-telephone">';
        const { hosts } = start(config());
        await flush();
        const identity = hosts.payment.identity;
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);
        hosts.payment.component.host().applyTelephone('+47 99 99 99 99');
        const input = document.getElementById('input-telephone');
        expect(input.value).toBe('+47 99 99 99 99');
        if (typed !== null) input.value = typed;
        if (how === 'leave') {
            hosts.payment.component.registeredMode();
        } else {
            const country = document.getElementById('input-payment-country');
            country.value = '160';
            country.dispatchEvent(new Event('change', { bubbles: true }));
        }
        await flush();
        expect([input.value, description]).toEqual([after, description]);
    });

    // The registry phone is a plugin write like the address (TWO-27110): a country change clears it unless the buyer changed it.
    test.each([
        [null, '', 'the registry phone still in place: cleared'],
        ['+47 22 00 00 00', '+47 22 00 00 00', 'a phone the buyer typed since: kept'],
    ])('a country change after a pick with a registry phone, case %#', async (typed, after, description) => {
        document.body.innerHTML = ADDRESS('payment') + '<input id="input-telephone">';
        const { hosts } = start(config(), { d: { organization_number: '0123', addresses: [{ type: 'REGISTERED', street_address: '1 High St', city: 'London', postal_code: 'EC1A 1AA', phone_number: '+44 20 7946 0000' }] } });
        await flush();
        await hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        const input = document.getElementById('input-telephone');
        expect(input.value).toBe('+44 20 7946 0000');
        if (typed !== null) input.value = typed;
        const country = document.getElementById('input-payment-country');
        country.value = '160';
        country.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
        expect([input.value, description]).toEqual([after, description]);
    });
});

describe('the tile', () => {
    const { Checkout } = require('../../catalog/view/javascript/two_checkout.js');
    const host = require('../../catalog/view/javascript/two/company-search-host.js');

    test('tileReady saves every capture again, forced, then names the company and asks the intent once', async () => {
        document.body.innerHTML = ADDRESS('payment') + '<form id="form-two" data-two-tile="address"><p class="text-danger" data-two-company-summary>Add your company</p></form><div id="two-intent-notice"></div>';
        const acme = { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' };
        const cfg = config({ companies: { payment: acme }, urls: Object.assign({}, config().urls, { intent: 'index.php?route=i' }), i18n: Object.assign({ company_from_address: 'Buying for %s.' }, I18N) });
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'ok' } });
        window.TwoCompanySearchHost = host;
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window).start();
        live = live.concat(Object.values(checkout.instances));
        await flush();
        expect(calls.filter(([route]) => route === 'save').length).toBe(0);
        await checkout.tileReady(document.getElementById('form-two'));
        const routes = calls.map(([route]) => route).filter((route) => route === 'save' || route === 'i');
        expect(routes).toEqual(['save', 'save', 'i']);
        expect(calls.find(([route]) => route === 'save')[1]).toMatchObject({ role: 'payment', company_name: 'Acme Ltd', organization_number: '0123' });
        const summary = document.querySelector('[data-two-company-summary]');
        expect([summary.textContent, summary.classList.contains('text-danger')]).toEqual(['Buying for Acme Ltd (0123).', false]);
        delete window.TwoCompanySearchHost;
    });

    test('a saved address keeps its own summary: a capture in the hidden new-address form never replaces it', async () => {
        document.body.innerHTML = ADDRESS('payment') + '<form id="form-two" data-two-tile="address"><p class="text-danger" data-two-company-summary data-two-saved-address>Add your company to the address</p></form><div id="two-intent-notice"></div>';
        const beta = { company_name: 'Beta AS', organization_number: '9876', country_code: 'GB', source: 'search' };
        const cfg = config({ companies: { payment: beta }, urls: Object.assign({}, config().urls, { intent: 'index.php?route=i' }), i18n: Object.assign({ company_from_address: 'Buying for %s.' }, I18N) });
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'ok' } });
        window.TwoCompanySearchHost = host;
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window).start();
        live = live.concat(Object.values(checkout.instances));
        await flush();
        await checkout.tileReady(document.getElementById('form-two'));
        const summary = document.querySelector('[data-two-company-summary]');
        expect([summary.textContent, summary.classList.contains('text-danger'), calls.filter(([route]) => route === 'i').length])
            .toEqual(['Add your company to the address', true, 0]);
        delete window.TwoCompanySearchHost;
    });
});

describe('sole trader', () => {
    const TOKENS = { delegation_token: 'D1', autofill_token: 'A1' };
    const POPUP = { closed: false, close() {}, focus() {} };
    // A country select with nothing chosen yet, as a guest first sees it.
    const UNCHOSEN = (role) => ADDRESS(role).replace('<option value="222">', '<option value="" selected>Select</option><option value="222">');
    const tokenCalls = (calls) => calls.filter(([route]) => route === 'stt').map(([, params]) => params);
    // The blocked-popup panel over the popover (TWO-26775).
    const prompt = () => document.querySelector('.two-company-dropdown__signup');

    // The tokens are page-level in the vendored module, so each test starts with none.
    beforeEach(() => {
        Object.assign(window.TwoSoleTrader.prototype, { delegationToken: '', autofillToken: '', _prefetch: null, _autofillBuyer: null, _prefetchOwed: false });
    });

    afterEach(() => jest.restoreAllMocks());

    const choose = (role, value) => {
        const select = document.getElementById('input-' + role + '-country');
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    test.each([
        [ADDRESS('shipping'), () => {}, 'guest: the shipping form alone, fresh'],
        [ADDRESS('shipping'), (hosts) => hosts.shipping.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: '' }), 'guest: after a registered pick'],
        [UNCHOSEN('shipping'), () => choose('shipping', '222'), 'guest: no country chosen at load, then the UK'],
        [UNCHOSEN('payment') + ADDRESS('shipping'), () => {}, 'a payment form with no country beside a UK shipping form'],
    ])('the first click opens the popup, case %#', async (dom, before, description) => {
        document.body.innerHTML = dom;
        const open = jest.spyOn(window, 'open').mockReturnValue(POPUP);
        const { hosts, calls } = start(config({ address_lookup: false }), { stt: TOKENS, st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        before(hosts);
        await flush();
        hosts.shipping.component.soleTraderMode();
        const opened = open.mock.calls.map(([url]) => /businessToken=D1&autofillToken=A1/.test(url));
        // The token route takes no country: the boot mint runs before any panel has one.
        expect([opened, prompt(), tokenCalls(calls), description]).toEqual([[true], null, [{ two_token: 'tok' }], description]);
    });

    test.each([
        [(host) => host.component.registeredMode({ openDropdown: true }), false, 'Registered company hides it'],
        [(host) => host.component.manualEntryMode(), false, 'Enter manually hides it'],
        [(host) => { window.open.mockReturnValue(POPUP); host.component.soleTraderMode(); }, false, 'Sole trader, now allowed, hides it'],
        [(host) => host.component.soleTraderMode(), true, 'Sole trader, still blocked, keeps it'],
    ])('the blocked-popup panel after a chip switch, case %#', async (chip, shown, description) => {
        document.body.innerHTML = ADDRESS('shipping');
        jest.spyOn(window, 'open').mockReturnValue(null);
        const { hosts } = start(config({ address_lookup: false }), { stt: TOKENS, st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        hosts.shipping.component.soleTraderMode();
        const blocked = prompt() && prompt().textContent;
        chip(hosts.shipping);
        expect([blocked, !!prompt(), description]).toEqual(['Registrer deg', shown, description]);
    });

    test.each([
        [false, { company_name: 'Acme Ltd', organization_number: '0123', source: 'search' }, 'nobody adopted: the registered pick is saved as it was'],
        [true, { company_name: 'Ola Trader', organization_number: '777', source: 'sole_trader' }, 'adopted: saved as the sole trader'],
    ])('what a Sole trader click saves, case %#', async (adopt, saved, description) => {
        document.body.innerHTML = ADDRESS('shipping');
        jest.spyOn(window, 'open').mockReturnValue(POPUP);
        const { hosts, calls } = start(config({ address_lookup: false }), { stt: TOKENS, st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        hosts.shipping.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: '' });
        hosts.shipping.component.soleTraderMode();
        if (adopt) hosts.shipping.component.soleTrader().adoptBuyer({ company_name: 'Ola Trader', organization_number: '777' });
        await hosts.shipping.flush();
        await flush();
        expect([calls.filter(([route]) => route === 'save').pop()[1], description]).toEqual([expect.objectContaining(saved), description]);
    });
});

// Custom request headers flagged "Also send from browser" (TWO-27051) reach the
// component, whose sole-trader flow sends them on its one call to Two.
test.each([
    [{ 'X-Waf': 't1' }, { 'X-Waf': 't1' }, 'the flagged headers are handed over'],
    [undefined, {}, 'none configured: an empty set'],
])('browser headers %#', (published, expected, description) => {
    document.body.innerHTML = ADDRESS('payment');
    const { hosts } = start(config(published === undefined ? {} : { custom_headers: published }));
    expect([hosts.payment.component.config().customHeaders, description]).toEqual([expected, description]);
});
