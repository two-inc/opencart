/**
 * Checkout's half of the buy button (Build Guide section 20.8): the first
 * payment method list checks Two's option once, never over a method the
 * buyer saved, and the marker is spent whether or not Two is offered.
 */
const { start, MARKER } = require('../../catalog/view/javascript/two_preselect.js');

/** OpenCart's payment method modal as its checkout script builds it, the first option checked. */
function modal(codes) {
    const radios = codes.map((code, i) => '<input type="radio" name="payment_method" value="' + code + '"' + (i === 0 ? ' checked' : '') + '>').join('');
    return '<div id="modal-payment"><form id="form-payment-method">' + radios + '</form></div>';
}

function checkout(saved) {
    const doc = document.implementation.createHTMLDocument('checkout');
    doc.body.innerHTML = '<input type="hidden" id="input-payment-code" value="' + saved + '">';
    return doc;
}

function win(marker) {
    const storage = new Map(marker ? [[MARKER, '1']] : []);
    return {
        storage,
        MutationObserver,
        sessionStorage: { getItem: (k) => (storage.has(k) ? storage.get(k) : null), removeItem: (k) => storage.delete(k) }
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const CASES = [
    { marker: true, saved: '', codes: ['cod.cod', 'two.two'], later: true, checked: 'two.two', spent: true, description: 'from the button, the list opened later checks Two' },
    { marker: true, saved: '', codes: ['cod.cod', 'two.two'], later: false, checked: 'two.two', spent: true, description: 'a list already open checks Two at once' },
    { marker: false, saved: '', codes: ['cod.cod', 'two.two'], later: true, checked: 'cod.cod', spent: false, description: 'without the button nothing changes' },
    { marker: true, saved: 'cod.cod', codes: ['cod.cod', 'two.two'], later: true, checked: 'cod.cod', spent: true, description: 'a method the buyer saved is never overridden, and the marker is spent' },
    { marker: true, saved: '', codes: ['cod.cod', 'bank_transfer.bank_transfer'], later: true, checked: 'cod.cod', spent: true, description: 'Two not offered for this basket: OpenCart\'s default stands and the marker is spent' }
];

describe('checkout preselection', () => {
    test.each(CASES)('$description', async (c) => {
        const doc = checkout(c.saved);
        const w = win(c.marker);
        if (!c.later) {
            doc.body.insertAdjacentHTML('beforeend', modal(c.codes));
        }
        start(doc, w);
        if (c.later) {
            doc.body.insertAdjacentHTML('beforeend', '<p>shipping methods</p>');
            await flush();
            expect(w.storage.has(MARKER)).toBe(c.marker);
            doc.body.insertAdjacentHTML('beforeend', modal(c.codes));
            await flush();
        }

        expect(doc.querySelector('input[name="payment_method"]:checked').value).toBe(c.checked);
        expect(w.storage.has(MARKER)).toBe(!c.spent && c.marker);
    });

    test('the marker is spent once: a list built again keeps what the buyer has', async () => {
        const doc = checkout('');
        const w = win(true);
        start(doc, w);
        doc.body.insertAdjacentHTML('beforeend', modal(['cod.cod', 'two.two']));
        await flush();
        doc.getElementById('modal-payment').remove();
        doc.body.insertAdjacentHTML('beforeend', modal(['cod.cod', 'two.two']));
        await flush();
        expect(doc.querySelector('input[name="payment_method"]:checked').value).toBe('cod.cod');
    });
});
