/**
 * The OpenCart host for the shared company search (TWO-26721).
 *
 * The popover, the modes, the identity and the sole-trader flow are the
 * vendored modules beside this file, byte for byte as the sibling plugins ship
 * them, and are never edited here. This file only supplies what they ask of a
 * host: the transport over the extension's own routes, OpenCart's address
 * fields, and the mount driver for OpenCart's AJAX re-renders.
 *
 * Exactly one control per role, placed by the one admin setting: on each
 * address form's own company field (payment, shipping), or on the tile's one
 * company field. Nothing here moves it from one to the other.
 */
(function (root, factory) {
    'use strict';

    if (typeof module === 'object' && module.exports) {
        module.exports = factory(root);
    } else {
        root.TwoCompanySearchHost = factory(root);
    }
}(typeof window !== 'undefined' ? window : this, function (root) {
    'use strict';

    const MIN_INPUT_LENGTH = 3;
    const SEARCH_DEBOUNCE_MS = 300;
    const CACHE_TTL_MS = 5 * 60 * 1000;
    const CACHE_LIMIT = 50;
    const DEFAULT_PARK_MS = 30000;
    const SAVE_DELAY_MS = 300;
    const TILE_SELECTOR = '#input-two-company';
    /** The registry's address typed as the company's own, preferred where the details carry several. */
    const PREFERRED_ADDRESS_TYPES = ['BUSINESS', 'REGISTERED', 'VISITING'];
    const AUTOFILLED = 'data-two-autofilled-value';
    const SOURCES = { registered: 'search', manual: 'manual', soletrader: 'sole_trader' };

    /** The panel's own English source strings, onto this extension's copy keys. */
    const PANEL_COPY = {
        'Registered company': 'chip_registered',
        'Sole trader': 'chip_sole_trader',
        'Enter manually': 'chip_manual',
        'Search for company': 'search_aria',
        'Company search is unavailable right now. Please try again shortly.': 'search_unavailable',
        '1 result is available, use up and down arrow keys to navigate.': 'results_one',
        '%1 results are available, use up and down arrow keys to navigate.': 'results_many',
        'Company Number': 'company_number',
        'Select a different sole trader': 'select_different_sole_trader',
        'Sign up or log in as a sole trader': 'sole_trader_signup'
    };
    /** Every address field an autofill writes, in the order a write fires `change`. */
    const ADDRESS_FIELDS = ['address-1', 'address-2', 'city', 'postcode', 'zone'];

    function modules() {
        return {
            Component: root.TwoCompanyCaptureComponent,
            Panel: root.TwoCompanySearchPanel,
            SoleTrader: root.TwoSoleTrader,
            createIdentity: root.TwoCompanyIdentity
        };
    }

    /**
     * Report the node a selector matches now and whenever OpenCart swaps it
     * for a fresh one. One observer per page, on the checkout container.
     */
    function Observer(doc) {
        this.doc = doc;
        this.entries = [];
        this.observer = null;
    }

    Observer.prototype.watch = function (selector, onNode) {
        if (!selector) return;
        const entry = { selector: selector, onNode: onNode, last: null };
        this.entries.push(entry);
        this.check(entry);
        if (!this.observer && typeof MutationObserver === 'function') {
            this.observer = new MutationObserver(() => this.entries.forEach((each) => this.check(each)));
            this.observer.observe(this.doc.body, { childList: true, subtree: true });
        }
    };

    Observer.prototype.disconnect = function () {
        if (this.observer) this.observer.disconnect();
        this.observer = null;
        this.entries = [];
    };

    Observer.prototype.check = function (entry) {
        const node = this.doc.querySelector(entry.selector);
        if (!node || node === entry.last) return;
        entry.last = node;
        entry.onNode(node);
    };

    /** The search transport, one per role, so a 429 parks this instance alone. */
    function Transport(host) {
        this.host = host;
        this.cache = new Map();
        this.controller = null;
        this.parkedUntil = 0;
        this.MIN_INPUT_LENGTH = MIN_INPUT_LENGTH;
        this.SEARCH_DEBOUNCE_MS = SEARCH_DEBOUNCE_MS;
    }

    Transport.prototype.minInputLengthMessage = function () {
        return this.host.copy('search_too_short');
    };

    /** With no country to search in, the empty answer says why rather than "no matches" (PS's line). */
    Transport.prototype.noResultsMessage = function () {
        return this.host.copy(this.host.country() ? 'no_matches' : 'select_country');
    };

    Transport.prototype.formatCompanyNumber = function (value) {
        const number = String(value || '').trim();
        return /^two:/i.test(number) ? '' : number;
    };

    Transport.prototype.abortActiveRequest = function () {
        if (!this.controller) return false;
        this.controller.abort();
        this.controller = null;
        return true;
    };

    /**
     * Through the checkout's call queue, like every other relay call: OpenCart
     * writes the whole session back at the end of each request, so a search
     * must not overlap a save (TWO-26721).
     */
    Transport.prototype.searchCompanies = function (params) {
        const country = String(params.getCountryCode() || '').toUpperCase();
        if (!country) return Promise.resolve({ items: [] });
        if (Date.now() < this.parkedUntil) return Promise.resolve({ unavailable: true });
        const key = country + '|' + params.term.toLowerCase();
        const hit = this.cache.get(key);
        if (hit && hit.at > Date.now() - CACHE_TTL_MS) return Promise.resolve({ items: hit.items });
        this.abortActiveRequest();
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        this.controller = controller;
        return this.host.api.get('search', { q: params.term, country: country }, controller ? controller.signal : undefined)
            .then((json) => {
                if (json && json.code === 'rate_limited') {
                    // Parks this control alone; the others keep searching.
                    this.parkedUntil = Date.now() + DEFAULT_PARK_MS;
                    return { unavailable: true };
                }
                if (!json || !Array.isArray(json.items) || json.degraded === true) return { unavailable: true };
                const items = json.items.map(toItem);
                this.remember(key, items);
                return { items: items };
            })
            .catch(() => (controller && controller.signal.aborted && this.controller !== controller ? { aborted: true } : { unavailable: true }))
            .finally(() => {
                if (this.controller === controller) this.controller = null;
            });
    };

    Transport.prototype.remember = function (key, items) {
        this.cache.delete(key);
        this.cache.set(key, { at: Date.now(), items: items });
        if (this.cache.size > CACHE_LIMIT) this.cache.delete(this.cache.keys().next().value);
    };

    function toItem(row) {
        const number = String(row.organization_number || '');
        const shown = /^\s*two:/i.test(number) ? '' : number.trim();
        const label = row.highlight || row.name || '';
        return {
            text: row.name,
            html: shown ? label + ' (' + shown + ')' : label,
            companyId: number,
            lookupId: row.lookup_id || ''
        };
    }

    /**
     * One role's host: the component's options, and what OpenCart needs back.
     *
     * @param {object} options role, placement ('address' | 'tile'), config, api, doc, observer, alert, onCompany
     */
    function RoleHost(options) {
        this.role = options.role;
        this.placement = options.placement;
        this.config = options.config;
        this.api = options.api;
        this.doc = options.doc;
        this.observer = options.observer;
        this.alert = options.alert || function () {};
        this.onCompany = options.onCompany || function () {};
        this.lookupSeq = 0;
        this.saveTimer = null;
        this.pending = Promise.resolve();
        this.saved = '';
        this.transport = new Transport(this);
    }

    RoleHost.prototype.copy = function (key) {
        const text = (this.config.i18n || {})[key];
        return typeof text === 'string' ? text : '';
    };

    RoleHost.prototype.translate = function (text) {
        const key = PANEL_COPY[text];
        const copy = key ? this.copy(key) : '';
        if (!copy) return text;
        return key === 'results_many' ? copy.replace('%d', '%1') : copy;
    };

    /** OpenCart's field ids: `input-<role>-<field>` in the checkout, bare `input-<field>` on the address edit form. */
    RoleHost.prototype.idPrefix = function () {
        return this.role === 'account' ? 'input-' : 'input-' + this.role + '-';
    };

    RoleHost.prototype.field = function (suffix) {
        return this.doc.getElementById(this.idPrefix() + suffix);
    };

    RoleHost.prototype.fieldSelector = function () {
        return this.placement === 'tile' ? TILE_SELECTOR : '#' + this.idPrefix() + 'company';
    };

    RoleHost.prototype.formRootSelector = function () {
        return this.role === 'account' ? '#form-address' : '#form-' + this.role + '-address, #form-register';
    };

    /** The ISO code of this role's own country select, lower cased; null where the role has no select. */
    RoleHost.prototype.adjacentCountry = function () {
        if (this.placement === 'tile') return null;
        const select = this.field('country');
        if (!select) return null;
        return String((this.config.countries || {})[select.value] || '').toLowerCase();
    };

    /** The billing country the shop resolved for the tile, carried on the tile's own field. */
    RoleHost.prototype.tileCountry = function () {
        const input = this.doc.querySelector(TILE_SELECTOR);
        return String((input && input.getAttribute('data-two-country')) || '').toLowerCase();
    };

    /** Whether a field holds exactly what an autofill put there. */
    function holdsOurs(input) {
        return input.hasAttribute(AUTOFILLED) && input.getAttribute(AUTOFILLED) === input.value;
    }

    RoleHost.prototype.writeField = function (suffix, value, written) {
        const input = this.field(suffix);
        if (!input || value === undefined || value === null) return;
        const text = String(value);
        if (input.tagName === 'SELECT') {
            const wanted = text.trim().toLowerCase();
            const option = Array.prototype.find.call(input.options, (each) => each.text.trim().toLowerCase() === wanted);
            if (!option) return;
            input.value = option.value;
        } else {
            input.value = text;
        }
        input.setAttribute(AUTOFILLED, input.value);
        written.push(input);
    };

    /**
     * Write every value first, then fire change on each, so no handler sees a
     * half-written address.
     *
     * A sole trader's record (`buyerRules`) is written by PrestaShop's rules
     * (TWO-26775): a blank writes nothing, a field the record leaves out is
     * cleared only where it still holds what was written there, and the zone
     * is filled only where it is empty or holds that write.
     *
     * @param {object} address
     * @param {object} [options] `{ buyerRules: true }`
     */
    RoleHost.prototype.writeAddress = function (address, options) {
        if (this.placement === 'tile' || !address) return;
        const buyerRules = !!(options && options.buyerRules);
        const street = address.street_address || address.street || '';
        const building = [address.building, address.apartment].filter(Boolean).join(' ');
        const values = { city: address.city, postcode: address.postal_code, zone: address.region };
        if (building) {
            values['address-1'] = building;
            values['address-2'] = street;
        } else if (street) {
            values['address-1'] = street;
        }
        if (buyerRules) {
            Object.keys(values).forEach((suffix) => {
                if (values[suffix] === undefined || values[suffix] === null || String(values[suffix]).trim() === '') delete values[suffix];
            });
            const zone = this.field('zone');
            if ('zone' in values && zone && zone.value && zone.value !== '0' && !holdsOurs(zone)) delete values.zone;
        }
        const written = [];
        ADDRESS_FIELDS.forEach((suffix) => {
            if (suffix in values) {
                this.writeField(suffix, values[suffix], written);
                return;
            }
            if (!buyerRules) return;
            const input = this.field(suffix);
            if (!input || !input.hasAttribute(AUTOFILLED)) return;
            const ours = holdsOurs(input);
            input.removeAttribute(AUTOFILLED);
            if (!ours || !input.value) return;
            input.value = '';
            written.push(input);
        });
        written.forEach((input) => input.dispatchEvent(new Event('change', { bubbles: true })));
    };

    /**
     * The buyer's own verified phone, on the billing form only. A blank clears
     * only a number this wrote (TWO-26775, as PrestaShop).
     *
     * @param {string} phone
     */
    RoleHost.prototype.writeTelephone = function (phone) {
        if (this.role !== 'payment') return;
        this.setTelephone(phone);
    };

    /**
     * The registry's phone from a company pick with autofill (TWO-27110), on
     * the buyer's own form: billing, else shipping when the page has no
     * billing address. A blank clears only a number this wrote.
     *
     * @param {string} phone
     */
    RoleHost.prototype.writeRegistryPhone = function (phone) {
        const buyer = this.role === 'payment' || (this.role === 'shipping' && !this.doc.getElementById('input-payment-company'));
        if (buyer) this.setTelephone(phone);
    };

    RoleHost.prototype.setTelephone = function (phone) {
        const input = this.doc.getElementById('input-telephone');
        if (!input) return;
        const value = typeof phone === 'string' ? phone.trim() : '';
        if (!value) {
            if (!input.value || !holdsOurs(input)) return;
            input.value = '';
            input.removeAttribute(AUTOFILLED);
        } else {
            input.value = value;
            input.setAttribute(AUTOFILLED, value);
        }
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    RoleHost.prototype.revertAddress = function () {
        ADDRESS_FIELDS.forEach((suffix) => {
            const input = this.field(suffix);
            if (!input || !input.hasAttribute(AUTOFILLED)) return;
            const ours = input.getAttribute(AUTOFILLED) === input.value;
            input.removeAttribute(AUTOFILLED);
            if (!ours) return;
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    };

    function preferredAddress(addresses) {
        const list = Array.isArray(addresses) ? addresses : [];
        // The registry's types read BUSINESS_ADDRESS, REGISTERED_ADDRESS and so on.
        const typed = list.find((each) => PREFERRED_ADDRESS_TYPES.some((type) => String(each.type || '').toUpperCase().indexOf(type) === 0));
        return typed || list[0] || null;
    }

    /** A pick: the number when the row had none, and the address where the merchant autofills. */
    RoleHost.prototype.lookUp = function (item) {
        const identity = this.identity;
        const seq = ++this.lookupSeq;
        const autofill = this.placement === 'address' && !!this.config.address_lookup;
        identity.addressNotice('');
        this.revertAddress();
        if (!item.lookupId || (!autofill && item.companyId)) return Promise.resolve();
        // The answer belongs to this pick alone: a later pick, Enter manually or a
        // new name since has set it aside, and its number must not reach that capture.
        // A Sole trader click nobody was adopted from has not: the pick still stands (TWO-26775).
        const superseded = () => seq !== this.lookupSeq || this.restingMode() !== 'registered'
            || identity.companyName() !== item.text;
        return this.api.get('company_details', { lookup_id: item.lookupId })
            .then((json) => {
                if (superseded()) return;
                if (!json || json.error) throw new Error('unavailable');
                if (!identity.companyId() && json.organization_number) {
                    identity.write({ companyId: json.organization_number, companyIdSource: 'registry' });
                }
                const address = preferredAddress(json.addresses);
                if (autofill && !address) throw new Error('no address');
                if (autofill) {
                    this.writeAddress(address);
                    this.writeRegistryPhone(address.phone_number);
                }
            })
            .catch(() => {
                if (!superseded() && autofill) identity.addressNotice(this.copy('address_unavailable'));
            });
    };

    RoleHost.prototype.country = function () {
        return String((this.component && this.component.countryCode()) || '').toUpperCase();
    };

    /** @returns {string} the mode of the capture held, a pending Sole trader click looked through */
    RoleHost.prototype.restingMode = function () {
        const mode = this.identity.captureMode();
        if (mode !== 'soletrader' || this.identity.soleTraderAdopted()) return mode;
        return this.component ? this.component.restingMode() : 'registered';
    };

    /**
     * The capture as the session keeps it. Sole-trader mode with nobody adopted
     * has captured nothing of its own: the company in it is still the one the
     * chip click left, and goes out under that capture's source, never as a
     * sole trader a reload would restore as adopted (TWO-26775).
     */
    RoleHost.prototype.company = function () {
        const identity = this.identity;
        const mode = this.restingMode();
        return {
            company_name: identity.companyName().trim(),
            organization_number: mode === 'manual' ? '' : identity.companyId().trim(),
            country_code: this.country(),
            source: SOURCES[mode] || 'search'
        };
    };

    /** Persist this role's capture in the session, so intent and confirm read what the buyer sees. */
    RoleHost.prototype.persist = function (force, quiet) {
        clearTimeout(this.saveTimer);
        this.saveTimer = null;
        const company = this.company();
        const key = JSON.stringify(company);
        if ((!force && key === this.saved) || (!company.country_code && company.company_name)) return this.pending;
        this.saved = key;
        this.savedSource = company.source;
        this.pending = this.api.post('save_company', {
            role: this.role,
            company_name: company.company_name,
            organization_number: company.organization_number,
            country: company.country_code || 'XX',
            source: company.source
        }).catch(() => null).then(() => (quiet ? null : this.onCompany(this.role, company.company_name ? company : null)));
        return this.pending;
    };

    RoleHost.prototype.schedulePersist = function () {
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.persist(), SAVE_DELAY_MS);
    };

    RoleHost.prototype.flush = function () {
        return this.saveTimer ? this.persist() : this.pending;
    };

    /** The capture the session already holds for this role, back into the identity. */
    RoleHost.prototype.restore = function () {
        const saved = (this.config.companies || {})[this.role];
        if (!saved || !saved.company_name) return;
        const mode = { manual: 'manual', sole_trader: 'soletrader' }[saved.source] || 'registered';
        this.identity.captureMode(mode);
        // A manual capture is a name alone, whatever the session holds beside it.
        this.identity.write({
            companyName: saved.company_name,
            companyId: mode === 'manual' ? '' : saved.organization_number,
            companyIdSource: mode === 'manual' ? '' : 'registry'
        }, { authoritative: true });
        if (mode === 'soletrader') this.identity.soleTraderAdopted(true);
        const input = this.doc.querySelector(this.fieldSelector());
        if (input) input.value = saved.company_name;
        this.saved = JSON.stringify(this.company());
        this.savedSource = this.company().source;
    };

    RoleHost.prototype.signupPrefill = function () {
        const value = (id) => {
            const input = this.doc.getElementById(id);
            return input ? String(input.value || '').trim() : '';
        };
        const prefix = this.placement === 'tile' ? 'input-payment-' : this.idPrefix();
        return {
            company_name: this.identity.companyName(),
            first_name: value(prefix + 'firstname') || value('input-firstname'),
            last_name: value(prefix + 'lastname') || value('input-lastname'),
            email: value('input-email'),
            phone_number: value('input-telephone'),
            country_code: this.country()
        };
    };

    /** The component's host contract, over this role. */
    RoleHost.prototype.options = function (parts) {
        const self = this;
        const tile = this.placement === 'tile';
        return {
            config: {
                checkoutApiUrl: this.config.checkout_api_url,
                checkoutPageUrl: this.config.checkout_page_url,
                // The header rows the merchant flagged to also send from the
                // browser, for the sole trader's own call to Two.
                customHeaders: this.config.custom_headers || {},
                // The placement rule, enforced without touching the component:
                // in the tile it offers no manual entry and has no address mount.
                isCompanySearchEnabled: !tile,
                soleTraderErrorMessage: this.copy('sole_trader_error')
            },
            Panel: parts.Panel,
            SoleTraderFlow: parts.SoleTrader,
            identity: this.identity,
            search: this.transport,
            translate: (text) => self.translate(text),
            observe: (selector, onNode) => self.observer.watch(selector, onNode),
            addressFieldSelector: tile ? '' : this.fieldSelector(),
            addressFormRootSelector: tile ? '' : this.formRootSelector(),
            tileFieldSelector: tile ? TILE_SELECTOR : '',
            fieldExists: (selector) => !!selector && !!self.doc.querySelector(selector),
            isVirtualCart: () => false,
            getAdjacentCountry: () => self.adjacentCountry(),
            getQuoteCountry: () => (tile ? self.tileCountry() : ''),
            getFallbackCountry: () => '',
            watchCountryChanges: (onChange) => {
                if (tile) {
                    self.observer.watch(TILE_SELECTOR, () => onChange(self.tileCountry()));
                    return;
                }
                self.doc.addEventListener('change', (event) => {
                    if (event.target && event.target.id === self.idPrefix() + 'country') onChange(self.adjacentCountry() || '');
                }, true);
            },
            supportedCompanyTypesUrl: (country) => self.api.url('sole_trader', { country: String(country).toUpperCase() }),
            supportedCountriesUrl: () => self.api.url('countries'),
            applyCompanyAddress: (item) => self.lookUp(item),
            revertAutofilledAddress: () => self.revertAddress(),
            // No country: the tokens are the buyer's, minted once at boot for
            // every panel on the page, before any panel may know its country.
            tokensUrl: () => self.api.url('sole_trader_tokens'),
            quoteId: () => '',
            apiClientParams: () => ({}),
            signupPrefill: () => self.signupPrefill(),
            signupCountry: () => self.country(),
            applyBuyerAddress: (address) => self.writeAddress(address, { buyerRules: true }),
            applyTelephone: (phone) => self.writeTelephone(phone),
            showError: (message) => self.alert(message || self.copy('sole_trader_error'))
        };
    };

    /** Build and start this role's component. Null when a vendored module failed to load. */
    RoleHost.prototype.start = function () {
        const parts = modules();
        if (typeof parts.Component !== 'function' || typeof parts.Panel !== 'function'
            || typeof parts.SoleTrader !== 'function' || typeof parts.createIdentity !== 'function') {
            return null;
        }
        this.identity = parts.createIdentity();
        this.restore();
        this.component = new parts.Component(this.options(parts));
        // A pick, an adoption and the move into manual entry are saved at once,
        // so the tile the shop renders next, and an address saved straight
        // after, already have them; only typing in manual entry waits for a
        // pause. Entering manual entry late would let an address save keep the
        // number it drops (TWO-26775).
        this.identity.subscribe(() => {
            this.syncBusy();
            if (this.identity.captureMode() === 'manual' && this.savedSource === 'manual') this.schedulePersist();
            else this.persist();
        });
        this.component.start();
        return this;
    };

    /** Take this role's control down: its observer, its sole-trader listeners and timers, its panel. */
    RoleHost.prototype.stop = function () {
        clearTimeout(this.saveTimer);
        this.observer.disconnect();
        const soleTrader = this.component && this.component.soleTrader();
        if (soleTrader) soleTrader.dispose();
        const panel = this.component && this.component.panel();
        if (panel) panel.unmount();
    };

    /** The spinner in the field across a sole-trader flight. */
    RoleHost.prototype.syncBusy = function () {
        const input = this.doc.querySelector(this.fieldSelector());
        if (!input) return;
        const busy = this.identity.soleTraderBusy();
        input.classList.toggle('two-company-field--busy', busy);
        if (busy) input.setAttribute('aria-busy', 'true');
        else input.removeAttribute('aria-busy');
    };

    /**
     * Mount the page's controls: one per address role in address placement,
     * the tile's one otherwise. Never both. The address edit form names its
     * one role (`account`) in the config.
     */
    function mount(options) {
        const placement = options.config.in_address ? 'address' : 'tile';
        const roles = Array.isArray(options.config.roles) ? options.config.roles
            : (placement === 'tile' ? ['tile'] : ['payment', 'shipping']);
        const observer = new Observer(options.doc);
        const hosts = {};
        roles.forEach((role) => {
            const host = new RoleHost(Object.assign({}, options, { role: role, placement: placement, observer: observer })).start();
            if (host) hosts[role] = host;
        });
        return hosts;
    }

    return { mount: mount, RoleHost: RoleHost, Transport: Transport, Observer: Observer, PANEL_COPY: PANEL_COPY, TILE_SELECTOR: TILE_SELECTOR };
}));
