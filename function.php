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
    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
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

    // En carrito o checkout, o páginas de pago/pedido recibido, permitir que WOOCS determine la moneda.
    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
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
        // Asegurarse de que el monto sea un float para WOOCS
        $amount_in_base_currency = floatval($amount_in_base_currency);

        if (!class_exists('WOOCS') || empty($amount_in_base_currency) || !is_numeric($amount_in_base_currency)) {
            // Si el monto es 0, o WOOCS no está, o no es numérico, devolverlo tal cual.
             if ($amount_in_base_currency === 0.0) return 0.0;
            return $amount_in_base_currency;
        }


        global $WOOCS;
        $store_base_currency = lc_get_store_base_currency(); // ej. COP

        if ($target_currency_code === $store_base_currency) {
            return $amount_in_base_currency; // No se necesita conversión.
        }
        
        // Usar woocs_exchange_value para convertir de la moneda base a la moneda objetivo.
        $converted_amount = $WOOCS->woocs_exchange_value($amount_in_base_currency, $store_base_currency, $target_currency_code);
        
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
    // $matches[1] es el texto previo al precio, ej: "(7% deposit of " o "<dt>Remaining:</dt><dd...>"
    // $matches[2] es el monto numérico, ej: "1.860.000,00"
    $leading_text = $matches[1];
    $amount_string_from_cop = $matches[2];

    $store_base_currency = lc_get_store_base_currency(); // ej. COP
    $selected_checkout_currency = lc_get_selected_checkout_currency_woocs(); // ej. USD

    // Convertir el string del monto (que está en COP) a un float.
    // Asumimos que el formato de número en el string es: "." como separador de miles, "," como decimal.
    $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop);
    $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);
    $raw_amount_cop = floatval($cleaned_amount_string);

    if ($selected_checkout_currency === $store_base_currency || empty($selected_checkout_currency)) {
        if ($raw_amount_cop >= 0) { // Incluye 0.00
            $formatted_price = wc_price($raw_amount_cop, array('currency' => $store_base_currency));
            return $leading_text . $formatted_price;
        }
        return $matches[0];
    }


    if ($raw_amount_cop >= 0) { // Incluir montos de 0.00
        $converted_amount = lc_convert_from_base_to_target_woocs($raw_amount_cop, $selected_checkout_currency);
        $converted_price_html = wc_price($converted_amount, array('currency' => $selected_checkout_currency));
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
    $html_content = $html_content_input;

    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout() && !is_wc_endpoint_url('view-order') && !(is_admin() && defined('DOING_AJAX') && $_REQUEST['action'] === 'woocommerce_load_order_items'))) {
        // Solo actuar en carrito, checkout, o página de ver pedido, y si WOOCS está activo.
        // También en la carga de items de pedido en el admin via AJAX para emails.
        return $html_content;
    }

    $price_string_pattern = '/((?:\(\s*(?:\d+%\s+)?deposit of\s+)|(?:<dt>Remaining:<\/dt><dd[^>]*>))(?:[A-Z]{0,3})?(?:&nbsp;|\s)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.]+)<\/bdi><\/span>/';
    $html_content_procesado = preg_replace_callback($price_string_pattern, 'lc_convert_price_string_callback_woocs', $html_content);
    
    if (null === $html_content_procesado && preg_last_error() !== PREG_NO_ERROR) {
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_strings_in_cart_checkout. Error: " . preg_last_error_msg() . " Patrón: " . $price_string_pattern);
        return $html_content;
    }

    return $html_content_procesado ?: $html_content; // Devuelve el procesado o el original si el procesado es null/false
}

/**
 * Filtra el HTML del total restante en la tabla de totales del carrito/checkout.
 */
add_filter('ovatb_get_cart_remaining_totals_html', 'lc_filter_ovatb_remaining_total_cart_table', 30, 1);
function lc_filter_ovatb_remaining_total_cart_table($html_value) {
    if (!class_exists('WOOCS') || (!is_cart() && !is_checkout())) {
        return $html_value;
    }

    $pattern = '/<strong>(?:[A-Z]{0,3})?(?:&nbsp;|\s)?<span class="woocommerce-Price-amount amount"><bdi>([0-9,.]+)<\/bdi><\/span><\/strong>/';
    $html_value_procesado = preg_replace_callback($pattern, function($matches) {
        $amount_string_from_cop = $matches[1];

        $store_base_currency = lc_get_store_base_currency();
        $selected_checkout_currency = lc_get_selected_checkout_currency_woocs();
        
        $cleaned_amount_string = str_replace('.', '', $amount_string_from_cop);
        $cleaned_amount_string = str_replace(',', '.', $cleaned_amount_string);
        $raw_amount_cop = floatval($cleaned_amount_string);

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
        // error_log("LC Debug: preg_replace_callback falló en lc_filter_ovatb_remaining_total_cart_table. Error: " . preg_last_error_msg() . " Patrón: " . $pattern);
        return $html_value;
    }
    return $html_value_procesado ?: $html_value;
}


/**
 * PARTE 3: PERMITIR SOLO UN TOUR EN EL CARRITO A LA VEZ
 */

add_filter('woocommerce_add_to_cart_validation', 'lc_permitir_solo_un_tour_por_compra', 20, 3);
/**
 * Valida que solo se pueda añadir un tour al carrito si este ya contiene un tour.
 *
 * @param bool $passed Si la validación ha pasado.
 * @param int $product_id ID del producto que se está añadiendo.
 * @param int $quantity Cantidad del producto.
 * @return bool
 */
function lc_permitir_solo_un_tour_por_compra($passed, $product_id, $quantity) {
    // Obtener el tipo de producto que se está intentando añadir.
    $product_being_added = wc_get_product($product_id);
    if (!$product_being_added) {
        return false; // No se pudo obtener el producto.
    }
    $product_type_being_added = $product_being_added->get_type();

    // Verificar si ya hay un tour en el carrito.
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

    // Si se está intentando añadir un tour y ya hay un tour en el carrito.
    if ($product_type_being_added === 'ovatb_tour' && $tour_in_cart) {
        // Mostrar un aviso al usuario.
        // Traduce este mensaje según sea necesario.
        wc_add_notice(__('Solo puedes procesar la reserva de un tour a la vez. Por favor, completa o vacía tu reserva actual antes de añadir otro tour.', 'woocommerce'), 'error');
        return false; // Prevenir que se añada al carrito.
    }

    return $passed; // Permitir añadir si no se cumplen las condiciones anteriores.
}

/**
 * PARTE 4: VACIAR CARRITO SI SE ABANDONA EL CHECKOUT (CON EXCEPCIONES PARA PASARELAS)
 */

add_action('template_redirect', 'lc_vaciar_carrito_al_abandonar_checkout', 20);
/**
 * Vacía el carrito si el usuario navega fuera del checkout a una página no relacionada con el pago.
 */
function lc_vaciar_carrito_al_abandonar_checkout() {
    // Asegurarse de que WooCommerce y las sesiones están disponibles.
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    
    // Iniciar sesión si aún no está iniciada (necesario para WC()->session).
    if ( ! WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie( true );
    }

    $user_was_on_checkout = WC()->session->get('lc_user_was_on_checkout_page', false);

    // Si el usuario está actualmente en la página de checkout.
    if (is_checkout() && !is_wc_endpoint_url('order-pay') && !is_wc_endpoint_url('order-received')) {
        WC()->session->set('lc_user_was_on_checkout_page', true);
        return; // No hacer nada más.
    }

    // Si el usuario estaba en el checkout y ahora está en otra página.
    if ($user_was_on_checkout) {
        // Definir URLs o partes de URLs que indican que el usuario está en una pasarela de pago.
        // ESTA LISTA DEBE SER PERSONALIZADA SEGÚN TUS PASARELAS DE PAGO.
        $payment_gateway_urls_patterns = array(
            'mercadopago', // Ejemplo para Mercado Pago
            'paypal.com',  // Ejemplo para PayPal
            'payu.com',    // Ejemplo para PayU
            'stripe.com',  // Ejemplo para Stripe (si redirige fuera)
            // Añade aquí otras partes de URLs de tus pasarelas.
            // También considera los endpoints de WooCommerce para "pagar pedido" o "pedido recibido".
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
        
        // Si el usuario NO está en una página de pasarela de pago conocida ni en confirmación de pedido.
        if (!$is_on_payment_gateway_or_confirmation && !is_cart()) { // No vaciar si va al carrito
            if (WC()->cart && !WC()->cart->is_empty()) {
                WC()->cart->empty_cart();
                // Opcional: Mostrar un aviso al usuario.
                // Traduce este mensaje según sea necesario.
                // wc_add_notice(__('Tu carrito ha sido vaciado porque abandonaste el proceso de pago.', 'woocommerce'), 'notice');
            }
        }
        
        // En cualquier caso, si salió del checkout (y no es una página de pasarela o confirmación),
        // o si completó el pedido, o está pagando, reseteamos la bandera.
        // Si está en una pasarela, la bandera se reseteará al volver a la tienda o al completar.
        if (!$is_on_payment_gateway_or_confirmation || is_wc_endpoint_url('order-received')) {
             WC()->session->set('lc_user_was_on_checkout_page', false);
        }
    }
}

// Podrías necesitar filtros similares para otros totales si "ova-tour-booking" los añade
// de forma personalizada y no son convertidos automáticamente por WOOCS.
// Por ejemplo, si hay un total de depósito en la tabla de totales:
// add_filter('ovatb_get_cart_deposit_totals_html', 'lc_filter_ovatb_deposit_total_cart_table', 30, 1);
// function lc_filter_ovatb_deposit_total_cart_table($html_value) { ... lógica similar ... }

?>
