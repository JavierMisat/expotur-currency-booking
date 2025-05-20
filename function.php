<?php
if (! defined('WP_DEBUG')) {
	die( 'Direct access forbidden.' );
}
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
});

/**
 * Redirigir directamente a la página de pago después de agregar un producto al carrito.
 */
add_filter( 'woocommerce_add_to_cart_redirect', 'custom_redirect_to_checkout_after_add_to_cart' );

function custom_redirect_to_checkout_after_add_to_cart() {
    // Obtener la URL de la página de pago.
    $checkout_url = wc_get_checkout_url();

    // Redirigir a la página de pago.
    return $checkout_url;
}



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

add_action('woocommerce_checkout_before_customer_details', function () {
	if (class_exists('WOOCS')) {
        echo '<fieldset class="woocommerce-billing-fields__field-wrapper" style="margin-bottom: 20px;">
                <div class="form-row form-row-wide">
                    <label><strong>' . __('Selecciona tu moneda de pago', 'woocommerce') . '</strong></label>';
        echo do_shortcode('[woocs sd=1]');
        echo '  </div>
              </fieldset>';
    }
});

// 5. Vaciar carrito si el usuario intenta agregar un nuevo tour (solo se permite uno a la vez)
add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $quantity) {
    if (!WC()->cart->is_empty()) {
        WC()->cart->empty_cart();
        wc_add_notice(__('Solo puedes reservar un tour por compra. Tu carrito anterior ha sido reiniciado.'), 'notice');
    }
    return $passed;
}, 10, 3);


/**
 * PARTE 5: OCULTAR MENSAJE "AÑADIDO AL CARRITO" DE WOOCOMMERCE
 */
add_filter('wc_add_to_cart_message_html', '__return_false');