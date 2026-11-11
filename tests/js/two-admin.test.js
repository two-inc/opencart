/**
 * The admin page script: "Autofill company address" is unavailable, and off,
 * while the company search sits in the payment tile (TWO-26721).
 */
function page(placement, autofill) {
    document.body.innerHTML = '<input type="checkbox" id="input-company-search-in-address"' + (placement ? ' checked' : '') + '>'
        + '<input type="checkbox" id="input-address-lookup"' + (autofill && placement ? ' checked' : '') + (placement ? '' : ' disabled') + '>';
    jest.isolateModules(() => require('../../admin/view/javascript/two_admin.js'));
    return [document.getElementById('input-company-search-in-address'), document.getElementById('input-address-lookup')];
}

const toggle = (box) => {
    box.checked = !box.checked;
    box.dispatchEvent(new Event('change', { bubbles: true }));
};

test.each([
    [true, true, [true, false], [false, true], 'on in address placement, greyed out and off in the tile'],
    [false, false, [false, true], [true, false], 'a page loaded in tile placement offers it, on by default, once the search moves to the address'],
])('placement %s, autofill %s', (placement, autofill, first, second, description) => {
    const [where, fill] = page(placement, autofill);
    expect([fill.disabled, fill.checked, description]).toEqual([!placement, placement && autofill, description]);
    toggle(where);
    expect([fill.disabled, fill.checked, description]).toEqual(first.concat(description));
    toggle(where);
    expect([fill.disabled, fill.checked, description]).toEqual(second.concat(description));
});

test('a merchant who switched autofill off finds it off again after a round trip to the tile', () => {
    const [where, fill] = page(true, true);
    toggle(fill);
    toggle(where);
    toggle(where);
    expect([fill.disabled, fill.checked]).toEqual([false, false]);
});

/**
 * The merchant fee beside each payment term: how one term is drawn, what the
 * line under the terms says, and one page load against a mocked shop.
 */
const TEXT = {
    stale: 'STALE.', stale_dated: 'STALE ON %s.', unavailable: 'UNAVAILABLE.', rejected: 'REFUSED %1$s: %2$s.', rejected_bare: 'REFUSED %s.',
    stale_rejected: 'WHY %1$s: %2$s.', stale_rejected_bare: 'WHY %s.', no_api_key: 'NO KEY.', no_figure: 'no figure'
};

function feePage(answer) {
    document.body.innerHTML = '<div data-two-fee-rates="/fees" data-two-fee-text=\'' + JSON.stringify(TEXT) + '\'>'
        + '<input type="checkbox" id="t30"><span data-two-term-fee="30"></span><span data-two-term-fee="60"></span><div data-two-fee-notice></div></div>';
    global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve(answer) }));
    jest.isolateModules(() => require('../../admin/view/javascript/two_admin.js'));
    return window.TwoAdmin.feesLoaded;
}

test.each([
    [{ percentage: 1.5, fixed: 2 }, 'NOK', '(1.50% + 2.00 NOK)', 'a percentage and a fixed fee'],
    [{ percentage: 1.5, fixed: 0 }, 'NOK', '(1.50%)', 'a percentage alone'],
    [{ percentage: 0, fixed: 2 }, 'NOK', '(2.00 NOK)', 'a fixed fee alone'],
    [{ percentage: 0.001, fixed: 0 }, 'NOK', '(0.00 NOK)', 'a fee that rounds to nothing shows zero with its currency'],
    [undefined, 'NOK', '(no figure)', 'a term the answer did not price says so'],
])('fee label: %j %s', (fee, currency, expected, description) => {
    feePage({ success: false });
    expect([window.TwoAdmin.feeLabel(fee, currency, TEXT), description]).toEqual([expected, description]);
});

test.each([
    [{ success: true, fees: {}, stale: false }, '', 'a fresh set needs no notice'],
    [{ success: true, fees: {}, stale: true }, 'STALE.', 'a stale set with no date'],
    [{ success: true, fees: {}, stale: true, fetched_at_display: '2026-11-04 10:00Z' }, 'STALE ON 2026-11-04 10:00Z.', 'a stale set says when it was fetched'],
    [{ success: true, fees: {}, stale: true, error: 'rejected', http_status: 400, message: 'bad country.' }, 'STALE. WHY 400: bad country.', 'a stale set after a refusal says why'],
    [{ success: false, error: 'not_configured' }, 'NO KEY.', 'no key saved'],
    [{ success: false, error: 'rejected', http_status: 422, message: ' $& odd ' }, 'REFUSED 422: $& odd.', 'a refusal quotes Two literally'],
    [{ success: false, error: 'rejected', http_status: 400, message: '' }, 'REFUSED 400.', 'a refusal with no message'],
    [{ success: false, error: 'upstream' }, 'UNAVAILABLE.', 'Two could not be reached'],
    [null, 'UNAVAILABLE.', 'the shop did not answer'],
])('fee notice: %j', (result, expected, description) => {
    feePage({ success: false });
    expect([window.TwoAdmin.feeNotice(result, TEXT), description]).toEqual([expected, description]);
});

test('the page asks for every rendered term and draws the answer', async () => {
    await feePage({ success: true, currency: 'nok', fees: { 30: { percentage: 1.5, fixed: 0 } }, stale: false });
    expect(global.fetch.mock.calls[0][0]).toBe('/fees');
    expect(global.fetch.mock.calls[0][1].body.get('terms')).toBe('[30,60]');
    const spans = Array.from(document.querySelectorAll('[data-two-term-fee]')).map((span) => span.textContent);
    expect(spans).toEqual(['(1.50%)', '(no figure)']);
    expect(document.querySelector('[data-two-fee-notice]').textContent).toBe('');
});

test.each([
    [{ success: false, error: 'upstream' }, 2, 'a failure is asked again on a term change'],
    [{ success: false, error: 'not_configured' }, 1, 'a missing key is final until the page is saved'],
    [{ success: true, currency: 'NOK', fees: { 30: { percentage: 1, fixed: 0 } }, stale: false }, 1, 'a fresh set is not asked again'],
])('re-ask on change: %j', async (answer, calls, description) => {
    await feePage(answer);
    const box = document.getElementById('t30');
    box.checked = true;
    box.dispatchEvent(new Event('change', { bubbles: true }));
    await Promise.resolve();
    expect([global.fetch.mock.calls.length, description]).toEqual([calls, description]);
});

/**
 * Diagnostics (TWO-27051): the custom request-header table's Add and Remove,
 * and the "Refresh merchant profile" button.
 */
const ROW = (i) => '<tr data-two-header-row><td><input type="text" name="payment_two_custom_headers[' + i + '][name]"></td>'
    + '<td><input type="text" name="payment_two_custom_headers[' + i + '][value]"></td>'
    + '<td><input type="checkbox" name="payment_two_custom_headers[' + i + '][send_from_browser]"></td>'
    + '<td><button type="button" data-two-header-remove>x</button></td></tr>';

function headersPage(rows) {
    document.body.innerHTML = '<div data-two-headers><table><tbody data-two-header-rows>' + rows.map(ROW).join('') + '</tbody></table>'
        + '<template data-two-header-template>' + ROW('__i__') + '</template><button type="button" data-two-header-add>+</button></div>';
    jest.isolateModules(() => require('../../admin/view/javascript/two_admin.js'));
}

const names = () => Array.from(document.querySelectorAll('[data-two-header-row] input[type="text"]')).map((input) => input.name);
const click = (selector, index) => document.querySelectorAll(selector)[index || 0].click();

test.each([
    [[], ['add'], ['payment_two_custom_headers[0][name]', 'payment_two_custom_headers[0][value]'], 'Add on an empty table makes row 0'],
    [[0, 1], ['add'], ['payment_two_custom_headers[0][name]', 'payment_two_custom_headers[0][value]', 'payment_two_custom_headers[1][name]', 'payment_two_custom_headers[1][value]', 'payment_two_custom_headers[2][name]', 'payment_two_custom_headers[2][value]'], 'Add after two rows makes row 2'],
    [[0, 1], ['remove'], ['payment_two_custom_headers[1][name]', 'payment_two_custom_headers[1][value]'], 'Remove drops its own row'],
    [[0, 1], ['remove', 'add'], ['payment_two_custom_headers[1][name]', 'payment_two_custom_headers[1][value]', 'payment_two_custom_headers[2][name]', 'payment_two_custom_headers[2][value]'], 'an index is never reused after a Remove'],
])('header table %#', (rows, steps, expected, description) => {
    headersPage(rows);
    steps.forEach((step) => click(step === 'add' ? '[data-two-header-add]' : '[data-two-header-remove]'));
    expect([names(), description]).toEqual([expected, description]);
});

test.each([
    [() => Promise.resolve({ json: () => Promise.resolve({ success: true, message: 'Refreshed.' }) }), 'Refreshed.', 'ms-2 small text-success', 'a refresh says so'],
    [() => Promise.resolve({ json: () => Promise.resolve({ success: false, message: 'Kept the old one.' }) }), 'Kept the old one.', 'ms-2 small text-danger', 'a failed refresh shows why'],
    [() => Promise.reject(new Error('offline')), 'Could not refresh.', 'ms-2 small text-danger', 'no answer shows the failure text'],
])('refresh merchant profile %#', async (answer, text, tone, description) => {
    document.body.innerHTML = '<button data-two-refresh="/refresh?user_token=t" data-two-refresh-error="Could not refresh."></button><span data-two-refresh-result></span>';
    global.fetch = jest.fn(answer);
    jest.isolateModules(() => require('../../admin/view/javascript/two_admin.js'));
    const button = document.querySelector('[data-two-refresh]');
    button.click();
    expect([button.disabled, description]).toEqual([true, description]);
    await window.TwoAdmin.refreshed;
    const result = document.querySelector('[data-two-refresh-result]');
    expect([result.textContent, result.className, button.disabled, global.fetch.mock.calls[0][1].method, description]).toEqual([text, tone, false, 'POST', description]);
});
