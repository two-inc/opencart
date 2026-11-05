<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';
// The payment method title when the merchant has set none for the language.
$_['text_title_default'] = 'Bedriftsfaktura';

// Tile. %s is the brand's product name.
$_['entry_company'] = 'Firma';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Handler for %s.';
$_['text_company_missing'] = 'For å betale med %s, søk etter firmaet ditt i firmafeltet i adressen din og velg det fra resultatene.';
$_['text_company_missing_saved'] = 'For å betale med %s, legg til firmaet ditt i adressen du bruker.';
$_['button_confirm'] = 'Bekreft ordre';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'E-postadresse for faktura';
$_['entry_purchase_order_number'] = 'PO-nummer';
$_['entry_project'] = 'Prosjekt';
$_['entry_department'] = 'Avdeling';
$_['text_optional'] = 'valgfritt';
$_['error_field_too_long'] = '%1$s kan ha høyst %2$d tegn.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Fakturaen din hos %s blir sannsynligvis godkjent, med forbehold om ytterligere kontroller.';
$_['text_intent_declined'] = 'Fakturaen din hos %s kan ikke godkjennes nå. Velg en annen betalingsmåte.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_too_short'] = 'Skriv inn %2$d eller flere tegn';
$_['js_no_matches'] = 'Ingen treff funnet';
$_['js_search_unavailable'] = 'Firmasøket er midlertidig utilgjengelig. Prøv igjen.';
$_['js_select_country'] = 'Velg land ovenfor for å søke opp firmaet ditt.';
$_['js_back_to_search'] = 'Søk etter selskap';
$_['js_chip_registered'] = 'Registrert firma';
$_['js_chip_sole_trader'] = 'Enkeltpersonforetak';
$_['js_chip_manual'] = 'Skriv inn manuelt';
$_['js_search_aria'] = 'Søk etter firma';
$_['js_results_one'] = '1 resultat er tilgjengelig, bruk pil opp og pil ned for å navigere.';
$_['js_results_many'] = '%d resultater er tilgjengelige, bruk pil opp og pil ned for å navigere.';
$_['js_company_number'] = 'Organisasjonsnummer';
$_['js_select_different_sole_trader'] = 'Velg et annet enkeltpersonforetak';
$_['js_sole_trader_signup'] = 'Registrer deg eller logg inn som enkeltpersonforetak';
$_['js_address_unavailable'] = 'Vi kunne ikke hente adressen til dette firmaet. Skriv den inn nedenfor.';
$_['text_company_edit_address'] = 'Rediger adresse';
$_['js_sole_trader_error'] = 'Noe gikk galt under oppsettet av betaling som enkeltpersonforetak. Prøv igjen.';
$_['js_checking'] = 'Sjekker om %1$s-betaling er tilgjengelig ...';
$_['js_settle_timeout'] = 'Vi lagrer fortsatt firmaopplysningene dine. Prøv igjen om et øyeblikk.';
$_['js_confirm_failed'] = 'Vi fikk ikke svar om ordren din. Den kan fortsatt være under behandling: vent litt og sjekk ordrehistorikken før du prøver igjen.';
$_['error_order_in_progress'] = 'Ordren din blir fortsatt lagt inn. Vent litt og sjekk ordrehistorikken før du prøver igjen.';
$_['js_totals_failed'] = 'Betalingsvilkåret ditt er lagret, men ordretotalen kunne ikke oppdateres. Last inn siden på nytt før du bekrefter.';
$_['error_too_many'] = 'For mange forespørsler. Vent litt og prøv igjen.';
$_['error_forbidden'] = 'Utsjekkingsøkten din er endret. Last inn siden på nytt og prøv igjen.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Tilkoblingsfeil mot betalingsleverandøren. Prøv igjen.';
$_['error_intent_unavailable'] = 'Betalingsleverandøren er midlertidig utilgjengelig. Prøv igjen senere.';
$_['error_intent_unprocessable'] = 'Kunne ikke behandle ordren din med %s-betaling.';
$_['error_placement_currency'] = '%1$s er ikke tilgjengelig for ordrer i %2$s. Velg en annen valuta eller betalingsmåte.';
$_['error_placement_buyer_country'] = 'Denne betalingsmåten er ikke tilgjengelig.';
$_['error_sole_trader'] = 'Registreringen for enkeltpersonforetak kunne ikke startes. Prøv igjen.';

// Callbacks.
$_['text_returning'] = 'Går tilbake til butikken';
$_['text_continue'] = 'Fortsett';
$_['text_cancelled'] = 'Ordren din er kansellert.';
$_['text_pending'] = 'Vi bekrefter fortsatt betalingen din. Vent et minutt før du prøver igjen, slik at ordren ikke legges inn to ganger.';
$_['text_cart_changed'] = 'Handlekurven din ble endret under verifiseringen av betalingen. Gå gjennom handlekurven og prøv igjen.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'Ordren din ble ikke funnet. Gå tilbake til utsjekken og prøv igjen.';
$_['error_payment_method'] = 'Velg en betalingsmåte.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'Fakturakjøp med %s er ikke tilgjengelig for denne bestillingen.';
$_['error_two_same_company'] = 'Kjøper og selger kan ikke være samme selskap';
$_['error_two_company_required'] = 'For å betale med %s må du oppgi firmanavn, organisasjonsnummer og firmaets land.';
$_['error_two_field_phone_number'] = 'Skriv inn et gyldig telefonnummer for å betale med faktura.';
$_['error_two_field_organization_number'] = 'Skriv inn et gyldig organisasjonsnummer for å betale med faktura.';
$_['error_two_field_company_name'] = 'Skriv inn et gyldig selskapsnavn for å betale med faktura.';
$_['error_two_field_first_name'] = 'Skriv inn et gyldig fornavn for å betale med faktura.';
$_['error_two_field_last_name'] = 'Skriv inn et gyldig etternavn for å betale med faktura.';
$_['error_two_field_email'] = 'Skriv inn en gyldig e-postadresse for å betale med faktura.';
$_['error_two_field_address'] = 'Skriv inn en gyldig adresse for å betale med faktura.';
$_['error_two_field_city'] = 'Skriv inn et gyldig poststed for å betale med faktura.';
$_['error_two_field_country'] = 'Skriv inn et gyldig land for å betale med faktura.';
$_['error_two_field_postal_code'] = 'Skriv inn et gyldig postnummer for å betale med faktura.';
$_['error_two_field_invoice_email'] = 'Skriv inn en gyldig fakturaadresse (e-post) for å betale med faktura.';

// Payment terms and the payment terms fee (Build Guide sections 4 and 5)
$_['text_terms_title'] = 'Velg betalingsvilkår';
$_['text_terms_description'] = 'Betalingsperioden din starter når ordren er oppfylt.';
$_['text_terms_description_eom'] = 'Betalingen forfaller ved slutten av måneden ordren oppfylles i, pluss dagene du velger.';
$_['text_term_days'] = '%d dager';
$_['text_term_eom'] = 'EOM+%d';
$_['text_term_eom_name'] = 'EOM+%1$d: betal %1$d dager etter månedens slutt';
$_['text_pay_in'] = 'Betal om %d dager';
$_['text_pay_in_eom'] = 'Betal om %d dager fra slutten av måneden';
$_['text_fee_line'] = 'Gebyr for betalingsvilkår – %d dager';
$_['text_fee_line_eom'] = 'Gebyr for betalingsvilkår – %d dager fra månedens slutt';
$_['text_fee_plus'] = '+%s';
$_['error_term'] = 'Det betalingsvilkåret er ikke tilgjengelig. Velg et annet.';
$_['error_placement_terms'] = 'Denne betalingsmåten er ikke tilgjengelig.';
$_['error_placement_fee'] = '%s er ikke tilgjengelig for denne ordren akkurat nå. Velg en annen betalingsmåte.';
$_['error_placement_fee_changed'] = 'Gebyret for betalingsvilkår er endret. Kontroller ordretotalen og bekreft på nytt.';

// TWO-27051: the explainer link and the optional fields' tooltips. %s is the brand's product name.
$_['text_about'] = 'Hva er %s?';
$_['text_about_body'] = '%s er en betalingsløsning for B2B-kjøp på nettet, som lar deg kjøpe fra dine favorittforhandlere og leverandører på handelskreditt. Ved å bruke %s kan du få tilgang til fleksibel handelskreditt umiddelbart for å gjøre kjøp enkelt.';
$_['text_about_strong'] = 'Kjøp nå, motta varene dine, betal fakturaen senere.';
$_['text_about_more'] = 'Klikk for å lese mer';
$_['help_invoice_email'] = 'Hvor %s skal sende fakturaen for denne ordren, hvis den avviker fra kontoens e-postadresse.';
$_['help_purchase_order_number'] = 'Din interne bestillingsreferanse for denne ordren.';
$_['help_project'] = 'Prosjektet som skal knyttes til dette kjøpet, for din egen interne referanse.';
$_['help_department'] = 'Avdelingen som skal knyttes til dette kjøpet, for din egen interne referanse.';

// Product page buy button (TWO-25800). %s is the brand's product name. The
// product page message's default wording is text_about_strong, the tile's own sentence.
$_['text_product_button'] = 'Kjøp med %s';
$_['error_product_button_unreachable'] = 'Beklager, vi kunne ikke legge dette i handlekurven. Prøv igjen.';
