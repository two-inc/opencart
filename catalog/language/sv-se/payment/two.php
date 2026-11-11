<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';
// The payment method title when the merchant has set none for the language.
$_['text_title_default'] = 'Företagsfaktura';

// Tile. %s is the brand's product name.
$_['entry_company'] = 'Företag';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Handlar för %s.';
$_['text_company_missing'] = 'För att betala med %s, sök efter ditt företag i företagsfältet i din adress och välj det bland resultaten.';
$_['text_company_missing_saved'] = 'För att betala med %s, lägg till ditt företag i adressen du använder.';
$_['button_confirm'] = 'Bekräfta order';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'E-postadress för faktura';
$_['entry_purchase_order_number'] = 'PO-nummer';
$_['entry_project'] = 'Projekt';
$_['entry_department'] = 'Avdelning';
$_['text_optional'] = 'valfritt';
$_['error_field_too_long'] = '%1$s får ha högst %2$d tecken.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Din faktura hos %s kommer sannolikt att godkännas, med förbehåll för ytterligare kontroller.';
$_['text_intent_declined'] = 'Din faktura hos %s kan inte godkännas just nu. Välj en annan betalningsmetod.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_too_short'] = 'Ange %2$d eller fler tecken';
$_['js_no_matches'] = 'Inga träffar hittades';
$_['js_search_unavailable'] = 'Företagssökningen är tillfälligt otillgänglig. Försök igen.';
$_['js_select_country'] = 'Välj ditt land ovan för att söka efter ditt företag.';
$_['js_back_to_search'] = 'Sök efter företag';
$_['js_chip_registered'] = 'Registrerat företag';
$_['js_chip_sole_trader'] = 'Enskild firma';
$_['js_chip_manual'] = 'Ange manuellt';
$_['js_search_aria'] = 'Sök efter företag';
$_['js_results_one'] = '1 resultat är tillgängligt, använd uppåt- och nedåtpilarna för att navigera.';
$_['js_results_many'] = '%d resultat är tillgängliga, använd uppåt- och nedåtpilarna för att navigera.';
$_['js_company_number'] = 'Organisationsnummer';
$_['js_select_different_sole_trader'] = 'Välj en annan enskild firma';
$_['js_sole_trader_signup'] = 'Registrera dig eller logga in som enskild firma';
$_['js_address_unavailable'] = 'Vi kunde inte hämta företagets adress. Ange den nedan.';
$_['text_company_edit_address'] = 'Redigera adress';
$_['js_sole_trader_error'] = 'Något gick fel när betalning som enskild firma skulle konfigureras. Försök igen.';
$_['js_checking'] = 'Kontrollerar möjligheten att betala med %1$s...';
$_['js_settle_timeout'] = 'Vi sparar fortfarande dina företagsuppgifter. Försök igen om en liten stund.';
$_['js_confirm_failed'] = 'Vi fick inget svar om din order. Den kan fortfarande behandlas: vänta en stund och kontrollera din orderhistorik innan du försöker igen.';
$_['error_order_in_progress'] = 'Din order håller fortfarande på att läggas. Vänta en stund och kontrollera din orderhistorik innan du försöker igen.';
$_['js_totals_failed'] = 'Ditt betalningsvillkor är sparat, men ordersumman kunde inte uppdateras. Ladda om sidan innan du bekräftar.';
$_['error_too_many'] = 'För många förfrågningar. Vänta en stund och försök igen.';
$_['error_forbidden'] = 'Din kassasession har ändrats. Ladda om sidan och försök igen.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Anslutningsfel mot betalningsleverantören. Försök igen.';
$_['error_intent_unavailable'] = 'Betalningsleverantören är tillfälligt otillgänglig. Försök igen senare.';
$_['error_intent_unprocessable'] = 'Kunde inte behandla din order med %s-betalning.';
$_['error_placement_currency'] = '%1$s är inte tillgängligt för order i %2$s. Välj en annan valuta eller betalningsmetod.';
$_['error_placement_buyer_country'] = 'Denna betalningsmetod är inte tillgänglig.';
$_['error_sole_trader'] = 'Registreringen för enskild firma kunde inte startas. Försök igen.';

// Callbacks.
$_['text_returning'] = 'Återvänder till butiken';
$_['text_continue'] = 'Fortsätt';
$_['text_cancelled'] = 'Din order är avbruten.';
$_['text_pending'] = 'Vi bekräftar fortfarande din betalning. Vänta en minut innan du försöker igen, så att ordern inte läggs två gånger.';
$_['text_cart_changed'] = 'Din varukorg ändrades under betalningsverifieringen. Kontrollera din varukorg och försök igen.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'Din order kunde inte hittas. Gå tillbaka till kassan och försök igen.';
$_['error_payment_method'] = 'Välj en betalningsmetod.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'Fakturaköp med %s är inte tillgängligt för den här beställningen.';
$_['error_two_same_company'] = 'Köpare och säljare kan inte vara samma företag';
$_['error_two_company_required'] = 'För att betala med %s anger du företagsnamn, organisationsnummer och företagets land.';
$_['error_two_field_phone_number'] = 'Ange ett giltigt telefonnummer för att betala mot faktura.';
$_['error_two_field_organization_number'] = 'Ange ett giltigt organisationsnummer för att betala mot faktura.';
$_['error_two_field_company_name'] = 'Ange ett giltigt företagsnamn för att betala mot faktura.';
$_['error_two_field_first_name'] = 'Ange ett giltigt förnamn för att betala mot faktura.';
$_['error_two_field_last_name'] = 'Ange ett giltigt efternamn för att betala mot faktura.';
$_['error_two_field_email'] = 'Ange en giltig e-postadress för att betala mot faktura.';
$_['error_two_field_address'] = 'Ange en giltig adress för att betala mot faktura.';
$_['error_two_field_city'] = 'Ange en giltig ort för att betala mot faktura.';
$_['error_two_field_country'] = 'Ange ett giltigt land för att betala mot faktura.';
$_['error_two_field_postal_code'] = 'Ange ett giltigt postnummer för att betala mot faktura.';
$_['error_two_field_invoice_email'] = 'Ange en giltig fakturaadress (e-post) för att betala mot faktura.';

// Payment terms and the payment terms fee (Build Guide sections 4 and 5)
$_['text_terms_title'] = 'Välj betalningsvillkor';
$_['text_terms_description'] = 'Din betalningsperiod börjar när din order levereras.';
$_['text_terms_description_eom'] = 'Betalningen förfaller i slutet av den månad ordern levereras, plus de dagar du väljer.';
$_['text_term_days'] = '%d dagar';
$_['text_term_eom'] = 'EOM+%d';
$_['text_term_eom_name'] = 'EOM+%1$d: betala %1$d dagar efter månadens slut';
$_['text_pay_in'] = 'Betala om %d dagar';
$_['text_pay_in_eom'] = 'Betala om %d dagar från månadsskiftet';
$_['text_fee_line'] = 'Avgift för betalningsvillkor - %d dagar';
$_['text_fee_line_eom'] = 'Avgift för betalningsvillkor - %d dagar från månadens slut';
$_['text_fee_plus'] = '+%s';
$_['error_term'] = 'Det betalningsvillkoret är inte tillgängligt. Välj ett annat.';
$_['error_placement_terms'] = 'Denna betalningsmetod är inte tillgänglig.';
$_['error_placement_fee'] = '%s är inte tillgängligt för den här ordern just nu. Välj en annan betalningsmetod.';
$_['error_placement_fee_changed'] = 'Avgiften för betalningsvillkor har ändrats. Kontrollera ordersumman och bekräfta igen.';

// TWO-27051: the explainer link and the optional fields' tooltips. %s is the brand's product name.
$_['text_about'] = 'Vad är %s?';
$_['text_about_body'] = '%s är en betalningslösning för B2B-köp online, som låter dig köpa från dina favorithandlare och leverantörer på handelskredit. Genom att använda %s kan du få tillgång till flexibel handelskredit direkt för att göra inköp enkelt.';
$_['text_about_strong'] = 'Köp nu, ta emot dina varor, betala din faktura senare.';
$_['text_about_more'] = 'Klicka för att läsa mer';
$_['help_invoice_email'] = 'Vart %s ska skicka fakturan för denna order, om den skiljer sig från ditt kontos e-postadress.';
$_['help_purchase_order_number'] = 'Din interna inköpsorderreferens för denna order.';
$_['help_project'] = 'Projektet som ska kopplas till detta köp, för din egen interna referens.';
$_['help_department'] = 'Avdelningen som ska kopplas till detta köp, för din egen interna referens.';

// Product page buy button (TWO-25800). %s is the brand's product name. The
// product page message's default wording is text_about_strong, the tile's own sentence.
$_['text_product_button'] = 'Köp med %s';
$_['error_product_button_unreachable'] = 'Tyvärr, vi kunde inte lägga detta i varukorgen. Försök igen.';
