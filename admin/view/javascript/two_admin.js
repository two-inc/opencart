/*
 * Two admin settings: the live API key check. While the merchant types a key
 * or changes the environment, the admin asks Two about the pair (the typed
 * key, or the saved one when the field is empty) and shows the verdict under
 * the key field. Nothing is saved or cached by the check; the save re-checks.
 *
 * Also: "Autofill company address" is unavailable, and off, while the company
 * search sits in the payment tile, as on the reference plugin (TWO-26721);
 * and the merchant fee beside each payment term (Payment terms tab).
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 600;

    function init() {
        var key = document.getElementById('input-api-key');
        var environment = document.getElementById('input-environment');
        var status = document.getElementById('two-key-status');
        var panel = document.getElementById('two-merchant-panel');
        if (!key || !environment || !status || !panel) {
            return;
        }
        var timer = null;
        var sequence = 0;

        function show(text, tone) {
            status.textContent = text;
            status.className = 'small mt-1 text-' + tone;
        }

        function check() {
            var mine = ++sequence;
            show(key.getAttribute('data-checking') || '', 'muted');
            var body = new URLSearchParams();
            body.append('api_key', key.value);
            body.append('environment', environment.value);
            fetch(key.getAttribute('data-verify-url'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (mine !== sequence) {
                        return;
                    }
                    panel.classList.toggle('d-none', !result.ok);
                    if (result.ok) {
                        document.getElementById('two-merchant-id').textContent = result.merchant_id || '';
                        document.getElementById('two-merchant-short-name').textContent = result.merchant_short_name || '';
                        document.getElementById('two-merchant-short-name-row').classList.toggle('d-none', !result.merchant_short_name);
                    }
                    show(result.message || '', result.ok ? 'success' : (result.definitive ? 'danger' : 'warning'));
                })
                .catch(function () {
                    if (mine === sequence) {
                        show('', 'muted');
                    }
                });
        }

        key.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(check, DEBOUNCE_MS);
        });
        environment.addEventListener('change', function () {
            clearTimeout(timer);
            check();
        });
    }

    function initAutofill() {
        var placement = document.getElementById('input-company-search-in-address');
        var autofill = document.getElementById('input-address-lookup');
        if (!placement || !autofill) {
            return;
        }
        var wanted = autofill.checked || autofill.disabled;
        autofill.addEventListener('change', function () {
            wanted = autofill.checked;
        });
        function sync() {
            autofill.disabled = !placement.checked;
            autofill.checked = placement.checked && wanted;
        }
        placement.addEventListener('change', sync);
        sync();
    }

    function feeAmount(n) {
        return Number(n || 0).toFixed(2);
    }

    /** One term's merchant fee, as the reference plugin draws it; an unpriced term says so rather than look free. */
    function feeLabel(fee, currency, text) {
        if (!fee) {
            return '(' + (text.no_figure || '') + ')';
        }
        var suffix = currency ? ' ' + currency : '';
        var pct = feeAmount(fee.percentage);
        var fixed = feeAmount(fee.fixed);
        var zero = feeAmount(0);
        var inner;
        if (pct === zero && fixed === zero) {
            inner = zero + suffix;
        } else if (pct === zero) {
            inner = fixed + suffix;
        } else if (fixed === zero) {
            inner = pct + '%';
        } else {
            inner = pct + '% + ' + fixed + suffix;
        }
        return '(' + inner + ')';
    }

    /** A refusal in words, with Two's own message when it sent one (drawn as text, never markup). */
    function feeRefusal(result, withMessage, bare) {
        var status = String(parseInt(result.http_status, 10) || '');
        // The sentence brings its own full stop.
        var message = String(result.message || '').replace(/^\s+/, '').replace(/\.?\s*$/, '');
        return message === ''
            ? String(bare || '').replace('%s', status)
            : String(withMessage || '').replace('%1$s', status).replace('%2$s', function () { return message; });
    }

    /** The line under the terms: why figures are missing or old, or nothing for a fresh set. */
    function feeNotice(result, text) {
        if (!result || !result.success || !result.fees) {
            if (result && result.error === 'not_configured') {
                return text.no_api_key || '';
            }
            if (result && result.error === 'rejected') {
                return feeRefusal(result, text.rejected, text.rejected_bare);
            }
            return text.unavailable || '';
        }
        if (!result.stale) {
            return '';
        }
        var notice = result.fetched_at_display ? String(text.stale_dated || '').replace('%s', result.fetched_at_display) : (text.stale || '');
        if (result.error === 'rejected') {
            notice += ' ' + feeRefusal(result, text.stale_rejected, text.stale_rejected_bare);
        }
        return notice;
    }

    /**
     * The fee Two charges the merchant beside each term on the Payment terms
     * tab, asked once the page is up. Every rendered term is asked, ticked or
     * not. A term tick asks again only while the last answer was not a fresh
     * set (the shop's own cooldown bounds an outage); a missing key is final.
     */
    function initFees() {
        var box = document.querySelector('[data-two-fee-rates]');
        var spans = box ? Array.prototype.slice.call(box.querySelectorAll('[data-two-term-fee]')) : [];
        if (!spans.length) {
            return;
        }
        var notice = box.querySelector('[data-two-fee-notice]');
        var text = {};
        try {
            text = JSON.parse(box.getAttribute('data-two-fee-text') || '{}') || {};
        } catch (e) {
            text = {};
        }
        var terms = spans.map(function (span) { return parseInt(span.getAttribute('data-two-term-fee'), 10); });
        var settled = false;
        var pending = false;

        function draw(result) {
            var drawable = Boolean(result && result.success && result.fees);
            var currency = drawable ? String(result.currency || '').trim().toUpperCase() : '';
            spans.forEach(function (span) {
                span.textContent = drawable ? feeLabel(result.fees[span.getAttribute('data-two-term-fee')], currency, text) : '';
            });
            if (notice) {
                notice.textContent = feeNotice(result, text);
            }
            settled = (drawable && !result.stale) || Boolean(result && result.error === 'not_configured');
        }

        function load() {
            if (settled || pending) {
                return Promise.resolve();
            }
            pending = true;
            var body = new URLSearchParams();
            body.append('terms', JSON.stringify(terms));
            return fetch(box.getAttribute('data-two-fee-rates'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(draw)
                .catch(function () { draw(null); })
                .then(function () { pending = false; });
        }

        box.addEventListener('change', load);
        TwoAdmin.feesLoaded = load();
    }

    /**
     * Diagnostics: the custom request-header table. Add clones the row
     * template under a fresh index (never reused, so no two rows share a
     * name); Remove drops its row. The save checks the table and refuses
     * the tab while a row is invalid.
     */
    function initHeaders() {
        var box = document.querySelector('[data-two-headers]');
        if (!box) {
            return;
        }
        var rows = box.querySelector('[data-two-header-rows]');
        var template = box.querySelector('[data-two-header-template]');
        var next = rows.querySelectorAll('[data-two-header-row]').length;
        box.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            if (target && target.closest('[data-two-header-add]')) {
                var html = template.innerHTML.replace(/__i__/g, String(next++));
                rows.insertAdjacentHTML('beforeend', html.trim());
                var added = rows.lastElementChild && rows.lastElementChild.querySelector('input[type="text"]');
                if (added) {
                    added.focus();
                }
            } else if (target && target.closest('[data-two-header-remove]')) {
                var row = target.closest('[data-two-header-row]');
                if (row) {
                    row.remove();
                }
            }
        });
    }

    /** Diagnostics: "Refresh merchant profile", which says how the refetch went beside the button. */
    function initRefresh() {
        var button = document.querySelector('[data-two-refresh]');
        var result = document.querySelector('[data-two-refresh-result]');
        if (!button || !result) {
            return;
        }
        button.addEventListener('click', function () {
            button.disabled = true;
            result.textContent = '';
            TwoAdmin.refreshed = fetch(button.getAttribute('data-two-refresh'), {method: 'POST', credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    result.textContent = answer.message || '';
                    result.className = 'ms-2 small text-' + (answer.success ? 'success' : 'danger');
                })
                .catch(function () {
                    result.textContent = button.getAttribute('data-two-refresh-error') || '';
                    result.className = 'ms-2 small text-danger';
                })
                .then(function () {
                    button.disabled = false;
                });
        });
    }

    var TwoAdmin = window.TwoAdmin = {feeLabel: feeLabel, feeNotice: feeNotice};

    function boot() {
        init();
        initAutofill();
        initFees();
        initHeaders();
        initRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
