<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';
// The payment method title at checkout when none is set for a language: the same as the storefront file's.
$_['text_title_default'] = 'Bedriftsfaktura';

// Text
$_['text_extension'] = 'Utvidelser';
$_['text_success'] = 'Innstillingene er lagret.';
$_['text_success_partial'] = 'De andre innstillingene ble lagret.';
$_['text_edit'] = 'Rediger innstillinger for Two';
$_['text_version'] = 'Versjon';
$_['text_environment_production'] = 'Produksjon';
$_['text_environment_sandbox'] = 'Sandbox';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'En nøkkel er lagret. La feltet stå tomt for å beholde den.';
$_['text_key_verified'] = 'API-nøkkelen ble verifisert';
$_['text_key_verifying'] = 'API-nøkkelen verifiseres. Last inn siden på nytt om et øyeblikk.';
$_['text_key_checking'] = 'Sjekker API-nøkkel...';
$_['text_key_kept'] = 'Den tidligere lagrede nøkkelen og miljøet ble beholdt.';
$_['text_merchant_id'] = 'Selger-ID';
$_['text_merchant_short_name'] = 'Kortnavn for selger';
$_['text_record_fetched_at'] = 'Merchantprofil hentet';
$_['text_status_mapping_intro'] = 'Koble %s-betalingsstatuser til ordrestatuser i OpenCart for å integrere dem i arbeidsflyten.';
$_['text_default_mappings'] = 'Standardkoblinger:';
$_['text_default_fulfil'] = '%s: ordre oppfylt → Sendt';
$_['text_default_cancel'] = '%s: ordre kansellert → Kansellert';
$_['text_default_refund'] = '%s: ordre refundert → Refundert';
$_['text_currently_active'] = 'Aktive nå:';
$_['text_none_selected'] = 'Ingen valgt';

// Tab
$_['tab_general'] = 'Generelt';
$_['tab_checkout_fields'] = 'Kassafelt';
$_['tab_payment_terms'] = 'Betalingsvilkår';
$_['tab_order_management'] = 'Ordrehåndtering';
$_['tab_diagnostics'] = 'Diagnostikk';

// Legend
$_['legend_status_mapping'] = 'Statuskobling';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sorteringsrekkefølge';
$_['entry_environment'] = 'Miljø';
$_['entry_api_key'] = 'API-nøkkel';
$_['entry_title'] = 'Tittel';
$_['entry_fulfil'] = '%s: ordre oppfylt, utløsende statuser';
$_['entry_cancel'] = '%s: Ordre kansellert';
$_['entry_refund'] = '%s: Ordre refundert';

// Help
$_['help_environment'] = 'Sandbox er for testing og flytter ingen ekte penger. Bruk API-nøkkelen som er utstedt for miljøet du velger.';
$_['help_api_key'] = 'Skriv inn API-nøkkelen du har fått fra %s.';
$_['help_title'] = 'Skriv inn en tittel som vises i utsjekken som tittel for betalingsmåten.';
$_['help_fulfil'] = 'Velg én eller flere ordrestatuser som skal utløse oppfyllelse hos %1$s. Når en av disse statusene settes, blir ordren markert som oppfylt hos %1$s. Kjøperens betalingsvilkår blir aktive og utbetalingssyklusen starter. Du kan velge flere statuser (hold Ctrl/Cmd for å velge flere). Standard: Sendt';
$_['help_cancel'] = 'Ordren er kansellert hos %s. Dette hindrer oppfyllelse og stopper betalingsprosessen. Standard: Kansellert';
$_['help_refund'] = 'Når en ordre flyttes til denne statusen, refunderes den i sin helhet hos %s, og kjøperen får en kreditnota. Standard: Refundert.';

// Error
$_['error_permission'] = 'Du har ikke tillatelse til å endre betalingsinnstillingene for Two.';
$_['error_partial'] = 'Noen innstillinger ble ikke lagret. Se de markerte feltene.';
$_['error_title_missing'] = 'Skriv inn en tittel.';
$_['error_status_missing'] = 'Velg en ordrestatus.';
$_['error_status_unknown'] = 'Velg en ordrestatus som finnes i denne butikken.';
$_['error_status_void'] = 'OpenCart bruker denne statusen for ordrer som redigeres, så den kan ikke kobles. Velg en annen ordrestatus.';
$_['error_key_missing'] = 'Skriv inn en API-nøkkel.';
$_['error_kept_malformed'] = 'Dette er ikke en gyldig API-nøkkel: den kan bare inneholde utskrivbare tegn, uten mellomrom eller linjeskift. Den tidligere lagrede nøkkelen ble beholdt.';
$_['error_kept_environment'] = 'Velg et gyldig miljø.';
$_['error_environment_unhealthy'] = 'Det lagrede miljøet kan ikke brukes i denne butikken, så sandbox brukes. Velg et miljø og lagre.';
$_['error_key_invalid'] = 'Denne API-nøkkelen ble avvist av %1$s. Den kan være ugyldig eller utløpt - kontroller nøkkelen i %1$s-portalen din.';
$_['error_key_service'] = '%s kunne ikke verifisere API-nøkkelen nå (HTTP %d). Dette er vanligvis midlertidig - prøv igjen om kort tid.';
$_['error_key_unreachable'] = 'Denne butikken fikk ikke kontakt med %s API i det hele tatt (nettverk, DNS eller brannmur). Selve API-nøkkelen er ikke vurdert.';
$_['error_key_not_configured'] = 'Skriv inn %1$s API-nøkkelen din for å aktivere %1$s.';
$_['error_key_unexpected'] = '%s ga et uventet svar under verifisering av API-nøkkelen (HTTP %d).';
$_['error_key_unverified'] = 'API-nøkkelen kunne ikke verifiseres.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Two-ordre';
$_['text_two_state'] = 'Status hos Two';
$_['text_no_two_order'] = 'Ingen Two-ordre er knyttet til denne ordren.';
$_['button_invoice'] = 'Last ned faktura';
$_['help_invoice'] = 'Fakturaen Two utstedte da ordren ble oppfylt.';
$_['error_invoice_permission'] = 'Du har ikke tillatelse til å se denne fakturaen.';
$_['error_invoice_not_ready'] = 'Fakturaen er ikke klar ennå fordi ordren fortsatt er under oppfyllelse. Prøv igjen senere.';
$_['error_invoice_state'] = 'Ingen faktura er tilgjengelig fordi ordren har tilstanden: %s.';
$_['error_invoice_no_reference'] = 'Ingen Two-ordre er knyttet til denne ordren.';
$_['error_invoice'] = 'Fakturaen kunne ikke hentes. Prøv igjen senere.';

// Checkout fields: placement switch, optional fields, minimum order. %s is the brand's product name.
$_['entry_company_search_in_address'] = 'Aktiver bedriftssøk i adresseregistrering';
$_['help_company_search_in_address'] = 'Når aktivert kan kjøperen søke etter bedriften sin i adresseregistreringsdelen av kassen. Hvis ikke, vil bedriftssøket vises i betalingsmetoden.';
$_['entry_address_lookup'] = 'Fyll ut firmaadresse automatisk';
$_['help_address_lookup'] = 'Fyller automatisk inn den registrerte adressen fra det nasjonale registeret.';
$_['entry_enable_invoice_email'] = 'Vis feltet E-postadresse for faktura';
$_['help_enable_invoice_email'] = 'Hvis du velger Ja, ser kundene et felt for faktura-e-post i %s-delen av utsjekken. Det ligger sammen med betalingsmåten framfor adressen, slik at kjøperen blir oppfordret til å vurdere en egen fakturaadresse selv når fakturaadressen og leveringsadressen er like.';
$_['entry_enable_po_number'] = 'Vis feltet PO-nummer';
$_['help_enable_po_number'] = 'Hvis du velger Ja, ser kundene et felt for PO-nummer i %s-delen av utsjekken.';
$_['entry_enable_project'] = 'Vis feltet Prosjekt';
$_['help_enable_project'] = 'Hvis du velger Ja, ser kundene et prosjektfelt i %s-delen av utsjekken.';
$_['entry_enable_department'] = 'Vis feltet Avdeling';
$_['help_enable_department'] = 'Hvis du velger Ja, ser kundene et avdelingsfelt i %s-delen av utsjekken.';
$_['entry_min_order_amount'] = 'Minste ordreverdi, %s';
$_['help_min_order_amount'] = 'Skjul betalingsmåten under denne ordreverdien (butikkens standardvaluta, på avgiftsgrunnlaget som er valgt nedenfor). La feltet stå tomt for ingen minsteverdi.';
$_['entry_min_order_basis'] = 'Avgiftsgrunnlag for minste ordreverdi';
$_['help_min_order_basis'] = 'Om handlekurven sammenlignes med minsteverdien inkludert eller eksklusiv avgift.';
$_['text_basis_default'] = 'Som plattformens minimum (inkludert skatt når det ikke finnes noe)';
$_['text_basis_option_gross'] = 'Inkludert avgift (brutto)';
$_['text_basis_option_net'] = 'Eksklusiv avgift (netto)';
$_['error_switch_invalid'] = 'Velg Ja eller Nei.';
$_['error_minimum_invalid'] = 'Skriv inn et beløp over null med høyst to desimaler, eller la feltet stå tomt.';
$_['error_basis_invalid'] = 'Velg et skattegrunnlag fra listen.';

// Health row (Build Guide section 18). %1$s is the provider's full name, %2$s the shop's default currency.
$_['entry_health'] = 'Betalingsmåte i kassen';
$_['text_health_hidden'] = 'Vises ikke i kassen';
$_['text_health_shown'] = 'Vises i kassen';
$_['text_withheld_disabled'] = 'utvidelsen er ikke aktivert. Slå på Status.';
$_['text_withheld_no_api_key'] = 'ingen API-nøkkel er lagret. Kontroller API-nøkkel.';
$_['text_withheld_key_rejected'] = 'API-nøkkelen ble avvist. Kontroller API-nøkkel og Miljø.';
$_['text_withheld_buyer_countries_empty'] = 'ingen kjøperland er aktivert for kontoen din for øyeblikket. Kontakt %1$s for å få dem aktivert.';
$_['text_withheld_buyer_countries_malformed'] = 'kjøperlandene på kontoen din kunne ikke leses. Kontakt %1$s.';
$_['text_withheld_merchant_unidentified'] = 'merchant-kontoen din er ennå ikke identifisert. Lagre Generelt for å verifisere API-nøkkelen.';
$_['text_withheld_currency'] = 'ingen av butikkens aktiverte valutaer kan brukes til fakturaer.';
$_['text_health_below'] = 'skjult for handlekorger under %s';
$_['text_health_floor'] = '%1$s %2$s %3$s';
$_['text_basis_gross'] = 'inklusiv mva.';
$_['text_basis_net'] = 'eksklusiv mva.';
$_['text_or'] = 'eller';
$_['text_health_countries'] = 'tilbys bare kjøpere i %s';
$_['text_health_currencies'] = 'skjult for handlekorger i %s';
// Order page: refunds and the credit note
$_['button_credit_note'] = 'Last ned kreditnota';
$_['help_credit_note'] = 'Den siste kreditnotaen Two utstedte for denne ordren.';
$_['error_credit_note'] = 'Kreditnotaen kunne ikke hentes. Den kan fortsatt være under behandling. Prøv igjen senere.';
$_['text_refund'] = 'Refusjon';
$_['text_refunded'] = 'Refundert hos Two';
$_['text_remaining'] = 'Gjenstår å refundere';
$_['text_refund_help'] = 'Velg hvor mange av hver linje som skal refunderes, legg inn en beløpslinje, eller begge deler. Statusen for full refusjon refunderer det som gjenstår.';
$_['text_refund_unavailable'] = 'Refusjon blir tilgjengelig når Two har fakturert ordren.';
$_['text_refund_unread'] = 'Two kunne ikke spørres om denne ordren. Last inn siden på nytt for å prøve igjen.';
$_['text_refunds'] = 'Refusjoner sendt fra denne butikken';
$_['column_line'] = 'Linje';
$_['column_quantity'] = 'Bestilt';
$_['column_net'] = 'Netto';
$_['column_tax'] = 'Skatt';
$_['column_rate'] = 'Skattesats';
$_['column_refund_quantity'] = 'Refunder';
$_['column_date'] = 'Dato';
$_['column_amount'] = 'Beløp';
$_['column_state'] = 'Utfall';
$_['entry_refund_net'] = 'Beløpslinje, netto';
$_['entry_refund_tax'] = 'Beløpslinje, skatt';
$_['entry_refund_rate'] = 'Beløpslinje, skattesats';
$_['entry_refund_reason'] = 'Årsak (på kreditnotaen)';
$_['button_refund'] = 'Send refusjon til Two';
$_['error_refund_permission'] = 'Du har ikke tillatelse til å refundere denne ordren.';
$_['error_refund_quantity'] = 'Ikke refundert: et antall er ikke et heltall.';
$_['text_refund_state_pending'] = 'Ikke besvart';
$_['text_refund_state_done'] = 'Refundert';
$_['text_refund_state_refused'] = 'Avvist';
$_['text_refund_state_unknown'] = 'Ukjent';
$_['text_refund_state_not_applied'] = 'Ikke utført';
$_['button_retry'] = 'Prøv igjen';
$_['text_refund_blocked'] = 'En refusjon nedenfor har ukjent utfall. Prøv den på nytt før du sender en ny refusjon: den sendes igjen med samme nøkkel, så Two refunderer den høyst én gang.';
$_['column_left'] = 'Gjenstår å refundere';
$_['text_panel_loading'] = 'Spør Two om denne ordren...';
$_['error_panel'] = 'Two-fanen kunne ikke lastes inn. Åpne fanen på nytt for å prøve igjen, eller last inn siden på nytt.';
$_['error_ajax'] = 'Butikken svarte ikke som forventet. Sjekk ordrehistorikken og Two-fanen før du prøver igjen.';
$_['button_check_status'] = 'Sjekk status hos Two';
$_['help_check_status'] = 'Denne ordren venter på at kjøperen skal komme tilbake fra Two. Spør Two nå: en ordre kjøperen har bekreftet, blir bekreftet og lagt inn, og en kansellert ordre blir notert.';

// Diagnostics: trusted proxies for the checkout's rate limits.
$_['entry_trusted_proxies'] = 'Klarerte proxyer';
$_['help_trusted_proxies'] = 'Adresser til dine egne reverse-proxyer, lastbalanserere eller CDN-utgang, som IP-er eller CIDR-områder, atskilt med komma eller nye linjer. Disse IP-adressene unntas fra rate limiting.';
$_['error_proxy_invalid'] = 'Klarerte proxyer: «%s» er ikke en gyldig IP-adresse eller et gyldig CIDR-område.';
$_['entry_disable_rate_limit'] = 'Deaktiver rate limiting for kassen';
$_['help_disable_rate_limit'] = 'Fjerner grensen per innringer på kassens AJAX-endepunkter. Grensen er slått på som standard. Hvis denne butikken ligger bak en CDN, lastbalanserer eller reverse proxy og Klarerte proxyer ovenfor er tom, ankommer hver kjøper som samme adresse og deler én grense - kjøpere blir da avvist midt i kassen med en for-mange-forespørsler-melding. Løsningen er å fylle inn Klarerte proxyer, som lar grensen skille kjøpere fra hverandre; slå dette på bare som en midlertidig løsning mens du samler listen, og av igjen etterpå.';

// Payment terms tab (Build Guide sections 4 and 5)
$_['text_terms_none'] = 'Merchantprofilen tilbyr ingen betalingsvilkår ennå, så Two tilbys ikke i utsjekken. Lagre en gyldig API-nøkkel, eller sjekk vilkårene dine i Two-selgerportalen.';
$_['text_days'] = '%d dager';
$_['text_term_type_standard'] = 'Standard';
$_['text_term_type_eom'] = 'Slutten av måneden';
$_['text_default_term_auto'] = 'Automatisk';
$_['text_differential_total'] = 'Total avgift for valgt vilkår';
$_['text_differential_difference'] = 'Avgiftsforskjell mot standard betalingsvilkår';
$_['text_surcharge_none'] = 'Ingen tillegg';
$_['text_surcharge_percentage'] = 'Prosent';
$_['text_surcharge_fixed'] = 'Fast beløp';
$_['text_surcharge_fixed_and_percentage'] = 'Fast beløp og prosent';
$_['text_rounding_none'] = 'Ingen';
$_['text_rounding_up'] = 'Opp';
$_['text_rounding_down'] = 'Ned';
$_['text_rounding_standard'] = 'Standard';
$_['text_no_rounding'] = 'Ingen avrunding';
$_['legend_surcharge'] = 'Tillegg';
$_['entry_payment_term_type'] = 'Type betalingsvilkår';
$_['entry_payment_terms'] = 'Tilgjengelige betalingsvilkår';
$_['entry_default_payment_term'] = 'Standard betalingsvilkår';
$_['entry_surcharge_type'] = 'Metode for tillegg';
$_['entry_surcharge_differential'] = 'Grunnlag for tilleggsberegning';
$_['entry_surcharge_line_description'] = 'Beskrivelse av tilleggsavgift';
$_['entry_surcharge_rounding_basis'] = 'Avrunding av tillegg';
$_['entry_surcharge_rounding_step'] = 'Avrundingssteg';
$_['entry_surcharge_tax_class'] = 'Avgiftsbehandling for tillegg';
$_['entry_surcharge_grid'] = 'Tillegg per vilkår';
$_['column_term'] = 'Vilkår';
$_['column_percentage'] = 'Prosent';
$_['column_fixed'] = 'Fast beløp';
$_['column_cap'] = 'Maksbeløp';
$_['help_payment_term_type'] = 'Standardvilkår løper fra oppfyllelse. Vilkår fra slutten av måneden løper fra slutten av måneden ordren oppfylles i, og tilbyr bare 30, 45 og 60 dager.';
$_['help_payment_terms'] = 'Vilkårene %1$s-kontoen din tilbyr. Kjøpere velger blant dem som er krysset av her; en ny installasjon krysser av for 30, 60 og 90 dager. Tilbys ingen av de avkryssede vilkårene, vises ikke %1$s i utsjekken.';
$_['help_default_payment_term'] = 'Vilkåret kjøperen starter med. Automatisk bruker kontoens standard når den tilbys, ellers 30 dager, ellers det korteste.';
$_['help_surcharge_type'] = 'Legg til et gebyr for betalingsvilkår for vilkåret kjøperen velger. %s beregner gebyret; butikken sender bare dette oppsettet. Ordretotalen Two-gebyr for betalingsvilkår må være aktivert (Utvidelser > Ordretotaler).';
$_['help_surcharge_differential'] = 'Total avgift belaster det konfigurerte tillegget for det valgte vilkåret. Avgiftsforskjell belaster bare differansen mot standard betalingsvilkår.';
$_['help_surcharge_line_description'] = 'Teksten kjøperen ser på gebyrlinjen. Bruk %s for vilkårets lengde i dager. La feltet stå tomt for standardteksten.';
$_['help_surcharge_rounding_basis'] = 'Rund gebyret av til et jevnt intervall. Velg Ingen for vanlige beløp med to desimaler.';
$_['help_surcharge_rounding_step'] = 'Intervallet gebyret rundes av til. Gjelder bare når en avrundingsretning er valgt.';
$_['help_surcharge_tax_class'] = 'Avgiftsklassen gebyret belastes med, som for ethvert produkt: butikkens egne skatteregler gjelder på kjøperens adresse. Hvis gebyret skal være uten skatt, oppretter du en avgiftsklasse med en sats på 0 prosent og velger den. Påkrevd når et tillegg er på.';
$_['help_surcharge_grid'] = 'Prosent er andelen av %2$s-gebyret som videreføres til kjøperen, fra 0 til 100. Maksbeløpet gjelder hele gebyret: prosenten og det faste beløpet til sammen, ikke bare prosenten. La feltet stå tomt for ingen grense. Faste beløp og maksbeløp er i %1$s og omregnes med kursene til %2$s for andre valutaer.';
$_['error_term_type_unknown'] = 'Velg en type betalingsvilkår fra listen.';
$_['error_terms_none'] = 'Du må velge minst ett betalingsvilkår.';
$_['error_terms_unknown'] = 'Velg bare vilkår kontoen din tilbyr.';
$_['error_default_term_unknown'] = 'Velg et standard betalingsvilkår blant de valgte vilkårene, eller Automatisk.';
$_['error_surcharge_type_unknown'] = 'Velg en metode for tillegg fra listen.';
$_['error_surcharge_tax_class_missing'] = 'Velg avgiftsbehandling for tillegg: tillegg er aktivert, så du må velge en avgiftsklasse før du lagrer.';
$_['error_surcharge_tax_class_unknown'] = 'Avgiftsbehandlingen for tillegg må være en av butikkens avgiftsklasser. Hvis gebyret skal være uten skatt, oppretter du en klasse med en sats på 0 prosent.';
$_['error_surcharge_step_unknown'] = 'Avrundingssteget må være en av de tilbudte verdiene.';
$_['error_surcharge_basis_unknown'] = 'Velg avrunding av tillegg fra listen.';
$_['error_surcharge_cell_invalid'] = '%1$s for vilkåret på %2$d dager må være et tall som ikke er negativt.';
$_['error_surcharge_cap_zero'] = 'Maksbeløpet for tillegget på vilkåret med %2$d dager kan ikke være 0. Hvis du ikke vil ta noe for dette vilkåret, setter du prosentsatsen og det faste gebyret til 0 i stedet og lar maksbeløpet stå tomt.';
$_['text_health_fee_quote'] = 'gebyrberegningen for betalingsvilkår har feilet siden %s (%s), så %s tilbys ikke for handlekurvene den feiler for';
$_['text_health_fx'] = 'Valutakursene fra %s ble hentet %s';
$_['text_withheld_no_terms'] = 'ingen betalingsvilkår tilbys: kontoen din tilbyr ingen av vilkårene som er krysset av på fanen Betalingsvilkår for denne vilkårstypen.';
$_['text_withheld_fee_total_off'] = 'et tillegg er satt, men ordretotalen Two-gebyr for betalingsvilkår er ikke aktivert. Aktiver den under Utvidelser > Ordretotaler, eller sett metoden for tillegg til Ingen tillegg.';
$_['text_withheld_tax_code_map_unreadable'] = 'lagrede avgiftskoder for linjer med 0%% kan ikke leses. Kryss av for Tøm lagrede avgiftskoder i delen Avgiftskoder for linjer med 0%% (fanen Ordrehåndtering) og lagre.';
$_['error_surcharge_percent_range'] = '%1$s for vilkåret på %2$d dager må være mellom 0 og 100: det er andelen av gebyret som videreføres til kjøperen.';

// TWO-27051: vendor name (General), subtitle and the tile switches (Checkout fields), the confirmed status and Validate tax subtotals (Order management), the merchant fee per term (Payment terms). %s is the brand's product name.
$_['entry_vendor_name'] = 'Leverandørnavn (valgfritt)';
$_['help_vendor_name'] = 'Hvis denne butikken representerer ett av flere leverandørnettsteder som deler samme %1$s-selgerkonto, skriv inn et navn her for å identifisere dette spesifikke nettstedet/leverandøren på hver bestilling som sendes til %1$s - la stå tomt hvis du bare driver ett nettsted.';
$_['error_vendor_invalid'] = 'Skriv inn et leverandørnavn på høyst 255 tegn, uten linjeskift eller bokstaver med aksenter: det sendes med hver forespørsel som en header.';
$_['entry_subtitle'] = 'Undertittel';
$_['help_subtitle'] = 'Valgfri undertittel som vises under tittelen i kassen.';
$_['entry_enable_order_intent'] = 'Aktiver forhåndsgodkjenningskontroll for bestillingsintensjon';
$_['help_enable_order_intent'] = 'Hvis du velger Ja, spør kassen %1$s om en forhåndsvisning av ordregodkjenningen før kjøperen sender betalingen, og viser resultatet i betalingsflisen. Hvis du velger Nei, hoppes dette forhåndskallet over - kjøperen går fortsatt gjennom den faktiske godkjenningskontrollen hos %1$s når betalingen sendes.';
$_['entry_show_about_link'] = 'Vis forklaringslenken "Hva er %s"';
$_['help_show_about_link'] = 'Hvis du velger Ja, ser kjøperne en info-verktøytips "Hva er %1$s?" med en lenke til en forklaringsressurs i %1$s-betalingsflisen ved kassen.';
$_['entry_display_tooltips'] = 'Vis inndata-verktøytips';
$_['help_display_tooltips'] = 'Hvis du velger Ja, viser de valgfrie kassefeltene ovenfor et kort hjelpe-verktøytips på etiketten.';
$_['legend_tax'] = 'Skatt';
$_['legend_tax_codes'] = 'Avgiftskoder for linjer med 0%';
$_['text_tax_codes_intro'] = 'Velg for hver avgiftsklasse koden %1$s mottar på en linje uten avgift, for hvert tilfelle avgiftsoppsettet ditt kan plassere den i. En linje får bare en kode fra disse radene: en rad satt til (ingen) sender ingen kode, og %1$s velger aldri en selv.';
$_['text_tax_codes_exempt_row'] = 'MVA-registrert kjøper, annen EU-stat';
$_['text_tax_codes_none_row'] = 'Kjøperens adresse svarer ikke til noen avgiftssats (ingen avgift, f.eks. eksport)';
$_['text_tax_codes_none'] = '(ingen)';
$_['text_tax_codes_unlisted'] = '%s (ikke oppført for linjer uten avgift)';
$_['text_tax_codes_record_error'] = 'Merchantopplysningene kunne ikke leses fra %s, så avgiftskodene kan ikke vises. Bruk Oppdater merchantprofil på fanen Diagnostikk. Lagrede avgiftskoder gjelder fortsatt i kassen.';
$_['text_tax_codes_list_error'] = 'Avgiftskodene for %1$s kunne ikke leses fra %2$s. Lagrede avgiftskoder gjelder fortsatt i kassen, og denne siden spør igjen innen 5 minutter.';
$_['text_tax_codes_stale'] = 'Avgiftskodene for %1$s kunne ikke oppdateres fra %2$s: siste forsøk (%3$s) feilet med HTTP %4$d. Listen som vises ble lest %5$s, og denne siden spør igjen innen 5 minutter.';
$_['text_tax_codes_unreadable'] = 'Lagrede avgiftskoder kan ikke leses, så %s vises ikke i kassen. Tøm dem for å rette dette.';
$_['text_tax_codes_saved_valid'] = 'Lagrede avgiftskoder gjelder fortsatt i kassen, selv om %1$s ikke krever dem for landet ditt (%2$s).';
$_['text_tax_codes_saved_invalid'] = 'Noen lagrede avgiftskoder er ikke gyldige på linjer uten avgift for landet ditt (%1$s), så %2$s avviser ordrer som bruker dem. Tøm dem med mindre du trenger dem.';
$_['entry_tax_codes_clear'] = 'Tøm lagrede avgiftskoder';
$_['entry_buyer_vat_field'] = 'Kjøperens MVA-nummer';
$_['help_buyer_vat_field'] = 'Kundefeltet som inneholder kjøperens MVA-nummer, lest fra betalingsadressen, ellers fra kontoen. Ordren til en spansk merchant sender det til %s, og det avgjør raden for MVA-registrert kjøper.';
$_['error_form_truncated'] = 'Ingenting ble lagret: skjemaet ble kuttet av serverens grense for innsendte felt (max_input_vars). Øk grensen og lagre på nytt.';
$_['error_tax_code_unlisted'] = 'Avgiftskodene ble ikke lagret: en valgt kode er ikke oppført for linjer med 0% i landet ditt.';
$_['error_vat_field_unknown'] = 'Velg et av kundefeltene i listen.';
$_['entry_enable_tax_subtotals'] = 'Valider avgiftsdelsummer';
$_['help_enable_tax_subtotals'] = 'Hvis du velger Ja, blir en tax_subtotals-oppdeling per avgiftssats sendt med ordreintensjoner, ordrer, ordreendringer og refusjoner, for validering mot ordrelinjene. Hvis du velger Nei, utelates tax_subtotals fra alle disse.';
$_['entry_confirmed'] = '%s: verifisert, klar for oppfyllelse';
$_['help_confirmed'] = 'Betalingen er verifisert og ordren er klar for oppfyllelse hos selgeren. Selgeren kan nå behandle og sende ordren. Standard: Under behandling';
$_['text_default_confirmed'] = '%s: verifisert, klar for oppfyllelse → Under behandling';
$_['error_status_conflict'] = 'Denne statusen er også koblet til oppfyllelse, kansellering eller refusjon av ordrer, så en ordre ville bli behandlet i det øyeblikket den bekreftes. Velg en annen ordrestatus.';
$_['text_fees_stale'] = 'Gebyrene kunne ikke oppdateres, så de sist hentede beløpene vises.';
$_['text_fees_stale_dated'] = 'Gebyrene kunne ikke oppdateres, så beløpene som ble hentet %s vises.';
$_['text_fees_unavailable'] = 'Gebyrene kunne ikke lastes fordi pristjenesten ikke var tilgjengelig. Beløpene ved siden av hvert betalingsvilkår mangler, de er ikke null.';
$_['text_fees_rejected'] = 'Gebyrene kunne ikke lastes. Pristjenesten avviste forespørselen med HTTP %1$s: %2$s. Beløpene ved siden av hvert betalingsvilkår mangler, de er ikke null.';
$_['text_fees_rejected_bare'] = 'Gebyrene kunne ikke lastes. Pristjenesten avviste forespørselen med HTTP %s. Beløpene ved siden av hvert betalingsvilkår mangler, de er ikke null.';
$_['text_fees_stale_rejected'] = 'Pristjenesten avviste forespørselen med HTTP %1$s: %2$s.';
$_['text_fees_stale_rejected_bare'] = 'Pristjenesten avviste forespørselen med HTTP %s.';
$_['text_fees_no_api_key'] = 'Gebyrer kan ikke vises før en API-nøkkel er lagret på fanen Generelt.';
$_['text_fees_no_figure'] = 'ikke oppgitt';

// TWO-27051 review: a map that names the confirmed status, and the subtitle's length.
$_['error_status_is_confirmed'] = 'Ordrer får denne statusen når de bekreftes, så de ville bli behandlet med en gang. Velg en annen ordrestatus, eller endre statusen for bekreftede ordrer.';
$_['error_subtitle_too_long'] = 'Skriv inn en undertittel på høyst 255 tegn.';

// Diagnostics (TWO-27051): debug logging, the SSL bypass, the custom request-header table, the error log, the merchant profile and the scheduled refresh URL. %s is the brand's product name.
$_['entry_debug_mode'] = 'Slå på feilsøkingslogging';
$_['help_debug_mode'] = 'Slå på detaljert logging for feilsøking. Logger hver API-forespørsel og hvert svar i butikkens feillogg, med innhold, og med API-nøkkelen og verdiene til egendefinerte headere skjult. Slå bare på når %s-support ber om det.';
$_['entry_disable_ssl_verify'] = 'Slå av SSL-verifisering';
$_['help_disable_ssl_verify'] = 'ADVARSEL: Slå bare på dette hvis du er bak en bedriftsproxy med egne SSL-sertifikater. Dette slår av verifisering av SSL-sertifikater og er en SIKKERHETSRISIKO. IKKE ANBEFALT i produksjon.';
$_['entry_custom_headers'] = 'Egendefinerte forespørselsheadere';
$_['column_header_name'] = 'Headernavn';
$_['column_header_value'] = 'Verdi';
$_['column_header_browser'] = 'Send også fra nettleseren';
$_['button_header_add'] = 'Legg til header';
$_['button_header_remove'] = 'Fjern';
$_['help_custom_headers'] = 'Headere IT-administratoren din ber deg legge til. Hver av dem sendes på alle kall denne butikken gjør til %s-API-et fra serveren din. Kryss av for "Send også fra nettleseren" bare der IT-administratoren din krever headeren også på kall fra kjøperens nettleser: verdien til den headeren blir publisert i kjøperens nettleser og kan leses av hvem som helst.';
$_['error_header_name_invalid'] = 'Egendefinerte forespørselsheadere: "%s" er ikke et gyldig headernavn.';
$_['error_header_name_reserved'] = 'Egendefinerte forespørselsheadere: "%s" er reservert og kan ikke overstyres. Velg et annet headernavn.';
$_['error_header_name_duplicate'] = 'Egendefinerte forespørselsheadere: "%s" brukes i mer enn én rad. Behold én rad per headernavn.';
$_['error_header_value_missing'] = 'Egendefinerte forespørselsheadere: fyll inn en verdi for headeren "%s", eller fjern raden.';
$_['error_header_value_invalid'] = 'Egendefinerte forespørselsheadere: verdien for headeren "%s" kan bare inneholde skrivbare ASCII-tegn - ingen linjeskift, kontrolltegn eller ikke-ASCII-tekst.';
$_['error_headers_tab'] = 'Ingenting på fanen Diagnostikk ble lagret. Rett eller fjern raden, og lagre på nytt.';
$_['entry_error_log'] = 'Feillogg';
$_['button_error_log'] = 'Vis de siste 100 feilloggoppføringene';
$_['heading_error_log'] = '%s-feillogg';
$_['text_error_log_intro'] = 'De siste %d linjene %s skrev i butikkens feillogg, eldste først. API-nøkkelen og verdiene til egendefinerte headere er skjult.';
$_['text_error_log_empty'] = 'Det er ingen linjer å vise ennå.';
$_['entry_refresh_profile'] = 'Merchantprofil';
$_['button_refresh_profile'] = 'Oppdater merchantprofil';
$_['help_refresh_profile'] = 'Betalingsbetingelsene du kan tilby, kjøpertillegg, minste ordreverdi og standardbetingelse leses fra %s og mellomlagres. De oppdateres etter en plan og hver gang API-nøkkelen eller miljøet lagres; bruk denne for å hente inn en endring nå.';
$_['text_profile_refreshed'] = 'Merchantprofilen er oppdatert.';
$_['error_profile_refresh'] = 'Kunne ikke oppdatere merchantprofilen - den tidligere mellomlagrede er fortsatt i bruk. Sjekk at API-nøkkelen verifiseres og at %s er tilgjengelig.';
$_['entry_cron_url'] = 'URL for planlagt oppdatering';
$_['help_cron_url'] = 'Kall denne hvert 15. minutt fra crontab på serveren din. Hvert kall sjekker Two-ordrer som kjøperen bekreftet, men aldri kom tilbake fra, og legger dem inn, og oppdaterer merchantprofilen når det er tid for det. Tokenet kan i stedet sendes som en X-Two-Cron-Token-header eller et POST-felt, som holder det utenfor prosesslister og proxy-logger. Hold det hemmelig; alle som har det, kan utløse kjøringen. OpenCarts egen cron (siden Cron Jobs) kjører den samme kontrollen hver time og oppdateringen daglig, men bare denne URL-en henter inn en ordre innen 15 minutter.';
$_['text_cron_last_run'] = 'Den planlagte oppdateringen kjørte sist %s.';
$_['text_cron_never'] = 'Den planlagte oppdateringen har ikke kjørt ennå.';
$_['text_pending_stale'] = '%d Two-ordrer venter på at kjøperen skal komme tilbake, og den planlagte URL-en har ikke kjørt den siste timen. Planlegg den hvert 15. minutt, så blir bekreftede ordrer lagt inn.';

// TWO-27055: the product page message and buy button (Checkout fields).
$_['entry_product_message_enabled'] = 'Vis melding på produktsider';
$_['help_product_message_enabled'] = 'Viser en kort kampanjelinje på produktsider, under knappen for å legge i handlekurven. Av som standard. Nøyaktig hvor den havner avhenger av temaet ditt.';
$_['entry_product_button_enabled'] = 'Vis kjøpsknapp på produktsider';
$_['help_product_button_enabled'] = 'Legger til en knapp under legg i handlekurv som legger varen i kurven og tar kjøperen til kassen med denne betalingsmåten allerede valgt. Av som standard. Nøyaktig hvor den havner avhenger av temaet ditt.';
$_['entry_product_message'] = 'Melding på produktsiden';
$_['help_product_message'] = 'Valgfritt. La feltet stå tomt for å bruke standardteksten. Unngå å oppgi et antall dager med mindre du er sikker på betalingsbetingelsene du tilbyr: betalingsfrister løper fra utlevering, og betingelsene som faktisk er tilgjengelige kommer fra merchant-oppføringen din.';
