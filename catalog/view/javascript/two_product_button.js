/**
 * The product page "Buy with" button (Build Guide section 20.8, TWO-25800).
 *
 * One click makes one add to cart through OpenCart's own route
 * (`checkout/cart.add`) with the product form as the buyer filled it in
 * (quantity, options, subscription plan), so the shop validates it as it does
 * for its own button. Only a confirmed add records the hand-off marker in
 * sessionStorage (tab-scoped, and only when checkout is this page's origin)
 * and sends the buyer to checkout, where two_preselect.js reads it once. A
 * refused add leaves the buyer here with the shop's own reason; no answer
 * within the timeout leaves them here with ours. It never places an order and
 * never promises Two will be offered.
 */
(function (root) {
    'use strict';

    const MARKER = 'twoPreselectPayment';
    const TIMEOUT_MS = 15000;

    function start(doc, win) {
        let inFlight = false;

        function handoffReaches(button) {
            const checkout = button.getAttribute('data-two-checkout-url');
            if (!checkout) {
                return false;
            }
            try {
                return new win.URL(checkout, win.location.href).origin === win.location.origin;
            } catch (e) {
                return false;
            }
        }

        function productForm(button) {
            return button.closest('form') || doc.getElementById('form-product');
        }

        function body(form) {
            const params = new win.URLSearchParams();
            new win.FormData(form).forEach((value, name) => {
                if (typeof value === 'string') {
                    params.append(name, value);
                }
            });

            return params;
        }

        // OpenCart answers a refused add with an `error` map (one entry per
        // field: a required option, the subscription plan, an unknown product)
        // and a confirmed one with `success`.
        function firstError(payload) {
            const error = payload && payload.error;
            if (typeof error === 'string') {
                return error;
            }
            if (error && typeof error === 'object') {
                const first = Object.keys(error).map((key) => error[key]).find((value) => typeof value === 'string' && value !== '');
                return first || '';
            }
            return '';
        }

        function succeeded(payload) {
            return Boolean(payload && payload.success) && !(payload.error && firstError(payload) !== '');
        }

        function report(button, message) {
            const holder = button.parentNode && button.parentNode.querySelector('.two-product-button__error');
            if (holder) {
                holder.textContent = message;
            }
        }

        function release() {
            inFlight = false;
            doc.querySelectorAll('[data-two-buy-now][disabled]').forEach((button) => button.removeAttribute('disabled'));
        }

        function handle(button) {
            const form = productForm(button);
            const url = button.getAttribute('data-two-add-url');
            if (!form || !url || typeof win.fetch !== 'function') {
                return;
            }
            inFlight = true;
            button.setAttribute('disabled', 'disabled');
            report(button, '');
            const controller = typeof win.AbortController === 'function' ? new win.AbortController() : null;
            const expiry = win.setTimeout(() => {
                if (controller) {
                    controller.abort();
                }
            }, TIMEOUT_MS);
            const request = {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body(form)
            };
            if (controller) {
                request.signal = controller.signal;
            }
            win.fetch(url, request).then((response) => response.json()).then((payload) => {
                win.clearTimeout(expiry);
                if (!succeeded(payload)) {
                    release();
                    report(button, firstError(payload) || button.getAttribute('data-two-unreachable') || '');
                    return;
                }
                if (handoffReaches(button)) {
                    try {
                        // This page, as checkout's referrer will name it.
                        win.sessionStorage.setItem(MARKER, win.location.href.split('#')[0]);
                    } catch (e) {
                        // Storage refused (private mode): the item is in the
                        // basket, only the preselection is lost.
                    }
                }
                win.location.assign(button.getAttribute('data-two-checkout-url'));
            }).catch(() => {
                win.clearTimeout(expiry);
                release();
                report(button, button.getAttribute('data-two-unreachable') || '');
            });
        }

        doc.addEventListener('click', (event) => {
            const button = event.target && event.target.closest ? event.target.closest('[data-two-buy-now]') : null;
            if (!button) {
                return;
            }
            event.preventDefault();
            // Latched: a second press while the add is out adds nothing more.
            if (inFlight) {
                return;
            }
            handle(button);
        });

        // Back from checkout through the page cache: the latch must not
        // survive into a page the buyer can use again.
        win.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                release();
            }
        });
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { start: start, MARKER: MARKER, TIMEOUT_MS: TIMEOUT_MS };
        return;
    }
    if (!root.TwoProductButton) {
        root.TwoProductButton = true;
        start(root.document, root);
    }
}(typeof window !== 'undefined' ? window : this));
