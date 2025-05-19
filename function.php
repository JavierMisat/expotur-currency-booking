<?php

/**
 * PARTE 1: FORZAR MONEDA BASE (COP) EN EL FRONTEND (EXCEPTO CARRITO Y CHECKOUT)
 */

/**
 * Restablece la moneda a la moneda base de la tienda en todas las páginas excepto carrito y checkout.
 * Se engancha en 'template_redirect' para actuar antes de que la página se renderice.
 */
add_action('template_redirect', 'lc_forzar_moneda_base_en_frontend_global');
function lc_forzar_moneda_base_en_frontend_global() {
    // Solo ejecutar en el frontend y si WOOCS está activo.
    if (is_admin() || !class_exists('WOOCS')) {
        return;
    }

    global $WOOCS;
    $moneda_base_tienda = lc_get_store_base_currency(); // Obtiene COP

    // Si estamos en el carrito o checkout, no hacemos nada aquí, WOOCS debe manejarlo.
    if (is_cart() || is_checkout()) {
        // Podríamos incluso asegurarnos de que WOOCS está usando la moneda que el usuario seleccionó,
        // pero usualmente WOOCS maneja esto bien por sí mismo en estas páginas.
        return;
    }

    // Si la moneda actual de WOOCS NO es la moneda base de la tienda, la restablecemos.
    // Esto es para cuando el usuario navega fuera del checkout a otras páginas.
    if ($WOOCS->current_currency !== $moneda_base_tienda) {
        $WOOCS->set_currency($moneda_base_tienda);
    }
}

/**
 * Filtro adicional para asegurar que wc_price() use la moneda base fuera del carrito/checkout.
 * Aunque forzar WOOCS debería ser suficiente, esto añade una capa de seguridad.
 */
add_filter('woocommerce_currency', 'lc_filter_woocommerce_currency_global', 9990);
function lc_filter_woocommerce_currency_global($currency_code) {
    if (is_admin() || !class_exists('WOOCS')) {
        return $currency_code; // Devuelve el código de moneda actual en admin o si WOOCS no está.
    }

    // En carrito o checkout, permitir que WOOCS (u otro filtro con mayor prioridad) determine la moneda.
    if (is_cart() || is_checkout()) {
        global $WOOCS;
        return $WOOCS->current_currency; // Devolver la moneda activa de WOOCS.
    }

    // Para todas las demás páginas del frontend, forzar la moneda base de la tienda.
    return lc_get_store_base_currency();
}


/**
 * PARTE 2: ASEGURAR CONVERSIÓN CORRECTA EN CARRITO Y CHECKOUT PARA "ova-tour-booking"
 */

// --- Funciones de Ayuda para Conversión y WOOCS ---

/**
 * Obtiene la moneda base de la tienda, priorizando la configuración de WOOCS.
 *
 * @return string Código de la moneda base (ej. "COP").
 */
if (!function_exists('lc_get_store_base_currency')) {
    function lc_get_store_base_currency() {
        if (class_exists('WOOCS')) {
            global $WOOCS;
            // $WOOCS->default_currency es la moneda base configurada en WOOCS.
            if (!empty($WOOCS->default_currency)) {
                return $WOOCS->default_currency;
            }
        }
        // Fallback a la moneda base de WooCommerce.
        return get_option('woocommerce_currency', 'COP');
    }
}

/**
 * Obtiene la moneda actualmente seleccionada por el usuario a través de WOOCS.
 *
 * @return string Código de la moneda seleccionada (ej. "USD", "COP").
 */
if (!function_exists('lc_get_selected_checkout_currency_woocs')) {
    function lc_get_selected_checkout_currency_woocs() {
        if (class_exists('WOOCS')) {
            global $WOOCS;
            return $WOOCS->current_currency;
        }
        // Fallback si WOOCS no está, aunque la lógica principal depende de WOOCS.
        return get_woocommerce_currency();
    }
}

/**
 * Convierte un monto desde la moneda base de la tienda a la moneda de checkout seleccionada, usando WOOCS.
 *
 * @param float $amount_in_base_currency El monto en la moneda base (COP).
 * @param string $target_currency_code El código de la moneda a la que se quiere convertir.
 * @return float El monto convertido.
 */
if (!function_exists('lc_convert_from_base_to_target_woocs')) {
    function lc_convert_from_base_to_target_woocs($amount_in_base_currency, $target_currency_code) {
        if (!class_exists('WOOCS') || empty($amount_in_base_currency) || !is_numeric($amount_in_base_currency)) {
            return $amount_in_base_currency; // No convertir si WOOCS no está o el monto no es válido.
        }

        global $WOOCS;
        $store_base_currency = lc_get_store_base_currency(); // ej. COP

        if ($target_currency_code === $store_base_currency) {
            return $amount_in_base_currency; // No se necesita conversión.
        }

        // WOOCS puede convertir directamente si conoce las tasas.
        // $WOOCS->raw_woocommerce_price($amount_in_base_currency) devuelve el precio en la moneda actual de WOOCS.
        // Para convertir a un target específico desde la base, necesitamos un poco más.
        // Primero, aseguramos que el precio esté realmente en la moneda base para WOOCS.
        $price_in_current_woocs_currency = $WOOCS->woocs_exchange_value(floatval($amount_in_base_currency), $store_base_currency, $target_currency_code);
        
        return $price_in_current_woocs_currency;

        // Alternativa más simple si el monto ya está "pensado" por WOOCS como base:
        // return $WOOCS->raw_woocommerce_price($amount_in_base_currency, $target_currency_code);
        // Sin embargo, es más seguro usar woocs_exchange_value si el monto viene crudo en COP.
    }
}

/**
 * Función de callback para preg_replace_callback.
 * Extrae, convierte (usando WOOCS) y reformatea el precio encontrado.
 *
 * @param array $matches Array de coincidencias de la expresión regular.
 * @return string HTML del precio convertido o el original si falla.
 */
function lc_convert_price_string_callback_woocs($matches) {
    // $matches[1] es el texto previo al precio, ej: "(7% deposit of " o "<dt>Remaining:</dt><dd...>"
    // $matches[2] es el monto numérico, ej: "1.860.000,00"
    $leading_text = $matches[1];
    $amount_string_from_cop = $matches[2];

    $store_base_currency = lc_get_store_base_currency(); // ej. COP
    $selected_checkout_currency = lc_get_selected_checkout_currency_woocs(); // ej. USD

    // Si la moneda seleccionada es la misma que la base, o si no se puede determinar,
    // no se hace conversión aquí, solo se reformatea con wc_price para consistencia.
    if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
        // Aunque no haya conversión de valor, reformatear con wc_price en la moneda base
        // asegura que el formato (símbolo, decimales) sea el correcto para esa moneda.
        $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop); // Quitar separador de miles (asume . para miles)
        $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);   // Reemplazar coma decimal por punto
        $raw_amount_cop = floatval($cleaned_amount_string);

        if ($raw_amount_cop >= 0) {
            $formatted_price = wc_price($raw_amount_cop, array('currency' => $store_base_currency));
            return $leading_text . $formatted_price;
        }
        return $matches[0]; // Devolver original si el monto no es válido
    }

    // Convertir el string del monto (que está en COP) a un float.
    // Asumimos que el formato de número en el string es: "." como separador de miles, "," como decimal.
    // Ajustar si tu formato COP es diferente. WooCommerce internamente usa "." como decimal.
    $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop); // Quitar separador de miles (asume . para miles)
    $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);   // Reemplazar coma decimal por punto
    $raw_amount_cop = floatval($cleaned_amount_string);

    if ($raw_amount_cop >= 0) { // Incluir montos de 0.00
        // Convertir el monto de COP a la moneda seleccionada en el checkout usando WOOCS.
        $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);

        // Formatear el monto convertido usando wc_price con la moneda de checkout.
        $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));

        // Reconstruir el string.
        return $leading_text . $converted_price_html;
    }

    return $matches[0]; // Devolver el string original si algo falla.
}

// --- Filtros para los strings de "ova-tour-booking" en carrito/checkout ---

/**
 * Filtra el subtotal del ítem en el carrito/checkout para los tours de "ova-tour-booking".
 * Esto afecta a strings como "(7% deposit of $1.860.000,00)" y "Remaining: $1.200.000,00".
 */
add_filter('ovatb_cart_item_subtotal', 'lc_filter_ovatb_strings_in_cart_checkout', 30, 4);
add_filter('ovatb_order_formatted_line_subtotal', 'lc_filter_ovatb_strings_in_cart_checkout', 30, 3); // Para página de "pedido recibido" y correos.
function lc_filter_ovatb_strings_in_cart_checkout($html_content_input, $arg2 = null, $arg3 = null, $arg4 = null) {
    // Este filtro se aplica tanto a 'ovatb_cart_item_subtotal' (4 args) como a 'ovatb_order_formatted_line_subtotal' (3 args).
    // El contenido HTML relevante es siempre el primer argumento.
    $html_content = $html_content_input;

    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout() && !is_wc_endpoint_url('view-order'))) {
        // Solo actuar en carrito, checkout, o página de ver pedido, y si WOOCS está activo.
        // is_wc_endpoint_url('view-order') es para la página de "pedido recibido/ver pedido".
        return $html_content;
    }

    // Patrón de Regex para capturar los montos en los strings de "ova-tour-booking".
    // Grupo 1: El texto que precede al precio (ej. "(7% deposit of ", "<dt>Remaining:</dt><dd...>")
    // Grupo 2: El monto numérico (ej. "1.860.000,00")
    // Este patrón busca el span generado por wc_price y captura el texto anterior relevante.
    $price_string_pattern = '/((?:\(\s*(?:\d+%\s+)?deposit of\s+)|(?:<dt>Remaining:<\/dt><dd[^>]*>))(?:[A-Z]{0,3})?(?:&nbsp;|\s)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.]+)<\/bdi><\/span>/';

    $html_content_procesado = preg_replace_callback($price_string_pattern, 'lc_convert_price_string_callback_woocs', $html_content);
    
    if (null === $html_content_procesado) {
        // Si preg_replace_callback falla (ej. error en regex), devolver el original para evitar errores fatales.
        // Considera loguear el error aquí.
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_strings_in_cart_checkout. Patrón: " . $price_string_pattern);
        return $html_content;
    }

    return $html_content_procesado;
}

/**
 * Filtra el HTML del total restante en la tabla de totales del carrito/checkout.
 */
add_filter('ovatb_get_cart_remaining_totals_html', 'lc_filter_ovatb_remaining_total_cart_table', 30, 1);
function lc_filter_ovatb_remaining_total_cart_table($html_value) {
    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout())) {
        return $html_value;
    }

    // Patrón para el total restante dentro de la etiqueta <strong>: "<strong>$1.000.000,00</strong>"
    // Grupo 1: El monto numérico.
    $pattern = '/<strong>(?:[A-Z]{0,3})?(?:&nbsp;|\s)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.]+)<\/bdi><\/span><\/strong>/';

    $html_value_procesado = preg_replace_callback($pattern, function($matches) {
        $amount_string_from_cop = $matches[1]; // El monto "1.860.000,00"

        $store_base_currency = lc_get_store_base_currency();
        $selected_checkout_currency = lc_get_selected_checkout_currency_woocs();

        if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
            $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop);
            $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);
            $raw_amount_cop = floatval($cleaned_amount_string);
            if ($raw_amount_cop >= 0) {
                return '<strong>' . wc_price($raw_amount_cop, array('currency' => $store_base_currency)) . '</strong>';
            }
            return $matches[0];
        }

        $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop);
        $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);
        $raw_amount_cop = floatval($cleaned_amount_string);

        if ($raw_amount_cop >= 0) {
            $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);
            $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));
            return '<strong>' . $converted_price_html . '</strong>';
        }
        return $matches[0];
    }, $html_value);

    if (null === $html_value_procesado) {
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_remaining_total_cart_table. Patrón: " . $pattern);
        return $html_value;
    }
    return $html_value_procesado;
}