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

    // Si estamos en el carrito o checkout, o páginas de pago/pedido, no hacemos nada aquí, WOOCS debe manejarlo.
    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
        return;
    }

    // Si la moneda actual de WOOCS NO es la moneda base de la tienda, la restablecemos.
    if ($WOOCS->current_currency !== $moneda_base_tienda) {
        $WOOCS->set_currency($moneda_base_tienda);
    }
}

/**
 * Filtro adicional para asegurar que wc_price() use la moneda base fuera del carrito/checkout.
 */
add_filter('woocommerce_currency', 'lc_filter_woocommerce_currency_global', 9990);
function lc_filter_woocommerce_currency_global($currency_code) {
    if (is_admin() || !class_exists('WOOCS')) {
        return $currency_code;
    }

    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
        global $WOOCS;
        return $WOOCS->current_currency;
    }

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
            if (!empty($WOOCS->default_currency)) {
                return $WOOCS->default_currency;
            }
        }
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
        return get_woocommerce_currency();
    }
}

/**
 * Convierte un monto desde la moneda base de la tienda a la moneda de checkout seleccionada, usando WOOCS.
 *
 * @param float $amount_in_base_currency El monto en la moneda base (COP). Ya debe ser un float.
 * @param string $target_currency_code El código de la moneda a la que se quiere convertir.
 * @return float El monto convertido.
 */
if (!function_exists('lc_convert_from_base_to_target_woocs')) {
    function lc_convert_from_base_to_target_woocs($amount_in_base_currency, $target_currency_code) {
        // $amount_in_base_currency ya es float debido a floatval() en la función que llama.
        if (!class_exists('WOOCS') || !is_numeric($amount_in_base_currency)) {
            return $amount_in_base_currency;
        }

        global $WOOCS;
        $store_base_currency = lc_get_store_base_currency(); // Esto debería ser COP

        if ($target_currency_code === $store_base_currency) {
            return $amount_in_base_currency;
        }
        
        // Convertir explícitamente de la moneda base de la tienda a la moneda objetivo.
        $converted_amount = $WOOCS->woocs_exchange_value(floatval($amount_in_base_currency), $store_base_currency, $target_currency_code);
        
        return $converted_amount;
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
    $leading_text = $matches[1]; // Texto previo al precio
    $amount_string_from_html = $matches[2]; // String del monto desde el HTML

    $store_base_currency = lc_get_store_base_currency();
    $selected_checkout_currency = lc_get_selected_checkout_currency_woocs();

    // Limpieza robusta del string numérico usando separadores de WooCommerce
    $number_chars_only = preg_replace('/[^\d.,]+/', '', $amount_string_from_html); // Permitir solo dígitos, comas y puntos
    $decimal_sep = wc_get_price_decimal_separator();
    $thousand_sep = wc_get_price_thousand_separator();

    $cleaned_for_float = str_replace($thousand_sep, '', $number_chars_only);
    if ($decimal_sep !== '.') { // Solo reemplazar si el decimal no es ya un punto
        $cleaned_for_float = str_replace($decimal_sep, '.', $cleaned_for_float);
    }
    $raw_amount_cop = floatval($cleaned_for_float);

    // Descomentar para depuración exhaustiva:
    // error_log("LC CB: InputStr: [{$amount_string_from_html}], Cleaned: [{$cleaned_for_float}], FloatVal: [{$raw_amount_cop}], Target: [{$selected_checkout_currency}], Base: [{$store_base_currency}]");

    if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
        if ($raw_amount_cop >= 0) {
            $formatted_price = wc_price($raw_amount_cop, array('currency' => $store_base_currency));
            // error_log("LC CB (Base): Formatted: [{$formatted_price}]");
            return $leading_text . $formatted_price;
        }
        // error_log("LC CB (Base): Invalid raw_amount_cop ([{$raw_amount_cop}])");
        return $matches[0];
    }

    if ($raw_amount_cop >= 0) {
        $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);
        // error_log("LC CB (Converted): RawCOP: [{$raw_amount_cop}], Converted: [{$converted_amount}] to [{$selected_checkout_currency}]");
        $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));
        // error_log("LC CB (Converted): Formatted: [{$converted_price_html}]");
        return $leading_text . $converted_price_html;
    }
    // error_log("LC CB: Fallback, raw_amount_cop ([{$raw_amount_cop}]) < 0 or invalid");
    return $matches[0];
}

// --- Filtros para los strings de "ova-tour-booking" en carrito/checkout ---

add_filter('ovatb_cart_item_subtotal', 'lc_filter_ovatb_strings_in_cart_checkout', 30, 4);
add_filter('ovatb_order_formatted_line_subtotal', 'lc_filter_ovatb_strings_in_cart_checkout', 30, 3);
function lc_filter_ovatb_strings_in_cart_checkout($html_content_input, $arg2 = null, $arg3 = null, $arg4 = null) {
    $html_content = $html_content_input;

    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout() && !is_wc_endpoint_url('view-order') && !(is_admin() && defined('DOING_AJAX') && isset($_REQUEST['action']) && $_REQUEST['action'] === 'woocommerce_load_order_items'))) {
        return $html_content;
    }

    // Patrón mejorado para ser más flexible con espacios y códigos de moneda opcionales antes del span
    $price_string_pattern = '/((?:\(\s*(?:\d+%\s+)?deposit of\s*)|(?:<dt>Remaining:<\/dt><dd[^>]*>))(?:[A-Z]{3}\s*|\$\s*|€\s*|£\s*)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.\s]+)<\/bdi><\/span>/';
    $html_content_procesado = preg_replace_callback($price_string_pattern, 'lc_convert_price_string_callback_woocs', $html_content);
    
    if (null === $html_content_procesado && preg_last_error() !== PREG_NO_ERROR) {
        // Descomentar para depuración:
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_strings_in_cart_checkout. Error: " . preg_last_error_msg() . " Patrón: " . $price_string_pattern);
        return $html_content;
    }

    return $html_content_procesado ?: $html_content;
}

add_filter('ovatb_get_cart_remaining_totals_html', 'lc_filter_ovatb_remaining_total_cart_table', 30, 1);
function lc_filter_ovatb_remaining_total_cart_table($html_value) {
    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout())) {
        return $html_value;
    }

    // Patrón mejorado para ser más flexible con espacios y códigos de moneda opcionales antes del span
    $pattern = '/<strong>(?:[A-Z]{3}\s*|\$\s*|€\s*|£\s*)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.\s]+)<\/bdi><\/span><\/strong>/';
    $html_value_procesado = preg_replace_callback($pattern, function($matches) {
        $amount_string_from_html = $matches[1];

        $store_base_currency = lc_get_store_base_currency();
        $selected_checkout_currency = lc_get_selected_checkout_currency_woocs();
        
        $number_chars_only = preg_replace('/[^\d.,]+/', '', $amount_string_from_html);
        $decimal_sep = wc_get_price_decimal_separator();
        $thousand_sep = wc_get_price_thousand_separator();
        $cleaned_for_float = str_replace($thousand_sep, '', $number_chars_only);
        if ($decimal_sep !== '.') {
            $cleaned_for_float = str_replace($decimal_sep, '.', $cleaned_for_float);
        }
        $raw_amount_cop = floatval($cleaned_for_float);

        if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
            if ($raw_amount_cop >= 0) {
                return '<strong>' . wc_price($raw_amount_cop, array('currency' => $store_base_currency)) . '</strong>';
            }
            return $matches[0];
        }

        if ($raw_amount_cop >= 0) {
            $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);
            $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));
            return '<strong>' . $converted_price_html . '</strong>';
        }
        return $matches[0];
    }, $html_value);

    if (null === $html_value_procesado && preg_last_error() !== PREG_NO_ERROR) {
         // Descomentar para depuración:
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_remaining_total_cart_table. Error: " . preg_last_error_msg() . " Patrón: " . $pattern);
        return $html_value;
    }
    return $html_value_procesado ?: $html_value;
}

/**
 * NUEVO FILTRO: Para el total general del pedido si es modificado por ova-tour-booking.
 */
add_filter('ovatb_cart_totals_order_total_html', 'lc_filter_ovatb_full_payable_total_cart_table', 30, 1);
function lc_filter_ovatb_full_payable_total_cart_table($html_value) {
    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout())) {
        return $html_value;
    }

    // El patrón es el mismo que para el "Remaining" en la tabla de totales
    $pattern = '/<strong>(?:[A-Z]{3}\s*|\$\s*|€\s*|£\s*)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.\s]+)<\/bdi><\/span><\/strong>/';
    
    $html_value_procesado = preg_replace_callback($pattern, function($matches) {
        $amount_string_from_html = $matches[1];

        $store_base_currency = lc_get_store_base_currency();
        $selected_checkout_currency = lc_get_selected_checkout_currency_woocs();
        
        $number_chars_only = preg_replace('/[^\d.,]+/', '', $amount_string_from_html);
        $decimal_sep = wc_get_price_decimal_separator();
        $thousand_sep = wc_get_price_thousand_separator();
        $cleaned_for_float = str_replace($thousand_sep, '', $number_chars_only);
        if ($decimal_sep !== '.') {
            $cleaned_for_float = str_replace($decimal_sep, '.', $cleaned_for_float);
        }
        $raw_amount_cop = floatval($cleaned_for_float);

        if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
            if ($raw_amount_cop >= 0) {
                return '<strong>' . wc_price($raw_amount_cop, array('currency' => $store_base_currency)) . '</strong>';
            }
            return $matches[0];
        }

        if ($raw_amount_cop >= 0) {
            $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);
            $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));
            return '<strong>' . $converted_price_html . '</strong>';
        }
        return $matches[0];
    }, $html_value);

    if (null === $html_value_procesado && preg_last_error() !== PREG_NO_ERROR) {
        // Descomentar para depuración:
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_full_payable_total_cart_table. Error: " . preg_last_error_msg() . " Patrón: " . $pattern);
        return $html_value;
    }
    return $html_value_procesado ?: $html_value;
}

/** * PARTE 3: PERMITIR SOLO UN TOUR EN EL CARRITO A LA VEZ
 */

add_filter('woocommerce_add_to_cart_validation', 'lc_permitir_solo_un_tour_por_compra', 20, 3);
function lc_permitir_solo_un_tour_por_compra($passed, $product_id, $quantity) {
    $product_being_added = wc_get_product($product_id);
    if (!$product_being_added) {
        return false;
    }
    $product_type_being_added = $product_being_added->get_type();

    $tour_in_cart = false;
    if (WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $_product = $cart_item['data'];
            if ($_product && $_product->get_type() === 'ovatb_tour') {
                $tour_in_cart = true;
                break;
            }
        }
    }

    if ($product_type_being_added === 'ovatb_tour' && $tour_in_cart) {
        wc_add_notice(__('Solo puedes procesar la reserva de un tour a la vez. Por favor, completa o vacía tu reserva actual antes de añadir otro tour.', 'woocommerce'), 'error');
        return false;
    }

    return $passed;
}

/**
 * PARTE 4: VACIAR CARRITO SI SE ABANDONA EL CHECKOUT (CON EXCEPCIONES PARA PASARELAS)
 */

add_action('template_redirect', 'lc_vaciar_carrito_al_abandonar_checkout', 20);
function lc_vaciar_carrito_al_abandonar_checkout() {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    
    if ( ! WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie( true );
    }

    $user_was_on_checkout = WC()->session->get('lc_user_was_on_checkout_page', false);

    if (is_checkout() && !is_wc_endpoint_url('order-pay') && !is_wc_endpoint_url('order-received')) {
        WC()->session->set('lc_user_was_on_checkout_page', true);
        return;
    }

    if ($user_was_on_checkout) {
        $payment_gateway_urls_patterns = array(
            'mercadopago',
            'paypal.com',
            'payu.com',
            'stripe.com',
            wc_get_endpoint_url('order-pay', '', wc_get_checkout_url()),
            wc_get_endpoint_url('order-received', '', wc_get_checkout_url())
        );
        
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $is_on_payment_gateway_or_confirmation = false;

        foreach ($payment_gateway_urls_patterns as $pattern) {
            if (strpos($current_url, $pattern) !== false) {
                $is_on_payment_gateway_or_confirmation = true;
                break;
            }
        }
        
        if (!$is_on_payment_gateway_or_confirmation && !is_cart()) {
            if (WC()->cart && !WC()->cart->is_empty()) {
                WC()->cart->empty_cart();
                // wc_add_notice(__('Tu carrito ha sido vaciado porque abandonaste el proceso de pago.', 'woocommerce'), 'notice');
            }
        }
        
        if (!$is_on_payment_gateway_or_confirmation || is_wc_endpoint_url('order-received')) {
             WC()->session->set('lc_user_was_on_checkout_page', false);
        }
    }
}

remove_action('woocommerce_before_single_product', 'woocommerce_output_all_notices', 10);
remove_action('woocommerce_before_cart', 'woocommerce_output_all_notices', 10);
remove_action('woocommerce_before_checkout_form', 'woocommerce_output_all_notices', 10);
