<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';
// The payment method title when the merchant has set none for the language.
$_['text_title_default'] = 'Factura para empresas';

// Tile. %s is the brand's product name.
$_['entry_company'] = 'Empresa';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Compras para %s.';
$_['text_company_missing'] = 'Para pagar con %s, busca tu empresa en el campo de empresa de tu dirección y selecciónala en los resultados.';
$_['text_company_missing_saved'] = 'Para pagar con %s, añade tu empresa a la dirección que estás usando.';
$_['button_confirm'] = 'Confirmar pedido';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'Dirección de correo electrónico de facturación';
$_['entry_purchase_order_number'] = 'Número de orden de compra';
$_['entry_project'] = 'Proyecto';
$_['entry_department'] = 'Departamento';
$_['text_optional'] = 'opcional';
$_['error_field_too_long'] = '%1$s debe tener como máximo %2$d caracteres.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Es probable que tu factura con %s sea aceptada, sujeta a comprobaciones adicionales.';
$_['text_intent_declined'] = 'Tu factura con %s no puede ser aprobada en este momento. Por favor, selecciona otro método de pago.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_too_short'] = 'Introduce %2$d o más caracteres';
$_['js_no_matches'] = 'No se han encontrado coincidencias';
$_['js_search_unavailable'] = 'La búsqueda de empresas no está disponible temporalmente. Por favor, inténtalo de nuevo.';
$_['js_select_country'] = 'Selecciona tu país arriba para buscar tu empresa.';
$_['js_back_to_search'] = 'Buscar empresa';
$_['js_chip_registered'] = 'Empresa registrada';
$_['js_chip_sole_trader'] = 'Autónomo';
$_['js_chip_manual'] = 'Introducir manualmente';
$_['js_search_aria'] = 'Buscar empresa';
$_['js_results_one'] = 'Hay 1 resultado disponible, usa las flechas arriba y abajo para navegar.';
$_['js_results_many'] = 'Hay %d resultados disponibles, usa las flechas arriba y abajo para navegar.';
$_['js_company_number'] = 'Número de empresa';
$_['js_select_different_sole_trader'] = 'Seleccionar otro autónomo';
$_['js_sole_trader_signup'] = 'Regístrate o inicia sesión como autónomo';
$_['js_address_unavailable'] = 'No pudimos obtener la dirección de esta empresa. Introdúcela a continuación.';
$_['text_company_edit_address'] = 'Editar dirección';
$_['js_sole_trader_error'] = 'Algo salió mal al configurar el pago como autónomo. Inténtalo de nuevo.';
$_['js_checking'] = 'Comprobando la elegibilidad para el pago con %1$s...';
$_['js_settle_timeout'] = 'Todavía estamos guardando los datos de tu empresa. Inténtalo de nuevo en un momento.';
$_['js_confirm_failed'] = 'No hemos recibido respuesta sobre tu pedido. Puede que todavía se esté procesando: espera un momento y revisa tu historial de pedidos antes de volver a intentarlo.';
$_['error_order_in_progress'] = 'Tu pedido todavía se está realizando. Espera un momento y revisa tu historial de pedidos antes de volver a intentarlo.';
$_['js_totals_failed'] = 'Tu plazo de pago se ha guardado, pero no se ha podido actualizar el total del pedido. Vuelve a cargar la página antes de confirmar.';
$_['error_too_many'] = 'Demasiadas solicitudes. Espera un momento y vuelve a intentarlo.';
$_['error_forbidden'] = 'Tu sesión de pago ha cambiado. Vuelve a cargar la página e inténtalo de nuevo.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Error de conexión con el proveedor de pago. Por favor, inténtalo de nuevo.';
$_['error_intent_unavailable'] = 'El proveedor de pago no está disponible temporalmente. Por favor, inténtalo más tarde.';
$_['error_intent_unprocessable'] = 'No se puede procesar tu pedido con el pago de %s.';
$_['error_placement_currency'] = '%1$s no está disponible para pedidos en %2$s. Elige otra moneda u otro método de pago.';
$_['error_placement_buyer_country'] = 'Este método de pago no está disponible.';
$_['error_sole_trader'] = 'No se ha podido iniciar el registro de autónomos. Inténtalo de nuevo.';

// Callbacks.
$_['text_returning'] = 'Volviendo a la tienda';
$_['text_continue'] = 'Continuar';
$_['text_cancelled'] = 'Tu pedido ha sido cancelado.';
$_['text_pending'] = 'Todavía estamos confirmando tu pago. Espera un minuto antes de volver a intentarlo, para que el pedido no se haga dos veces.';
$_['text_cart_changed'] = 'Tu carrito cambió durante la verificación del pago. Revisa tu carrito y vuelve a intentarlo.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'No se ha encontrado tu pedido. Vuelve al checkout e inténtalo de nuevo.';
$_['error_payment_method'] = 'Elige un método de pago.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'La compra con factura mediante %s no está disponible para este pedido.';
$_['error_two_same_company'] = 'El comprador y el comerciante no pueden ser la misma empresa';
$_['error_two_company_required'] = 'Para pagar con %s, introduce el nombre de la empresa, el número de organización y el país de la empresa.';
$_['error_two_field_phone_number'] = 'Introduce un número de teléfono válido para pagar con factura.';
$_['error_two_field_organization_number'] = 'Introduce un número de organización válido para pagar con factura.';
$_['error_two_field_company_name'] = 'Introduce un nombre de empresa válido para pagar con factura.';
$_['error_two_field_first_name'] = 'Introduce un nombre válido para pagar con factura.';
$_['error_two_field_last_name'] = 'Introduce unos apellidos válidos para pagar con factura.';
$_['error_two_field_email'] = 'Introduce un correo electrónico válido para pagar con factura.';
$_['error_two_field_address'] = 'Introduce una dirección válida para pagar con factura.';
$_['error_two_field_city'] = 'Introduce una ciudad válida para pagar con factura.';
$_['error_two_field_country'] = 'Introduce un país válido para pagar con factura.';
$_['error_two_field_postal_code'] = 'Introduce un código postal válido para pagar con factura.';
$_['error_two_field_invoice_email'] = 'Introduce una dirección de correo electrónico de facturación válida para pagar con factura.';

// Payment terms and the payment terms fee (Build Guide sections 4 and 5)
$_['text_terms_title'] = 'Elige tus plazos de pago';
$_['text_terms_description'] = 'Tu período de pago comienza cuando se cumple tu pedido.';
$_['text_terms_description_eom'] = 'El pago vence al final del mes en que se cumple tu pedido, más los días que elijas.';
$_['text_term_days'] = '%d días';
$_['text_term_eom'] = 'EOM+%d';
$_['text_term_eom_name'] = 'EOM+%1$d: pague %1$d días después del fin de mes';
$_['text_pay_in'] = 'Pagar en %d días';
$_['text_pay_in_eom'] = 'Pagar en %d días desde el fin de mes';
$_['text_fee_line'] = 'Comisión por plazos de pago - %d días';
$_['text_fee_line_eom'] = 'Comisión por plazo de pago - %d días desde el fin de mes';
$_['text_fee_plus'] = '+%s';
$_['error_term'] = 'Ese plazo de pago no está disponible. Elige otro.';
$_['error_placement_terms'] = 'Este método de pago no está disponible.';
$_['error_placement_fee'] = '%s no está disponible para este pedido en este momento. Elige otro método de pago.';
$_['error_placement_fee_changed'] = 'La comisión por plazo de pago ha cambiado. Revisa el total del pedido y vuelve a confirmar.';

// TWO-27051: the explainer link and the optional fields' tooltips. %s is the brand's product name.
$_['text_about'] = '¿Qué es %s?';
$_['text_about_body'] = '%s es una solución de pago para compras B2B en línea que te permite comprar a tus comercios y proveedores favoritos con crédito comercial. Con %s, puedes acceder al instante a crédito comercial flexible para simplificar tus compras.';
$_['text_about_strong'] = 'Compra ahora, recibe tus productos y paga tu factura más tarde.';
$_['text_about_more'] = 'Haz clic para saber más';
$_['help_invoice_email'] = 'Dónde debe %s enviar la factura de este pedido, si es distinta del correo electrónico de tu cuenta.';
$_['help_purchase_order_number'] = 'Tu referencia interna de orden de compra para este pedido.';
$_['help_project'] = 'El proyecto que se asociará a esta compra, para tu propia referencia interna.';
$_['help_department'] = 'El departamento que se asociará a esta compra, para tu propia referencia interna.';

// Product page buy button (TWO-25800). %s is the brand's product name. The
// product page message's default wording is text_about_strong, the tile's own sentence.
$_['text_product_button'] = 'Comprar con %s';
$_['error_product_button_unreachable'] = 'Lo sentimos, no hemos podido añadir esto a tu cesta. Inténtalo de nuevo.';
