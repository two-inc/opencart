/**
 * Two checkout capture for OpenCart 4.1 (Build Guide sections 1, 2 and 6.3).
 *
 * - Company search: the shared control (the vendored modules in `two/`,
 *   hosted by `two/company-search-host.js`), one per address role
 *   (`payment`, `shipping`) or one in the payment tile (`tile`), as the
 *   merchant's placement switch says. This file keeps the session in step
 *   with what each control captured.
 * - The order intent, asked from the tile once Two is the chosen method and
 *   the company is known; never while the method list renders.
 * - The payment term chips in the tile (section 4), an ARIA radiogroup: a
 *   choice is saved to the shop and the order totals reloaded, so the
 *   payment terms fee follows the term. Both go through the call queue, so
 *   Confirm waits for them.
 *
 * Settings come from the JSON island the checkout page event adds
 * (`#two-checkout-config`). Every call goes to the shop, which relays it to
 * Two with the merchant's key, except the enrolled sole trader's own details,
 * which only the buyer's Two session can read.
 */
(function (root) {
    'use strict';

    function readConfig(doc) {
        const island = doc.getElementById('two-checkout-config');
        if (!island) {
            return null;
        }
        try {
            return JSON.parse(island.textContent);
        } catch (error) {
            return null;
        }
    }


    /** How long a relay call may take before it is abandoned, by call (the shop's own calls to Two time out at 30 s). */
    const TIMEOUTS = { intent: 40000, search: 30000 };
    const DEFAULT_TIMEOUT = 20000;
    /** Calls the browser never abandons: placing the order runs as long as the shop needs, as a form post would. */
    const NO_TIMEOUT = ['confirm'];
    /**
     * Calls that need not wait for an order intent ahead of them: the intent
     * writes only its own rate-limit stamps to the session, and these write
     * nothing a later intent could undo (confirm asks its own intent).
     */
    const SKIP_INTENTS = ['search', 'company_details', 'confirm'];
    /** How long Confirm waits for pending saves before it gives the buyer a message instead. */
    const SETTLE_CAP = 15000;

    function wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    /** The shop's relay: every call carries the session's token. */
    class Api {
        constructor(config, fetchImpl) {
            this.config = config;
            this.fetch = fetchImpl || ((url, init) => root.fetch(url, init));
            // Every call so far, and every call so far except order intents.
            this.queue = Promise.resolve();
            this.writes = Promise.resolve();
        }

        /**
         * Runs a call after the ones before it have finished. Calls are
         * queued: OpenCart writes the whole session back at the end of every
         * request, even one that only reads it, so two at once could undo
         * one's change. A search, a lookup and confirm skip any order intent
         * ahead of them (SKIP_INTENTS), but still wait for every other call,
         * and every later call waits for them.
         */
        serial(name, call) {
            const before = SKIP_INTENTS.indexOf(name) !== -1 ? this.writes : this.queue;
            const run = before.then(call, call);
            const done = run.then(() => null, () => null);
            this.queue = Promise.all([this.queue, done]).then(() => null);
            if (name !== 'intent') {
                this.writes = Promise.all([this.writes, done]).then(() => null);
            }
            return run;
        }

        /** One request, abandoned after its timeout (none for confirm) or when the caller's signal aborts. */
        timed(name, url, init, signal) {
            if (signal && signal.aborted) {
                // Superseded while it waited in the queue: never sent.
                return Promise.reject(new Error('aborted'));
            }
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timer = NO_TIMEOUT.indexOf(name) === -1 ? setTimeout(() => controller && controller.abort(), TIMEOUTS[name] || DEFAULT_TIMEOUT) : null;
            if (signal && controller) {
                signal.addEventListener('abort', () => controller.abort());
            }
            return this.fetch(url, Object.assign({ credentials: 'same-origin', signal: controller ? controller.signal : undefined }, init))
                .then((response) => response.json())
                .finally(() => clearTimeout(timer));
        }

        url(name, params) {
            const query = new URLSearchParams(Object.assign({ two_token: this.config.token }, params || {}));
            return this.config.urls[name] + '&' + query.toString();
        }

        /** Resolves once every call queued so far, order intents aside, has finished. */
        settled() {
            return this.writes;
        }

        get(name, params, signal) {
            return this.serial(name, () => this.timed(name, this.url(name, params), {}, signal));
        }

        post(name, params) {
            const body = new URLSearchParams(Object.assign({ two_token: this.config.token }, params || {}));
            return this.serial(name, () => this.timed(name, this.config.urls[name], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }));
        }
    }


    /** The page's controls, one per role, and the tile's order intent. */
    class Checkout {
        constructor(config, doc, api, win) {
            this.config = config;
            this.doc = doc;
            this.api = api;
            this.win = win || root;
            this.instances = {};
            this.intentSeq = 0;
        }

        /** Mount the shared company search (company-search-host.js) for this page's placement. */
        start() {
            const host = this.win.TwoCompanySearchHost;
            if (host && typeof host.mount === 'function') {
                this.instances = host.mount({
                    config: this.config,
                    api: this.api,
                    doc: this.doc,
                    alert: (message) => this.alert(message),
                    onCompany: (role, company) => this.companyChanged(role, company)
                });
            }
            return this;
        }

        companyChanged(role, company) {
            this.config.companies = Object.assign({}, this.config.companies || {});
            if (company) {
                this.config.companies[role] = company;
            } else {
                delete this.config.companies[role];
            }
            if (!this.tile || (role !== 'tile' && role !== this.tileRole())) {
                return Promise.resolve();
            }
            this.showCompany();
            if (!company) {
                this.intentSeq += 1;
                const notice = this.doc.getElementById('two-intent-notice');
                if (notice) {
                    notice.className = '';
                    notice.textContent = '';
                }
                return Promise.resolve();
            }
            return this.intent();
        }

        /** The address role whose company the tile pays for: billing when the page has one. */
        tileRole() {
            return this.doc.getElementById('input-payment-company') || (this.config.companies || {}).payment ? 'payment' : 'shipping';
        }

        /**
         * Resolves once every pending save has reached the shop, so placing
         * the order reads the company the buyer last gave. Order intents are
         * not waited for: confirm asks its own.
         */
        settled() {
            const pending = Object.keys(this.instances).map((role) => this.instances[role].flush());
            return Promise.all(pending).then(() => this.api.settled());
        }

        /**
         * Places the order: one confirm however often the button is pressed.
         * The button is disabled at once; Confirm waits for pending saves for
         * at most SETTLE_CAP, and goes through the same queue as every call.
         */
        confirm(button, form) {
            if (this.confirming) {
                return Promise.resolve();
            }
            this.confirming = true;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            const reset = (message) => {
                this.confirming = false;
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (message) {
                    this.alert(message);
                }
            };
            const capped = Promise.race([this.settled().then(() => true), wait(SETTLE_CAP).then(() => false)]);
            return capped.then((settled) => {
                if (!settled) {
                    reset(this.config.i18n.settle_timeout);
                    return null;
                }
                const params = {};
                new FormData(form).forEach((value, key) => {
                    params[key] = value;
                });
                return this.api.post('confirm', params).then((json) => {
                    if (json && json.redirect) {
                        this.win.location = json.redirect;
                        return;
                    }
                    // The shop escapes its notice.
                    reset('');
                    if (json && json.error) {
                        this.alert(json.error, true);
                    }
                });
            }).catch(() => reset(this.config.i18n.confirm_failed));
        }

        /** A dismissible notice where OpenCart shows its own. */
        alert(message, escaped) {
            const host = this.doc.getElementById('alert');
            if (!host) {
                return;
            }
            const box = this.doc.createElement('div');
            box.className = 'alert alert-danger alert-dismissible';
            box.setAttribute('role', 'alert');
            if (escaped) {
                box.innerHTML = message;
            } else {
                box.textContent = message;
            }
            const close = this.doc.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.setAttribute('data-bs-dismiss', 'alert');
            box.appendChild(close);
            host.prepend(box);
        }

        /** The payment tile has rendered (Two is the chosen method). */
        tileReady(form) {
            this.tile = form;
            const group = form.querySelector('[data-two-term-chips]');
            if (group) {
                this.terms = new TermChips({
                    group: group,
                    api: this.api,
                    config: this.config,
                    doc: this.doc,
                    alert: (message, escaped) => this.alert(message, escaped),
                    payIn: form.querySelector('[data-two-pay-in]'),
                    endOfMonth: Boolean(this.config.end_of_month)
                }).start();
            }
            // Each control's capture again, forced and in the call queue, so the
            // session the intent and confirm read is the one the buyer sees.
            const saves = Object.keys(this.instances).map((role) => this.instances[role].persist(true, true));
            return Promise.all(saves).then(() => {
                this.showCompany();
                const captured = form.getAttribute('data-two-tile') === 'search'
                    ? Boolean((this.config.companies || {}).tile)
                    : Boolean(form.querySelector('[data-two-company-summary]:not(.text-danger)'));
                return captured ? this.intent() : null;
            });
        }

        /** In address placement, the tile names the company the buyer's address form holds now. */
        showCompany() {
            const summary = this.tile && this.tile.querySelector('[data-two-company-summary]');
            const company = (this.config.companies || {})[this.tileRole()];
            const copy = (this.config.i18n || {}).company_from_address;
            // A saved address's summary is what that address keeps, which is
            // what confirm places: the hidden new-address form never replaces it.
            if (!summary || summary.hasAttribute('data-two-saved-address') || !company || !company.company_name || !copy) {
                return;
            }
            const number = String(company.organization_number || '');
            const label = number && !/^\s*two:/i.test(number) ? company.company_name + ' (' + number + ')' : company.company_name;
            summary.textContent = copy.replace('%s', label);
            summary.classList.remove('text-danger');
        }

        /** Asks the order intent and shows its notice; a later ask supersedes an earlier one. */
        intent() {
            const notice = this.doc.getElementById('two-intent-notice');
            // The merchant switched the preview off: confirm still asks its own intent.
            if (!this.tile || !notice || this.config.intent_preview === false) {
                return Promise.resolve();
            }
            const seq = (this.intentSeq += 1);
            const params = {};
            new FormData(this.tile).forEach((value, key) => {
                params[key] = value;
            });
            notice.className = 'form-text';
            notice.textContent = this.config.i18n.checking || '';
            return this.api.post('intent', params)
                .then((json) => {
                    if (seq !== this.intentSeq) {
                        return;
                    }
                    const outcome = json && json.outcome;
                    notice.className = outcome === 'approved' ? 'alert alert-success' : (json && json.notice ? 'alert alert-warning' : '');
                    // The shop escapes the notice.
                    notice.innerHTML = (json && json.notice) || '';
                })
                .catch(() => {
                    if (seq === this.intentSeq) {
                        notice.className = '';
                        notice.textContent = '';
                    }
                });
        }
    }

    /** How long arrow-key traversal waits before saving, so a run of keystrokes saves once. */
    const TERM_SAVE_DELAY_MS = 250;

    /**
     * The payment term chips (Build Guide section 4): one radio per offered
     * term in a radiogroup, one tab stop on the chosen chip, arrow keys,
     * Home and End moving the choice (the W3C radio group pattern). A single
     * term is shown, not chosen. Each chip shows the fee its term would add,
     * once the shop has quoted it; a term whose quote fails shows none.
     */
    class TermChips {
        constructor(options) {
            this.group = options.group;
            this.api = options.api;
            this.config = options.config;
            this.doc = options.doc;
            this.alert = options.alert || (() => {});
            this.payIn = options.payIn || null;
            this.endOfMonth = Boolean(options.endOfMonth);
            this.timer = null;
            this.chips = Array.prototype.slice.call(this.group.querySelectorAll('[data-two-term]'));
            const checked = this.chips.find((chip) => chip.getAttribute('aria-checked') === 'true');
            this.saved = checked ? Number(checked.dataset.twoTerm) : null;
        }

        start() {
            if (this.chips.length > 1) {
                this.chips.forEach((chip) => chip.addEventListener('click', () => this.choose(Number(chip.dataset.twoTerm))));
                this.group.addEventListener('keydown', (event) => this.key(event));
            }
            this.fees();
            return this;
        }

        key(event) {
            if (event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }
            const current = Math.max(this.chips.indexOf(this.doc.activeElement), 0);
            const last = this.chips.length - 1;
            const next = { ArrowRight: current + 1, ArrowDown: current + 1, ArrowLeft: current - 1, ArrowUp: current - 1, Home: 0, End: last }[event.key];
            if (next === undefined) {
                return;
            }
            event.preventDefault();
            const chip = this.chips[(next + this.chips.length) % this.chips.length];
            chip.focus();
            this.schedule(Number(chip.dataset.twoTerm), TERM_SAVE_DELAY_MS);
        }

        /** One predicate sets the tick, the state and the tab stop, so they cannot drift apart. */
        select(days) {
            this.chips.forEach((chip) => {
                const on = Number(chip.dataset.twoTerm) === days;
                chip.setAttribute('aria-checked', on ? 'true' : 'false');
                chip.tabIndex = on ? 0 : -1;
                chip.classList.toggle('btn-primary', on);
                chip.classList.toggle('btn-outline-primary', !on);
            });
            if (this.payIn) {
                const copy = this.endOfMonth ? this.config.i18n.pay_in_eom : this.config.i18n.pay_in;
                this.payIn.textContent = (copy || '').replace('%d', String(days));
            }
        }

        choose(days) {
            return this.schedule(days, 0);
        }

        /**
         * Shows the term at once and saves it after $delay (a run of arrow
         * keys saves once). The save takes its place in the call queue the
         * moment the choice is made, holding it until the delay ends, so a
         * Confirm pressed meanwhile waits and places the term on screen.
         */
        schedule(days, delay) {
            this.select(days);
            this.next = days;
            clearTimeout(this.timer);
            if (!this.pending) {
                let release;
                const held = new Promise((resolve) => {
                    release = resolve;
                });
                this.release = release;
                this.pending = this.api.serial('term', () => held.then(() => {
                    this.pending = null;
                    return this.save(this.next);
                }));
            }
            this.timer = setTimeout(() => this.release(), delay);
            return this.pending;
        }

        /**
         * Saves the term, then reloads the totals, from inside the queue
         * (calls made here go straight to the shop, not to the back of the
         * queue). A failed save re-syncs the chips and the totals with the
         * shop's last saved term, unless a later choice is already waiting:
         * that one then saves, and the chips and totals follow it.
         */
        save(days) {
            const body = new URLSearchParams({ two_token: this.config.token, days: String(days) }).toString();
            const failed = (message) => {
                if (message) {
                    this.alert(message, true);
                }
                if (this.pending || this.saved === null) {
                    return null;
                }
                this.select(this.saved);
                return this.totals().catch(() => null);
            };
            return this.api.timed('term', this.config.urls.term, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then((json) => {
                if (!json || !json.ok) {
                    return failed(json && json.error);
                }
                this.saved = days;
                // The term is saved: a totals reload that fails is tried once more, then the buyer is told.
                return this.totals().catch(() => this.totals()).catch(() => this.alert(this.config.i18n.totals_failed || '', false));
            }, () => failed(''));
        }

        /**
         * Rewrites the order's totals for the new term: the checkout's own
         * confirm view writes the order and renders its totals, of which only
         * the totals rows are swapped in, so nothing the buyer typed is lost.
         * Runs inside the term save's place in the queue.
         */
        totals() {
            const url = this.config.urls.totals;
            if (!url) {
                return Promise.resolve();
            }
            return this.api.fetch(url, { credentials: 'same-origin' }).then((response) => response.text()).then((html) => {
                const parsed = this.doc.createElement('template');
                parsed.innerHTML = html;
                const fresh = parsed.content.querySelector('tfoot');
                const host = this.doc.getElementById('checkout-confirm');
                const stale = host ? host.querySelector('tfoot') : null;
                if (fresh && stale) {
                    stale.replaceWith(this.doc.importNode(fresh, true));
                }
            });
        }

        /** Fills each chip's fee from the shop's quotes; the shop escapes them. */
        fees() {
            if (!this.config.urls.fees) {
                return Promise.resolve();
            }
            return this.api.get('fees').then((json) => {
                const fees = (json && json.fees) || {};
                this.chips.forEach((chip) => {
                    const slot = chip.querySelector('[data-two-term-fee]');
                    const fee = fees[chip.dataset.twoTerm];
                    if (slot) {
                        slot.innerHTML = fee || '';
                    }
                    const name = chip.getAttribute('aria-label');
                    if (fee && name && slot) {
                        chip.setAttribute('aria-label', name + ', ' + slot.textContent);
                    }
                });
            }).catch(() => null);
        }
    }

    const api = { Api: Api, TermChips: TermChips, Checkout: Checkout, readConfig: readConfig };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    const config = readConfig(root.document);
    if (!config) {
        return;
    }
    const checkout = new Checkout(config, root.document, new Api(config)).start();
    root.TwoCheckout = { tileReady: (form) => checkout.tileReady(form), settled: () => checkout.settled(), confirm: (button, form) => checkout.confirm(button, form) };
    // The tile may have rendered before this script ran.
    const form = root.document.getElementById('form-two');
    if (form) {
        checkout.tileReady(form);
    }
}(typeof window !== 'undefined' ? window : this));
