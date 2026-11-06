<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';
// The payment method title at checkout when none is set for a language: the same as the storefront file's.
$_['text_title_default'] = 'Företagsfaktura';

// Text
$_['text_extension'] = 'Tillägg';
$_['text_success'] = 'Inställningarna har sparats.';
$_['text_success_partial'] = 'De övriga inställningarna sparades.';
$_['text_edit'] = 'Redigera inställningar för Two';
$_['text_version'] = 'Version';
$_['text_environment_production'] = 'Produktion';
$_['text_environment_sandbox'] = 'Sandbox';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'En nyckel är sparad. Lämna fältet tomt för att behålla den.';
$_['text_key_verified'] = 'API-nyckeln verifierades';
$_['text_key_verifying'] = 'API-nyckeln verifieras. Ladda om sidan om en liten stund.';
$_['text_key_checking'] = 'Kontrollerar API-nyckel...';
$_['text_key_kept'] = 'Den tidigare sparade nyckeln och miljön behölls.';
$_['text_merchant_id'] = 'Handlar-ID';
$_['text_merchant_short_name'] = 'Handlarens kortnamn';
$_['text_record_fetched_at'] = 'Merchantprofil hämtad';
$_['text_status_mapping_intro'] = 'Koppla %s-betalningsstatusar till orderstatusar i OpenCart för att integrera dem i arbetsflödet.';
$_['text_default_mappings'] = 'Standardmappningar:';
$_['text_default_fulfil'] = '%s: order levererad → Skickad';
$_['text_default_cancel'] = '%s: order avbruten → Avbruten';
$_['text_default_refund'] = '%s: order återbetalad → Återbetalad';
$_['text_currently_active'] = 'Aktiva just nu:';
$_['text_none_selected'] = 'Inget valt';

// Tab
$_['tab_general'] = 'Allmänt';
$_['tab_checkout_fields'] = 'Kassafält';
$_['tab_payment_terms'] = 'Betalningsvillkor';
$_['tab_order_management'] = 'Orderhantering';
$_['tab_diagnostics'] = 'Diagnostik';

// Legend
$_['legend_status_mapping'] = 'Statusmappning';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sorteringsordning';
$_['entry_environment'] = 'Miljö';
$_['entry_api_key'] = 'API-nyckel';
$_['entry_title'] = 'Titel';
$_['entry_fulfil'] = '%s: order levererad, utlösande statusar';
$_['entry_cancel'] = '%s: Order avbruten';
$_['entry_refund'] = '%s: Order återbetalad';

// Help
$_['help_environment'] = 'Sandbox är för test och flyttar inga riktiga pengar. Använd API-nyckeln som utfärdats för den miljö du väljer.';
$_['help_api_key'] = 'Ange din API-nyckel som du fått från %s.';
$_['help_title'] = 'Ange en titel som visas i kassan som betalningsmetodens titel.';
$_['help_fulfil'] = 'Välj en eller flera orderstatusar som ska utlösa leveransrapportering till %1$s. När någon av dessa statusar sätts markeras ordern som levererad hos %1$s. Köparens betalningsvillkor aktiveras och utbetalningscykeln startar. Du kan välja flera statusar (håll ned Ctrl/Cmd för att välja flera). Standard: Skickad';
$_['help_cancel'] = 'Ordern har avbrutits hos %s. Detta förhindrar leverans och stoppar betalningsprocessen. Standard: Avbruten';
$_['help_refund'] = 'När en order flyttas till den här statusen återbetalas den i sin helhet hos %s, och köparen får en kreditnota. Standard: Återbetalad.';

// Error
$_['error_permission'] = 'Du har inte behörighet att ändra betalningsinställningarna för Two.';
$_['error_partial'] = 'Vissa inställningar sparades inte. Se de markerade fälten.';
$_['error_title_missing'] = 'Ange en titel.';
$_['error_status_missing'] = 'Välj en orderstatus.';
$_['error_status_unknown'] = 'Välj en orderstatus som finns i den här butiken.';
$_['error_status_void'] = 'OpenCart använder den här statusen för order som redigeras, så den kan inte kopplas. Välj en annan orderstatus.';
$_['error_key_missing'] = 'Ange en API-nyckel.';
$_['error_kept_malformed'] = 'Det här är inte en giltig API-nyckel: den får bara innehålla utskrivbara tecken, utan mellanslag eller radbrytningar. Den tidigare sparade nyckeln behölls.';
$_['error_kept_environment'] = 'Välj en giltig miljö.';
$_['error_environment_unhealthy'] = 'Den sparade miljön kan inte användas i den här butiken, så sandbox används. Välj en miljö och spara.';
$_['error_key_invalid'] = 'Den här API-nyckeln avvisades av %1$s. Den kan vara ogiltig eller ha upphört att gälla - kontrollera nyckeln i din %1$s-portal.';
$_['error_key_service'] = '%s kunde inte verifiera API-nyckeln just nu (HTTP %d). Det är oftast tillfälligt - försök igen om en stund.';
$_['error_key_unreachable'] = 'Den här butiken kunde inte nå %s API alls (nätverk, DNS eller brandvägg). Själva API-nyckeln har inte bedömts.';
$_['error_key_not_configured'] = 'Ange din %1$s API-nyckel för att aktivera %1$s.';
$_['error_key_unexpected'] = '%s gav ett oväntat svar vid verifieringen av API-nyckeln (HTTP %d).';
$_['error_key_unverified'] = 'API-nyckeln kunde inte verifieras.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Two-order';
$_['text_two_state'] = 'Status hos Two';
$_['text_no_two_order'] = 'Ingen Two-order är kopplad till den här ordern.';
$_['button_invoice'] = 'Ladda ner faktura';
$_['help_invoice'] = 'Fakturan som Two utfärdade när ordern levererades.';
$_['error_invoice_permission'] = 'Du har inte behörighet att se den här fakturan.';
$_['error_invoice_not_ready'] = 'Fakturan är inte klar ännu eftersom ordern fortfarande levereras. Försök igen senare.';
$_['error_invoice_state'] = 'Ingen faktura är tillgänglig eftersom ordern har tillståndet: %s.';
$_['error_invoice_no_reference'] = 'Ingen Two-order är kopplad till den här ordern.';
$_['error_invoice'] = 'Fakturan kunde inte hämtas. Försök igen senare.';

// Checkout fields: placement switch, optional fields, minimum order. %s is the brand's product name.
$_['entry_company_search_in_address'] = 'Aktivera företagssökning i adressregistrering';
$_['help_company_search_in_address'] = 'Om aktiverad kan köparen söka efter sitt företag inom adressregistreringsdelen av kassan. Annars kommer företagssökningen att visas inom betalningsmetoden.';
$_['entry_address_lookup'] = 'Fyll i företagsadress automatiskt';
$_['help_address_lookup'] = 'Fyller automatiskt i den registrerade adressen från det nationella registret.';
$_['entry_enable_invoice_email'] = 'Visa fältet E-postadress för faktura';
$_['help_enable_invoice_email'] = 'Om du väljer Ja ser kunderna ett fält för fakturamejladress i %s-avsnittet i kassan. Det ligger tillsammans med betalningsmetoden i stället för adressen, så att köparen uppmanas att överväga en särskild faktureringsadress även när faktura- och leveransadressen är samma.';
$_['entry_enable_po_number'] = 'Visa fältet PO-nummer';
$_['help_enable_po_number'] = 'Om du väljer Ja ser kunderna ett fält för PO-nummer i %s-avsnittet i kassan.';
$_['entry_enable_project'] = 'Visa fältet Projekt';
$_['help_enable_project'] = 'Om du väljer Ja ser kunderna ett projektfält i %s-avsnittet i kassan.';
$_['entry_enable_department'] = 'Visa fältet Avdelning';
$_['help_enable_department'] = 'Om du väljer Ja ser kunderna ett avdelningsfält i %s-avsnittet i kassan.';
$_['entry_min_order_amount'] = 'Minsta ordervärde, %s';
$_['help_min_order_amount'] = 'Dölj betalningsmetoden under detta ordervärde (butikens standardvaluta, på den momsbasis som väljs nedan). Lämna fältet tomt för inget minimum.';
$_['entry_min_order_basis'] = 'Momsbasis för minsta ordervärde';
$_['help_min_order_basis'] = 'Om varukorgen jämförs med minimivärdet inklusive eller exklusive moms.';
$_['text_basis_default'] = 'Som plattformens minimum (inklusive skatt när det inte finns något)';
$_['text_basis_option_gross'] = 'Inklusive moms (brutto)';
$_['text_basis_option_net'] = 'Exklusive moms (netto)';
$_['error_switch_invalid'] = 'Välj Ja eller Nej.';
$_['error_minimum_invalid'] = 'Ange ett belopp över noll med högst två decimaler, eller lämna fältet tomt.';
$_['error_basis_invalid'] = 'Välj ett skatteunderlag i listan.';

// Health row (Build Guide section 18). %1$s is the provider's full name, %2$s the shop's default currency.
$_['entry_health'] = 'Betalningsmetod i kassan';
$_['text_health_hidden'] = 'Visas inte i kassan';
$_['text_health_shown'] = 'Visas i kassan';
$_['text_withheld_disabled'] = 'tillägget är inte aktiverat. Slå på Status.';
$_['text_withheld_no_api_key'] = 'ingen API-nyckel är sparad. Kontrollera API-nyckel.';
$_['text_withheld_key_rejected'] = 'API-nyckeln avvisades. Kontrollera API-nyckel och Miljö.';
$_['text_withheld_buyer_countries_empty'] = 'inga köparländer är för närvarande aktiverade för ditt konto. Kontakta %1$s för att aktivera dem.';
$_['text_withheld_buyer_countries_malformed'] = 'köparländerna på ditt konto kunde inte läsas. Kontakta %1$s.';
$_['text_withheld_merchant_unidentified'] = 'ditt merchant-konto har ännu inte identifierats. Spara Allmänt för att verifiera API-nyckeln.';
$_['text_withheld_currency'] = 'ingen av butikens aktiverade valutor kan användas för fakturor.';
$_['text_health_below'] = 'döljs för varukorgar under %s';
$_['text_health_floor'] = '%1$s %2$s %3$s';
$_['text_basis_gross'] = 'inklusive skatt';
$_['text_basis_net'] = 'exklusive skatt';
$_['text_or'] = 'eller';
$_['text_health_countries'] = 'erbjuds endast köpare i %s';
$_['text_health_currencies'] = 'döljs för varukorgar i %s';
// Order page: refunds and the credit note
$_['button_credit_note'] = 'Ladda ner kreditnota';
$_['help_credit_note'] = 'Den senaste kreditnotan som Two utfärdade för den här ordern.';
$_['error_credit_note'] = 'Kreditnotan kunde inte hämtas. Den kan fortfarande behandlas. Försök igen senare.';
$_['text_refund'] = 'Återbetalning';
$_['text_refunded'] = 'Återbetalt hos Two';
$_['text_remaining'] = 'Kvar att återbetala';
$_['text_refund_help'] = 'Välj hur många av varje rad som ska återbetalas, ange en beloppsrad, eller båda. Statusen för full återbetalning återbetalar det som återstår.';
$_['text_refund_unavailable'] = 'Återbetalningar blir tillgängliga när Two har fakturerat ordern.';
$_['text_refund_unread'] = 'Two kunde inte tillfrågas om den här ordern. Ladda om sidan för att försöka igen.';
$_['text_refunds'] = 'Återbetalningar skickade från den här butiken';
$_['column_line'] = 'Rad';
$_['column_quantity'] = 'Beställt';
$_['column_net'] = 'Netto';
$_['column_tax'] = 'Skatt';
$_['column_rate'] = 'Skattesats';
$_['column_refund_quantity'] = 'Återbetala';
$_['column_date'] = 'Datum';
$_['column_amount'] = 'Belopp';
$_['column_state'] = 'Utfall';
$_['entry_refund_net'] = 'Beloppsrad, netto';
$_['entry_refund_tax'] = 'Beloppsrad, skatt';
$_['entry_refund_rate'] = 'Beloppsrad, skattesats';
$_['entry_refund_reason'] = 'Orsak (på kreditnotan)';
$_['button_refund'] = 'Skicka återbetalning till Two';
$_['error_refund_permission'] = 'Du har inte behörighet att återbetala den här ordern.';
$_['error_refund_quantity'] = 'Inte återbetald: ett antal är inte ett heltal.';
$_['text_refund_state_pending'] = 'Inte besvarad';
$_['text_refund_state_done'] = 'Återbetald';
$_['text_refund_state_refused'] = 'Avvisad';
$_['text_refund_state_unknown'] = 'Okänd';
$_['text_refund_state_not_applied'] = 'Inte genomförd';
$_['button_retry'] = 'Försök igen';
$_['text_refund_blocked'] = 'En återbetalning nedan har ett okänt utfall. Försök med den igen innan du skickar en ny återbetalning: den skickas igen med samma nyckel, så Two återbetalar den högst en gång.';
$_['column_left'] = 'Kvar att återbetala';
$_['text_panel_loading'] = 'Frågar Two om den här ordern...';
$_['error_panel'] = 'Fliken Two kunde inte läsas in. Öppna fliken igen för att försöka igen, eller ladda om sidan.';
$_['error_ajax'] = 'Butiken svarade inte som väntat. Kontrollera orderhistoriken och fliken Two innan du försöker igen.';
$_['button_check_status'] = 'Kontrollera status hos Two';
$_['help_check_status'] = 'Den här ordern väntar på att köparen ska återvända från Two. Fråga Two nu: en order som köparen verifierat bekräftas och läggs, och en avbruten order noteras.';

// Diagnostics: trusted proxies for the checkout's rate limits.
$_['entry_trusted_proxies'] = 'Betrodda proxyservrar';
$_['help_trusted_proxies'] = 'Adresser till dina egna reverse-proxyservrar, lastbalanserare eller CDN-utgång, som IP-adresser eller CIDR-intervall, separerade med kommatecken eller radbrytningar. Dessa IP-adresser undantas från hastighetsbegränsning.';
$_['error_proxy_invalid'] = 'Betrodda proxyservrar: "%s" är inte en giltig IP-adress eller ett giltigt CIDR-intervall.';
$_['entry_disable_rate_limit'] = 'Inaktivera hastighetsbegränsning i kassan';
$_['help_disable_rate_limit'] = 'Tar bort taket per anropare på kassans AJAX-slutpunkter. Taket är påslaget som standard. Om butiken ligger bakom en CDN, lastbalanserare eller reverse-proxy och Betrodda proxyservrar ovan är tomt, kommer varje köpare fram som samma adress och delar ett tak - köpare nekas då mitt i kassan med ett för-många-förfrågningar-meddelande. Lösningen är att fylla i Betrodda proxyservrar, vilket låter taket skilja köpare åt; slå bara på detta som en tillfällig lösning medan du tar fram listan, och av igen efteråt.';

// Payment terms tab (Build Guide sections 4 and 5)
$_['text_terms_none'] = 'Merchantprofilen erbjuder inga betalningsvillkor ännu, så Two erbjuds inte i kassan. Spara en giltig API-nyckel, eller kontrollera dina villkor i Twos handlarportal.';
$_['text_days'] = '%d dagar';
$_['text_term_type_standard'] = 'Standard';
$_['text_term_type_eom'] = 'Månadsskifte';
$_['text_default_term_auto'] = 'Automatiskt';
$_['text_differential_total'] = 'Total avgift för valt villkor';
$_['text_differential_difference'] = 'Avgiftsskillnad mot standard betalningsvillkor';
$_['text_surcharge_none'] = 'Ingen tilläggsavgift appliceras';
$_['text_surcharge_percentage'] = 'Procent';
$_['text_surcharge_fixed'] = 'Fast belopp';
$_['text_surcharge_fixed_and_percentage'] = 'Fast belopp och procent';
$_['text_rounding_none'] = 'Ingen';
$_['text_rounding_up'] = 'Uppåt';
$_['text_rounding_down'] = 'Nedåt';
$_['text_rounding_standard'] = 'Standard';
$_['text_no_rounding'] = 'Ingen avrundning';
$_['legend_surcharge'] = 'Tilläggsavgift';
$_['entry_payment_term_type'] = 'Typ av betalningsvillkor';
$_['entry_payment_terms'] = 'Tillgängliga betalningsvillkor';
$_['entry_default_payment_term'] = 'Standardbetalningsvillkor';
$_['entry_surcharge_type'] = 'Metod för tilläggsavgift';
$_['entry_surcharge_differential'] = 'Grund för tilläggsberäkning';
$_['entry_surcharge_line_description'] = 'Beskrivning av tilläggsavgift';
$_['entry_surcharge_rounding_basis'] = 'Avrundning av tillägg';
$_['entry_surcharge_rounding_step'] = 'Avrundningssteg';
$_['entry_surcharge_tax_class'] = 'Momshantering för tilläggsavgift';
$_['entry_surcharge_grid'] = 'Tilläggsavgift per villkor';
$_['column_term'] = 'Villkor';
$_['column_percentage'] = 'Procent';
$_['column_fixed'] = 'Fast belopp';
$_['column_cap'] = 'Tak';
$_['help_payment_term_type'] = 'Standardvillkor löper från leverans. Villkor från månadsskiftet löper från slutet av den månad ordern levereras, och erbjuder bara 30, 45 och 60 dagar.';
$_['help_payment_terms'] = 'Villkoren som ditt %1$s-konto erbjuder. Köpare väljer bland dem som är markerade här; en ny installation markerar 30, 60 och 90 dagar. Om inget av de markerade villkoren erbjuds visas inte %1$s i kassan.';
$_['help_default_payment_term'] = 'Villkoret som köparen börjar med. Automatiskt använder kontots standard när den erbjuds, annars 30 dagar, annars det kortaste.';
$_['help_surcharge_type'] = 'Lägg till en avgift för betalningsvillkor för det villkor köparen väljer. %s beräknar avgiften; butiken skickar bara den här konfigurationen. Ordertotalen Two-avgift för betalningsvillkor måste vara aktiverad (Tillägg > Ordertotaler).';
$_['help_surcharge_differential'] = 'Total avgift tar ut den konfigurerade tilläggsavgiften för det valda villkoret. Avgiftsskillnad tar endast ut skillnaden jämfört med standardbetalningsvillkoret.';
$_['help_surcharge_line_description'] = 'Texten köparen ser på avgiftsraden. Använd %s för villkorets längd i dagar. Lämna tomt för standardtexten.';
$_['help_surcharge_rounding_basis'] = 'Avrunda avgiften till ett jämnt steg. Välj Ingen för vanliga belopp med två decimaler.';
$_['help_surcharge_rounding_step'] = 'Steget som avgiften avrundas till. Gäller bara när en avrundningsriktning är vald.';
$_['help_surcharge_tax_class'] = 'Skatteklassen som avgiften debiteras med, som för vilken produkt som helst: butikens egna skatteregler gäller på köparens adress. Om avgiften ska vara skattefri skapar du en skatteklass med en sats på 0 procent och väljer den. Krävs när en tilläggsavgift är på.';
$_['help_surcharge_grid'] = 'Procent är den andel av %2$s-avgiften som förs vidare till köparen, från 0 till 100. Taket gäller hela avgiften: procenten och det fasta beloppet tillsammans, inte bara procenten. Lämna tomt för inget tak. Fasta belopp och tak anges i %1$s och räknas om med kurserna från %2$s för andra valutor.';
$_['error_term_type_unknown'] = 'Välj en typ av betalningsvillkor i listan.';
$_['error_terms_none'] = 'Du måste välja minst ett betalningsvillkor.';
$_['error_terms_unknown'] = 'Välj bara villkor som ditt konto erbjuder.';
$_['error_default_term_unknown'] = 'Välj ett standardbetalningsvillkor bland de valda villkoren, eller Automatiskt.';
$_['error_surcharge_type_unknown'] = 'Välj en metod för tilläggsavgift i listan.';
$_['error_surcharge_tax_class_missing'] = 'Välj momshantering för tilläggsavgift: tilläggsavgifter är aktiverade, så du måste välja en skatteklass innan du sparar.';
$_['error_surcharge_tax_class_unknown'] = 'Momshanteringen för tilläggsavgift måste vara en av butikens skatteklasser. Om avgiften ska vara skattefri skapar du en klass med en sats på 0 procent.';
$_['error_surcharge_step_unknown'] = 'Avrundningssteget måste vara ett av de erbjudna värdena.';
$_['error_surcharge_basis_unknown'] = 'Välj avrundning av tillägg i listan.';
$_['error_surcharge_cell_invalid'] = '%1$s för villkoret på %2$d dagar måste vara ett tal som inte är negativt.';
$_['error_surcharge_cap_zero'] = 'Taket för tilläggsavgift för villkoret på %2$d dagar kan inte vara 0. Om du inte vill ta någon avgift för detta villkor, sätt i stället procentsatsen och den fasta avgiften till 0 och lämna taket tomt.';
$_['text_health_fee_quote'] = 'avgiftsberäkningen för betalningsvillkor har misslyckats sedan %s (%s), så %s erbjuds inte för de varukorgar den misslyckas för';
$_['text_health_fx'] = 'Valutakurserna från %s hämtades %s';
$_['text_withheld_no_terms'] = 'inga betalningsvillkor erbjuds: ditt konto erbjuder inget av de villkor som är markerade på fliken Betalningsvillkor för den här villkorstypen.';
$_['text_withheld_fee_total_off'] = 'en tilläggsavgift är inställd, men ordertotalen Two-avgift för betalningsvillkor är inte aktiverad. Aktivera den under Tillägg > Ordertotaler, eller sätt metoden för tilläggsavgift till Ingen tilläggsavgift appliceras.';
$_['text_withheld_tax_code_map_unreadable'] = 'sparade skattekoder för rader med 0%% kan inte läsas. Markera Rensa sparade skattekoder i avsnittet Skattekoder för rader med 0%% (fliken Orderhantering) och spara.';
$_['error_surcharge_percent_range'] = '%1$s för villkoret på %2$d dagar måste vara mellan 0 och 100: det är den andel av avgiften som förs vidare till köparen.';

// TWO-27051: vendor name (General), subtitle and the tile switches (Checkout fields), the confirmed status and Validate tax subtotals (Order management), the merchant fee per term (Payment terms). %s is the brand's product name.
$_['entry_vendor_name'] = 'Leverantörsnamn (valfritt)';
$_['help_vendor_name'] = 'Om den här butiken representerar en av flera leverantörswebbplatser som delar samma %1$s-handlarkonto, ange ett namn här för att identifiera just denna webbplats/leverantör på varje order som skickas till %1$s - lämna tomt om du bara driver en webbplats.';
$_['error_vendor_invalid'] = 'Ange ett leverantörsnamn på högst 255 tecken, utan radbrytningar eller bokstäver med accenter: det skickas med varje förfrågan som en header.';
$_['entry_subtitle'] = 'Underrubrik';
$_['help_subtitle'] = 'Valfri underrubrik som visas under titeln i kassan.';
$_['entry_enable_order_intent'] = 'Aktivera förhandsgodkännandekontroll för orderavsikt';
$_['help_enable_order_intent'] = 'Om du väljer Ja frågar kassan %1$s om en förhandsvisning av ordergodkännandet innan köparen skickar betalningen, och visar resultatet i betalningsplattan. Om du väljer Nej hoppas detta förhandsanrop över - köparen går ändå igenom den faktiska godkännandekontrollen hos %1$s när betalningen skickas.';
$_['entry_show_about_link'] = 'Visa förklaringslänken "Vad är %s"';
$_['help_show_about_link'] = 'Om du väljer Ja ser köparna en info-tooltip "Vad är %1$s?" med en länk till en förklarande resurs i %1$s-betalningsplattan i kassan.';
$_['entry_display_tooltips'] = 'Visa inmatningstooltips';
$_['help_display_tooltips'] = 'Om du väljer Ja visar de valfria kassafälten ovan en kort hjälptooltip på sin etikett.';
$_['legend_tax'] = 'Skatt';
$_['legend_tax_codes'] = 'Skattekoder för rader med 0%';
$_['text_tax_codes_intro'] = 'Välj för varje skatteklass koden %1$s får på en rad utan skatt, för varje fall som din skatteinställning kan placera den i. En rad får bara en kod från dessa rader: en rad satt till (ingen) skickar ingen kod, och %1$s väljer aldrig en själv.';
$_['text_tax_codes_exempt_row'] = 'Momsregistrerad köpare, annan EU-stat';
$_['text_tax_codes_none_row'] = 'Köparens adress motsvarar ingen skattesats (ingen skatt, t.ex. export)';
$_['text_tax_codes_none'] = '(ingen)';
$_['text_tax_codes_unlisted'] = '%s (inte listad för rader utan skatt)';
$_['text_tax_codes_record_error'] = 'Merchantuppgifterna kunde inte läsas från %s, så skattekoderna kan inte visas. Använd Uppdatera merchantprofil på fliken Diagnostik. Sparade skattekoder gäller fortfarande i kassan.';
$_['text_tax_codes_list_error'] = 'Skattekoderna för %1$s kunde inte läsas från %2$s. Sparade skattekoder gäller fortfarande i kassan, och sidan frågar igen inom 5 minuter.';
$_['text_tax_codes_stale'] = 'Skattekoderna för %1$s kunde inte uppdateras från %2$s: senaste försöket (%3$s) misslyckades med HTTP %4$d. Listan som visas lästes %5$s, och sidan frågar igen inom 5 minuter.';
$_['text_tax_codes_unreadable'] = 'Sparade skattekoder kan inte läsas, så %s visas inte i kassan. Rensa dem för att åtgärda detta.';
$_['text_tax_codes_saved_valid'] = 'Sparade skattekoder gäller fortfarande i kassan, trots att %1$s inte kräver dem för ditt land (%2$s).';
$_['text_tax_codes_saved_invalid'] = 'Vissa sparade skattekoder är inte giltiga på rader utan skatt för ditt land (%1$s), så %2$s avvisar ordrar som använder dem. Rensa dem om du inte behöver dem.';
$_['entry_tax_codes_clear'] = 'Rensa sparade skattekoder';
$_['entry_buyer_vat_field'] = 'Köparens momsregistreringsnummer';
$_['help_buyer_vat_field'] = 'Kundfältet som innehåller köparens momsregistreringsnummer, läst från betalningsadressen, annars från kontot. En spansk merchants order skickar det till %s, och det avgör raden för momsregistrerad köpare.';
$_['error_form_truncated'] = 'Ingenting sparades: formuläret kortades av serverns gräns för skickade fält (max_input_vars). Höj gränsen och spara igen.';
$_['error_tax_code_unlisted'] = 'Skattekoderna sparades inte: en vald kod är inte listad för rader med 0% i ditt land.';
$_['error_vat_field_unknown'] = 'Välj ett av kundfälten i listan.';
$_['entry_enable_tax_subtotals'] = 'Validera momsdelsummor';
$_['help_enable_tax_subtotals'] = 'Om du väljer Ja skickas en tax_subtotals-uppdelning per momssats med orderavsikter, ordrar, orderändringar och återbetalningar, för validering mot orderraderna. Om du väljer Nej utesluts tax_subtotals från alla dessa.';
$_['entry_confirmed'] = '%s: verifierad, klar för leverans';
$_['help_confirmed'] = 'Betalningen är verifierad och ordern är klar för handlarens leverans. Handlaren kan nu behandla och skicka ordern. Standard: Behandlas';
$_['text_default_confirmed'] = '%s: verifierad, klar för leverans → Behandlas';
$_['error_status_conflict'] = 'Den här statusen är också kopplad till leverans, avbokning eller återbetalning av ordrar, så en order skulle hanteras i samma stund som den bekräftas. Välj en annan orderstatus.';
$_['text_fees_stale'] = 'Avgifterna kunde inte uppdateras, så de senast hämtade beloppen visas.';
$_['text_fees_stale_dated'] = 'Avgifterna kunde inte uppdateras, så beloppen som hämtades %s visas.';
$_['text_fees_unavailable'] = 'Avgifterna kunde inte läsas in eftersom pristjänsten inte kunde nås. Beloppen vid varje betalningsvillkor saknas, de är inte noll.';
$_['text_fees_rejected'] = 'Avgifterna kunde inte läsas in. Pristjänsten avvisade begäran med HTTP %1$s: %2$s. Beloppen vid varje betalningsvillkor saknas, de är inte noll.';
$_['text_fees_rejected_bare'] = 'Avgifterna kunde inte läsas in. Pristjänsten avvisade begäran med HTTP %s. Beloppen vid varje betalningsvillkor saknas, de är inte noll.';
$_['text_fees_stale_rejected'] = 'Pristjänsten avvisade begäran med HTTP %1$s: %2$s.';
$_['text_fees_stale_rejected_bare'] = 'Pristjänsten avvisade begäran med HTTP %s.';
$_['text_fees_no_api_key'] = 'Avgifter kan inte visas förrän en API-nyckel har sparats på fliken Allmänt.';
$_['text_fees_no_figure'] = 'inget belopp';

// TWO-27051 review: a map that names the confirmed status, and the subtitle's length.
$_['error_status_is_confirmed'] = 'Ordrar får den här statusen när de bekräftas, så de skulle hanteras direkt. Välj en annan orderstatus, eller ändra statusen för bekräftade ordrar.';
$_['error_subtitle_too_long'] = 'Ange en underrubrik på högst 255 tecken.';

// Diagnostics (TWO-27051): debug logging, the SSL bypass, the custom request-header table, the error log, the merchant profile and the scheduled refresh URL. %s is the brand's product name.
$_['entry_debug_mode'] = 'Aktivera felsökningsloggning';
$_['help_debug_mode'] = 'Aktivera detaljerad loggning för felsökning. Loggar varje API-begäran och varje svar i butikens fellogg, med innehåll, och med API-nyckeln och värdena för anpassade headers dolda. Aktivera endast när %s support ber om det.';
$_['entry_disable_ssl_verify'] = 'Avaktivera SSL-verifiering';
$_['help_disable_ssl_verify'] = 'VARNING: Aktivera detta endast om du sitter bakom en företagsproxy med egna SSL-certifikat. Detta avaktiverar verifieringen av SSL-certifikat och är en SÄKERHETSRISK. REKOMMENDERAS INTE i produktion.';
$_['entry_custom_headers'] = 'Anpassade begärandeheaders';
$_['column_header_name'] = 'Headernamn';
$_['column_header_value'] = 'Värde';
$_['column_header_browser'] = 'Skicka även från webbläsaren';
$_['button_header_add'] = 'Lägg till header';
$_['button_header_remove'] = 'Ta bort';
$_['help_custom_headers'] = 'Headers som din IT-administratör ber dig lägga till. Var och en skickas i alla anrop denna butik gör till %s-API:et från din server. Markera "Skicka även från webbläsaren" endast där din IT-administratör kräver headern även i anrop från köparens webbläsare: headerns värde publiceras då i köparens webbläsare och kan läsas av vem som helst.';
$_['error_header_name_invalid'] = 'Anpassade begärandeheaders: "%s" är inte ett giltigt headernamn.';
$_['error_header_name_reserved'] = 'Anpassade begärandeheaders: "%s" är reserverad och kan inte skrivas över. Välj ett annat headernamn.';
$_['error_header_name_duplicate'] = 'Anpassade begärandeheaders: "%s" används i mer än en rad. Behåll en rad per headernamn.';
$_['error_header_value_missing'] = 'Anpassade begärandeheaders: ange ett värde för headern "%s", eller ta bort raden.';
$_['error_header_value_invalid'] = 'Anpassade begärandeheaders: värdet för headern "%s" får bara innehålla skrivbara ASCII-tecken - inga radbrytningar, styrtecken eller icke-ASCII-text.';
$_['error_headers_tab'] = 'Inget på fliken Diagnostik sparades. Rätta eller ta bort raden och spara igen.';
$_['entry_error_log'] = 'Fellogg';
$_['button_error_log'] = 'Visa de senaste 100 felloggposterna';
$_['heading_error_log'] = '%s-fellogg';
$_['text_error_log_intro'] = 'De senaste %d raderna som %s skrev i butikens fellogg, äldst först. API-nyckeln och värdena för anpassade headers är dolda.';
$_['text_error_log_empty'] = 'Det finns inga rader att visa ännu.';
$_['entry_refresh_profile'] = 'Merchantprofil';
$_['button_refresh_profile'] = 'Uppdatera merchantprofil';
$_['help_refresh_profile'] = 'De betalningsvillkor du kan erbjuda, köparavgifter, lägsta ordervärde och standardvillkor läses från %s och cachas. De uppdateras enligt ett schema och varje gång API-nyckeln eller miljön sparas; använd detta för att hämta in en ändring nu.';
$_['text_profile_refreshed'] = 'Merchantprofilen har uppdaterats.';
$_['error_profile_refresh'] = 'Kunde inte uppdatera merchantprofilen - den tidigare cachade används fortfarande. Kontrollera att API-nyckeln verifieras och att %s är nåbar.';
$_['entry_cron_url'] = 'URL för schemalagd uppdatering';
$_['help_cron_url'] = 'Anropa denna var 15:e minut från serverns crontab. Varje anrop kontrollerar Two-ordrar som köparen verifierade men aldrig återvände från och lägger dem, och uppdaterar merchantprofilen när det är dags. Token kan i stället skickas som en X-Two-Cron-Token-header eller ett POST-fält, vilket håller den utanför processlistor och proxyloggar. Håll den hemlig; alla som har den kan utlösa körningen. OpenCarts egen cron (sidan Cron Jobs) kör samma kontroll varje timme och uppdateringen dagligen, men bara denna URL tar hand om en order inom 15 minuter.';
$_['text_cron_last_run'] = 'Den schemalagda uppdateringen kördes senast %s.';
$_['text_cron_never'] = 'Den schemalagda uppdateringen har inte körts ännu.';
$_['text_pending_stale'] = '%d Two-ordrar väntar på att köparen ska återvända, och den schemalagda URL:en har inte körts den senaste timmen. Schemalägg den var 15:e minut så att verifierade ordrar läggs.';

// TWO-27055: the product page message and buy button (Checkout fields).
$_['entry_product_message_enabled'] = 'Visa meddelande på produktsidor';
$_['help_product_message_enabled'] = 'Visar en kort kampanjrad på produktsidor, under knappen för att lägga i varukorgen. Av som standard. Exakt var den hamnar beror på ditt tema.';
$_['entry_product_button_enabled'] = 'Visa köpknapp på produktsidor';
$_['help_product_button_enabled'] = 'Lägger till en knapp under lägg i varukorgen som lägger varan i korgen och tar köparen till kassan med denna betalningsmetod redan vald. Av som standard. Exakt var den hamnar beror på ditt tema.';
$_['entry_product_message'] = 'Meddelande på produktsidan';
$_['help_product_message'] = 'Valfritt. Lämna tomt för att använda standardtexten. Undvik att ange ett antal dagar om du inte är säker på de betalningsvillkor du erbjuder: betalningsvillkoren löper från utleverans, och de villkor som faktiskt är tillgängliga kommer från din merchant-post.';
