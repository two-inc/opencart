/**
 * The payment term chips (Build Guide section 4): an ARIA radiogroup with
 * one tab stop and arrow keys, a saved choice that reloads the order totals,
 * a refused one that puts the saved term back, and the per-term fees.
 */
const { Api, TermChips } = require('../../catalog/view/javascript/two_checkout.js');

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function page(terms, checked) {
    document.body.innerHTML = '<div id="alert"></div>'
        + '<div id="checkout-confirm"><table><tfoot><tr><td>old</td></tr></tfoot></table></div>'
        + '<div role="radiogroup" data-two-term-chips>'
        + terms.map((days) => '<button type="button" role="radio" data-two-term="' + days + '" aria-checked="' + (days === checked) + '" tabindex="' + (days === checked ? 0 : -1) + '"><span>' + days + ' days</span><span data-two-term-fee></span></button>').join('')
        + '</div><p data-two-pay-in></p>';
}

/** A shop answering by route; every call is recorded as [route, params]. */
function shop(answers) {
    const calls = [];
    const fetchImpl = jest.fn((url, init) => {
        const query = new URLSearchParams(url.split('?')[1]);
        const params = init && init.body ? Object.fromEntries(new URLSearchParams(init.body)) : {};
        const route = query.get('route');
        calls.push([route, params]);
        const answer = typeof answers[route] === 'function' ? answers[route](params) : answers[route];
        if (answer instanceof Error) {
            return Promise.reject(answer);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve(answer || {}), text: () => Promise.resolve(typeof answer === 'string' ? answer : '') });
    });
    return { calls, fetchImpl };
}

function chips(answers, terms, checked) {
    page(terms, checked);
    const config = {
        token: 'tok',
        urls: { term: 'index.php?route=term', fees: 'index.php?route=fees', totals: 'index.php?route=totals' },
        i18n: { pay_in: 'Pay in %d days', pay_in_eom: 'Pay in %d days from end of month', totals_failed: 'reload the page' }
    };
    const scripted = shop(Object.assign({ fees: { fees: {} }, term: { ok: true }, totals: '<table><tfoot><tr><td>new</td></tr></tfoot></table>' }, answers));
    const alerts = [];
    const instance = new TermChips({
        group: document.querySelector('[data-two-term-chips]'),
        api: new Api(config, scripted.fetchImpl),
        config: config,
        doc: document,
        alert: (message) => alerts.push(message),
        payIn: document.querySelector('[data-two-pay-in]')
    }).start();
    return { instance, calls: scripted.calls, alerts };
}

const state = () => Array.from(document.querySelectorAll('[data-two-term]')).map((chip) => chip.dataset.twoTerm + ':' + chip.getAttribute('aria-checked') + ':' + chip.tabIndex);

describe('keyboard', () => {
    test.each([
        ['ArrowRight', {}, '30', ['14:false:-1', '30:true:0', '60:false:-1'], 'moves to the next term'],
        ['ArrowDown', {}, '30', ['14:false:-1', '30:true:0', '60:false:-1'], 'moves down like right'],
        ['ArrowLeft', {}, '60', ['14:false:-1', '30:false:-1', '60:true:0'], 'wraps from the first to the last'],
        ['End', {}, '60', ['14:false:-1', '30:false:-1', '60:true:0'], 'jumps to the last'],
        ['Home', {}, '14', ['14:true:0', '30:false:-1', '60:false:-1'], 'stays on the first'],
        ['ArrowRight', { altKey: true }, null, ['14:true:0', '30:false:-1', '60:false:-1'], 'leaves a modified arrow to the browser'],
        ['a', {}, null, ['14:true:0', '30:false:-1', '60:false:-1'], 'ignores other keys']
    ])('%s %j: %s saved, %s', async (key, modifiers, saved, expected, description) => {
        jest.useFakeTimers();
        const { calls } = chips({}, [14, 30, 60], 14);
        document.querySelector('[data-two-term="14"]').focus();
        document.querySelector('[data-two-term-chips]').dispatchEvent(new KeyboardEvent('keydown', Object.assign({ key: key, bubbles: true }, modifiers)));
        expect(state()).toEqual(expected);
        jest.advanceTimersByTime(300);
        jest.useRealTimers();
        await flush();
        await flush();
        const terms = calls.filter(([route]) => route === 'term').map(([, params]) => params.days);
        expect({ description, terms }).toEqual({ description, terms: saved === null ? [] : [saved] });
    });
});

describe('saving a term', () => {
    test.each([
        [{ term: { ok: true } }, ['14:false:-1', '30:true:0'], 'new', 'Pay in 30 days', [], 'a saved term reloads the totals'],
        [{ term: { ok: false, error: 'not offered' } }, ['14:true:0', '30:false:-1'], 'new', 'Pay in 14 days', ['not offered'], 'a refused term puts the saved one back and re-syncs the totals'],
    ])('%#: %s', async (answers, expected, totals, payIn, alerts, description) => {
        const result = chips(answers, [14, 30], 14);
        await result.instance.choose(30);
        await flush();
        expect({ description, state: state(), totals: document.querySelector('#checkout-confirm tfoot td').textContent, payIn: document.querySelector('[data-two-pay-in]').textContent, alerts: result.alerts })
            .toEqual({ description, state: expected, totals: totals, payIn: payIn, alerts: alerts });
    });

    test('the save and the totals reload go through the queue, so Confirm waits for both', async () => {
        const result = chips({}, [14, 30], 14);
        const choice = result.instance.choose(30);
        let settled = false;
        result.instance.api.settled().then(() => { settled = true; });
        await choice;
        await flush();
        expect(settled).toBe(true);
        expect(result.calls.map(([route]) => route)).toEqual(['fees', 'term', 'totals']);
    });
});

describe('arrow keys and Confirm', () => {
    test('a run of arrow keys saves the last term once, and holds the queue from the first key, so Confirm waits for it', async () => {
        jest.useFakeTimers();
        const { instance, calls } = chips({}, [14, 30, 60], 14);
        const group = document.querySelector('[data-two-term-chips]');
        document.querySelector('[data-two-term="14"]').focus();
        group.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        group.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        let confirmed = null;
        // Confirm, pressed inside the debounce window, queues behind the held save.
        instance.api.serial('confirm', () => {
            confirmed = calls.filter(([route]) => route === 'term').map(([, params]) => params.days);
            return Promise.resolve();
        });
        jest.advanceTimersByTime(100);
        await Promise.resolve();
        expect(confirmed).toBeNull();
        jest.advanceTimersByTime(300);
        jest.useRealTimers();
        for (let i = 0; i < 6; i += 1) {
            await flush();
        }
        expect(confirmed).toEqual(['60']);
        expect(calls.map(([route]) => route)).toEqual(['fees', 'term', 'totals']);
    });
});

describe('a failed save', () => {
    test.each([
        [false, ['14:true:0', '30:false:-1', '60:false:-1'], ['30'], 'new', 'with nothing waiting, the chips and the totals re-sync to the saved term'],
        [true, ['14:false:-1', '30:false:-1', '60:true:0'], ['30', '60'], 'new', 'with a later choice waiting, that choice saves and both follow it'],
    ])('%#: %s', async (later, expected, saves, totals, description) => {
        const result = chips({ term: (params) => (params.days === '30' ? { ok: false, error: 'busy' } : { ok: true }) }, [14, 30, 60], 14);
        const first = result.instance.choose(30);
        if (later) {
            await flush();
            result.instance.choose(60);
        }
        await first;
        for (let i = 0; i < 6; i += 1) {
            await flush();
        }
        const terms = result.calls.filter(([route]) => route === 'term').map(([, params]) => params.days);
        expect({ description, state: state(), terms, totals: document.querySelector('#checkout-confirm tfoot td').textContent })
            .toEqual({ description, state: expected, terms: saves, totals: totals });
    });
});

describe('a saved term whose totals reload fails', () => {
    test.each([
        [1, 'new', [], 'one failed reload is tried again'],
        [2, 'old', ['reload the page'], 'two failed reloads tell the buyer'],
    ])('%#: %s', async (failures, totals, alerts, description) => {
        let left = failures;
        const result = chips({ totals: () => (left-- > 0 ? new Error('offline') : '<table><tfoot><tr><td>new</td></tr></tfoot></table>') }, [14, 30], 14);
        await result.instance.choose(30);
        for (let i = 0; i < 4; i += 1) {
            await flush();
        }
        const reloads = result.calls.filter(([route]) => route === 'totals').length;
        expect({ description, totals: document.querySelector('#checkout-confirm tfoot td').textContent, alerts: result.alerts, reloads })
            .toEqual({ description, totals: totals, alerts: alerts, reloads: 2 });
    });
});

describe('a single term', () => {
    test('is shown but not chosen: no click or key saves it', async () => {
        const { calls } = chips({}, [30], 30);
        document.querySelector('[data-two-term="30"]').click();
        await flush();
        expect(calls.map(([route]) => route)).toEqual(['fees']);
    });
});

describe('fees', () => {
    test.each([
        [{ 14: '+£1.00', 30: '+£2.50' }, ['+£1.00', '+£2.50'], 'each chip shows its own fee'],
        [{ 30: '+£2.50' }, ['', '+£2.50'], 'a term with no quote shows none'],
        [{}, ['', ''], 'no fees show nothing'],
    ])('%#: %s', async (fees, expected, description) => {
        chips({ fees: { fees: fees } }, [14, 30], 14);
        await flush();
        await flush();
        const shown = Array.from(document.querySelectorAll('[data-two-term-fee]')).map((slot) => slot.textContent);
        expect({ description, shown }).toEqual({ description, shown: expected });
    });
});
