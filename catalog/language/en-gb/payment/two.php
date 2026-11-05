<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';
// The payment method title when the merchant has set none for the language.
$_['text_title_default'] = 'Business invoice';

// Tile. %s is the brand's product name.
$_['entry_company'] = 'Company';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Buying for %s.';
$_['text_company_missing'] = 'To pay with %s, search for your company in the company field of your address and select it from the results.';
$_['text_company_missing_saved'] = 'To pay with %s, add your company to the address you are using.';
$_['button_confirm'] = 'Confirm order';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'Invoice email address';
$_['entry_purchase_order_number'] = 'PO number';
$_['entry_project'] = 'Project';
$_['entry_department'] = 'Department';
$_['text_optional'] = 'optional';
$_['error_field_too_long'] = '%1$s must be %2$d characters or fewer.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Your invoice with %s is likely to be accepted, subject to additional checks.';
$_['text_intent_declined'] = 'Your invoice with %s cannot be approved at this time. Please select an alternative payment method.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_too_short'] = 'Enter %2$d or more characters';
$_['js_no_matches'] = 'No matches found';
$_['js_search_unavailable'] = 'Company search is temporarily unavailable. Please try again.';
$_['js_select_country'] = 'Select your country above to search for your company.';
$_['js_back_to_search'] = 'Search for company';
$_['js_chip_registered'] = 'Registered company';
$_['js_chip_sole_trader'] = 'Sole trader';
$_['js_chip_manual'] = 'Enter manually';
$_['js_search_aria'] = 'Search for company';
$_['js_results_one'] = '1 result is available, use up and down arrow keys to navigate.';
$_['js_results_many'] = '%d results are available, use up and down arrow keys to navigate.';
$_['js_company_number'] = 'Company Number';
$_['js_select_different_sole_trader'] = 'Select a different sole trader';
$_['js_sole_trader_signup'] = 'Sign up or log in as a sole trader';
$_['js_address_unavailable'] = 'We could not fetch this company\'s address. Please enter it below.';
$_['text_company_edit_address'] = 'Edit address';
$_['js_sole_trader_error'] = 'Something went wrong setting up sole trader checkout. Please try again.';
$_['js_checking'] = 'Checking %1$s payment eligibility...';
$_['js_settle_timeout'] = 'We are still saving your company details. Please try again in a moment.';
$_['js_confirm_failed'] = 'We could not hear back about your order. It may still be processing: please wait a moment and check your order history before trying again.';
$_['error_order_in_progress'] = 'Your order is still being placed. Please wait a moment and check your order history before trying again.';
$_['js_totals_failed'] = 'Your payment term is saved, but the order total could not be updated. Please reload the page before you confirm.';
$_['error_too_many'] = 'Too many requests. Please wait and try again.';
$_['error_forbidden'] = 'Your checkout session has changed. Please reload the page and try again.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Connection error with payment provider. Please try again.';
$_['error_intent_unavailable'] = 'Payment provider temporarily unavailable. Please try again later.';
$_['error_intent_unprocessable'] = 'Unable to process your order with %s payment.';
$_['error_placement_currency'] = '%1$s is not available for orders in %2$s. Please choose another currency or payment method.';
$_['error_placement_buyer_country'] = 'This payment method is not available.';
$_['error_sole_trader'] = 'The sole trader sign-up could not be started. Please try again.';

// Callbacks.
$_['text_returning'] = 'Returning to the shop';
$_['text_continue'] = 'Continue';
$_['text_cancelled'] = 'Your order is cancelled.';
$_['text_pending'] = 'We\'re still confirming your payment. Please wait a minute before you try again, so that the order is not placed twice.';
$_['text_cart_changed'] = 'Your cart changed during payment verification. Please review your cart and try again.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'Your order could not be found. Please go back to checkout and try again.';
$_['error_payment_method'] = 'Please choose a payment method.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'Invoice purchase with %s is not available for this order.';
$_['error_two_same_company'] = 'Buyer and merchant may not be the same company';
$_['error_two_company_required'] = 'To pay with %s, enter your company name, organisation number and company country.';
$_['error_two_field_phone_number'] = 'Please enter a valid phone number to pay on invoice';
$_['error_two_field_organization_number'] = 'Please enter a valid organisation number to pay on invoice';
$_['error_two_field_company_name'] = 'Please enter a valid company name to pay on invoice';
$_['error_two_field_first_name'] = 'Please enter a valid first name to pay on invoice';
$_['error_two_field_last_name'] = 'Please enter a valid last name to pay on invoice';
$_['error_two_field_email'] = 'Please enter a valid email to pay on invoice';
$_['error_two_field_address'] = 'Please enter a valid address to pay on invoice';
$_['error_two_field_city'] = 'Please enter a valid city to pay on invoice';
$_['error_two_field_country'] = 'Please enter a valid country to pay on invoice';
$_['error_two_field_postal_code'] = 'Please enter a valid postal code to pay on invoice';
$_['error_two_field_invoice_email'] = 'Please enter a valid invoice email address to pay on invoice';

// Payment terms and the payment terms fee (Build Guide sections 4 and 5)
$_['text_terms_title'] = 'Choose your payment terms';
$_['text_terms_description'] = 'Your payment period starts when your order is fulfilled.';
$_['text_terms_description_eom'] = 'Payment is due at the end of the month your order is fulfilled in, plus the days you choose.';
$_['text_term_days'] = '%d days';
$_['text_term_eom'] = 'EOM+%d';
$_['text_term_eom_name'] = 'EOM+%1$d: pay %1$d days after the end of the month';
$_['text_pay_in'] = 'Pay in %d days';
$_['text_pay_in_eom'] = 'Pay in %d days from end of month';
$_['text_fee_line'] = 'Payment terms fee - %d days';
$_['text_fee_line_eom'] = 'Payment terms fee - %d days from end of month';
$_['text_fee_plus'] = '+%s';
$_['error_term'] = 'That payment term is not available. Please choose another.';
$_['error_placement_terms'] = 'This payment method is not available.';
$_['error_placement_fee'] = '%s is not available for this order right now. Please choose another payment method.';
$_['error_placement_fee_changed'] = 'Your payment terms fee changed. Please review your order total and confirm again.';

// TWO-27051: the explainer link and the optional fields' tooltips. %s is the brand's product name.
$_['text_about'] = 'What is %s?';
$_['text_about_body'] = '%s is a payment solution for B2B purchases online, allowing you to buy from your favourite merchants and suppliers on trade credit. Using %s, you can access flexible trade credit instantly to make purchasing simple.';
$_['text_about_strong'] = 'Buy now, receive your goods, pay your invoice later.';
$_['text_about_more'] = 'Click to find out more';
$_['help_invoice_email'] = 'Where %s should send the invoice for this order, if different from your account email.';
$_['help_purchase_order_number'] = 'Your internal purchase order reference for this order.';
$_['help_project'] = 'The project to associate with this purchase, for your own internal reference.';
$_['help_department'] = 'The department to associate with this purchase, for your own internal reference.';

// Product page buy button (TWO-25800). %s is the brand's product name. The
// product page message's default wording is text_about_strong, the tile's own sentence.
$_['text_product_button'] = 'Buy with %s';
$_['error_product_button_unreachable'] = 'Sorry, we could not add this to your basket. Please try again.';
