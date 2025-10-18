jQuery(function($) {
    'use strict';

    /**
     * Esta función se activa cuando un usuario sale de un campo de contacto clave.
     * Recopila todos los datos del formulario y los envía al servidor.
     */
    var captureCartHandler = function() {
        
        // --- SELECTORES UNIVERSALES MEJORADOS ---
        // Se utilizan todos los selectores posibles para encontrar los campos de forma segura.
        // Se prioriza la búsqueda por clase de WooCommerce, que es la más estándar.
        var phone_number = $('.woocommerce-form-row--phone input, #billing_phone, #shipping-phone, #billing-phone, input[name="billing_phone"], form.checkout input[type="tel"]').val();
        var first_name = $('.woocommerce-form-row--first input, #billing_first_name, #shipping-first_name, input[name="billing_first_name"]').val();

        // Condición principal: no hacer nada si el campo de teléfono está vacío.
        if (!phone_number) {
            return;
        }

        // Serializa todos los campos del formulario de pago para poder restaurarlos después.
        var checkout_data = $('form.checkout').serialize();

        // Prepara los datos para ser enviados al servidor vía AJAX.
        var data = {
            action: 'wse_pro_capture_cart',
            security: wse_pro_frontend_params.nonce,
            phone: phone_number,
            first_name: first_name,
            checkout_data: checkout_data
        };

        console.log('WooWApp: Evento detectado. Enviando datos al servidor...', data);

        // Envía la información al servidor de forma asíncrona.
        $.post(wse_pro_frontend_params.ajax_url, data, function(response) {
            if (response.success) {
                console.log('WooWApp: Carrito capturado exitosamente por el servidor.');
            } else {
                console.log('WooWApp: El servidor devolvió un error al capturar el carrito.', response);
            }
        });
    };

    // --- LISTA DE DISPARADORES DEFINITIVA Y UNIVERSAL ---
    // Se asigna el evento "blur" a CUALQUIER campo de los que hemos identificado como posibles
    // para el nombre y el teléfono, incluyendo las clases de WooCommerce.
    var selectors = [
        // Selectores de Teléfono (del más específico al más genérico)
        '.woocommerce-form-row--phone input', // El más fiable
        '#billing_phone', 
        '#shipping-phone', 
        '#billing-phone', 
        'input[name="billing_phone"]', 
        'form.checkout input[type="tel"]',
        // Selectores de Nombre
        '.woocommerce-form-row--first input', // El más fiable
        '#billing_first_name', 
        '#shipping-first_name', 
        'input[name="billing_first_name"]'
    ].join(', ');

    $(document.body).on('blur', selectors, captureCartHandler);

    console.log('WooWApp: Script de captura de carrito cargado y listo.');
});
