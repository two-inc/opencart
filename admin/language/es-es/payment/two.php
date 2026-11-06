<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';
// The payment method title at checkout when none is set for a language: the same as the storefront file's.
$_['text_title_default'] = 'Factura para empresas';

// Text
$_['text_extension'] = 'Extensiones';
$_['text_success'] = 'Ajustes guardados.';
$_['text_success_partial'] = 'Los demás ajustes se han guardado.';
$_['text_edit'] = 'Editar los ajustes de Two';
$_['text_version'] = 'Versión';
$_['text_environment_production'] = 'Producción';
$_['text_environment_sandbox'] = 'Entorno de pruebas';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'Hay una clave guardada. Deja este campo vacío para conservarla.';
$_['text_key_verified'] = 'Clave API verificada correctamente';
$_['text_key_verifying'] = 'La clave API se está verificando. Vuelve a cargar la página en un momento.';
$_['text_key_checking'] = 'Comprobando la clave API...';
$_['text_key_kept'] = 'Se han conservado la clave y el entorno guardados anteriormente.';
$_['text_merchant_id'] = 'ID del comerciante';
$_['text_merchant_short_name'] = 'Nombre corto del comerciante';
$_['text_record_fetched_at'] = 'Perfil del comercio obtenido';
$_['text_status_mapping_intro'] = 'Asigna los estados de pago de %s a los estados de pedido de OpenCart para integrarlos en tu flujo de trabajo.';
$_['text_default_mappings'] = 'Asignaciones predeterminadas:';
$_['text_default_fulfil'] = '%s: pedido cumplido → Enviado';
$_['text_default_cancel'] = '%s: pedido cancelado → Cancelado';
$_['text_default_refund'] = '%s: pedido reembolsado → Reembolsado';
$_['text_currently_active'] = 'Actualmente activos:';
$_['text_none_selected'] = 'Ninguno seleccionado';

// Tab
$_['tab_general'] = 'General';
$_['tab_checkout_fields'] = 'Campos del proceso de pago';
$_['tab_payment_terms'] = 'Plazos de pago';
$_['tab_order_management'] = 'Gestión de pedidos';
$_['tab_diagnostics'] = 'Diagnóstico';

// Legend
$_['legend_status_mapping'] = 'Asignación de estados';

// Entry
$_['entry_status'] = 'Estado';
$_['entry_sort_order'] = 'Orden de clasificación';
$_['entry_environment'] = 'Entorno';
$_['entry_api_key'] = 'Clave API';
$_['entry_title'] = 'Título';
$_['entry_fulfil'] = '%s: pedido cumplido, estados que activan el proceso';
$_['entry_cancel'] = '%s: Pedido cancelado';
$_['entry_refund'] = '%s: Pedido reembolsado';

// Help
$_['help_environment'] = 'El entorno de pruebas es para hacer pruebas y no mueve dinero real. Usa la clave de API emitida para el entorno que selecciones.';
$_['help_api_key'] = 'Introduce tu clave API proporcionada por %s.';
$_['help_title'] = 'Introduce el título que aparecerá en la página de pago como nombre del método de pago.';
$_['help_fulfil'] = 'Selecciona uno o más estados del pedido que deban activar el cumplimiento en %1$s. Cuando se establezca cualquiera de estos estados, el pedido se marcará como cumplido en %1$s. Los plazos de pago del comprador se activarán y comenzará el ciclo de pagos. Puedes seleccionar varios estados (mantén pulsado Ctrl/Cmd para seleccionar varios). Predeterminado: Enviado';
$_['help_cancel'] = 'El pedido ha sido cancelado en %s. Esto impide su cumplimiento y detiene el proceso de pago. Predeterminado: Cancelado';
$_['help_refund'] = 'Al pasar un pedido a este estado, se reembolsa por completo en %s y se emite una nota de crédito al comprador. Predeterminado: Reembolsado.';

// Error
$_['error_permission'] = 'No tienes permiso para modificar los ajustes de pago de Two.';
$_['error_partial'] = 'Algunos ajustes no se han guardado. Revisa los campos resaltados.';
$_['error_title_missing'] = 'Introduce un título.';
$_['error_status_missing'] = 'Selecciona un estado de pedido.';
$_['error_status_unknown'] = 'Selecciona un estado de pedido que exista en esta tienda.';
$_['error_status_void'] = 'OpenCart usa este estado para los pedidos que se están editando, así que no se puede asignar. Selecciona otro estado de pedido.';
$_['error_key_missing'] = 'Introduce una clave API.';
$_['error_kept_malformed'] = 'Esta no es una clave API válida: solo puede contener caracteres imprimibles, sin espacios ni saltos de línea. Se ha conservado la clave guardada anteriormente.';
$_['error_kept_environment'] = 'Selecciona un entorno válido.';
$_['error_environment_unhealthy'] = 'El entorno guardado no se puede usar en esta tienda, así que se usa el entorno de pruebas. Selecciona un entorno y guarda.';
$_['error_key_invalid'] = '%1$s ha rechazado esta clave API. Puede que no sea válida o haya caducado: comprueba la clave en tu portal de %1$s.';
$_['error_key_service'] = '%s no ha podido verificar la clave API en este momento (HTTP %d). Suele ser temporal: vuelve a intentarlo en unos minutos.';
$_['error_key_unreachable'] = 'Esta tienda no ha podido conectar con la API de %s (red, DNS o firewall). La clave API en sí no se ha evaluado.';
$_['error_key_not_configured'] = 'Introduce tu clave API de %1$s para activar %1$s.';
$_['error_key_unexpected'] = '%s ha devuelto una respuesta inesperada al verificar la clave API (HTTP %d).';
$_['error_key_unverified'] = 'No se ha podido verificar la clave API.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Pedido de Two';
$_['text_two_state'] = 'Estado en Two';
$_['text_no_two_order'] = 'No hay ningún pedido de Two vinculado a este pedido.';
$_['button_invoice'] = 'Descargar factura';
$_['help_invoice'] = 'La factura que Two emitió cuando se cumplió el pedido.';
$_['error_invoice_permission'] = 'No tienes permiso para ver esta factura.';
$_['error_invoice_not_ready'] = 'La factura aún no está lista porque el pedido todavía se está procesando. Por favor, inténtalo de nuevo más tarde.';
$_['error_invoice_state'] = 'No hay ninguna factura disponible porque el pedido está en estado: %s.';
$_['error_invoice_no_reference'] = 'No hay ningún pedido de Two vinculado a este pedido.';
$_['error_invoice'] = 'No se ha podido obtener la factura. Inténtalo de nuevo más tarde.';

// Checkout fields: placement switch, optional fields, minimum order. %s is the brand's product name.
$_['entry_company_search_in_address'] = 'Activar la búsqueda de empresa en la introducción de la dirección';
$_['help_company_search_in_address'] = 'Cuando está activado, el comprador puede buscar su empresa dentro de la sección de introducción de la dirección del proceso de pago. De lo contrario, la búsqueda de empresa se mostrará dentro del método de pago.';
$_['entry_address_lookup'] = 'Autocompletar la dirección de la empresa';
$_['help_address_lookup'] = 'Rellena automáticamente la dirección registrada a partir del registro nacional.';
$_['entry_enable_invoice_email'] = 'Mostrar el campo de correo electrónico para la factura';
$_['help_enable_invoice_email'] = 'Si eliges Sí, los clientes verán un campo de correo electrónico de facturación en la sección de pago de %s del proceso de pago. Se sitúa junto al método de pago y no junto a la dirección, de modo que se invita al comprador a considerar una dirección de facturación específica incluso cuando sus direcciones de facturación y envío coinciden.';
$_['entry_enable_po_number'] = 'Mostrar el campo de número de pedido de compra';
$_['help_enable_po_number'] = 'Si eliges Sí, los clientes verán un campo de número de pedido de compra en la sección de pago de %s del proceso de pago.';
$_['entry_enable_project'] = 'Mostrar el campo de proyecto';
$_['help_enable_project'] = 'Si eliges Sí, los clientes verán un campo de proyecto en la sección de pago de %s del proceso de pago.';
$_['entry_enable_department'] = 'Mostrar el campo de departamento';
$_['help_enable_department'] = 'Si eliges Sí, los clientes verán un campo de departamento en la sección de pago de %s del proceso de pago.';
$_['entry_min_order_amount'] = 'Valor mínimo del pedido, %s';
$_['help_min_order_amount'] = 'Ocultar el método de pago por debajo de este valor de pedido (moneda predeterminada de la tienda, sobre la base impositiva seleccionada más abajo). Déjalo vacío para no establecer ningún mínimo.';
$_['entry_min_order_basis'] = 'Base imponible del valor mínimo del pedido';
$_['help_min_order_basis'] = 'Si el carrito se compara con el mínimo con impuestos incluidos o excluidos.';
$_['text_basis_default'] = 'Como el mínimo de la plataforma (con impuestos si no hay ninguno)';
$_['text_basis_option_gross'] = 'Impuestos incluidos (bruto)';
$_['text_basis_option_net'] = 'Sin impuestos (neto)';
$_['error_switch_invalid'] = 'Elige Sí o No.';
$_['error_minimum_invalid'] = 'Introduce un importe mayor que cero con dos decimales como máximo, o deja el campo vacío.';
$_['error_basis_invalid'] = 'Elige una base impositiva de la lista.';

// Health row (Build Guide section 18). %1$s is the provider's full name, %2$s the shop's default currency.
$_['entry_health'] = 'Método de pago en la caja';
$_['text_health_hidden'] = 'No se muestra en la caja';
$_['text_health_shown'] = 'Se muestra en la caja';
$_['text_withheld_disabled'] = 'la extensión no está activada. Activa Estado.';
$_['text_withheld_no_api_key'] = 'no hay ninguna clave API guardada. Compruebe Clave API.';
$_['text_withheld_key_rejected'] = 'la clave API fue rechazada. Compruebe Clave API y Entorno.';
$_['text_withheld_buyer_countries_empty'] = 'actualmente no hay ningún país de comprador activado para tu cuenta. Ponte en contacto con %1$s para activarlos.';
$_['text_withheld_buyer_countries_malformed'] = 'los países del comprador de tu cuenta no se han podido leer. Ponte en contacto con %1$s.';
$_['text_withheld_merchant_unidentified'] = 'tu cuenta de comerciante aún no se ha identificado. Guarda General para verificar la clave API.';
$_['text_withheld_currency'] = 'ninguna de las monedas activadas en la tienda permite emitir facturas.';
$_['text_health_below'] = 'oculto para cestas por debajo de %s';
$_['text_health_floor'] = '%1$s %2$s %3$s';
$_['text_basis_gross'] = 'con impuestos';
$_['text_basis_net'] = 'sin impuestos';
$_['text_or'] = 'o';
$_['text_health_countries'] = 'se ofrece solo a compradores en %s';
$_['text_health_currencies'] = 'oculto para carritos en %s';
// Order page: refunds and the credit note
$_['button_credit_note'] = 'Descargar nota de crédito';
$_['help_credit_note'] = 'La última nota de crédito que Two emitió para este pedido.';
$_['error_credit_note'] = 'No se ha podido obtener la nota de crédito. Puede que aún se esté procesando. Inténtalo de nuevo más tarde.';
$_['text_refund'] = 'Reembolso';
$_['text_refunded'] = 'Reembolsado en Two';
$_['text_remaining'] = 'Pendiente de reembolsar';
$_['text_refund_help'] = 'Elige cuántas unidades de cada línea reembolsar, introduce una línea de importe, o ambas cosas. El estado de reembolso total reembolsa lo que quede.';
$_['text_refund_unavailable'] = 'Los reembolsos estarán disponibles cuando Two haya facturado el pedido.';
$_['text_refund_unread'] = 'No se ha podido consultar a Two sobre este pedido. Vuelve a cargar la página para intentarlo de nuevo.';
$_['text_refunds'] = 'Reembolsos enviados desde esta tienda';
$_['column_line'] = 'Línea';
$_['column_quantity'] = 'Pedido';
$_['column_net'] = 'Neto';
$_['column_tax'] = 'Impuestos';
$_['column_rate'] = 'Tipo impositivo';
$_['column_refund_quantity'] = 'Reembolsar';
$_['column_date'] = 'Fecha';
$_['column_amount'] = 'Importe';
$_['column_state'] = 'Resultado';
$_['entry_refund_net'] = 'Línea de importe, neto';
$_['entry_refund_tax'] = 'Línea de importe, impuestos';
$_['entry_refund_rate'] = 'Línea de importe, tipo impositivo';
$_['entry_refund_reason'] = 'Motivo (en la nota de crédito)';
$_['button_refund'] = 'Enviar reembolso a Two';
$_['error_refund_permission'] = 'No tienes permiso para reembolsar este pedido.';
$_['error_refund_quantity'] = 'No reembolsado: una cantidad no es un número entero.';
$_['text_refund_state_pending'] = 'Sin respuesta';
$_['text_refund_state_done'] = 'Reembolsado';
$_['text_refund_state_refused'] = 'Rechazado';
$_['text_refund_state_unknown'] = 'Desconocido';
$_['text_refund_state_not_applied'] = 'No aplicado';
$_['button_retry'] = 'Reintentar';
$_['text_refund_blocked'] = 'Un reembolso de abajo tiene un resultado desconocido. Reinténtalo antes de enviar otro reembolso: se vuelve a enviar con la misma clave, así que Two lo reembolsa como máximo una vez.';
$_['column_left'] = 'Pendiente de reembolsar';
$_['text_panel_loading'] = 'Consultando a Two sobre este pedido...';
$_['error_panel'] = 'No se ha podido cargar la pestaña Two. Vuelve a abrir la pestaña para reintentarlo o vuelve a cargar la página.';
$_['error_ajax'] = 'La tienda no respondió como se esperaba. Revisa el historial del pedido y la pestaña Two antes de volver a intentarlo.';
$_['button_check_status'] = 'Comprobar estado con Two';
$_['help_check_status'] = 'Este pedido espera a que el comprador vuelva de Two. Consulte a Two ahora: un pedido que el comprador verificó se confirma y se registra, y uno cancelado se anota.';

// Diagnostics: trusted proxies for the checkout's rate limits.
$_['entry_trusted_proxies'] = 'Proxies de confianza';
$_['help_trusted_proxies'] = 'Direcciones de sus propios proxies inversos, balanceadores de carga o salida de CDN, como IPs o rangos CIDR, separados por comas o saltos de línea. Estas direcciones IP quedarán exentas de la limitación de solicitudes.';
$_['error_proxy_invalid'] = 'Proxies de confianza: "%s" no es una dirección IP ni un rango CIDR válido.';
$_['entry_disable_rate_limit'] = 'Desactivar la limitación de solicitudes en el checkout';
$_['help_disable_rate_limit'] = 'Elimina el límite por llamante en los endpoints AJAX del checkout. El límite está activado por defecto. Si esta tienda está detrás de una CDN, balanceador de carga o proxy inverso y Proxies de confianza está vacío, cada comprador llega como esa misma dirección y comparten un único límite - los compradores son entonces rechazados a mitad del checkout con un mensaje de demasiadas solicitudes. La solución es rellenar Proxies de confianza, lo que permite al límite distinguir entre compradores; active esto solo como solución provisional mientras obtiene esa lista, y desactívelo después.';

// Payment terms tab (Build Guide sections 4 and 5)
$_['text_terms_none'] = 'El perfil del comercio aún no ofrece ningún plazo de pago, así que Two no se ofrece en el checkout. Guarda una clave API válida o revisa tus plazos en el portal de comercios de Two.';
$_['text_days'] = '%d días';
$_['text_term_type_standard'] = 'Estándar';
$_['text_term_type_eom'] = 'Fin de mes';
$_['text_default_term_auto'] = 'Automático';
$_['text_differential_total'] = 'Comisión total para el plazo seleccionado';
$_['text_differential_difference'] = 'Diferencia de comisión respecto al plazo de pago predeterminado';
$_['text_surcharge_none'] = 'No se aplica ningún recargo';
$_['text_surcharge_percentage'] = 'Porcentaje';
$_['text_surcharge_fixed'] = 'Comisión fija';
$_['text_surcharge_fixed_and_percentage'] = 'Comisión fija y porcentaje';
$_['text_rounding_none'] = 'Ninguno';
$_['text_rounding_up'] = 'Arriba';
$_['text_rounding_down'] = 'Caído';
$_['text_rounding_standard'] = 'Estándar';
$_['text_no_rounding'] = 'Sin redondeo';
$_['legend_surcharge'] = 'Recargo';
$_['entry_payment_term_type'] = 'Tipo de plazo de pago';
$_['entry_payment_terms'] = 'Plazos de pago disponibles';
$_['entry_default_payment_term'] = 'Plazo de pago predeterminado';
$_['entry_surcharge_type'] = 'Método de recargo';
$_['entry_surcharge_differential'] = 'Base de cálculo del recargo';
$_['entry_surcharge_line_description'] = 'Descripción de la línea de recargo';
$_['entry_surcharge_rounding_basis'] = 'Redondeo del recargo';
$_['entry_surcharge_rounding_step'] = 'Paso de redondeo';
$_['entry_surcharge_tax_class'] = 'Tratamiento fiscal del recargo';
$_['entry_surcharge_grid'] = 'Recargo por plazo';
$_['column_term'] = 'Plazo';
$_['column_percentage'] = 'Porcentaje';
$_['column_fixed'] = 'Comisión fija';
$_['column_cap'] = 'Límite';
$_['help_payment_term_type'] = 'Los plazos estándar cuentan desde que se cumple el pedido. Los plazos de fin de mes cuentan desde el final del mes en que se cumple el pedido, y solo ofrecen 30, 45 y 60 días.';
$_['help_payment_terms'] = 'Los plazos que ofrece tu cuenta de %1$s. Los compradores eligen entre los marcados aquí; una instalación nueva marca 30, 60 y 90 días. Si no se ofrece ninguno de los plazos marcados, %1$s no se muestra en el checkout.';
$_['help_default_payment_term'] = 'El plazo con el que empieza el comprador. Automático usa el predeterminado de tu cuenta cuando se ofrece; si no, 30 días; si no, el más corto.';
$_['help_surcharge_type'] = 'Añade una comisión por el plazo de pago que elija el comprador. %s calcula la comisión; la tienda solo envía esta configuración. El total de pedido Comisión por plazos de pago de Two debe estar activado (Extensiones > Totales de pedido).';
$_['help_surcharge_differential'] = 'Comisión total cobra el recargo configurado para el plazo elegido. Diferencia de comisión cobra solo la diferencia respecto al plazo de pago predeterminado.';
$_['help_surcharge_line_description'] = 'Etiqueta de la línea de comisión que ve el comprador. Usa %s para la duración del plazo en días. Déjalo vacío para usar el texto predeterminado.';
$_['help_surcharge_rounding_basis'] = 'Ajusta la comisión a un incremento exacto. Selecciona Ninguno para importes estándar de dos decimales.';
$_['help_surcharge_rounding_step'] = 'Incremento al que se redondea la comisión. Solo se aplica cuando se selecciona una dirección de redondeo.';
$_['help_surcharge_tax_class'] = 'La clase de impuesto con la que se cobra la comisión, como en cualquier producto: se aplican las reglas fiscales de la tienda según la dirección del comprador. Para dejar la comisión sin impuestos, crea una clase de impuesto con un tipo del 0 por ciento y selecciónala. Obligatorio mientras haya un recargo activo.';
$_['help_surcharge_grid'] = 'El porcentaje es la parte de la comisión de %2$s que se repercute al comprador, de 0 a 100. El límite se aplica a toda la comisión: el porcentaje y la comisión fija juntos, no solo el porcentaje. Déjalo vacío si no quieres límite. Las comisiones fijas y los límites están en %1$s y se convierten con los tipos de cambio de %2$s para otras monedas.';
$_['error_term_type_unknown'] = 'Elige un tipo de plazo de pago de la lista.';
$_['error_terms_none'] = 'Debes seleccionar al menos un plazo de pago.';
$_['error_terms_unknown'] = 'Selecciona solo plazos que ofrezca tu cuenta.';
$_['error_default_term_unknown'] = 'Elige un plazo de pago predeterminado entre los plazos seleccionados, o Automático.';
$_['error_surcharge_type_unknown'] = 'Elige un método de recargo de la lista.';
$_['error_surcharge_tax_class_missing'] = 'Selecciona un tratamiento fiscal del recargo: los recargos están activados, así que debes elegir una clase de impuesto antes de guardar.';
$_['error_surcharge_tax_class_unknown'] = 'El tratamiento fiscal del recargo debe ser una de las clases de impuesto de la tienda. Para dejar la comisión sin impuestos, crea una clase con un tipo del 0 por ciento.';
$_['error_surcharge_step_unknown'] = 'El paso de redondeo debe ser uno de los valores ofrecidos.';
$_['error_surcharge_basis_unknown'] = 'Elige un redondeo del recargo de la lista.';
$_['error_surcharge_cell_invalid'] = '%1$s para el plazo de %2$d días debe ser un número no negativo.';
$_['error_surcharge_cap_zero'] = 'El límite del recargo para el plazo de %2$d días no puede ser 0. Si no quieres cobrar nada en este plazo, establece el porcentaje y la cuota fija en 0 y deja el límite vacío.';
$_['text_health_fee_quote'] = 'el cálculo de la comisión por plazos de pago falla desde %s (%s), así que %s no se ofrece en los carritos para los que falla';
$_['text_health_fx'] = 'Tipos de cambio de %s obtenidos %s';
$_['text_withheld_no_terms'] = 'no se ofrece ningún plazo de pago: tu cuenta no ofrece ninguno de los plazos marcados en la pestaña Plazos de pago para este tipo de plazo.';
$_['text_withheld_fee_total_off'] = 'hay un recargo configurado, pero el total de pedido Comisión por plazos de pago de Two no está activado. Actívalo en Extensiones > Totales de pedido, o establece el método de recargo en No se aplica ningún recargo.';
$_['text_withheld_tax_code_map_unreadable'] = 'los códigos de impuesto guardados para líneas al 0%% no se pueden leer. Marca Borrar los códigos de impuesto guardados en la sección Códigos de impuesto para líneas al 0%% (pestaña Gestión de pedidos) y guarda.';
$_['error_surcharge_percent_range'] = '%1$s para el plazo de %2$d días debe estar entre 0 y 100: es la parte de la comisión que se repercute al comprador.';

// TWO-27051: vendor name (General), subtitle and the tile switches (Checkout fields), the confirmed status and Validate tax subtotals (Order management), the merchant fee per term (Payment terms). %s is the brand's product name.
$_['entry_vendor_name'] = 'Nombre del proveedor (opcional)';
$_['help_vendor_name'] = 'Si esta tienda representa uno de varios sitios de vendedor que comparten la misma cuenta de comerciante de %1$s, introduce aquí un nombre para identificar este sitio/vendedor concreto en cada pedido enviado a %1$s - déjalo en blanco si solo gestionas un único sitio.';
$_['error_vendor_invalid'] = 'Introduce un nombre de proveedor de 255 caracteres como máximo, sin saltos de línea ni letras acentuadas: se envía con cada solicitud como cabecera.';
$_['entry_subtitle'] = 'Subtítulo';
$_['help_subtitle'] = 'Subtítulo opcional que se muestra debajo del título en la página de pago.';
$_['entry_enable_order_intent'] = 'Activar la comprobación de preaprobación de la intención de pedido';
$_['help_enable_order_intent'] = 'Si eliges Sí, el proceso de pago consulta a %1$s una vista previa de la aprobación del pedido antes de que el comprador envíe el pago, y muestra el resultado en el bloque de pago. Si eliges No, se omite esta consulta previa: el comprador sigue pasando por la comprobación de aprobación real de %1$s al enviar el pago.';
$_['entry_show_about_link'] = 'Mostrar el enlace explicativo "Qué es %s"';
$_['help_show_about_link'] = 'Si eliges Sí, los compradores verán una información sobre herramientas "¿Qué es %1$s?" con un enlace a un recurso explicativo en el bloque de pago de %1$s en el proceso de pago.';
$_['entry_display_tooltips'] = 'Mostrar descripciones emergentes en los campos';
$_['help_display_tooltips'] = 'Si eliges Sí, los campos opcionales del proceso de pago indicados arriba muestran una breve información de ayuda sobre su etiqueta.';
$_['legend_tax'] = 'Impuestos';
$_['legend_tax_codes'] = 'Códigos de impuesto para líneas al 0%';
$_['text_tax_codes_intro'] = 'Para cada clase de impuesto, elija el código que %1$s recibe en una línea sin impuesto en cada caso en que su configuración de impuestos pueda situarla. Una línea toma un código solo de estas filas: una fila en (ninguno) no envía código, y %1$s nunca elige uno por su cuenta.';
$_['text_tax_codes_exempt_row'] = 'Comprador registrado a efectos de IVA, otro Estado de la UE';
$_['text_tax_codes_none_row'] = 'La dirección del comprador no corresponde a ningún tipo de impuesto (sin impuesto, p. ej. exportaciones)';
$_['text_tax_codes_none'] = '(ninguno)';
$_['text_tax_codes_unlisted'] = '%s (no figura para líneas sin impuesto)';
$_['text_tax_codes_record_error'] = 'No se pudieron leer los datos del comercio desde %s, así que no se pueden mostrar los códigos de impuesto. Use Actualizar el perfil del comercio en la pestaña Diagnóstico. Los códigos guardados se siguen aplicando en el pago.';
$_['text_tax_codes_list_error'] = 'No se pudieron leer los códigos de impuesto para %1$s desde %2$s. Los códigos guardados se siguen aplicando en el pago, y esta página vuelve a consultarlos en 5 minutos.';
$_['text_tax_codes_stale'] = 'No se pudieron actualizar los códigos de impuesto para %1$s desde %2$s: el último intento (%3$s) falló con HTTP %4$d. La lista mostrada se leyó el %5$s, y esta página vuelve a consultarlos en 5 minutos.';
$_['text_tax_codes_unreadable'] = 'Los códigos de impuesto guardados no se pueden leer, así que %s no se muestra en la caja. Bórrelos para solucionarlo.';
$_['text_tax_codes_saved_valid'] = 'Los códigos de impuesto guardados se siguen aplicando en el pago, aunque %1$s no los exige para su país (%2$s).';
$_['text_tax_codes_saved_invalid'] = 'Algunos códigos de impuesto guardados no son válidos en líneas sin impuesto para su país (%1$s), así que %2$s rechaza los pedidos que los usan. Bórrelos salvo que los necesite.';
$_['entry_tax_codes_clear'] = 'Borrar los códigos de impuesto guardados';
$_['entry_buyer_vat_field'] = 'NIF-IVA del comprador';
$_['help_buyer_vat_field'] = 'El campo del cliente que contiene el NIF-IVA del comprador, leído de la dirección de pago o, si no, de la cuenta. El pedido de un comercio español lo envía a %s, y decide la fila de comprador registrado a efectos de IVA.';
$_['error_form_truncated'] = 'No se guardó nada: el formulario se cortó por el límite del servidor de campos enviados (max_input_vars). Aumente el límite y guarde de nuevo.';
$_['error_tax_code_unlisted'] = 'Los códigos de impuesto no se guardaron: un código elegido no figura para líneas al 0% en su país.';
$_['error_vat_field_unknown'] = 'Elija uno de los campos de cliente de la lista.';
$_['entry_enable_tax_subtotals'] = 'Validar subtotales de impuestos';
$_['help_enable_tax_subtotals'] = 'Si seleccionas Sí, se enviará un desglose de tax_subtotals por tipo de impuesto con las intenciones de pedido, los pedidos, las modificaciones de pedidos y los reembolsos, para su validación frente a las líneas del pedido. Si seleccionas No, tax_subtotals se omitirá en todos ellos.';
$_['entry_confirmed'] = '%s: verificado, listo para el cumplimiento';
$_['help_confirmed'] = 'El pago ha sido verificado y el pedido está listo para que el comerciante lo gestione. Ahora el comerciante puede procesar y enviar el pedido. Predeterminado: Procesando';
$_['text_default_confirmed'] = '%s: verificado, listo para el cumplimiento → Procesando';
$_['error_status_conflict'] = 'Este estado también está asignado al cumplimiento, la cancelación o el reembolso de pedidos, por lo que un pedido se procesaría en cuanto se confirme. Selecciona otro estado de pedido.';
$_['text_fees_stale'] = 'No se han podido actualizar las comisiones, por lo que se muestran las últimas cifras obtenidas.';
$_['text_fees_stale_dated'] = 'No se han podido actualizar las comisiones, por lo que se muestran las cifras obtenidas el %s.';
$_['text_fees_unavailable'] = 'No se han podido cargar las comisiones porque no se ha podido contactar con el servicio de precios. Las cifras junto a cada plazo faltan, no son cero.';
$_['text_fees_rejected'] = 'No se han podido cargar las comisiones. El servicio de precios ha rechazado la solicitud con HTTP %1$s: %2$s. Las cifras junto a cada plazo faltan, no son cero.';
$_['text_fees_rejected_bare'] = 'No se han podido cargar las comisiones. El servicio de precios ha rechazado la solicitud con HTTP %s. Las cifras junto a cada plazo faltan, no son cero.';
$_['text_fees_stale_rejected'] = 'El servicio de precios ha rechazado la solicitud con HTTP %1$s: %2$s.';
$_['text_fees_stale_rejected_bare'] = 'El servicio de precios ha rechazado la solicitud con HTTP %s.';
$_['text_fees_no_api_key'] = 'No se pueden mostrar las comisiones hasta que se guarde una clave API en la pestaña General.';
$_['text_fees_no_figure'] = 'sin cifra';

// TWO-27051 review: a map that names the confirmed status, and the subtitle's length.
$_['error_status_is_confirmed'] = 'Los pedidos reciben este estado cuando se confirman, por lo que se procesarían de inmediato. Selecciona otro estado de pedido o cambia el estado de los pedidos confirmados.';
$_['error_subtitle_too_long'] = 'Introduce un subtítulo de 255 caracteres como máximo.';

// Diagnostics (TWO-27051): debug logging, the SSL bypass, the custom request-header table, the error log, the merchant profile and the scheduled refresh URL. %s is the brand's product name.
$_['entry_debug_mode'] = 'Activar el registro de depuración';
$_['help_debug_mode'] = 'Active un registro detallado para la resolución de problemas. Registra cada solicitud y respuesta de la API en el registro de errores de la tienda, cuerpos incluidos, con la clave de API y los valores de las cabeceras personalizadas ocultos. Actívelo solo cuando se lo pida el soporte de %s.';
$_['entry_disable_ssl_verify'] = 'Desactivar la verificación SSL';
$_['help_disable_ssl_verify'] = 'ADVERTENCIA: Activa esto únicamente si estás detrás de un proxy corporativo con certificados SSL personalizados. Esto desactiva la verificación de certificados SSL y es un riesgo de seguridad. NO RECOMENDADO para producción.';
$_['entry_custom_headers'] = 'Cabeceras de solicitud personalizadas';
$_['column_header_name'] = 'Nombre de la cabecera';
$_['column_header_value'] = 'Valor';
$_['column_header_browser'] = 'Enviar también desde el navegador';
$_['button_header_add'] = 'Añadir cabecera';
$_['button_header_remove'] = 'Eliminar';
$_['help_custom_headers'] = 'Cabeceras que su administrador de TI le pide añadir. Cada una se envía en todas las llamadas que esta tienda hace a la API de %s desde su servidor. Marque "Enviar también desde el navegador" solo cuando su administrador de TI exija la cabecera también en las llamadas desde el navegador del comprador: el valor de esa cabecera se publicará en el navegador del comprador y cualquiera podrá leerlo.';
$_['error_header_name_invalid'] = 'Cabeceras de solicitud personalizadas: "%s" no es un nombre de cabecera válido.';
$_['error_header_name_reserved'] = 'Cabeceras de solicitud personalizadas: "%s" está reservada y no se puede sobrescribir. Elija otro nombre de cabecera.';
$_['error_header_name_duplicate'] = 'Cabeceras de solicitud personalizadas: "%s" se usa en más de una fila. Deje una sola fila por nombre de cabecera.';
$_['error_header_value_missing'] = 'Cabeceras de solicitud personalizadas: introduzca un valor para la cabecera "%s", o elimine la fila.';
$_['error_header_value_invalid'] = 'Cabeceras de solicitud personalizadas: el valor de la cabecera "%s" solo puede contener caracteres ASCII imprimibles - sin saltos de línea, caracteres de control ni texto no ASCII.';
$_['error_headers_tab'] = 'No se ha guardado nada de la pestaña Diagnóstico. Corrija o elimine la fila y vuelva a guardar.';
$_['entry_error_log'] = 'Registro de errores';
$_['button_error_log'] = 'Ver los últimos 100 registros del registro de errores';
$_['heading_error_log'] = '%s: registro de errores';
$_['text_error_log_intro'] = 'Las últimas %d líneas que %s escribió en el registro de errores de la tienda, de la más antigua a la más reciente. La clave de API y los valores de las cabeceras personalizadas están ocultos.';
$_['text_error_log_empty'] = 'Todavía no hay líneas que mostrar.';
$_['entry_refresh_profile'] = 'Perfil del comercio';
$_['button_refresh_profile'] = 'Actualizar el perfil del comercio';
$_['help_refresh_profile'] = 'Los plazos de pago que puede ofrecer, los recargos al comprador, el importe mínimo de pedido y el plazo predeterminado se leen de %s y se almacenan en caché. Se actualizan de forma programada y cada vez que se guarda la clave de API o el entorno; use esto para aplicar un cambio ahora.';
$_['text_profile_refreshed'] = 'Perfil del comercio actualizado.';
$_['error_profile_refresh'] = 'No se pudo actualizar el perfil del comercio: se sigue usando el que estaba en caché. Compruebe que la clave de API se verifica y que %s es accesible.';
$_['entry_cron_url'] = 'URL de la actualización programada';
$_['help_cron_url'] = 'Llame a esta URL cada 15 minutos desde el crontab de su servidor. Cada llamada comprueba los pedidos de Two que el comprador verificó pero de los que nunca volvió, los registra y actualiza el perfil del comercio cuando toca. El token también puede enviarse como cabecera X-Two-Cron-Token o como campo POST, lo que lo mantiene fuera de las listas de procesos y de los registros del proxy. Manténgalo en secreto; cualquiera que lo tenga puede lanzar la ejecución. El cron propio de OpenCart (la página Cron Jobs) ejecuta la misma comprobación cada hora y la actualización a diario, pero solo esta URL recupera un pedido en 15 minutos.';
$_['text_cron_last_run'] = 'La actualización programada se ejecutó por última vez el %s.';
$_['text_cron_never'] = 'La actualización programada aún no se ha ejecutado.';
$_['text_pending_stale'] = '%d pedidos de Two esperan a que el comprador vuelva, y la URL programada no se ha ejecutado en la última hora. Prográmela cada 15 minutos para que se registren los pedidos verificados.';

// TWO-27055: the product page message and buy button (Checkout fields).
$_['entry_product_message_enabled'] = 'Mostrar mensaje en las páginas de producto';
$_['help_product_message_enabled'] = 'Muestra una breve línea promocional en las páginas de producto, debajo del botón de añadir a la cesta. Desactivado de forma predeterminada. El lugar exacto donde aparece depende de tu plantilla.';
$_['entry_product_button_enabled'] = 'Mostrar botón de compra en las páginas de producto';
$_['help_product_button_enabled'] = 'Añade un botón debajo de añadir al carrito que pone el artículo en la cesta y lleva al comprador al pago con este método ya seleccionado. Desactivado por defecto. Dónde aparece exactamente depende de tu plantilla.';
$_['entry_product_message'] = 'Mensaje de la página de producto';
$_['help_product_message'] = 'Opcional. Déjalo vacío para usar el texto predeterminado. Evita indicar un número de días salvo que tengas la certeza de los plazos que ofreces: los plazos de pago cuentan desde la preparación del pedido, y los plazos realmente disponibles proceden de tu registro de comerciante.';
