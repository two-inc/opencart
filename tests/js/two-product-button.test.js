/**
 * The product page buy button (Build Guide section 20.8): one add to cart
 * through OpenCart's own route, the hand-off marker only after a confirmed
 * add, and the buyer left on the page with a reason otherwise.
 */
const { start, MARKER, TIMEOUT_MS } = require('../../catalog/view/javascript/two_product_button.js');

const ADD_URL = 'https://shop.test/index.php?route=checkout/cart.add&language=en-gb';
const CHECKOUT = 'https://shop.test/index.php?route=checkout/checkout&language=en-gb';
const UNREACHABLE = 'Sorry, we could not add this to your basket. Please try again.';

/** The product page as OpenCart's default theme draws it, with the fragment at the end of the form. */
function page(checkoutUrl) {
    const doc = document.implementation.createHTMLDocument('product');
    doc.body.innerHTML = '<form id="form-product">'
        + '<select name="option[226]"><option value="15" selected>Red</option></select>'
        + '<input name="quantity" value="2"><input type="hidden" name="product_id" value="42">'
        + '<button type="submit" id="button-cart">Add to cart</button>'
        + '<div class="two-product"><div class="two-product-button">'
        + '<button type="button" data-two-buy-now data-two-add-url="' + ADD_URL + '" data-two-checkout-url="' + checkoutUrl + '"'
        + ' data-two-unreachable="' + UNREACHABLE + '">Buy with Two</button>'
        + '<p class="two-product-button__error" role="alert"></p></div></div></form>';
    return doc;
}

/** A window whose fetch answers as scripted and whose navigation is recorded. */
function shopWindow(answer) {
    const storage = new Map();
    const win = {
        URL, URLSearchParams, FormData, AbortController,
        setTimeout: (fn, ms) => setTimeout(fn, ms),
        clearTimeout: (id) => clearTimeout(id),
        location: { href: 'https://shop.test/index.php?route=product/product&product_id=42', origin: 'https://shop.test', assign: jest.fn() },
        sessionStorage: { getItem: (k) => (storage.has(k) ? storage.get(k) : null), setItem: (k, v) => storage.set(k, String(v)), removeItem: (k) => storage.delete(k) },
        addEventListener: jest.fn(),
        fetch: jest.fn((url, init) => {
            if (answer === 'never') {
                return new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(new Error('aborted'))));
            }
            if (answer instanceof Error) {
                return Promise.reject(answer);
            }
            return Promise.resolve({ json: () => Promise.resolve(answer) });
        })
    };
    return { win, storage };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const CASES = [
    { answer: { success: 'Added' }, checkout: CHECKOUT, marker: 'https://shop.test/index.php?route=product/product&product_id=42', navigates: true, error: '', description: 'a confirmed add records the marker and goes to checkout' },
    { answer: { success: 'Added' }, checkout: 'http://shop.test/index.php?route=checkout/checkout', marker: null, navigates: true, error: '', description: 'checkout on another origin: still added and sent on, but no marker it could never read' },
    { answer: { error: { option_226: 'Colour required!', quantity: 'Too many' }, redirect: 'x' }, checkout: CHECKOUT, marker: null, navigates: false, error: 'Colour required!', description: 'a refused add stays on the page with the shop\'s first reason' },
    { answer: { error: { warning: 'Product not found!' } }, checkout: CHECKOUT, marker: null, navigates: false, error: 'Product not found!', description: 'an unknown product is the shop\'s reason too' },
    { answer: { success: 'Added', error: { warning: 'But not really' } }, checkout: CHECKOUT, marker: null, navigates: false, error: 'But not really', description: 'an answer carrying an error is not a confirmed add' },
    { answer: {}, checkout: CHECKOUT, marker: null, navigates: false, error: UNREACHABLE, description: 'an answer that confirms nothing is not an add' },
    { answer: new Error('offline'), checkout: CHECKOUT, marker: null, navigates: false, error: UNREACHABLE, description: 'no answer leaves the buyer here with our sentence' },
    { answer: 'never', checkout: CHECKOUT, marker: null, navigates: false, error: UNREACHABLE, description: 'an add that outlives the timeout is abandoned with our sentence' }
];

describe('product page buy button', () => {
    test.each(CASES)('$description', async (c) => {
        jest.useFakeTimers({ doNotFake: ['setImmediate', 'queueMicrotask', 'nextTick'] });
        const doc = page(c.checkout);
        const { win, storage } = shopWindow(c.answer);
        start(doc, win);
        const button = doc.querySelector('[data-two-buy-now]');

        button.click();
        button.click();
        expect(button.disabled).toBe(true);
        if (c.answer === 'never') {
            jest.advanceTimersByTime(TIMEOUT_MS);
        }
        jest.useRealTimers();
        await flush();
        await flush();

        expect(win.fetch).toHaveBeenCalledTimes(1);
        const [url, init] = win.fetch.mock.calls[0];
        expect(url).toBe(ADD_URL);
        expect(init.method).toBe('POST');
        expect(Object.fromEntries(init.body)).toEqual({ 'option[226]': '15', quantity: '2', product_id: '42' });
        expect(storage.has(MARKER) ? storage.get(MARKER) : null).toBe(c.marker);
        expect(win.location.assign.mock.calls).toEqual(c.navigates ? [[c.checkout]] : []);
        expect(doc.querySelector('.two-product-button__error').textContent).toBe(c.error);
        expect(button.disabled).toBe(c.navigates);
    });

    test('a click outside the button does nothing', () => {
        const doc = page(CHECKOUT);
        const { win } = shopWindow({ success: 'Added' });
        start(doc, win);
        doc.getElementById('button-cart').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(win.fetch).not.toHaveBeenCalled();
    });
});
