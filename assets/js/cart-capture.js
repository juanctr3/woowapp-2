/**
 * WooWApp - Sistema de Captura de Carrito Abandonado
 * Detección automática de campos y formularios - 100% Universal
 * 
 * @package WooWApp
 * @version 2.2.2
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // ==========================================
    // 🔍 CONFIGURACIÓN DEL SERVIDOR
    // ==========================================
    
    const SERVER_CONFIG = {
        type: document.documentElement.getAttribute('data-server-type') || 'unknown',
        debug: document.documentElement.getAttribute('data-wse-debug') === 'true',
        ajaxUrl: (window.wseProCapture && window.wseProCapture.ajax_url) || '/wp-admin/admin-ajax.php',
        nonce: (window.wseProCapture && window.wseProCapture.nonce) || '',
    };

    if (SERVER_CONFIG.debug) {
        console.log('%c🚀 WooWApp - Modo Debug Activo', 'color: #f59e0b; font-weight: bold; font-size: 14px');
        console.log('🖥️  Servidor:', SERVER_CONFIG.type);
        console.log('🔗 AJAX URL:', SERVER_CONFIG.ajaxUrl);
        console.log('✅ Nonce presente:', !!SERVER_CONFIG.nonce);
    }

    let isProcessing = false;
    let previousData = null; // Nuevo: Caché para datos previos

    // ==========================================
    // 📊 SELECTORES DE CAMPOS - CONFIGURACIÓN BASE
    // ==========================================
    
    let FIELD_SELECTORS = {
        billing_email: [
            '#billing_email',
            '#billing-email',
            'input[name="billing_email"]',
            '.woocommerce-billing-email input',
            'input[type="email"][name*="billing"]',
            '[data-field-name="billing_email"]',
            '[aria-label*="email"]',
            '[aria-label*="correo"]',
            '[placeholder*="email"]',
            '[placeholder*="correo"]',
        ],
        billing_phone: [
            '#billing_phone',
            '#billing-phone',
            'input[name="billing_phone"]',
            'input[name="phone"]',
            '.woocommerce-billing-phone input',
            '[data-field-name="billing_phone"]',
            '[aria-label*="teléfono"]',
            '[aria-label*="phone"]',
            '[aria-label*="celular"]',
            '[placeholder*="teléfono"]',
            '[placeholder*="phone"]',
            '[placeholder*="celular"]',
            'input[type="tel"]',
        ],
        billing_first_name: [
            '#billing_first_name',
            '#billing-first-name',
            'input[name="billing_first_name"]',
            '.woocommerce-billing-first_name input',
            '[data-field-name="billing_first_name"]',
            '[aria-label*="nombre"]',
            '[aria-label*="first name"]',
            '[placeholder*="nombre"]',
            '[placeholder*="first name"]',
        ],
        billing_last_name: [
            '#billing_last_name',
            '#billing-last-name',
            'input[name="billing_last_name"]',
            '.woocommerce-billing-last_name input',
            '[data-field-name="billing_last_name"]',
            '[aria-label*="apellido"]',
            '[aria-label*="last name"]',
            '[placeholder*="apellido"]',
            '[placeholder*="last name"]',
        ],
        billing_address_1: [
            '#billing_address_1',
            '#billing-address-1',
            'textarea[name="billing_address_1"]',
            'input[name="billing_address_1"]',
            '.woocommerce-billing-address-1 input',
            '.woocommerce-billing-address-1 textarea',
            '[data-field-name="billing_address_1"]',
            '[aria-label*="dirección"]',
            '[aria-label*="address"]',
            '[placeholder*="dirección"]',
            '[placeholder*="address"]',
            '[placeholder*="calle"]',
        ],
        billing_city: [
            '#billing_city',
            '#billing-city',
            'input[name="billing_city"]',
            '.woocommerce-billing-city input',
            '[data-field-name="billing_city"]',
            '[aria-label*="ciudad"]',
            '[aria-label*="city"]',
            '[placeholder*="ciudad"]',
            '[placeholder*="city"]',
        ],
        billing_state: [
            '#billing_state',
            '#billing-state',
            'select[name="billing_state"]',
            'input[name="billing_state"]',
            '.woocommerce-billing-state select',
            '.woocommerce-billing-state input',
            '[data-field-name="billing_state"]',
            '[aria-label*="estado"]',
            '[aria-label*="state"]',
            '[aria-label*="provincia"]',
            '[aria-label*="departamento"]',
            '[placeholder*="estado"]',
            '[placeholder*="state"]',
        ],
        billing_postcode: [
            '#billing_postcode',
            '#billing-postcode',
            'input[name="billing_postcode"]',
            '.woocommerce-billing-postcode input',
            '[data-field-name="billing_postcode"]',
            '[aria-label*="código postal"]',
            '[aria-label*="postcode"]',
            '[aria-label*="zip"]',
            '[placeholder*="código postal"]',
            '[placeholder*="postcode"]',
            '[placeholder*="zip"]',
        ],
        billing_country: [
            '#billing_country',
            '#billing-country',
            'select[name="billing_country"]',
            'input[name="billing_country"]',
            '.woocommerce-billing-country select',
            '.woocommerce-billing-country input',
            '[data-field-name="billing_country"]',
            '[aria-label*="país"]',
            '[aria-label*="country"]',
            '[placeholder*="país"]',
            '[placeholder*="country"]',
        ],
    };

    // ==========================================
    // 🔄 USAR CONFIGURACIÓN PERSONALIZADA SI EXISTE
    // ==========================================
    
    if (typeof wseFieldConfig !== 'undefined' && wseFieldConfig && Object.keys(wseFieldConfig).length > 0) {
        if (SERVER_CONFIG.debug) {
            console.log('%c📋 Config personalizada encontrada', 'color: #10b981', wseFieldConfig);
        }
        
        // Mezclar config personalizada con la base (personalizada tiene prioridad)
        FIELD_SELECTORS = Object.assign({}, FIELD_SELECTORS, wseFieldConfig);
    }

    if (SERVER_CONFIG.debug) {
        console.log('%c📊 Selectores de campos cargados', 'color: #6366f1', FIELD_SELECTORS);
    }

    // ==========================================
    // 🔍 DETECCIÓN UNIVERSAL DE FORMULARIOS
    // ==========================================

    /**
     * 🎯 Encontrar formulario de checkout - UNIVERSAL
     * Intenta múltiples métodos de localización
     */
    function findCheckoutForm() {
        // Lista de selectores a probar EN ORDEN DE CONFIABILIDAD
        const formSelectors = [
            // WooCommerce clásico
            'form.checkout',
            
            // WooCommerce Blocks
            'form.wc-block-checkout__form',
            'form.wp-block-woocommerce-checkout-form',
            
            // Otros temas/plugins
            'form.woocommerce-checkout',
            'form[name="checkout"]',
            '[data-checkout] form',
            
            // Contenedores genéricos
            '[data-checkout]',
            '.checkout-form',
            '.checkout-container',
            '.woocommerce-checkout',
            
            // Si nada de lo anterior funciona, buscar formulario con campos de billing
            'form:has(input[name="billing_email"], input[name="billing_phone"])',
            'div:has(input[name="billing_email"], input[name="billing_phone"])',
        ];

        for (let selector of formSelectors) {
            try {
                const $form = $(selector).first();
                if ($form.length) {
                    if (SERVER_CONFIG.debug) {
                        console.log(`✅ Formulario encontrado con selector: ${selector}`);
                    }
                    return $form;
                }
            } catch (e) {
                // Selector inválido, continuar
            }
        }

        if (SERVER_CONFIG.debug) {
            console.warn('⚠️  No se encontró formulario de checkout');
        }
        return null;
    }

    /**
     * 🔍 Encontrar elemento por múltiples selectores
     * Intenta cada selector hasta encontrar el campo
     */
    function findField(fieldName) {
        const selectors = FIELD_SELECTORS[fieldName];
        
        if (!selectors) {
            if (SERVER_CONFIG.debug) {
                console.warn(`⚠️  No hay selectores configurados para: ${fieldName}`);
            }
            return null;
        }

        for (let i = 0; i < selectors.length; i++) {
            const selector = selectors[i];
            
            try {
                const $el = $(selector).first();
                
                // Verificar que el elemento existe y es visible
                if ($el.length && ($el.is(':visible') || $el.attr('type') === 'hidden')) {
                    if (SERVER_CONFIG.debug) {
                        console.log(`✅ ${fieldName} encontrado con selector: ${selector}`);
                    }
                    return $el;
                }
            } catch (e) {
                // Selector CSS inválido, continuar con el siguiente
            }
        }

        if (SERVER_CONFIG.debug) {
            console.warn(`❌ Campo NO encontrado: ${fieldName}`);
        }
        return null;
    }

    /**
     * Obtener valor de un campo
     */
    function getFieldValue(fieldName) {
        const $field = findField(fieldName);
        
        if (!$field) {
            return '';
        }

        let value = $field.val() || '';
        value = String(value).trim();

        if (SERVER_CONFIG.debug && value) {
            console.log(`✅ ${fieldName}: "${value}"`);
        }

        return value;
    }

    /**
     * Diagnosticar y reportar campos encontrados
     */
    function diagnoseFields() {
        if (!SERVER_CONFIG.debug) {
            return;
        }

        console.group('%c🔍 DIAGNÓSTICO DE CAMPOS', 'color: #6366f1; font-weight: bold; font-size: 12px');
        
        const requiredFields = [
            'billing_email',
            'billing_phone',
            'billing_first_name',
            'billing_last_name',
            'billing_address_1',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
        ];

        let foundCount = 0;

        requiredFields.forEach(fieldName => {
            const $field = findField(fieldName);
            const found = $field && $field.length > 0;
            const value = found ? $field.val() : 'N/A';
            
            if (found) foundCount++;
            
            console.log(
                `${found ? '✅' : '❌'} ${fieldName}`,
                {
                    encontrado: found,
                    visible: found && $field.is(':visible'),
                    tipo: found ? $field.prop('tagName') : 'N/A',
                    valor: value
                }
            );
        });

        console.log(`\n📊 Total de campos encontrados: ${foundCount}/${requiredFields.length}`);
        console.groupEnd();
    }

    // ==========================================
    // 🌐 ENVÍO DE DATOS AL SERVIDOR
    // ==========================================

    /**
     * Enviar datos capturados al servidor
     */
    function sendCaptureData(data) {
        return new Promise((resolve) => {
            if (SERVER_CONFIG.debug) {
                console.log('%c📤 Iniciando AJAX', 'color: #6366f1', {
                    url: SERVER_CONFIG.ajaxUrl,
                    action: data.action,
                    nonce: !!data.nonce,
                });
            }

            $.ajax({
                url: SERVER_CONFIG.ajaxUrl,
                type: 'POST',
                data: data,
                timeout: 15000,
                cache: false,
                dataType: 'json',

                // ✅ Petición exitosa
                success: function(response) {
                    if (SERVER_CONFIG.debug) {
                        console.log('%c✅ Respuesta del servidor', 'color: #10b981', response);
                    }
                    
                    if (response && response.success && response.data && response.data.captured) {
                        if (SERVER_CONFIG.debug) {
                            console.log('%c🎉 Datos capturados exitosamente', 'color: #10b981; font-weight: bold');
                        }
                    }
                    
                    resolve(true);
                },

                // ❌ Error en la petición
                error: function(xhr, status, error) {
                    if (SERVER_CONFIG.debug) {
                        console.error('%c❌ Error AJAX', 'color: #ef4444; font-weight: bold', {
                            status: status,
                            error: error,
                            httpStatus: xhr.status,
                            responseText: xhr.responseText ? xhr.responseText.substring(0, 200) : 'N/A'
                        });
                    }
                    
                    resolve(false);
                },

                // Timeout
                timeout: function() {
                    if (SERVER_CONFIG.debug) {
                        console.warn('%c⏱️  Timeout - Petición tardó más de 15s', 'color: #f59e0b');
                    }
                    resolve(false);
                }
            });
        });
    }

    // ==========================================
    // 📤 CAPTURA Y ENVÍO DE DATOS
    // ==========================================

    /**
     * Capturar datos del formulario y enviar al servidor
     */
    async function captureAndSend() {
        // Evitar procesar mientras ya se está procesando
        if (isProcessing) {
            if (SERVER_CONFIG.debug) {
                console.log('⏳ Ya hay un proceso en curso, esperando...');
            }
            return;
        }

        // Recolectar datos de todos los campos
        const data = {
            action: 'wse_pro_capture_cart',
            billing_email: getFieldValue('billing_email'),
            billing_phone: getFieldValue('billing_phone'),
            billing_first_name: getFieldValue('billing_first_name'),
            billing_last_name: getFieldValue('billing_last_name'),
            billing_address_1: getFieldValue('billing_address_1'),
            billing_city: getFieldValue('billing_city'),
            billing_state: getFieldValue('billing_state'),
            billing_postcode: getFieldValue('billing_postcode'),
            billing_country: getFieldValue('billing_country'),
        };

        // Validación: Al menos email O teléfono
        if (!data.billing_email && !data.billing_phone) {
            if (SERVER_CONFIG.debug) {
                console.log('⏭️  Sin email ni teléfono - No capturar');
            }
            return;
        }

        // Nuevo: Comparar con datos previos para evitar envíos duplicados
        const currentDataJson = JSON.stringify(data);
        if (previousData && currentDataJson === previousData) {
            if (SERVER_CONFIG.debug) {
                console.log('⏭️  Datos iguales a los previos - No enviar');
            }
            return;
        }

        // Actualizar caché
        previousData = currentDataJson;

        // Añadir nonce si existe
        if (SERVER_CONFIG.nonce) {
            data.nonce = SERVER_CONFIG.nonce;
        }

        isProcessing = true;

        if (SERVER_CONFIG.debug) {
            console.log('%c📤 Enviando datos...', 'color: #6366f1; font-weight: bold', data);
        }

        await sendCaptureData(data);

        isProcessing = false;
    }

    // ==========================================
    // 🎯 ADJUNTAR LISTENERS A CAMPOS
    // ==========================================

    /**
     * Adjuntar event listeners a todos los campos
     */
    function attachListeners() {
        if (SERVER_CONFIG.debug) {
            console.log('%c🔌 Adjuntando listeners a campos...', 'color: #6366f1');
        }

        let listenersAttached = 0;

        // Iterar sobre cada campo
        Object.keys(FIELD_SELECTORS).forEach(fieldName => {
            const selectors = FIELD_SELECTORS[fieldName];
            
            selectors.forEach(selector => {
                try {
                    // Remover listeners anteriores (si existen)
                    $(document).off('change blur input', selector);
                    
                    // Adjuntar nuevos listeners
                    $(document).on('change blur input', selector, function() {
                        if (SERVER_CONFIG.debug) {
                            console.log(`👁️  Campo cambió: ${fieldName}`);
                        }
                        
                        // Debounce: Esperar 3 segundos antes de capturar (aumentado para menos envíos)
                        clearTimeout(window.captureDebounceTimer);
                        window.captureDebounceTimer = setTimeout(captureAndSend, 3000);
                    });

                    listenersAttached++;
                } catch (e) {
                    // Selector CSS inválido, ignorar
                }
            });
        });

        // Listener para Select2 (usado por WooCommerce)
        $(document).off('select2:select').on('select2:select', 'select[name*="billing"]', function() {
            if (SERVER_CONFIG.debug) {
                console.log('✓ Select2 cambió');
            }
            clearTimeout(window.captureDebounceTimer);
            window.captureDebounceTimer = setTimeout(captureAndSend, 1500);
        });

        // Listener para actualizaciones de checkout (WooCommerce AJAX)
        $(document.body).off('updated_checkout').on('updated_checkout', function() {
            if (SERVER_CONFIG.debug) {
                console.log('%c🔄 Evento: Checkout actualizado', 'color: #6366f1');
            }
            clearTimeout(window.captureDebounceTimer);
            window.captureDebounceTimer = setTimeout(captureAndSend, 2500);
        });

        // Listener para cambios en bloques (WooCommerce Blocks)
        $(document.body).off('change').on('change', '[data-wc-on-change]', function() {
            if (SERVER_CONFIG.debug) {
                console.log('%c🔄 Evento: Bloque WooCommerce cambió', 'color: #6366f1');
            }
            clearTimeout(window.captureDebounceTimer);
            window.captureDebounceTimer = setTimeout(captureAndSend, 2000);
        });

        if (SERVER_CONFIG.debug) {
            console.log(`%c✅ ${listenersAttached} listeners adjuntados correctamente`, 'color: #10b981');
        }
    }

    // ==========================================
    // 🚀 INICIALIZACIÓN
    // ==========================================

    /**
     * Inicializar el script
     */
    function init() {
        // Buscar formulario de checkout (UNIVERSAL)
        const $checkoutForm = findCheckoutForm();

        if (!$checkoutForm || $checkoutForm.length === 0) {
            if (SERVER_CONFIG.debug) {
                console.log('⏳ Formulario de checkout no encontrado aún. Esperando...');
            }
            return;
        }

        if (SERVER_CONFIG.debug) {
            console.log('%c✅ Formulario de checkout ENCONTRADO', 'color: #10b981; font-weight: bold');
        }

        // Diagnosticar campos disponibles
        diagnoseFields();

        // Adjuntar listeners a campos
        attachListeners();

        // Primera captura después de 3 segundos (para llenar datos existentes)
        setTimeout(() => {
            if (SERVER_CONFIG.debug) {
                console.log('%c📌 Ejecutando captura inicial', 'color: #f59e0b');
            }
            captureAndSend();
        }, 3000);

        // Captura periódica cada 60 segundos (aumentado para menos envíos)
        setInterval(() => {
            if (SERVER_CONFIG.debug) {
                console.log('%c⏰ Captura periódica', 'color: #f59e0b');
            }
            captureAndSend();
        }, 60000);

        if (SERVER_CONFIG.debug) {
            console.log('%c🎉 WooWApp inicializado correctamente', 'color: #10b981; font-weight: bold; font-size: 14px');
        }
    }

    // ==========================================
    // 🔄 TRIGGER DE INICIALIZACIÓN
    // ==========================================

    // Si el documento aún se está cargando, esperar
    if (document.readyState === 'loading') {
        $(document).on('DOMContentLoaded', init);
    } else {
        // Si ya está cargado, inicializar inmediatamente
        init();
    }

    // También iniciar cuando el checkout se actualice (WooCommerce AJAX)
    $(document.body).on('updated_checkout', function() {
        if (!window.wseInitialized) {
            window.wseInitialized = true;
            if (SERVER_CONFIG.debug) {
                console.log('%c🔄 Inicialización por evento updated_checkout', 'color: #6366f1');
            }
            init();
        }
    });

    // Listener para Blocks
    $(document.body).on('wc_blocks_loaded', function() {
        if (!window.wseInitialized) {
            window.wseInitialized = true;
            if (SERVER_CONFIG.debug) {
                console.log('%c🔄 Inicialización por evento wc_blocks_loaded', 'color: #6366f1');
            }
            init();
        }
    });

    // ==========================================
    // 🧪 MODO TEST (DEBUG)
    // ==========================================

    if (SERVER_CONFIG.debug) {
        // Exponer función de prueba en consola
        window.wseTestFields = function() {
            console.log('%c🧪 TEST DE CAMPOS', 'color: #f59e0b; font-weight: bold; font-size: 14px');
            diagnoseFields();
            console.log('%c📤 Enviando datos de prueba...', 'color: #f59e0b');
            captureAndSend();
        };

        console.log('%c💡 Tip: Escribe wseTestFields() en la consola para probar la captura', 'color: #f59e0b; font-style: italic');
    }
});
