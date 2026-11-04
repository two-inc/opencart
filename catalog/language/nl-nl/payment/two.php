<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';
// The payment method title when the merchant has set none for the language.
$_['text_title_default'] = 'Zakelijke factuur';

// Tile. %s is the brand's product name.
$_['entry_company'] = 'Bedrijf';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Je koopt voor %s.';
$_['text_company_missing'] = 'Om met %s te betalen, zoek je bedrijf in het bedrijfsveld van je adres en kies het uit de resultaten.';
$_['text_company_missing_saved'] = 'Om met %s te betalen, voeg je bedrijf toe aan het adres dat je gebruikt.';
$_['button_confirm'] = 'Bestelling bevestigen';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'E-mailadres factuur';
$_['entry_purchase_order_number'] = 'PO-nummer';
$_['entry_project'] = 'Project';
$_['entry_department'] = 'Afdeling';
$_['text_optional'] = 'optioneel';
$_['error_field_too_long'] = '%1$s mag maximaal %2$d tekens bevatten.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Je factuur bij %s wordt waarschijnlijk geaccepteerd, onder voorbehoud van aanvullende controles.';
$_['text_intent_declined'] = 'Je factuur bij %s kan op dit moment niet worden goedgekeurd. Kies een andere betaalmethode.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_too_short'] = 'Voer %2$d of meer tekens in';
$_['js_no_matches'] = 'Geen overeenkomsten gevonden';
$_['js_search_unavailable'] = 'Zoeken naar bedrijven is tijdelijk niet beschikbaar. Probeer het opnieuw.';
$_['js_select_country'] = 'Selecteer hierboven je land om je bedrijf te zoeken.';
$_['js_back_to_search'] = 'Zoek naar bedrijf';
$_['js_chip_registered'] = 'Geregistreerd bedrijf';
$_['js_chip_sole_trader'] = 'Eenmanszaak';
$_['js_chip_manual'] = 'Handmatig invoeren';
$_['js_search_aria'] = 'Zoek naar bedrijf';
$_['js_results_one'] = '1 resultaat beschikbaar, gebruik de pijltjestoetsen omhoog en omlaag om te navigeren.';
$_['js_results_many'] = '%d resultaten beschikbaar, gebruik de pijltjestoetsen omhoog en omlaag om te navigeren.';
$_['js_company_number'] = 'Bedrijfsnummer';
$_['js_select_different_sole_trader'] = 'Kies een andere eenmanszaak';
$_['js_sole_trader_signup'] = 'Meld je aan of log in als eenmanszaak';
$_['js_address_unavailable'] = 'We konden het adres van dit bedrijf niet ophalen. Vul het hieronder in.';
$_['text_company_edit_address'] = 'Adres bewerken';
$_['js_sole_trader_error'] = 'Er is iets misgegaan bij het instellen van afrekenen als eenmanszaak. Probeer het opnieuw.';
$_['js_checking'] = 'Geschiktheid voor %1$s-betaling controleren...';
$_['js_settle_timeout'] = 'We zijn je bedrijfsgegevens nog aan het opslaan. Probeer het zo opnieuw.';
$_['js_confirm_failed'] = 'We hebben niets teruggehoord over je bestelling. Die wordt mogelijk nog verwerkt: wacht even en controleer je bestelgeschiedenis voordat je het opnieuw probeert.';
$_['error_order_in_progress'] = 'Je bestelling wordt nog geplaatst. Wacht even en controleer je bestelgeschiedenis voordat je het opnieuw probeert.';
$_['js_totals_failed'] = 'Je betaaltermijn is opgeslagen, maar het totaalbedrag kon niet worden bijgewerkt. Laad de pagina opnieuw voordat je bevestigt.';
$_['error_too_many'] = 'Te veel verzoeken. Wacht even en probeer het opnieuw.';
$_['error_forbidden'] = 'Je afrekensessie is gewijzigd. Laad de pagina opnieuw en probeer het nog eens.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Verbindingsfout met de betaalprovider. Probeer het opnieuw.';
$_['error_intent_unavailable'] = 'De betaalprovider is tijdelijk niet beschikbaar. Probeer het later opnieuw.';
$_['error_intent_unprocessable'] = 'Je bestelling kan niet worden verwerkt met %s-betaling.';
$_['error_placement_currency'] = '%1$s is niet beschikbaar voor bestellingen in %2$s. Kies een andere valuta of betaalmethode.';
$_['error_placement_buyer_country'] = 'Deze betaalmethode is niet beschikbaar.';
$_['error_sole_trader'] = 'De aanmelding voor eenmanszaken kon niet worden gestart. Probeer het opnieuw.';

// Callbacks.
$_['text_returning'] = 'Terug naar de winkel';
$_['text_continue'] = 'Doorgaan';
$_['text_cancelled'] = 'Je bestelling is geannuleerd.';
$_['text_pending'] = 'We zijn je betaling nog aan het bevestigen. Wacht een minuut voordat je het opnieuw probeert, zodat de bestelling niet twee keer wordt geplaatst.';
$_['text_cart_changed'] = 'Je winkelwagen is gewijzigd tijdens de betaalverificatie. Controleer je winkelwagen en probeer het opnieuw.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'Je bestelling kon niet worden gevonden. Ga terug naar het afrekenen en probeer het opnieuw.';
$_['error_payment_method'] = 'Kies een betaalmethode.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'Kopen op factuur met %s is niet beschikbaar voor deze bestelling.';
$_['error_two_same_company'] = 'Koper en verkoper mogen niet hetzelfde bedrijf zijn';
$_['error_two_company_required'] = 'Om met %s te betalen, vul je de bedrijfsnaam, het organisatienummer en het land van het bedrijf in.';
$_['error_two_field_phone_number'] = 'Vul een geldig telefoonnummer in om op factuur te betalen.';
$_['error_two_field_organization_number'] = 'Vul een geldig organisatienummer in om op factuur te betalen.';
$_['error_two_field_company_name'] = 'Vul een geldige bedrijfsnaam in om op factuur te betalen.';
$_['error_two_field_first_name'] = 'Vul een geldige voornaam in om op factuur te betalen.';
$_['error_two_field_last_name'] = 'Vul een geldige achternaam in om op factuur te betalen.';
$_['error_two_field_email'] = 'Vul een geldig e-mailadres in om op factuur te betalen.';
$_['error_two_field_address'] = 'Vul een geldig adres in om op factuur te betalen.';
$_['error_two_field_city'] = 'Vul een geldige plaats in om op factuur te betalen.';
$_['error_two_field_country'] = 'Vul een geldig land in om op factuur te betalen.';
$_['error_two_field_postal_code'] = 'Vul een geldige postcode in om op factuur te betalen.';
$_['error_two_field_invoice_email'] = 'Vul een geldig factuur-e-mailadres in om op factuur te betalen.';

// Payment terms and the payment terms fee (Build Guide sections 4 and 5)
$_['text_terms_title'] = 'Kies je betaaltermijn';
$_['text_terms_description'] = 'Je betaaltermijn start zodra je bestelling is uitgeleverd.';
$_['text_terms_description_eom'] = 'De betaling vervalt aan het einde van de maand waarin je bestelling wordt uitgeleverd, plus het aantal dagen dat je kiest.';
$_['text_term_days'] = '%d dagen';
$_['text_term_eom'] = 'EOM+%d';
$_['text_term_eom_name'] = 'EOM+%1$d: betaal %1$d dagen na het einde van de maand';
$_['text_pay_in'] = 'Betaal in %d dagen';
$_['text_pay_in_eom'] = 'Betaal in %d dagen vanaf einde van de maand';
$_['text_fee_line'] = 'Vergoeding betaaltermijn - %d dagen';
$_['text_fee_line_eom'] = 'Vergoeding betaaltermijn - %d dagen vanaf einde van de maand';
$_['text_fee_plus'] = '+%s';
$_['error_term'] = 'Die betaaltermijn is niet beschikbaar. Kies een andere.';
$_['error_placement_terms'] = 'Deze betaalmethode is niet beschikbaar.';
$_['error_placement_fee'] = '%s is op dit moment niet beschikbaar voor deze bestelling. Kies een andere betaalmethode.';
$_['error_placement_fee_changed'] = 'De vergoeding voor je betaaltermijn is gewijzigd. Controleer het totaalbedrag en bevestig opnieuw.';

// TWO-27051: the explainer link and the optional fields' tooltips. %s is the brand's product name.
$_['text_about'] = 'Wat is %s?';
$_['text_about_body'] = '%s is een betaaloplossing voor online B2B-aankopen, waarmee je bij je favoriete winkels en leveranciers op handelskrediet kunt kopen. Met %s krijg je direct toegang tot flexibel handelskrediet, zodat inkopen eenvoudig blijft.';
$_['text_about_strong'] = 'Koop nu, ontvang je goederen, betaal je factuur later.';
$_['text_about_more'] = 'Klik voor meer informatie';
$_['help_invoice_email'] = 'Waar %s de factuur voor deze bestelling naartoe moet sturen, als dit afwijkt van je account-e-mailadres.';
$_['help_purchase_order_number'] = 'Je interne inkooporderreferentie voor deze bestelling.';
$_['help_project'] = 'Het project dat aan deze aankoop moet worden gekoppeld, voor je eigen interne referentie.';
$_['help_department'] = 'De afdeling die aan deze aankoop moet worden gekoppeld, voor je eigen interne referentie.';
