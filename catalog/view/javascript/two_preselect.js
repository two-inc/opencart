/**
 * Checkout's half of the product page buy button (Build Guide section 20.8).
 *
 * OpenCart 4.1 lists the payment methods in a modal the buyer opens with
 * Choose, and checks the method saved in the session (`#input-payment-code`),
 * else the first. When the buyer came straight from the buy button (the
 * sessionStorage marker names this page's referrer), the first time that
 * list is built Two's option is
 * checked instead of the first, and the marker is spent, whether or not Two
 * is on the list: a basket Two does not qualify for simply opens with
 * OpenCart's own default. A method the buyer already saved is never
 * overridden, and a choice the buyer makes in the list spends the marker.
 * The buyer still confirms with Continue, as for any method.
 */
(function (root) {
    'use strict';

    const MARKER = 'twoPreselectPayment';
    const CODE = 'two.two';

    function start(doc, win) {
        let observer = null;

        // The marker is the product page the button was pressed on, and holds
        // only on the hop from that page. Checkout that bounces to the cart
        // (a quantity under the minimum, out of stock) never runs this
        // script, so a later checkout, reached from anywhere else, spends it
        // unused.
        function wanted() {
            let marker = null;
            try {
                marker = win.sessionStorage.getItem(MARKER);
            } catch (e) {
                return false;
            }
            if (marker === null) {
                return false;
            }
            if (marker !== '' && marker === doc.referrer) {
                return true;
            }
            spend();
            return false;
        }

        function spend() {
            try {
                win.sessionStorage.removeItem(MARKER);
            } catch (e) {
                // Nothing to spend.
            }
            if (observer) {
                observer.disconnect();
                observer = null;
            }
        }

        // True once the method list exists, so the marker is spent.
        function attempt() {
            const form = doc.getElementById('form-payment-method');
            if (!form) {
                return false;
            }
            const saved = doc.getElementById('input-payment-code');
            const option = form.querySelector('input[type="radio"][name="payment_method"][value="' + CODE + '"]');
            if ((!saved || saved.value === '') && option && !option.checked) {
                option.checked = true;
            }
            spend();
            return true;
        }

        if (!wanted()) {
            return;
        }
        doc.addEventListener('change', (event) => {
            const target = event.target;
            if (event.isTrusted && target && target.name === 'payment_method') {
                spend();
            }
        }, true);
        if (attempt() || typeof win.MutationObserver !== 'function') {
            return;
        }
        observer = new win.MutationObserver(() => {
            attempt();
        });
        observer.observe(doc.documentElement, { childList: true, subtree: true });
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { start: start, MARKER: MARKER };
        return;
    }
    start(root.document, root);
}(typeof window !== 'undefined' ? window : this));
