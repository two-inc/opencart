<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';
// The payment method title at checkout when none is set for a language: the same as the storefront file's.
$_['text_title_default'] = 'Business invoice';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Settings saved.';
$_['text_success_partial'] = 'The other settings were saved.';
$_['text_edit'] = 'Edit Two settings';
$_['text_version'] = 'Version';
$_['text_environment_production'] = 'Production';
$_['text_environment_sandbox'] = 'Sandbox';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'A key is saved. Leave this blank to keep it.';
$_['text_key_verified'] = 'API key verified successfully';
$_['text_key_verifying'] = 'The API key is being verified. Reload the page in a moment.';
$_['text_key_checking'] = 'Checking the API key...';
$_['text_key_kept'] = 'The previously saved key and environment were kept.';
$_['text_merchant_id'] = 'Merchant ID';
$_['text_merchant_short_name'] = 'Merchant short name';
$_['text_record_fetched_at'] = 'Merchant profile fetched';
$_['text_status_mapping_intro'] = 'Map %s payment states to OpenCart order statuses for workflow integration.';
$_['text_default_mappings'] = 'Default mappings:';
$_['text_default_fulfil'] = '%s: order fulfilled → Shipped';
$_['text_default_cancel'] = '%s: order cancelled → Canceled';
$_['text_default_refund'] = '%s: order refunded → Refunded';
$_['text_currently_active'] = 'Currently active:';
$_['text_none_selected'] = 'None selected';

// Tab
$_['tab_general'] = 'General';
$_['tab_checkout_fields'] = 'Checkout fields';
$_['tab_payment_terms'] = 'Payment terms';
$_['tab_order_management'] = 'Order management';
$_['tab_diagnostics'] = 'Diagnostics';

// Legend
$_['legend_status_mapping'] = 'Status mapping';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort order';
$_['entry_environment'] = 'Environment';
$_['entry_api_key'] = 'API key';
$_['entry_title'] = 'Title';
$_['entry_fulfil'] = '%s: order fulfilled, trigger statuses';
$_['entry_cancel'] = '%s: order cancelled';
$_['entry_refund'] = '%s: order refunded';

// Help
$_['help_environment'] = 'Sandbox is for testing and moves no real money. Use the API key issued for the environment you select.';
$_['help_api_key'] = 'Enter your API key which is provided by %s.';
$_['help_title'] = 'Enter a title which will appear on the checkout page as the payment method title.';
$_['help_fulfil'] = 'Select one or more order statuses that should trigger %1$s fulfillment. When any of these statuses are set, the order will be marked as fulfilled with %1$s. Buyer payment terms become active and payout cycle begins. You can select multiple statuses (hold Ctrl/Cmd to select multiple). Default: Shipped';
$_['help_cancel'] = 'Order has been cancelled with %s. This prevents fulfillment and stops the payment process. Default: Canceled';
$_['help_refund'] = 'Moving an order into this status refunds it in full with %s and issues a credit note to the buyer. Default: Refunded.';

// Error
$_['error_permission'] = 'You do not have permission to modify the Two payment settings.';
$_['error_partial'] = 'Some settings were not saved. See the highlighted fields.';
$_['error_title_missing'] = 'Enter a title.';
$_['error_status_missing'] = 'Select an order status.';
$_['error_status_unknown'] = 'Select an order status this shop has.';
$_['error_status_void'] = 'OpenCart uses this status for orders that are being edited, so it cannot be mapped. Select another order status.';
$_['error_key_missing'] = 'Enter an API key.';
$_['error_kept_malformed'] = 'This is not a valid API key: it may only contain printable characters, with no spaces or line breaks. The previously saved key was kept.';
$_['error_kept_environment'] = 'Select a valid environment.';
$_['error_environment_unhealthy'] = 'The stored environment cannot be used on this shop, so sandbox is used. Select an environment and save.';
$_['error_key_invalid'] = 'This API key was rejected by %1$s. It may be invalid or expired - check the key in your %1$s portal.';
$_['error_key_service'] = '%s could not verify the API key right now (HTTP %d). This is usually temporary - try again shortly.';
$_['error_key_unreachable'] = 'This shop could not reach the %s API at all (network, DNS or firewall). The API key itself has not been judged.';
$_['error_key_not_configured'] = 'Enter your %1$s API key to enable %1$s.';
$_['error_key_unexpected'] = '%s returned an unexpected response while verifying the API key (HTTP %d).';
$_['error_key_unverified'] = 'The API key could not be verified.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Two order';
$_['text_two_state'] = 'State at Two';
$_['text_no_two_order'] = 'No Two order is linked to this order.';
$_['button_invoice'] = 'Download invoice';
$_['help_invoice'] = 'The invoice Two issued when the order was fulfilled.';
$_['error_invoice_permission'] = 'You do not have permission to see this invoice.';
$_['error_invoice_not_ready'] = 'The invoice is not ready yet because the order is still being fulfilled. Please try again later.';
$_['error_invoice_state'] = 'No invoice is available because the order is in state: %s.';
$_['error_invoice_no_reference'] = 'No Two order is linked to this order.';
$_['error_invoice'] = 'The invoice could not be retrieved. Please try again later.';

// Checkout fields: placement switch, optional fields, minimum order. %s is the brand's product name.
$_['entry_company_search_in_address'] = 'Enable company search in address entry';
$_['help_company_search_in_address'] = 'When enabled, the buyer may search for their company within the address entry section of the checkout. Otherwise, company search will be visible within the payment method.';
$_['entry_address_lookup'] = 'Autofill company address';
$_['help_address_lookup'] = 'Enables automatically filling in the registered address from the national registry.';
$_['entry_enable_invoice_email'] = 'Show invoice email field';
$_['help_enable_invoice_email'] = 'If you choose Yes then customers will see an invoice email field in the %s payment section at checkout. It sits with the payment method rather than the address so the buyer is prompted to consider a dedicated invoicing address even when their billing and shipping addresses match.';
$_['entry_enable_po_number'] = 'Show PO number field';
$_['help_enable_po_number'] = 'If you choose Yes then customers will see a PO number field in the %s payment section at checkout.';
$_['entry_enable_project'] = 'Show project field';
$_['help_enable_project'] = 'If you choose Yes then customers will see a project field in the %s payment section at checkout.';
$_['entry_enable_department'] = 'Show department field';
$_['help_enable_department'] = 'If you choose Yes then customers will see a department field in the %s payment section at checkout.';
$_['entry_min_order_amount'] = 'Minimum order value, %s';
$_['help_min_order_amount'] = 'Hide the payment method below this order value (shop default currency, on the tax basis selected below). Leave empty for no minimum.';
$_['entry_min_order_basis'] = 'Minimum order value tax basis';
$_['help_min_order_basis'] = 'Whether the basket is compared against the minimum including or excluding tax.';
$_['text_basis_default'] = 'As the platform minimum (including tax when there is none)';
$_['text_basis_option_gross'] = 'Including tax (gross)';
$_['text_basis_option_net'] = 'Excluding tax (net)';
$_['error_switch_invalid'] = 'Choose Yes or No.';
$_['error_minimum_invalid'] = 'Enter an amount above zero with at most two decimals, or leave the field empty.';
$_['error_basis_invalid'] = 'Choose a tax basis from the list.';

// Health row (Build Guide section 18). %1$s is the provider's full name, %2$s the shop's default currency.
$_['entry_health'] = 'Payment method at checkout';
$_['text_health_hidden'] = 'Not shown at checkout';
$_['text_health_shown'] = 'Shown at checkout';
$_['text_withheld_disabled'] = 'the extension is not enabled. Switch Status on.';
$_['text_withheld_no_api_key'] = 'no API key is saved. Check API key.';
$_['text_withheld_key_rejected'] = 'the API key was rejected. Check API key and Environment.';
$_['text_withheld_buyer_countries_empty'] = 'no buyer countries are currently enabled for your account. Contact %1$s to have them enabled.';
$_['text_withheld_buyer_countries_malformed'] = 'the buyer countries on your account could not be read. Contact %1$s.';
$_['text_withheld_merchant_unidentified'] = 'your merchant account has not been identified yet. Save General to verify the API key.';
$_['text_withheld_currency'] = 'none of the shop\'s enabled currencies is one invoices can be issued in.';
$_['text_health_below'] = 'hidden for baskets below %s';
$_['text_health_floor'] = '%1$s %2$s %3$s';
$_['text_basis_gross'] = 'including tax';
$_['text_basis_net'] = 'excluding tax';
$_['text_or'] = 'or';
$_['text_health_countries'] = 'offered only to buyers in %s';
$_['text_health_currencies'] = 'hidden for baskets in %s';
// Order page: refunds and the credit note
$_['button_credit_note'] = 'Download credit note';
$_['help_credit_note'] = 'The latest credit note Two issued for this order.';
$_['error_credit_note'] = 'The credit note could not be retrieved. It may still be processing. Please try again later.';
$_['text_refund'] = 'Refund';
$_['text_refunded'] = 'Refunded at Two';
$_['text_remaining'] = 'Left to refund';
$_['text_refund_help'] = 'Pick how many of each line to refund, or enter an amount line, or both. The full refund status refunds whatever is left.';
$_['text_refund_unavailable'] = 'Refunds open once Two has invoiced the order.';
$_['text_refund_unread'] = 'Two could not be asked for this order. Reload the page to try again.';
$_['text_refunds'] = 'Refunds sent from this shop';
$_['column_line'] = 'Line';
$_['column_quantity'] = 'Ordered';
$_['column_net'] = 'Net';
$_['column_tax'] = 'Tax';
$_['column_rate'] = 'Tax rate';
$_['column_refund_quantity'] = 'Refund';
$_['column_date'] = 'Date';
$_['column_amount'] = 'Amount';
$_['column_state'] = 'Outcome';
$_['entry_refund_net'] = 'Amount line, net';
$_['entry_refund_tax'] = 'Amount line, tax';
$_['entry_refund_rate'] = 'Amount line, tax rate';
$_['entry_refund_reason'] = 'Reason (on the credit note)';
$_['button_refund'] = 'Send refund to Two';
$_['error_refund_permission'] = 'You do not have permission to refund this order.';
$_['error_refund_quantity'] = 'Not refunded: a quantity is not a whole number.';
$_['text_refund_state_pending'] = 'Not answered';
$_['text_refund_state_done'] = 'Refunded';
$_['text_refund_state_refused'] = 'Refused';
$_['text_refund_state_unknown'] = 'Unknown';
$_['text_refund_state_not_applied'] = 'Not applied';
$_['button_retry'] = 'Retry';
$_['text_refund_blocked'] = 'A refund below has an unknown outcome. Retry it before sending another refund: it is sent again under the same key, so Two refunds it at most once.';
$_['column_left'] = 'Left to refund';
$_['text_panel_loading'] = 'Asking Two for this order...';
$_['error_panel'] = 'The Two tab could not be loaded. Open the tab again to retry, or reload the page.';
$_['error_ajax'] = 'The shop did not answer as expected. Check the order history and the Two tab before trying again.';
$_['button_check_status'] = 'Check status with Two';
$_['help_check_status'] = 'This order is waiting for the buyer to return from Two. Ask Two now: an order the buyer verified is confirmed and placed, a cancelled one is noted.';

// Diagnostics: trusted proxies for the checkout's rate limits.
$_['entry_trusted_proxies'] = 'Trusted proxies';
$_['help_trusted_proxies'] = 'Addresses of your own reverse proxies, load balancers or CDN egress, as IPs or CIDR ranges, separated by commas or new lines. These IP addresses will be exempt from rate limiting.';
$_['error_proxy_invalid'] = 'Trusted proxies: "%s" is not a valid IP address or CIDR range.';
$_['entry_disable_rate_limit'] = 'Disable checkout rate limiting';
$_['help_disable_rate_limit'] = 'Removes the per-caller ceiling on the checkout AJAX endpoints. The ceiling is on by default. If this store sits behind a CDN, load balancer or reverse proxy and Trusted proxies above is empty, every buyer arrives as that one address and shares a single ceiling - buyers are then refused mid-checkout with a too-many-requests message. The fix is to fill in Trusted proxies, which lets the ceiling tell buyers apart; switch this On only as a stopgap while you get that list, and back Off afterwards.';

// Payment terms tab (Build Guide sections 4 and 5)
$_['text_terms_none'] = 'The merchant record offers no payment term yet, so Two is not offered at checkout. Save a valid API key, or check your terms in the Two merchant portal.';
$_['text_days'] = '%d days';
$_['text_term_type_standard'] = 'Standard';
$_['text_term_type_eom'] = 'End of month';
$_['text_default_term_auto'] = 'Automatic';
$_['text_differential_total'] = 'Total fee for selected term';
$_['text_differential_difference'] = 'Fee difference vs default payment term';
$_['text_surcharge_none'] = 'No surcharge applied';
$_['text_surcharge_percentage'] = 'Percentage';
$_['text_surcharge_fixed'] = 'Fixed fee';
$_['text_surcharge_fixed_and_percentage'] = 'Fixed fee and percentage';
$_['text_rounding_none'] = 'None';
$_['text_rounding_up'] = 'Up';
$_['text_rounding_down'] = 'Down';
$_['text_rounding_standard'] = 'Standard';
$_['text_no_rounding'] = 'No rounding';
$_['legend_surcharge'] = 'Surcharge';
$_['entry_payment_term_type'] = 'Payment term type';
$_['entry_payment_terms'] = 'Available payment terms';
$_['entry_default_payment_term'] = 'Default payment term';
$_['entry_surcharge_type'] = 'Surcharge method';
$_['entry_surcharge_differential'] = 'Surcharge calculation basis';
$_['entry_surcharge_line_description'] = 'Surcharge line description';
$_['entry_surcharge_rounding_basis'] = 'Surcharge rounding';
$_['entry_surcharge_rounding_step'] = 'Rounding step';
$_['entry_surcharge_tax_class'] = 'Surcharge tax treatment';
$_['entry_surcharge_grid'] = 'Per-term surcharge';
$_['column_term'] = 'Term';
$_['column_percentage'] = 'Percentage';
$_['column_fixed'] = 'Fixed fee';
$_['column_cap'] = 'Cap';
$_['help_payment_term_type'] = 'Standard terms run from fulfilment. End of month terms run from the end of the month the order is fulfilled in, and offer 30, 45 and 60 days only.';
$_['help_payment_terms'] = 'The terms your %1$s account offers. Buyers choose from the ones ticked here; a fresh install ticks 30, 60 and 90 days. With none of the ticked terms offered, %1$s is not shown at checkout.';
$_['help_default_payment_term'] = 'The term the buyer starts on. Automatic uses your account\'s default when it is offered, else 30 days, else the shortest.';
$_['help_surcharge_type'] = 'Add a payment terms fee for the buyer\'s chosen term. %s computes the fee; the shop only sends this configuration. The Two payment terms fee order total must be enabled (Extensions > Order Totals).';
$_['help_surcharge_differential'] = 'Total fee charges the configured surcharge for the chosen term. Fee difference charges only the difference versus the default payment term.';
$_['help_surcharge_line_description'] = 'Buyer-facing label for the fee line. Use %s for the term length in days. Leave blank for the default.';
$_['help_surcharge_rounding_basis'] = 'Snap the fee to a clean increment. Select None for standard two-decimal amounts.';
$_['help_surcharge_rounding_step'] = 'Increment the fee is rounded to. Applies only when a rounding direction is selected.';
$_['help_surcharge_tax_class'] = 'The tax class the fee is charged at, as for any product: the shop\'s own tax rules apply at the buyer\'s address. To leave the fee untaxed, create a tax class with a 0% rate and select it. Required while a surcharge is on.';
$_['help_surcharge_grid'] = 'Percentage is the share of the %2$s fee passed to the buyer, from 0 to 100. The cap applies to the whole fee: the percentage and the fixed fee together, not the percentage alone. Leave it empty for no cap. Fixed fees and caps are in %1$s, converted at %2$s\'s rates for other currencies.';
$_['error_term_type_unknown'] = 'Choose a payment term type from the list.';
$_['error_terms_none'] = 'You must select at least one payment term.';
$_['error_terms_unknown'] = 'Select only terms your account offers.';
$_['error_default_term_unknown'] = 'Choose a default payment term among the selected terms, or Automatic.';
$_['error_surcharge_type_unknown'] = 'Choose a surcharge method from the list.';
$_['error_surcharge_tax_class_missing'] = 'Select a surcharge tax treatment: surcharges are enabled, so you must choose a tax class before saving.';
$_['error_surcharge_tax_class_unknown'] = 'The surcharge tax treatment must be one of the shop\'s tax classes. To leave the fee untaxed, create a class with a 0% rate.';
$_['error_surcharge_step_unknown'] = 'Rounding step must be one of the offered values.';
$_['error_surcharge_basis_unknown'] = 'Choose a surcharge rounding from the list.';
$_['error_surcharge_cell_invalid'] = '%1$s for the %2$d-day term must be a non-negative number.';
$_['error_surcharge_cap_zero'] = 'Surcharge cap for the %2$d-day term cannot be 0. To charge nothing on this term, set the percentage and the fixed fee to 0 instead, and leave the cap empty.';
$_['text_health_fee_quote'] = 'payment terms fee quotes have failed since %s (%s), so %s is not offered to the carts they fail for';
$_['text_health_fx'] = '%s\'s FX rates fetched %s';
$_['text_withheld_no_terms'] = 'no payment term is offered: your account offers none of the terms ticked on the Payment terms tab for this term type.';
$_['text_withheld_fee_total_off'] = 'a surcharge is set but the Two payment terms fee order total is not enabled. Enable it in Extensions > Order Totals, or set the surcharge method to No surcharge applied.';
$_['text_withheld_tax_code_map_unreadable'] = 'the saved tax codes for 0%% lines cannot be read. Tick Clear saved tax codes in the Tax codes for 0%% lines section (Order management tab) and save.';
$_['error_surcharge_percent_range'] = '%1$s for the %2$d-day term must be between 0 and 100: it is the share of the fee passed to the buyer.';

// TWO-27051: vendor name (General), subtitle and the tile switches (Checkout fields), the confirmed status and Validate tax subtotals (Order management), the merchant fee per term (Payment terms). %s is the brand's product name.
$_['entry_vendor_name'] = 'Vendor name (optional)';
$_['help_vendor_name'] = 'If this store represents one of several vendor sites sharing the same %1$s merchant account, enter a name here to identify this specific site/vendor on each order sent to %1$s - leave blank if you only run a single site.';
$_['error_vendor_invalid'] = 'Enter a vendor name of at most 255 characters, without line breaks or accented letters: it is sent with each request as a header.';
$_['entry_subtitle'] = 'Subtitle';
$_['help_subtitle'] = 'Optional subtitle shown beneath the title at checkout.';
$_['entry_enable_order_intent'] = 'Enable order intent pre-approval check';
$_['help_enable_order_intent'] = 'If you choose Yes then the checkout calls %1$s to preview order approval before the buyer submits payment, and reflects the result in the payment tile. If you choose No, this preview call is skipped - the buyer still goes through %1$s\'s real approval check when they submit payment.';
$_['entry_show_about_link'] = 'Show "What is %s" explainer link';
$_['help_show_about_link'] = 'If you choose Yes then buyers see a "What is %1$s?" info tooltip with a link to an explainer resource in the %1$s payment tile at checkout.';
$_['entry_display_tooltips'] = 'Display input tooltips';
$_['help_display_tooltips'] = 'If you choose Yes then the optional checkout fields above show a short help tooltip on their label.';
$_['legend_tax'] = 'Tax';
$_['legend_tax_codes'] = 'Tax codes for 0% lines';
$_['text_tax_codes_intro'] = 'For each tax class, choose the code %1$s receives on a zero-rated line in each case your tax setup can put it in. A line takes a code only from these rows: a row left on (none) sends no code, and %1$s never picks one itself.';
$_['text_tax_codes_exempt_row'] = 'VAT-registered buyer, other EU state';
$_['text_tax_codes_none_row'] = 'Buyer\'s address does not map to a tax rate (no tax charged, e.g. exports)';
$_['text_tax_codes_none'] = '(none)';
$_['text_tax_codes_unlisted'] = '%s (not listed for zero-rated lines)';
$_['text_tax_codes_record_error'] = 'The merchant details could not be read from %s, so the tax codes cannot be shown. Use Refresh merchant profile on the Diagnostics tab. Saved tax codes keep applying at checkout.';
$_['text_tax_codes_list_error'] = 'The tax codes for %1$s could not be read from %2$s. Saved tax codes keep applying at checkout, and this page asks again within 5 minutes.';
$_['text_tax_codes_stale'] = 'The tax codes for %1$s could not be refreshed from %2$s: the last attempt (%3$s) failed with HTTP %4$d. The list shown was read %5$s, and this page asks again within 5 minutes.';
$_['text_tax_codes_unreadable'] = 'Saved tax codes cannot be read, so %s is not shown at checkout. Clear them to fix this.';
$_['text_tax_codes_saved_valid'] = 'Saved tax codes still apply at checkout, though %1$s does not require them for your country (%2$s).';
$_['text_tax_codes_saved_invalid'] = 'Some saved tax codes are not valid on zero-rated lines for your country (%1$s), so %2$s refuses orders that use them. Clear them unless you need them.';
$_['entry_tax_codes_clear'] = 'Clear saved tax codes';
$_['entry_buyer_vat_field'] = 'Buyer\'s VAT number';
$_['help_buyer_vat_field'] = 'The customer field that holds the buyer\'s VAT number, read from the payment address, else from the account. A Spanish merchant\'s order sends it to %s, and it decides the VAT-registered buyer row.';
$_['error_form_truncated'] = 'Nothing was saved: the form was cut short by the server\'s limit on posted fields (max_input_vars). Raise the limit and save again.';
$_['error_tax_code_unlisted'] = 'The tax codes were not saved: a chosen code is not listed for 0% lines in your country.';
$_['error_vat_field_unknown'] = 'Choose one of the listed customer fields.';
$_['entry_enable_tax_subtotals'] = 'Validate tax subtotals';
$_['help_enable_tax_subtotals'] = 'If you choose Yes, a per-tax-rate tax_subtotals breakdown is sent with order intents, orders, order updates and refunds, for validation against the order lines. If you choose No, tax_subtotals is omitted from all of them.';
$_['entry_confirmed'] = '%s: verified, ready for fulfillment';
$_['help_confirmed'] = 'Payment is verified and order is ready for merchant fulfillment. Merchant can now process and ship the order. Default: Processing';
$_['text_default_confirmed'] = '%s: verified, ready for fulfillment → Processing';
$_['error_status_conflict'] = 'This status is also mapped to fulfil, cancel or refund orders, so an order would be acted on the moment it is confirmed. Select another order status.';
$_['text_fees_stale'] = 'Fees could not be refreshed, so the figures last retrieved are shown.';
$_['text_fees_stale_dated'] = 'Fees could not be refreshed, so the figures retrieved on %s are shown.';
$_['text_fees_unavailable'] = 'Fees could not be loaded because the pricing service could not be reached. The figures beside each term are missing, not zero.';
$_['text_fees_rejected'] = 'Fees could not be loaded. The pricing service refused the request with HTTP %1$s: %2$s. The figures beside each term are missing, not zero.';
$_['text_fees_rejected_bare'] = 'Fees could not be loaded. The pricing service refused the request with HTTP %s. The figures beside each term are missing, not zero.';
$_['text_fees_stale_rejected'] = 'The pricing service refused the request with HTTP %1$s: %2$s.';
$_['text_fees_stale_rejected_bare'] = 'The pricing service refused the request with HTTP %s.';
$_['text_fees_no_api_key'] = 'Fees cannot be shown until an API key is saved on the General tab.';
$_['text_fees_no_figure'] = 'no figure';

// TWO-27051 review: a map that names the confirmed status, and the subtitle's length.
$_['error_status_is_confirmed'] = 'Orders are placed at this status when they are confirmed, so they would be acted on at once. Select another order status, or change the confirmed status.';
$_['error_subtitle_too_long'] = 'Enter a subtitle of at most 255 characters.';

// Diagnostics (TWO-27051): debug logging, the SSL bypass, the custom request-header table, the error log, the merchant profile and the scheduled refresh URL. %s is the brand's product name.
$_['entry_debug_mode'] = 'Enable debug logging';
$_['help_debug_mode'] = 'Enable detailed logging for troubleshooting. Logs each API request and response to the shop\'s error log, bodies included, with the API key and custom header values redacted. Only enable when requested by %s support.';
$_['entry_disable_ssl_verify'] = 'Disable SSL verification';
$_['help_disable_ssl_verify'] = 'WARNING: Only enable this if you are behind a corporate proxy with custom SSL certificates. This disables SSL certificate verification and is a SECURITY RISK. NOT RECOMMENDED for production.';
$_['entry_custom_headers'] = 'Custom request headers';
$_['column_header_name'] = 'Header name';
$_['column_header_value'] = 'Value';
$_['column_header_browser'] = 'Also send from browser';
$_['button_header_add'] = 'Add header';
$_['button_header_remove'] = 'Remove';
$_['help_custom_headers'] = 'Headers your IT administrator asks you to add. Each one is sent on every call this store makes to the %s API from your server. Tick "Also send from browser" only where your IT administrator requires the header on calls from the buyer\'s browser as well: that header\'s value will be published to the buyer\'s browser and may be read by anyone.';
$_['error_header_name_invalid'] = 'Custom request headers: "%s" is not a valid header name.';
$_['error_header_name_reserved'] = 'Custom request headers: "%s" is reserved and cannot be overridden. Choose a different header name.';
$_['error_header_name_duplicate'] = 'Custom request headers: "%s" is used by more than one row. Keep one row per header name.';
$_['error_header_value_missing'] = 'Custom request headers: enter a value for header "%s", or remove the row.';
$_['error_header_value_invalid'] = 'Custom request headers: the value for header "%s" may only contain printable ASCII characters - no line breaks, control characters, or non-ASCII text.';
$_['error_headers_tab'] = 'Nothing on the Diagnostics tab was saved. Correct or remove the row, then save again.';
$_['entry_error_log'] = 'Error log';
$_['button_error_log'] = 'View last 100 error log records';
$_['heading_error_log'] = '%s error log';
$_['text_error_log_intro'] = 'The last %d lines %s wrote to the shop\'s error log, oldest first. The API key and custom header values are redacted.';
$_['text_error_log_empty'] = 'There are no lines to show yet.';
$_['entry_refresh_profile'] = 'Merchant profile';
$_['button_refresh_profile'] = 'Refresh merchant profile';
$_['help_refresh_profile'] = 'Your offerable payment terms, buyer-surcharge rates, minimum order value and default term are read from %s and cached. They are refreshed on a schedule and whenever the API key or environment is saved; use this to pull a change through now.';
$_['text_profile_refreshed'] = 'Merchant profile refreshed.';
$_['error_profile_refresh'] = 'Could not refresh the merchant profile - the previously cached one is still in use. Check that the API key verifies and that %s is reachable.';
$_['entry_cron_url'] = 'Scheduled refresh URL';
$_['help_cron_url'] = 'Call this every 15 minutes from your server\'s crontab. Each call checks Two orders the buyer verified but never returned from and places them, and refreshes the merchant profile when it is due. The token may instead be sent as an X-Two-Cron-Token header or a POST field, which keeps it out of process listings and proxy logs. Keep it secret; anyone holding it can trigger the run. OpenCart\'s own cron (the Cron Jobs page) runs the same check hourly and the refresh daily, but only this URL recovers an order within 15 minutes.';
$_['text_cron_last_run'] = 'The scheduled refresh last ran at %s.';
$_['text_cron_never'] = 'The scheduled refresh has not run yet.';
$_['text_pending_stale'] = '%d Two orders are waiting for the buyer to return, and the scheduled URL has not run in the last hour. Schedule it every 15 minutes so verified orders are placed.';

// TWO-27055: the product page message and buy button (Checkout fields).
$_['entry_product_message_enabled'] = 'Show message on product pages';
$_['help_product_message_enabled'] = 'Shows a short promotional line on product pages, beneath the add to cart button. Off by default. Where exactly it lands depends on your theme.';
$_['entry_product_button_enabled'] = 'Show buy button on product pages';
$_['help_product_button_enabled'] = 'Adds a button under add to cart that puts the item in the basket and takes the buyer to checkout with this payment method already chosen. Off by default. Where exactly it lands depends on your theme.';
$_['entry_product_message'] = 'Product page message';
$_['help_product_message'] = 'Optional. Leave empty to use the default wording. Avoid naming a number of days unless you are certain of the terms you offer: payment terms run from fulfilment, and the terms actually available come from your merchant record.';
