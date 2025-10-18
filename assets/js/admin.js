jQuery(function($) {
    'use strict';

    /**
     * Lógica para mostrar/ocultar campos de configuración de API dinámicamente.
     * CON ANIMACIONES SUAVES
     */
    function toggleApiFields() {
        var selectedPanel = $('#wse_pro_api_panel_selection').val();

        // Oculta con animación
        $('tr:has([data-panel])').slideUp(300);
        
        // Pequeño delay para evitar parpadeos
        setTimeout(function() {
            var panelRows = $('tr:has([data-panel="' + selectedPanel + '"])');
            panelRows.slideDown(400);

            if (selectedPanel === 'panel1') {
                var selectedMessageType = $('#wse_pro_message_type_panel1').val();
                
                panelRows.filter(':has([data-msg-type])').slideUp(300);
                
                setTimeout(function() {
                    panelRows.filter(':has([data-msg-type="' + selectedMessageType + '"])').slideDown(400);
                }, 350);
            }
        }, 350);
    }

    // Estado inicial con animación
    setTimeout(toggleApiFields, 100);

    // Cambios con animación
    $('#wse_pro_api_panel_selection, #wse_pro_message_type_panel1').on('change', function() {
        toggleApiFields();
    });

    /**
     * Lógica del Acordeón con animaciones mejoradas
     */
    $(document.body).on('click', '.wc-wa-accordion-trigger', function() {
        var $trigger = $(this);
        var $content = $trigger.next('.wc-wa-accordion-content');
        
        // Toggle de la clase active
        $trigger.toggleClass('active');
        
        // Animación más suave
        $content.slideToggle(400, 'swing', function() {
            // Scroll suave si se abre
            if ($content.is(':visible')) {
                $('html, body').animate({
                    scrollTop: $trigger.offset().top - 100
                }, 300);
            }
        });
        
        // Animación del icono
        $trigger.find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    /**
     * Insertar variables y emojis con feedback visual
     */
    $(document.body).on('click', '.wc-wa-accordion-content button', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var targetId = $button.closest('.wc-wa-accordion-content').data('target-id');
        var $textarea = $('#' + targetId);
        var textToInsert = $button.data('value');
        
        if (!$textarea.length) {
            console.error('Textarea target not found: ' + targetId);
            showNotification('Error al insertar', 'error');
            return;
        }

        var cursorPos = $textarea.prop('selectionStart');
        var currentVal = $textarea.val();
        var textBefore = currentVal.substring(0, cursorPos);
        var textAfter = currentVal.substring(cursorPos, currentVal.length);

        // Insertar texto
        $textarea.val(textBefore + textToInsert + textAfter);
        $textarea.focus();
        $textarea.prop('selectionStart', cursorPos + textToInsert.length);
        $textarea.prop('selectionEnd', cursorPos + textToInsert.length);
        
        // Efecto visual en el botón
        $button.addClass('button-clicked');
        setTimeout(function() {
            $button.removeClass('button-clicked');
        }, 200);
        
        // Feedback visual en el textarea
        $textarea.addClass('textarea-updated');
        setTimeout(function() {
            $textarea.removeClass('textarea-updated');
        }, 500);
    });

    /**
     * Botón de Prueba de Envío con mejor feedback visual
     */
    $('#wse_pro_send_test_button').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $statusSpan = $('#test_send_status');
        var testNumber = $('#wse_pro_test_number').val();

        if (!testNumber) {
            showNotification('Por favor, ingresa un número de teléfono para la prueba.', 'error');
            $('#wse_pro_test_number').addClass('input-error').focus();
            setTimeout(function() {
                $('#wse_pro_test_number').removeClass('input-error');
            }, 2000);
            return;
        }

        // Estado de carga
        $button.prop('disabled', true).addClass('button-loading');
        $statusSpan.html('<span class="spinner is-active" style="float:left; margin-top:2px;"></span> Enviando...')
            .css({
                'color': '#6366f1',
                'background': '#eef2ff',
                'padding': '8px 16px',
                'border-radius': '8px'
            })
            .fadeIn(300);

        $.ajax({
            url: wse_pro_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wse_pro_send_test',
                security: wse_pro_admin_params.nonce,
                test_number: testNumber
            },
            success: function(response) {
                if (response.success && response.data.success) {
                    $statusSpan.html('✓ ' + response.data.message)
                        .css({
                            'color': 'white',
                            'background': 'linear-gradient(135deg, #10b981, #059669)',
                            'box-shadow': '0 4px 6px rgba(16, 185, 129, 0.3)'
                        });
                    showNotification('✓ Mensaje enviado correctamente', 'success');
                } else {
                    var errorMessage = response.data.message ? response.data.message : 'Error desconocido.';
                    $statusSpan.html('✗ ' + errorMessage)
                        .css({
                            'color': 'white',
                            'background': 'linear-gradient(135deg, #ef4444, #dc2626)',
                            'box-shadow': '0 4px 6px rgba(239, 68, 68, 0.3)'
                        });
                    showNotification('✗ ' + errorMessage, 'error');
                }
            },
            error: function() {
                $statusSpan.html('✗ Error de conexión con el servidor.')
                    .css({
                        'color': 'white',
                        'background': 'linear-gradient(135deg, #ef4444, #dc2626)',
                        'box-shadow': '0 4px 6px rgba(239, 68, 68, 0.3)'
                    });
                showNotification('✗ Error de conexión', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('button-loading');
                $statusSpan.find('.spinner').remove();
            }
        });
    });

    /**
     * Sistema de notificaciones toast
     */
    function showNotification(message, type) {
        type = type || 'info';
        
        var bgColor = {
            'success': 'linear-gradient(135deg, #10b981, #059669)',
            'error': 'linear-gradient(135deg, #ef4444, #dc2626)',
            'warning': 'linear-gradient(135deg, #f59e0b, #d97706)',
            'info': 'linear-gradient(135deg, #6366f1, #4f46e5)'
        };

        var $notification = $('<div class="woowapp-notification">')
            .html(message)
            .css({
                'position': 'fixed',
                'top': '20px',
                'right': '20px',
                'background': bgColor[type],
                'color': 'white',
                'padding': '16px 24px',
                'border-radius': '8px',
                'box-shadow': '0 10px 25px rgba(0,0,0,0.3)',
                'z-index': 99999,
                'font-weight': '600',
                'max-width': '400px',
                'animation': 'slideInRight 0.4s ease-out',
                'cursor': 'pointer'
            })
            .appendTo('body')
            .hide()
            .fadeIn(300);

        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 4000);

        $notification.on('click', function() {
            $(this).fadeOut(200, function() {
                $(this).remove();
            });
        });
    }

    /**
     * Validación en tiempo real de campos
     */
    $('#wse_pro_test_number, #wse_pro_from_number, #billing_phone').on('blur', function() {
        var $input = $(this);
        var value = $input.val();
        
        if (value && !/^\d+$/.test(value)) {
            $input.addClass('input-error');
            showNotification('El número debe contener solo dígitos', 'warning');
        } else {
            $input.removeClass('input-error');
        }
    });

    /**
     * Contador de caracteres para textareas
     */
    $('.wse-pro-textarea-container textarea').each(function() {
        var $textarea = $(this);
        var maxLength = 1000; // WhatsApp limit aproximado
        
        var $counter = $('<div class="char-counter">')
            .css({
                'text-align': 'right',
                'font-size': '12px',
                'color': '#6b7280',
                'margin-top': '8px',
                'font-weight': '600'
            });
        
        $textarea.after($counter);
        
        function updateCounter() {
            var length = $textarea.val().length;
            var color = length > maxLength * 0.9 ? '#ef4444' : '#6b7280';
            $counter.html(length + ' / ' + maxLength + ' caracteres').css('color', color);
        }
        
        updateCounter();
        $textarea.on('input', updateCounter);
    });

    /**
     * Auto-save indicator
     */
    var saveTimeout;
    $('.form-table input, .form-table select, .form-table textarea').on('change input', function() {
        clearTimeout(saveTimeout);
        
        var $indicator = $('.woowapp-autosave-indicator');
        if (!$indicator.length) {
            $indicator = $('<span class="woowapp-autosave-indicator">')
                .css({
                    'position': 'fixed',
                    'bottom': '20px',
                    'right': '20px',
                    'background': 'linear-gradient(135deg, #f59e0b, #d97706)',
                    'color': 'white',
                    'padding': '10px 20px',
                    'border-radius': '8px',
                    'font-size': '13px',
                    'font-weight': '600',
                    'box-shadow': '0 4px 12px rgba(0,0,0,0.2)',
                    'z-index': 9999,
                    'display': 'none'
                })
                .html('⚠ Cambios sin guardar')
                .appendTo('body');
        }
        
        $indicator.fadeIn(200);
        
        saveTimeout = setTimeout(function() {
            $indicator.fadeOut(200);
        }, 3000);
    });

    /**
     * Smooth scroll para errores
     */
    if ($('.error, .woocommerce-error').length) {
        $('html, body').animate({
            scrollTop: $('.error, .woocommerce-error').first().offset().top - 100
        }, 500);
    }

    /**
     * Efecto de pulsación en botones
     */
    $(document).on('mousedown', '.button, .button-primary, .button-secondary', function() {
        $(this).css('transform', 'scale(0.98)');
    }).on('mouseup mouseleave', '.button, .button-primary, .button-secondary', function() {
        $(this).css('transform', '');
    });

    /**
     * Agregar animaciones CSS adicionales
     */
    $('<style>')
        .html(`
            @keyframes slideInRight {
                from {
                    transform: translateX(400px);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            .button-clicked {
                animation: buttonPulse 0.3s ease !important;
                background: #10b981 !important;
                color: white !important;
            }
            
            @keyframes buttonPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
            
            .textarea-updated {
                animation: textareaGlow 0.5s ease !important;
            }
            
            @keyframes textareaGlow {
                0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
                50% { box-shadow: 0 0 20px 5px rgba(99, 102, 241, 0.4); }
            }
            
            .input-error {
                animation: shake 0.4s ease !important;
                border-color: #ef4444 !important;
            }
            
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-10px); }
                75% { transform: translateX(10px); }
            }
        `)
        .appendTo('head');

    console.log('✨ WooWApp Pro Admin Scripts Loaded Successfully!');
});
