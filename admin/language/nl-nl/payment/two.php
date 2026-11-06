<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';
// The payment method title at checkout when none is set for a language: the same as the storefront file's.
$_['text_title_default'] = 'Zakelijke factuur';

// Text
$_['text_extension'] = 'Extensies';
$_['text_success'] = 'Instellingen opgeslagen.';
$_['text_success_partial'] = 'De overige instellingen zijn opgeslagen.';
$_['text_edit'] = 'Two-instellingen bewerken';
$_['text_version'] = 'Versie';
$_['text_environment_production'] = 'Productie';
$_['text_environment_sandbox'] = 'Sandbox';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'Er is een sleutel opgeslagen. Laat dit veld leeg om die te behouden.';
$_['text_key_verified'] = 'API-sleutel succesvol geverifieerd';
$_['text_key_verifying'] = 'De API-sleutel wordt geverifieerd. Laad de pagina zo opnieuw.';
$_['text_key_checking'] = 'API-sleutel controleren...';
$_['text_key_kept'] = 'De eerder opgeslagen sleutel en omgeving zijn behouden.';
$_['text_merchant_id'] = 'Merchant-ID';
$_['text_merchant_short_name'] = 'Korte naam merchant';
$_['text_record_fetched_at'] = 'Merchantprofiel opgehaald';
$_['text_status_mapping_intro'] = 'Koppel %s-betaalstatussen aan bestelstatussen in OpenCart om ze in je werkproces te integreren.';
$_['text_default_mappings'] = 'Standaardtoewijzingen:';
$_['text_default_fulfil'] = '%s: bestelling uitgeleverd → Verzonden';
$_['text_default_cancel'] = '%s: bestelling geannuleerd → Geannuleerd';
$_['text_default_refund'] = '%s: bestelling terugbetaald → Terugbetaald';
$_['text_currently_active'] = 'Momenteel actief:';
$_['text_none_selected'] = 'Niets geselecteerd';

// Tab
$_['tab_general'] = 'Algemeen';
$_['tab_checkout_fields'] = 'Afrekenvelden';
$_['tab_payment_terms'] = 'Betaaltermijnen';
$_['tab_order_management'] = 'Orderbeheer';
$_['tab_diagnostics'] = 'Diagnostiek';

// Legend
$_['legend_status_mapping'] = 'Statustoewijzing';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sorteervolgorde';
$_['entry_environment'] = 'Omgeving';
$_['entry_api_key'] = 'API-sleutel';
$_['entry_title'] = 'Titel';
$_['entry_fulfil'] = '%s: bestelling uitgeleverd, triggerstatussen';
$_['entry_cancel'] = '%s: Bestelling geannuleerd';
$_['entry_refund'] = '%s: Bestelling terugbetaald';

// Help
$_['help_environment'] = 'Sandbox is bedoeld om te testen en verplaatst geen echt geld. Gebruik de API-sleutel die is uitgegeven voor de omgeving die je selecteert.';
$_['help_api_key'] = 'Vul de API-sleutel in die je van %s hebt gekregen.';
$_['help_title'] = 'Vul een titel in die op de afrekenpagina wordt weergegeven als titel van de betaalmethode.';
$_['help_fulfil'] = 'Selecteer een of meer bestelstatussen die uitlevering bij %1$s moeten activeren. Zodra een van deze statussen wordt ingesteld, wordt de bestelling bij %1$s als uitgeleverd gemarkeerd. De betaaltermijnen van de koper worden actief en de uitbetalingscyclus start. Je kunt meerdere statussen selecteren (houd Ctrl/Cmd ingedrukt om meerdere te selecteren). Standaard: Verzonden';
$_['help_cancel'] = 'De bestelling is bij %s geannuleerd. Dit voorkomt uitlevering en stopt het betaalproces. Standaard: Geannuleerd';
$_['help_refund'] = 'Als een bestelling naar deze status gaat, wordt die volledig terugbetaald bij %s en krijgt de koper een creditnota. Standaard: Terugbetaald.';

// Error
$_['error_permission'] = 'Je hebt geen toestemming om de betaalinstellingen van Two te wijzigen.';
$_['error_partial'] = 'Sommige instellingen zijn niet opgeslagen. Zie de gemarkeerde velden.';
$_['error_title_missing'] = 'Vul een titel in.';
$_['error_status_missing'] = 'Selecteer een bestelstatus.';
$_['error_status_unknown'] = 'Selecteer een bestelstatus die deze winkel heeft.';
$_['error_status_void'] = 'OpenCart gebruikt deze status voor bestellingen die worden bewerkt, dus deze kan niet worden gekoppeld. Selecteer een andere bestelstatus.';
$_['error_key_missing'] = 'Vul een API-sleutel in.';
$_['error_kept_malformed'] = 'Dit is geen geldige API-sleutel: deze mag alleen afdrukbare tekens bevatten, zonder spaties of regeleinden. De eerder opgeslagen sleutel is behouden.';
$_['error_kept_environment'] = 'Selecteer een geldige omgeving.';
$_['error_environment_unhealthy'] = 'De opgeslagen omgeving kan in deze winkel niet worden gebruikt, dus wordt sandbox gebruikt. Selecteer een omgeving en sla op.';
$_['error_key_invalid'] = 'Deze API-sleutel is geweigerd door %1$s. Mogelijk is de sleutel ongeldig of verlopen - controleer de sleutel in je %1$s-portaal.';
$_['error_key_service'] = '%s kon de API-sleutel op dit moment niet verifiëren (HTTP %d). Dit is meestal tijdelijk - probeer het straks opnieuw.';
$_['error_key_unreachable'] = 'Deze webshop kon de %s API helemaal niet bereiken (netwerk, DNS of firewall). De API-sleutel zelf is niet beoordeeld.';
$_['error_key_not_configured'] = 'Voer je %1$s API-sleutel in om %1$s te activeren.';
$_['error_key_unexpected'] = '%s gaf een onverwacht antwoord bij het verifiëren van de API-sleutel (HTTP %d).';
$_['error_key_unverified'] = 'De API-sleutel kon niet worden geverifieerd.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Two-bestelling';
$_['text_two_state'] = 'Status bij Two';
$_['text_no_two_order'] = 'Er is geen Two-bestelling aan deze bestelling gekoppeld.';
$_['button_invoice'] = 'Factuur downloaden';
$_['help_invoice'] = 'De factuur die Two heeft uitgegeven toen de bestelling werd uitgeleverd.';
$_['error_invoice_permission'] = 'Je hebt geen toestemming om deze factuur te bekijken.';
$_['error_invoice_not_ready'] = 'De factuur is nog niet klaar omdat de bestelling nog wordt uitgeleverd. Probeer het later opnieuw.';
$_['error_invoice_state'] = 'Er is geen factuur beschikbaar omdat de bestelling de status %s heeft.';
$_['error_invoice_no_reference'] = 'Er is geen Two-bestelling aan deze bestelling gekoppeld.';
$_['error_invoice'] = 'De factuur kon niet worden opgehaald. Probeer het later opnieuw.';

// Checkout fields: placement switch, optional fields, minimum order. %s is the brand's product name.
$_['entry_company_search_in_address'] = 'Bedrijfszoekfunctie in adresinvoer inschakelen';
$_['help_company_search_in_address'] = 'Indien ingeschakeld, kan de koper zijn bedrijf opzoeken binnen het adresinvoergedeelte van het afrekenen. Zo niet, dan is de bedrijfszoekfunctie zichtbaar binnen de betaalmethode.';
$_['entry_address_lookup'] = 'Bedrijfsadres automatisch invullen';
$_['help_address_lookup'] = 'Vult automatisch het geregistreerde adres in vanuit het nationale register.';
$_['entry_enable_invoice_email'] = 'Veld E-mailadres factuur weergeven';
$_['help_enable_invoice_email'] = 'Als je Ja kiest, zien klanten een veld voor het e-mailadres van de factuur in het %s-betaalgedeelte tijdens het afrekenen. Het staat bij de betaalmethode in plaats van bij het adres, zodat de koper wordt aangezet om een apart factuuradres te overwegen, ook als het factuur- en verzendadres hetzelfde zijn.';
$_['entry_enable_po_number'] = 'Veld PO-nummer weergeven';
$_['help_enable_po_number'] = 'Als je Ja kiest, zien klanten een veld PO-nummer in het %s-betaalgedeelte tijdens het afrekenen.';
$_['entry_enable_project'] = 'Veld Project weergeven';
$_['help_enable_project'] = 'Als je Ja kiest, zien klanten een veld Project in het %s-betaalgedeelte tijdens het afrekenen.';
$_['entry_enable_department'] = 'Veld Afdeling weergeven';
$_['help_enable_department'] = 'Als je Ja kiest, zien klanten een veld Afdeling in het %s-betaalgedeelte tijdens het afrekenen.';
$_['entry_min_order_amount'] = 'Minimale orderwaarde, %s';
$_['help_min_order_amount'] = 'Verberg de betaalmethode onder deze orderwaarde (standaardvaluta van de winkel, op de hieronder gekozen belastinggrondslag). Laat leeg voor geen minimum.';
$_['entry_min_order_basis'] = 'Belastinggrondslag minimale orderwaarde';
$_['help_min_order_basis'] = 'Bepaalt of de winkelwagen inclusief of exclusief btw met het minimum wordt vergeleken.';
$_['text_basis_default'] = 'Zoals het platformminimum (inclusief belasting als er geen is)';
$_['text_basis_option_gross'] = 'Inclusief btw (bruto)';
$_['text_basis_option_net'] = 'Exclusief btw (netto)';
$_['error_switch_invalid'] = 'Kies Ja of Nee.';
$_['error_minimum_invalid'] = 'Vul een bedrag boven nul in met maximaal twee decimalen, of laat het veld leeg.';
$_['error_basis_invalid'] = 'Kies een belastinggrondslag uit de lijst.';

// Health row (Build Guide section 18). %1$s is the provider's full name, %2$s the shop's default currency.
$_['entry_health'] = 'Betaalmethode in de checkout';
$_['text_health_hidden'] = 'Niet zichtbaar in de checkout';
$_['text_health_shown'] = 'Zichtbaar in de checkout';
$_['text_withheld_disabled'] = 'de extensie is niet ingeschakeld. Zet Status aan.';
$_['text_withheld_no_api_key'] = 'er is geen API-sleutel opgeslagen. Controleer API-sleutel.';
$_['text_withheld_key_rejected'] = 'de API-sleutel is geweigerd. Controleer API-sleutel en Omgeving.';
$_['text_withheld_buyer_countries_empty'] = 'er zijn momenteel geen landen van de koper geactiveerd voor je account. Neem contact op met %1$s om ze te laten activeren.';
$_['text_withheld_buyer_countries_malformed'] = 'de landen van de koper op je account konden niet worden gelezen. Neem contact op met %1$s.';
$_['text_withheld_merchant_unidentified'] = 'je merchant-account is nog niet geïdentificeerd. Sla Algemeen op om de API-sleutel te verifiëren.';
$_['text_withheld_currency'] = 'geen van de ingeschakelde valuta van de winkel kan voor facturen worden gebruikt.';
$_['text_health_below'] = 'verborgen voor winkelwagens onder %s';
$_['text_health_floor'] = '%1$s %2$s %3$s';
$_['text_basis_gross'] = 'inclusief btw';
$_['text_basis_net'] = 'exclusief btw';
$_['text_or'] = 'of';
$_['text_health_countries'] = 'alleen aangeboden aan kopers in %s';
$_['text_health_currencies'] = 'verborgen voor winkelwagens in %s';
// Order page: refunds and the credit note
$_['button_credit_note'] = 'Creditnota downloaden';
$_['help_credit_note'] = 'De laatste creditnota die Two voor deze bestelling heeft uitgegeven.';
$_['error_credit_note'] = 'De creditnota kon niet worden opgehaald. Mogelijk wordt deze nog verwerkt. Probeer het later opnieuw.';
$_['text_refund'] = 'Terugbetaling';
$_['text_refunded'] = 'Terugbetaald bij Two';
$_['text_remaining'] = 'Nog terug te betalen';
$_['text_refund_help'] = 'Kies hoeveel van elke regel je terugbetaalt, vul een bedragregel in, of allebei. De status voor volledige terugbetaling betaalt terug wat er nog over is.';
$_['text_refund_unavailable'] = 'Terugbetalingen zijn mogelijk zodra Two de bestelling heeft gefactureerd.';
$_['text_refund_unread'] = 'Two kon niet worden geraadpleegd over deze bestelling. Laad de pagina opnieuw om het nog eens te proberen.';
$_['text_refunds'] = 'Terugbetalingen verzonden vanuit deze winkel';
$_['column_line'] = 'Regel';
$_['column_quantity'] = 'Besteld';
$_['column_net'] = 'Netto';
$_['column_tax'] = 'Belasting';
$_['column_rate'] = 'Belastingtarief';
$_['column_refund_quantity'] = 'Terugbetalen';
$_['column_date'] = 'Datum';
$_['column_amount'] = 'Bedrag';
$_['column_state'] = 'Resultaat';
$_['entry_refund_net'] = 'Bedragregel, netto';
$_['entry_refund_tax'] = 'Bedragregel, belasting';
$_['entry_refund_rate'] = 'Bedragregel, belastingtarief';
$_['entry_refund_reason'] = 'Reden (op de creditnota)';
$_['button_refund'] = 'Terugbetaling naar Two sturen';
$_['error_refund_permission'] = 'Je hebt geen toestemming om deze bestelling terug te betalen.';
$_['error_refund_quantity'] = 'Niet terugbetaald: een aantal is geen geheel getal.';
$_['text_refund_state_pending'] = 'Niet beantwoord';
$_['text_refund_state_done'] = 'Terugbetaald';
$_['text_refund_state_refused'] = 'Geweigerd';
$_['text_refund_state_unknown'] = 'Onbekend';
$_['text_refund_state_not_applied'] = 'Niet toegepast';
$_['button_retry'] = 'Opnieuw proberen';
$_['text_refund_blocked'] = 'Een terugbetaling hieronder heeft een onbekend resultaat. Probeer die opnieuw voordat je een andere terugbetaling verstuurt: die wordt opnieuw verzonden met dezelfde sleutel, zodat Two hem hooguit één keer terugbetaalt.';
$_['column_left'] = 'Nog terug te betalen';
$_['text_panel_loading'] = 'Two wordt gevraagd naar deze bestelling...';
$_['error_panel'] = 'Het tabblad Two kon niet worden geladen. Open het tabblad opnieuw om het nog eens te proberen, of laad de pagina opnieuw.';
$_['error_ajax'] = 'De winkel antwoordde niet zoals verwacht. Controleer de bestelgeschiedenis en het tabblad Two voordat je het opnieuw probeert.';
$_['button_check_status'] = 'Status controleren bij Two';
$_['help_check_status'] = 'Deze bestelling wacht tot de koper terugkeert van Two. Vraag het Two nu: een bestelling die de koper heeft geverifieerd, wordt bevestigd en geplaatst, en een geannuleerde wordt genoteerd.';

// Diagnostics: trusted proxies for the checkout's rate limits.
$_['entry_trusted_proxies'] = 'Vertrouwde proxy\'s';
$_['help_trusted_proxies'] = 'Adressen van uw eigen reverse proxy\'s, load balancers of CDN-uitgang, als IP\'s of CIDR-bereiken, gescheiden door komma\'s of nieuwe regels. Deze IP-adressen worden vrijgesteld van rate limiting.';
$_['error_proxy_invalid'] = 'Vertrouwde proxy\'s: "%s" is geen geldig IP-adres of CIDR-bereik.';
$_['entry_disable_rate_limit'] = 'Rate limiting bij checkout uitschakelen';
$_['help_disable_rate_limit'] = 'Verwijdert de limiet per beller op de AJAX-eindpunten van de checkout. De limiet staat standaard aan. Als deze winkel achter een CDN, load balancer of reverse proxy staat en Vertrouwde proxy\'s hierboven leeg is, arriveert elke koper als datzelfde ene adres en delen ze één limiet - kopers worden dan halverwege het afrekenen geweigerd met een te-veel-verzoeken-melding. De oplossing is Vertrouwde proxy\'s in te vullen, zodat de limiet kopers uit elkaar kan houden; schakel dit alleen tijdelijk in terwijl u die lijst verzamelt, en weer uit daarna.';

// Payment terms tab (Build Guide sections 4 and 5)
$_['text_terms_none'] = 'Het merchantprofiel biedt nog geen betaaltermijn aan, dus Two wordt niet aangeboden bij het afrekenen. Sla een geldige API-sleutel op, of controleer je termijnen in het merchantportaal van Two.';
$_['text_days'] = '%d dagen';
$_['text_term_type_standard'] = 'Standaard';
$_['text_term_type_eom'] = 'Einde van de maand';
$_['text_default_term_auto'] = 'Automatisch';
$_['text_differential_total'] = 'Totale vergoeding voor geselecteerde termijn';
$_['text_differential_difference'] = 'Verschil ten opzichte van standaard betaaltermijn';
$_['text_surcharge_none'] = 'Geen toeslag';
$_['text_surcharge_percentage'] = 'Percentage';
$_['text_surcharge_fixed'] = 'Vast bedrag';
$_['text_surcharge_fixed_and_percentage'] = 'Vast bedrag en percentage';
$_['text_rounding_none'] = 'Geen';
$_['text_rounding_up'] = 'Naar boven';
$_['text_rounding_down'] = 'Naar beneden';
$_['text_rounding_standard'] = 'Standaard';
$_['text_no_rounding'] = 'Geen afronding';
$_['legend_surcharge'] = 'Toeslag';
$_['entry_payment_term_type'] = 'Type betaaltermijn';
$_['entry_payment_terms'] = 'Beschikbare betaaltermijnen';
$_['entry_default_payment_term'] = 'Standaard betaaltermijn';
$_['entry_surcharge_type'] = 'Toeslagmethode';
$_['entry_surcharge_differential'] = 'Basis voor toeslagberekening';
$_['entry_surcharge_line_description'] = 'Omschrijving toeslagregel';
$_['entry_surcharge_rounding_basis'] = 'Afronden van toeslag';
$_['entry_surcharge_rounding_step'] = 'Afronding';
$_['entry_surcharge_tax_class'] = 'Btw-behandeling toeslag';
$_['entry_surcharge_grid'] = 'Toeslag per termijn';
$_['column_term'] = 'Termijn';
$_['column_percentage'] = 'Percentage';
$_['column_fixed'] = 'Vast bedrag';
$_['column_cap'] = 'Maximum';
$_['help_payment_term_type'] = 'Standaardtermijnen lopen vanaf de uitlevering. Termijnen vanaf het einde van de maand lopen vanaf het einde van de maand waarin de bestelling wordt uitgeleverd, en bieden alleen 30, 45 en 60 dagen.';
$_['help_payment_terms'] = 'De termijnen die je %1$s-account biedt. Kopers kiezen uit de termijnen die hier zijn aangevinkt; een nieuwe installatie vinkt 30, 60 en 90 dagen aan. Als geen van de aangevinkte termijnen wordt aangeboden, wordt %1$s niet getoond bij het afrekenen.';
$_['help_default_payment_term'] = 'De termijn waarmee de koper begint. Automatisch gebruikt de standaardtermijn van je account als die wordt aangeboden, anders 30 dagen, anders de kortste.';
$_['help_surcharge_type'] = 'Voeg een toeslag toe voor de betaaltermijn die de koper kiest. %s berekent de toeslag; de winkel stuurt alleen deze configuratie. Het besteltotaal Two-toeslag betaaltermijnen moet zijn ingeschakeld (Extensies > Besteltotalen).';
$_['help_surcharge_differential'] = 'Totale vergoeding brengt de ingestelde toeslag voor de gekozen termijn in rekening. Verschil in vergoeding brengt alleen het verschil ten opzichte van de standaard betaaltermijn in rekening.';
$_['help_surcharge_line_description'] = 'Label van de toeslagregel die de koper ziet. Gebruik %s voor de lengte van de termijn in dagen. Laat leeg voor de standaardtekst.';
$_['help_surcharge_rounding_basis'] = 'Rond de toeslag af op een net interval. Kies Geen voor standaardbedragen met twee decimalen.';
$_['help_surcharge_rounding_step'] = 'Het interval waarop de toeslag wordt afgerond. Geldt alleen als een afrondingsrichting is gekozen.';
$_['help_surcharge_tax_class'] = 'De belastingklasse waartegen de toeslag wordt berekend, zoals voor elk product: de eigen belastingregels van de winkel gelden op het adres van de koper. Wil je de toeslag onbelast laten, maak dan een belastingklasse met een tarief van 0 procent en kies die. Verplicht zolang er een toeslag aan staat.';
$_['help_surcharge_grid'] = 'Percentage is het deel van de %2$s-vergoeding dat aan de koper wordt doorberekend, van 0 tot 100. Het maximum geldt voor de hele vergoeding: het percentage en het vaste bedrag samen, niet alleen het percentage. Laat leeg voor geen maximum. Vaste bedragen en maxima zijn in %1$s en worden voor andere valuta omgerekend tegen de koersen van %2$s.';
$_['error_term_type_unknown'] = 'Kies een type betaaltermijn uit de lijst.';
$_['error_terms_none'] = 'Je moet minimaal één betaaltermijn selecteren.';
$_['error_terms_unknown'] = 'Selecteer alleen termijnen die je account aanbiedt.';
$_['error_default_term_unknown'] = 'Kies een standaard betaaltermijn uit de geselecteerde termijnen, of Automatisch.';
$_['error_surcharge_type_unknown'] = 'Kies een toeslagmethode uit de lijst.';
$_['error_surcharge_tax_class_missing'] = 'Selecteer een btw-behandeling voor de toeslag: toeslagen zijn ingeschakeld, dus je moet een belastingklasse kiezen voordat je opslaat.';
$_['error_surcharge_tax_class_unknown'] = 'De btw-behandeling van de toeslag moet een van de belastingklassen van de winkel zijn. Wil je de toeslag onbelast laten, maak dan een klasse met een tarief van 0 procent.';
$_['error_surcharge_step_unknown'] = 'De afrondingsstap moet een van de aangeboden waarden zijn.';
$_['error_surcharge_basis_unknown'] = 'Kies een afronding van de toeslag uit de lijst.';
$_['error_surcharge_cell_invalid'] = '%1$s voor de termijn van %2$d dagen moet een niet-negatief getal zijn.';
$_['error_surcharge_cap_zero'] = 'Het toeslagmaximum voor de termijn van %2$d dagen mag niet 0 zijn. Wil je op deze termijn niets in rekening brengen, stel dan het percentage en het vaste bedrag in op 0 en laat het maximum leeg.';
$_['text_health_fee_quote'] = 'de berekening van de toeslag voor betaaltermijnen mislukt sinds %s (%s), dus %s wordt niet aangeboden voor de winkelwagens waarvoor die mislukt';
$_['text_health_fx'] = 'Wisselkoersen van %s opgehaald %s';
$_['text_withheld_no_terms'] = 'er wordt geen betaaltermijn aangeboden: je account biedt geen van de termijnen die op het tabblad Betaaltermijnen zijn aangevinkt voor dit type termijn.';
$_['text_withheld_fee_total_off'] = 'er is een toeslag ingesteld, maar het besteltotaal Two-toeslag betaaltermijnen is niet ingeschakeld. Schakel het in onder Extensies > Besteltotalen, of zet de toeslagmethode op Geen toeslag.';
$_['text_withheld_tax_code_map_unreadable'] = 'opgeslagen belastingcodes voor regels met 0%% kunnen niet worden gelezen. Vink Opgeslagen belastingcodes wissen aan in de sectie Belastingcodes voor regels met 0%% (tabblad Orderbeheer) en sla op.';
$_['error_surcharge_percent_range'] = '%1$s voor de termijn van %2$d dagen moet tussen 0 en 100 liggen: het is het deel van de toeslag dat aan de koper wordt doorberekend.';

// TWO-27051: vendor name (General), subtitle and the tile switches (Checkout fields), the confirmed status and Validate tax subtotals (Order management), the merchant fee per term (Payment terms). %s is the brand's product name.
$_['entry_vendor_name'] = 'Leveranciersnaam (optioneel)';
$_['help_vendor_name'] = 'Als deze winkel een van meerdere leverancierssites is die dezelfde %1$s-handelaarsaccount delen, voer hier een naam in om deze specifieke site/leverancier te identificeren op elke bestelling die naar %1$s wordt gestuurd - laat leeg als je maar één site gebruikt.';
$_['error_vendor_invalid'] = 'Voer een leveranciersnaam in van maximaal 255 tekens, zonder regeleinden of letters met accenten: deze wordt bij elk verzoek als header meegestuurd.';
$_['entry_subtitle'] = 'Subtitel';
$_['help_subtitle'] = 'Optionele ondertitel die bij het afrekenen onder de titel wordt weergegeven.';
$_['entry_enable_order_intent'] = 'Order-intent voorgoedkeuringscontrole inschakelen';
$_['help_enable_order_intent'] = 'Als je Ja kiest, vraagt het afrekenen %1$s om een voorbeeld van de ordergoedkeuring voordat de koper de betaling verstuurt, en toont het resultaat in de betaaltegel. Als je Nee kiest, wordt deze voorbeeldaanroep overgeslagen - de koper doorloopt nog steeds de echte goedkeuringscontrole van %1$s bij het versturen van de betaling.';
$_['entry_show_about_link'] = 'Toon uitlegkoppeling "Wat is %s"';
$_['help_show_about_link'] = 'Als je Ja kiest, zien kopers een info-tooltip "Wat is %1$s?" met een koppeling naar een uitlegbron in de %1$s-betaaltegel bij het afrekenen.';
$_['entry_display_tooltips'] = 'Invoertooltips weergeven';
$_['help_display_tooltips'] = 'Als je Ja kiest, tonen de optionele afrekenvelden hierboven een korte helptooltip bij hun label.';
$_['legend_tax'] = 'Belasting';
$_['legend_tax_codes'] = 'Belastingcodes voor regels met 0%';
$_['text_tax_codes_intro'] = 'Kies per belastingklasse de code die %1$s ontvangt op een regel zonder belasting, voor elk geval waarin uw belastinginstellingen die regel kunnen plaatsen. Een regel krijgt alleen een code uit deze rijen: een rij op (geen) stuurt geen code, en %1$s kiest er nooit zelf een.';
$_['text_tax_codes_exempt_row'] = 'Btw-geregistreerde koper, andere EU-lidstaat';
$_['text_tax_codes_none_row'] = 'Het adres van de koper valt onder geen belastingtarief (geen belasting, bijv. export)';
$_['text_tax_codes_none'] = '(geen)';
$_['text_tax_codes_unlisted'] = '%s (niet vermeld voor regels zonder belasting)';
$_['text_tax_codes_record_error'] = 'De merchantgegevens konden niet worden gelezen bij %s, dus de belastingcodes kunnen niet worden getoond. Gebruik Merchantprofiel vernieuwen op het tabblad Diagnostiek. Opgeslagen belastingcodes blijven gelden bij het afrekenen.';
$_['text_tax_codes_list_error'] = 'De belastingcodes voor %1$s konden niet worden gelezen bij %2$s. Opgeslagen belastingcodes blijven gelden bij het afrekenen, en deze pagina vraagt ze binnen 5 minuten opnieuw op.';
$_['text_tax_codes_stale'] = 'De belastingcodes voor %1$s konden niet worden vernieuwd bij %2$s: de laatste poging (%3$s) mislukte met HTTP %4$d. De getoonde lijst is gelezen op %5$s, en deze pagina vraagt ze binnen 5 minuten opnieuw op.';
$_['text_tax_codes_unreadable'] = 'Opgeslagen belastingcodes kunnen niet worden gelezen, dus %s wordt niet getoond bij het afrekenen. Wis ze om dit op te lossen.';
$_['text_tax_codes_saved_valid'] = 'Opgeslagen belastingcodes blijven gelden bij het afrekenen, hoewel %1$s ze niet vereist voor uw land (%2$s).';
$_['text_tax_codes_saved_invalid'] = 'Sommige opgeslagen belastingcodes zijn niet geldig op regels zonder belasting voor uw land (%1$s), dus %2$s weigert bestellingen die ze gebruiken. Wis ze, tenzij u ze nodig hebt.';
$_['entry_tax_codes_clear'] = 'Opgeslagen belastingcodes wissen';
$_['entry_buyer_vat_field'] = 'Btw-nummer van de koper';
$_['help_buyer_vat_field'] = 'Het klantveld met het btw-nummer van de koper, gelezen uit het betaaladres en anders uit het account. De bestelling van een Spaanse merchant stuurt het naar %s, en het bepaalt de rij voor een btw-geregistreerde koper.';
$_['error_form_truncated'] = 'Er is niets opgeslagen: het formulier is afgekapt door de serverlimiet op verzonden velden (max_input_vars). Verhoog de limiet en sla opnieuw op.';
$_['error_tax_code_unlisted'] = 'De belastingcodes zijn niet opgeslagen: een gekozen code is niet vermeld voor regels met 0% in uw land.';
$_['error_vat_field_unknown'] = 'Kies een van de vermelde klantvelden.';
$_['entry_enable_tax_subtotals'] = 'Btw-subtotalen valideren';
$_['help_enable_tax_subtotals'] = 'Als je Ja kiest, wordt een tax_subtotals-uitsplitsing per btw-tarief meegestuurd met orderintenties, orders, orderwijzigingen en terugbetalingen, ter validatie tegen de orderregels. Als je Nee kiest, worden tax_subtotals bij al deze verzoeken weggelaten.';
$_['entry_confirmed'] = '%s: geverifieerd, klaar voor uitlevering';
$_['help_confirmed'] = 'De betaling is geverifieerd en de bestelling is klaar om door de merchant te worden uitgeleverd. De merchant kan de bestelling nu verwerken en verzenden. Standaard: In behandeling';
$_['text_default_confirmed'] = '%s: geverifieerd, klaar voor uitlevering → In behandeling';
$_['error_status_conflict'] = 'Deze status is ook gekoppeld aan het uitleveren, annuleren of terugbetalen van bestellingen, dus een bestelling zou worden verwerkt zodra deze is bevestigd. Kies een andere bestelstatus.';
$_['text_fees_stale'] = 'De kosten konden niet worden vernieuwd, dus de laatst opgehaalde bedragen worden weergegeven.';
$_['text_fees_stale_dated'] = 'De kosten konden niet worden vernieuwd, dus de bedragen die op %s zijn opgehaald worden weergegeven.';
$_['text_fees_unavailable'] = 'De kosten konden niet worden geladen omdat de prijsservice niet bereikbaar was. De bedragen naast elke termijn ontbreken, ze zijn niet nul.';
$_['text_fees_rejected'] = 'De kosten konden niet worden geladen. De prijsservice heeft het verzoek geweigerd met HTTP %1$s: %2$s. De bedragen naast elke termijn ontbreken, ze zijn niet nul.';
$_['text_fees_rejected_bare'] = 'De kosten konden niet worden geladen. De prijsservice heeft het verzoek geweigerd met HTTP %s. De bedragen naast elke termijn ontbreken, ze zijn niet nul.';
$_['text_fees_stale_rejected'] = 'De prijsservice heeft het verzoek geweigerd met HTTP %1$s: %2$s.';
$_['text_fees_stale_rejected_bare'] = 'De prijsservice heeft het verzoek geweigerd met HTTP %s.';
$_['text_fees_no_api_key'] = 'Kosten kunnen niet worden weergegeven totdat er een API-sleutel is opgeslagen op het tabblad Algemeen.';
$_['text_fees_no_figure'] = 'geen bedrag';

// TWO-27051 review: a map that names the confirmed status, and the subtitle's length.
$_['error_status_is_confirmed'] = 'Bestellingen krijgen deze status wanneer ze worden bevestigd, dus ze zouden meteen worden verwerkt. Kies een andere bestelstatus, of wijzig de status voor bevestigde bestellingen.';
$_['error_subtitle_too_long'] = 'Voer een subtitel in van maximaal 255 tekens.';

// Diagnostics (TWO-27051): debug logging, the SSL bypass, the custom request-header table, the error log, the merchant profile and the scheduled refresh URL. %s is the brand's product name.
$_['entry_debug_mode'] = 'Foutopsporingslogboek inschakelen';
$_['help_debug_mode'] = 'Schakel gedetailleerde logboekregistratie in voor probleemoplossing. Elk API-verzoek en -antwoord wordt in het foutenlogboek van de winkel vastgelegd, inclusief de inhoud, met de API-sleutel en de waarden van aangepaste headers onleesbaar gemaakt. Schakel dit alleen in op verzoek van de support van %s.';
$_['entry_disable_ssl_verify'] = 'SSL-verificatie uitschakelen';
$_['help_disable_ssl_verify'] = 'WAARSCHUWING: schakel dit alleen in als je achter een bedrijfsproxy met eigen SSL-certificaten zit. Hiermee wordt de verificatie van SSL-certificaten uitgeschakeld en dat is een VEILIGHEIDSRISICO. NIET AANBEVOLEN voor productie.';
$_['entry_custom_headers'] = 'Aangepaste request-headers';
$_['column_header_name'] = 'Headernaam';
$_['column_header_value'] = 'Waarde';
$_['column_header_browser'] = 'Ook vanuit de browser verzenden';
$_['button_header_add'] = 'Header toevoegen';
$_['button_header_remove'] = 'Verwijderen';
$_['help_custom_headers'] = 'Headers die uw IT-beheerder u vraagt toe te voegen. Elke header wordt verzonden bij alle aanroepen die deze winkel vanaf uw server naar de %s API doet. Vink "Ook vanuit de browser verzenden" alleen aan waar uw IT-beheerder de header ook vereist bij aanroepen vanuit de browser van de koper: de waarde van die header wordt dan in de browser van de koper gepubliceerd en kan door iedereen worden gelezen.';
$_['error_header_name_invalid'] = 'Aangepaste request-headers: "%s" is geen geldige headernaam.';
$_['error_header_name_reserved'] = 'Aangepaste request-headers: "%s" is gereserveerd en kan niet worden overschreven. Kies een andere headernaam.';
$_['error_header_name_duplicate'] = 'Aangepaste request-headers: "%s" staat in meer dan één rij. Houd één rij per headernaam.';
$_['error_header_value_missing'] = 'Aangepaste request-headers: vul een waarde in voor header "%s", of verwijder de rij.';
$_['error_header_value_invalid'] = 'Aangepaste request-headers: de waarde voor header "%s" mag alleen afdrukbare ASCII-tekens bevatten - geen regeleinden, stuurtekens of niet-ASCII-tekst.';
$_['error_headers_tab'] = 'Er is niets op het tabblad Diagnostiek opgeslagen. Corrigeer of verwijder de rij en sla opnieuw op.';
$_['entry_error_log'] = 'Foutenlogboek';
$_['button_error_log'] = 'Bekijk de laatste 100 foutenlogboekregels';
$_['heading_error_log'] = '%s-foutenlogboek';
$_['text_error_log_intro'] = 'De laatste %d regels die %s in het foutenlogboek van de winkel schreef, oudste eerst. De API-sleutel en de waarden van aangepaste headers zijn onleesbaar gemaakt.';
$_['text_error_log_empty'] = 'Er zijn nog geen regels om te tonen.';
$_['entry_refresh_profile'] = 'Merchantprofiel';
$_['button_refresh_profile'] = 'Merchantprofiel vernieuwen';
$_['help_refresh_profile'] = 'Uw aan te bieden betaaltermijnen, kopertoeslagtarieven, minimale orderwaarde en standaardtermijn worden uit %s gelezen en gecachet. Ze worden volgens een schema vernieuwd en telkens wanneer de API-sleutel of de omgeving wordt opgeslagen; gebruik dit om een wijziging nu door te halen.';
$_['text_profile_refreshed'] = 'Merchantprofiel vernieuwd.';
$_['error_profile_refresh'] = 'Kon het merchantprofiel niet vernieuwen - het eerder gecachete profiel is nog in gebruik. Controleer of de API-sleutel verifieert en of %s bereikbaar is.';
$_['entry_cron_url'] = 'URL voor geplande vernieuwing';
$_['help_cron_url'] = 'Roep dit elke 15 minuten aan vanuit de crontab van uw server. Elke aanroep controleert Two-bestellingen die de koper heeft geverifieerd maar waarvan hij nooit is teruggekeerd, plaatst ze, en vernieuwt het merchantprofiel wanneer dat nodig is. Het token kan ook worden verzonden als X-Two-Cron-Token-header of als POST-veld, waardoor het buiten proceslijsten en proxylogs blijft. Houd het geheim; iedereen die het heeft, kan de run starten. De eigen cron van OpenCart (de pagina Cron Jobs) voert dezelfde controle elk uur uit en de vernieuwing dagelijks, maar alleen deze URL plaatst een bestelling binnen 15 minuten.';
$_['text_cron_last_run'] = 'De geplande vernieuwing is voor het laatst uitgevoerd op %s.';
$_['text_cron_never'] = 'De geplande vernieuwing is nog niet uitgevoerd.';
$_['text_pending_stale'] = '%d Two-bestellingen wachten tot de koper terugkeert, en de geplande URL heeft het afgelopen uur niet gedraaid. Plan hem elke 15 minuten in, zodat geverifieerde bestellingen worden geplaatst.';

// TWO-27055: the product page message and buy button (Checkout fields).
$_['entry_product_message_enabled'] = 'Bericht tonen op productpagina’s';
$_['help_product_message_enabled'] = 'Toont een korte promotionele regel op productpagina’s, onder de knop om aan de winkelwagen toe te voegen. Standaard uitgeschakeld. Waar de regel precies terechtkomt, hangt af van je thema.';
$_['entry_product_button_enabled'] = 'Koopknop tonen op productpagina’s';
$_['help_product_button_enabled'] = 'Voegt onder in winkelwagen een knop toe die het artikel in de winkelwagen legt en de koper naar de kassa brengt met deze betaalmethode al gekozen. Standaard uit. Waar de knop precies terechtkomt hangt af van je thema.';
$_['entry_product_message'] = 'Bericht op de productpagina';
$_['help_product_message'] = 'Optioneel. Laat leeg om de standaardtekst te gebruiken. Vermeld geen aantal dagen tenzij je zeker bent van de termijnen die je aanbiedt: betaaltermijnen lopen vanaf de uitlevering, en de termijnen die echt beschikbaar zijn komen uit je merchant-record.';
