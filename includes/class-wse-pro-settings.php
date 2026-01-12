<?php
/**
 * Maneja la creación de la página de ajustes para WooWApp.
 * 
 * VERSIÓN: 2.2.1
 * CHANGELOG:
 * - Agregado campo de prefijo personalizable para cupones
 * - Mejorado guardado de checkboxes de cupones
 * - Agregados selectores de tiempo con unidades (minutos/horas/días)
 * 
 * @package WooWApp
 * @version 2.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Settings {

    public function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_woowapp', [$this, 'settings_tab_content']);
        add_action('woocommerce_update_options_woowapp', [$this, 'update_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wse_pro_send_test', [WSE_Pro_API_Handler::class, 'ajax_send_test_whatsapp']);
		add_action('wp_ajax_wse_regenerate_cron_key', [$this, 'ajax_regenerate_cron_key']);
        
        // Registrar tipos de campos personalizados
        add_action('woocommerce_admin_field_textarea_with_pickers', [$this, 'render_textarea_with_pickers']);
        add_action('woocommerce_admin_field_button', [$this, 'render_button_field']);
        add_action('woocommerce_admin_field_coupon_config', [$this, 'render_coupon_config']);
        add_action('woocommerce_admin_field_message_header', [$this, 'render_message_header']);
        add_action('woocommerce_admin_field_time_selector', [$this, 'render_time_selector']);

        // IMPORTANTE: Hook prioritario para guardar cupones ANTES del guardado estándar
        add_action('woocommerce_update_options_woowapp', [$this, 'save_coupon_fields'], 5);
    }

    /**
     * Añade la pestaña de WooWApp a la configuración de WooCommerce
     */
    public function add_settings_tab($settings_tabs) {
        $settings_tabs['woowapp'] = __('WooWApp', 'woowapp-smsenlinea-pro');
        return $settings_tabs;
    }

    /**
     * Renderiza el contenido de la pestaña con subpestañas
     */
    public function settings_tab_content() {
        $current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'administration';
        
        $tabs = [
            'administration'    => __('Administración', 'woowapp-smsenlinea-pro'),
            'admin_messages'    => __('Mensajes Admin', 'woowapp-smsenlinea-pro'),
            'customer_messages' => __('Mensajes Cliente', 'woowapp-smsenlinea-pro'),
            'notifications'     => __('Notificaciones', 'woowapp-smsenlinea-pro'),
        ];

        echo '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach ($tabs as $id => $name) {
            $class = ($current_section === $id) ? 'nav-tab-active' : '';
            $url = admin_url('admin.php?page=wc-settings&tab=woowapp&section=' . $id);
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($class) . '">' . esc_html($name) . '</a>';
        }
        echo '</h2>';
		// --- INICIO: DASHBOARD PLAN SMSENLINEA (PANEL 1) ---
        if ($current_section === 'administration') {
            // Obtenemos el secret real usando el ID que vi en tu archivo (wse_pro_api_secret_panel1)
            $api_secret = get_option('wse_pro_api_secret_panel1');

            if (!empty($api_secret)) {
                // Usamos un transient (caché temporal) de 5 minutos para no ralentizar el admin consultando la API en cada clic
                $datos_plan = get_transient('wse_pro_plan_cache_' . md5($api_secret));

                if (false === $datos_plan) {
                    $url = "https://whatsapp.smsenlinea.com/api/get/subscription?secret=" . $api_secret;
                    $response = wp_remote_get($url, ['timeout' => 10]);

                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $body = wp_remote_retrieve_body($response);
                        $json = json_decode($body, true);
                        if (isset($json['data'])) {
                            $datos_plan = $json['data'];
                            set_transient('wse_pro_plan_cache_' . md5($api_secret), $datos_plan, 5 * MINUTE_IN_SECONDS);
                        }
                    }
                }

                // Si tenemos datos (ya sea de la caché o recién traídos), mostramos el panel
                if ($datos_plan) {
                    $plan_name = isset($datos_plan['name']) ? $datos_plan['name'] : 'Desconocido';
                    
                    // Extraer uso de WhatsApp
                    $wa_used = isset($datos_plan['usage']['wa_send']['used']) ? $datos_plan['usage']['wa_send']['used'] : 0;
                    $wa_limit = isset($datos_plan['usage']['wa_send']['limit']) ? $datos_plan['usage']['wa_send']['limit'] : 0;
                    $wa_display_limit = ($wa_limit == 0) ? __('Ilimitado', 'woowapp-smsenlinea-pro') : $wa_limit;
                    
                    // Extraer uso de SMS
                    $sms_used = isset($datos_plan['usage']['sms_send']['used']) ? $datos_plan['usage']['sms_send']['used'] : 0;
                    $sms_limit = isset($datos_plan['usage']['sms_send']['limit']) ? $datos_plan['usage']['sms_send']['limit'] : 0;
                    $sms_display_limit = ($sms_limit == 0) ? __('Ilimitado', 'woowapp-smsenlinea-pro') : $sms_limit;

                    ?>
                    <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #25D366; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 20px 0 30px; padding: 20px; max-width: 1000px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                            <div>
                                <h2 style="margin: 0 0 15px 0; font-size: 18px;">
                                    <?php esc_html_e('Plan Actual:', 'woowapp-smsenlinea-pro'); ?> 
                                    <strong style="color: #2271b1;"><?php echo esc_html($plan_name); ?></strong>
                                </h2>
                                <div style="display: flex; gap: 40px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span class="dashicons dashicons-whatsapp" style="color: #25D366; font-size: 24px; width: 24px; height: 24px;"></span>
                                        <div>
                                            <span style="display: block; font-size: 12px; color: #646970;"><?php esc_html_e('Mensajes WhatsApp', 'woowapp-smsenlinea-pro'); ?></span>
                                            <strong style="font-size: 16px;"><?php echo esc_html($wa_used); ?> / <?php echo esc_html($wa_display_limit); ?></strong>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span class="dashicons dashicons-email-alt" style="color: #0073aa; font-size: 24px; width: 24px; height: 24px;"></span>
                                        <div>
                                            <span style="display: block; font-size: 12px; color: #646970;"><?php esc_html_e('Mensajes SMS', 'woowapp-smsenlinea-pro'); ?></span>
                                            <strong style="font-size: 16px;"><?php echo esc_html($sms_used); ?> / <?php echo esc_html($sms_display_limit); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <a href="https://smsenlinea.com/planes-whatsapp-y-sms/" target="_blank" class="button button-primary">
                                    <?php esc_html_e('Mejorar mi Plan', 'woowapp-smsenlinea-pro'); ?> <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            }
        }
        // --- FIN: DASHBOARD PLAN SMSENLINEA ---

        woocommerce_admin_fields($this->get_settings($current_section));
    }

    /**
     * Guarda la configuración estándar
     */
    public function update_settings() {
        $current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'administration';
        woocommerce_update_options($this->get_settings($current_section));
    }

    /**
     * NUEVO: Método dedicado para guardar campos de cupón
     * Se ejecuta ANTES del guardado estándar para asegurar que los checkboxes se procesen correctamente
     */
    public function save_coupon_fields() {
        // Solo procesar en la sección de notificaciones
        if (!isset($_GET['section']) || $_GET['section'] !== 'notifications') {
            return;
        }

        // Verificar nonce de WooCommerce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'woocommerce-settings')) {
            return;
        }

        // Procesar los 3 mensajes
        for ($i = 1; $i <= 3; $i++) {
            // 1. CHECKBOX: Activar cupón (CRÍTICO para checkboxes)
            $enable_key = 'wse_pro_abandoned_cart_coupon_enable_' . $i;
            $enable_value = isset($_POST[$enable_key]) ? 'yes' : 'no';
            update_option($enable_key, $enable_value);
            
            // 2. PREFIJO del cupón (NUEVO)
            $prefix_key = 'wse_pro_abandoned_cart_coupon_prefix_' . $i;
            if (isset($_POST[$prefix_key])) {
                $prefix_value = sanitize_text_field($_POST[$prefix_key]);
                // Limpiar el prefijo: solo letras, números y guiones
                $prefix_value = preg_replace('/[^a-zA-Z0-9\-]/', '', $prefix_value);
                // Si está vacío, usar default
                if (empty($prefix_value)) {
                    $prefix_value = 'woowapp-m' . $i;
                }
                update_option($prefix_key, $prefix_value);
            }
            
            // 3. Tipo de cupón
            $type_key = 'wse_pro_abandoned_cart_coupon_type_' . $i;
            if (isset($_POST[$type_key])) {
                $type_value = sanitize_text_field($_POST[$type_key]);
                // Validar que sea un tipo válido
                if (in_array($type_value, ['percent', 'fixed_cart', 'fixed_product'])) {
                    update_option($type_key, $type_value);
                }
            }
            
            // 4. Cantidad de descuento
            $amount_key = 'wse_pro_abandoned_cart_coupon_amount_' . $i;
            if (isset($_POST[$amount_key])) {
                $amount_value = floatval($_POST[$amount_key]);
                if ($amount_value > 0) {
                    update_option($amount_key, $amount_value);
                }
            }
            
            // 5. Días de expiración
            $expiry_key = 'wse_pro_abandoned_cart_coupon_expiry_' . $i;
            if (isset($_POST[$expiry_key])) {
                $expiry_value = intval($_POST[$expiry_key]);
                if ($expiry_value > 0 && $expiry_value <= 365) {
                    update_option($expiry_key, $expiry_value);
                }
            }

            // También guardamos tiempo y unidad aquí para consistencia
            $time_key = 'wse_pro_abandoned_cart_time_' . $i;
            if (isset($_POST[$time_key])) {
                $time_value = intval($_POST[$time_key]);
                if ($time_value > 0) {
                    update_option($time_key, $time_value);
                }
            }
            
            $unit_key = 'wse_pro_abandoned_cart_unit_' . $i;
            if (isset($_POST[$unit_key])) {
                $unit_value = sanitize_text_field($_POST[$unit_key]);
                if (in_array($unit_value, ['minutes', 'hours', 'days'])) {
                    update_option($unit_key, $unit_value);
                }
            }
        }
    }

    /**
     * Obtiene la configuración según la sección
     */
    public function get_settings($section = '') {
        if ($section === true) {
            return array_merge(
                $this->get_administration_settings(),
                $this->get_admin_messages_settings(),
                $this->get_customer_messages_settings(),
                $this->get_notifications_settings()
            );
        }
        
        switch ($section) {
            case 'admin_messages':
                return $this->get_admin_messages_settings();
            case 'customer_messages':
                return $this->get_customer_messages_settings();
            case 'notifications':
                return $this->get_notifications_settings();
            default:
                return $this->get_administration_settings();
        }
    }

    /**
     * Configuración de Administración (API y General)
     */
    private function get_administration_settings() {
        $log_url = admin_url('admin.php?page=wc-status&tab=logs');
        $log_handle = WSE_Pro_API_Handler::$log_handle;
        $panel1_docs_url = 'https://documenter.getpostman.com/view/20356708/2s93zB5c3s#intro';
        $panel2_login_url = 'https://app.smsenlinea.com/login';

        return [
            [
                'name' => __('Ajustes de API y Generales', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_api_settings_title'
            ],
            // --- INICIO: Enlace a Documentación ---
        [
            'name' => __('Ayuda y Documentación', 'woowapp-smsenlinea-pro'), // Título para esta "sección"
            'type' => 'title', // Usamos 'title' para que se muestre como un encabezado
            'id'   => 'wse_pro_docs_link_section',
            // En la descripción ponemos el botón y un texto explicativo
            'desc' => '<a href="https://descargas.smsenlinea.com/documentaciones/woowapp.php" target="_blank" class="button button-primary">' .
                      '<span class="dashicons dashicons-book-alt" style="vertical-align: middle; margin-right: 5px;"></span>' . // Icono opcional
                      esc_html__('Ver Documentación Completa', 'woowapp-smsenlinea-pro') .
                      '</a>' .
                      '<p class="description" style="margin-top: 5px;">' .
                      esc_html__('Consulta la guía detallada para configurar todas las opciones, entender los placeholders y solucionar problemas.', 'woowapp-smsenlinea-pro') .
                      '</p>',
        ],
        // --- FIN: Enlace a Documentación ---
            [
                'name' => __('Seleccionar API', 'woowapp-smsenlinea-pro'),
                'type' => 'select',
                'id' => 'wse_pro_api_panel_selection',
                'options' => [
                    'panel2' => __('API Panel 2 (WhatsApp QR)', 'woowapp-smsenlinea-pro'),
                    'panel1' => __('API Panel 1 (SMS y WhatsApp Clásico)', 'woowapp-smsenlinea-pro')
                ],
                'desc' => __('Elige el panel de SMSenlinea que deseas utilizar.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'panel2'
            ],
            
            // PANEL 2 - WhatsApp QR
            [
                'name' => __('Token de Autenticación (Panel 2)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_api_token',
                'css' => 'min-width:300px;',
                'desc' => sprintf(
                    __('Ingresa el token de tu instancia. Inicia sesión en <a href="%s" target="_blank">Panel 2</a>.', 'woowapp-smsenlinea-pro'),
                    esc_url($panel2_login_url)
                ),
                'custom_attributes' => ['data-panel' => 'panel2']
            ],
            [
                'name' => __('Número de Remitente (Panel 2)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_from_number',
                'desc' => __('Incluye el código de país. Ej: 5211234567890.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel2']
            ],
            
            // PANEL 1 - Clásico
            [
                'name' => __('API Secret (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_api_secret_panel1',
                'css' => 'min-width:300px;',
                'desc' => sprintf(
                    __('Copia tu API Secret desde <a href="%s" target="_blank">Panel 1</a>.', 'woowapp-smsenlinea-pro'),
                    esc_url($panel1_docs_url)
                ),
                'custom_attributes' => ['data-panel' => 'panel1']
            ],
            [
                'name' => __('Tipo de Mensaje (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'select',
                'id' => 'wse_pro_message_type_panel1',
                'options' => [
                    'whatsapp' => __('WhatsApp', 'woowapp-smsenlinea-pro'),
                    'sms' => __('SMS', 'woowapp-smsenlinea-pro')
                ],
                'desc' => __('Selecciona el tipo de mensaje.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'whatsapp',
                'custom_attributes' => ['data-panel' => 'panel1']
            ],
            [
                'name' => __('WhatsApp Account ID (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_whatsapp_account_panel1',
                'css' => 'min-width:300px;',
                'desc' => __('ID único de tu cuenta de WhatsApp.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'whatsapp']
            ],
            [
                'name' => __('Modo de Envío SMS (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'select',
                'id' => 'wse_pro_sms_mode_panel1',
                'options' => [
                    'devices' => __('Usar mis dispositivos', 'woowapp-smsenlinea-pro'),
                    'credits' => __('Usar créditos', 'woowapp-smsenlinea-pro')
                ],
                'desc' => __('devices=Android; credits=gateway.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'default' => 'devices',
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms']
            ],
            [
                'name' => __('Device / Gateway ID (Panel 1)', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_sms_device_panel1',
                'css' => 'min-width:300px;',
                'desc' => __('ID de tu dispositivo o gateway.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => ['data-panel' => 'panel1', 'data-msg-type' => 'sms']
            ],
            
            // Configuración General
            [
                'name' => __('Código de País Predeterminado', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_default_country_code',
                'desc' => __('Ej: 57 para Colombia.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true
            ],
            [
                'name' => __('Adjuntar Imagen de Producto (Pedidos)', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_attach_product_image',
                'desc' => __('<strong>Activa para adjuntar imagen.</strong> (Solo WhatsApp)', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            [
                'name' => __('Activar Registro de Actividad (Log)', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_log',
                'default' => 'yes',
                'desc' => sprintf(
                    __('Ver en <a href="%s">WooCommerce > Registros</a> (<code>%s</code>).', 'woowapp-smsenlinea-pro'),
                    esc_url($log_url),
                    esc_html($log_handle)
                )
            ],
            // === INICIO: Cron Externo ===
            [
                'name' => __('Alternativa de Cron (Externo)', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_external_cron_title',
                /* translators: %s: Enlace a un servicio de cron externo */
                'desc' => sprintf(
                    __('Si WP-Cron no funciona bien en tu servidor, puedes usar un servicio externo (como <a href="%s" target="_blank">cron-job.org</a>) para llamar a la siguiente URL cada 5-10 minutos. Esto ejecutará la revisión de carritos abandonados.', 'woowapp-smsenlinea-pro'),
                    'https://cron-job.org/'
                )
            ],
            [
                'name' => __('Clave Secreta de Cron', 'woowapp-smsenlinea-pro'),
                'type' => 'text', // Usamos 'text' para poder hacerlo readonly
                'id' => 'wse_pro_cron_secret_key_display', // ID diferente para evitar que se guarde desde aquí
                'desc' => __('Esta clave asegura que solo tú o un servicio autorizado pueda ejecutar la tarea.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'readonly' => 'readonly', // Hacer el campo no editable
                    'value' => esc_attr(self::get_cron_secret_key()), // Mostrar la clave actual
                    'onclick' => 'this.select();', // Seleccionar al hacer clic
                    'style' => 'width: 400px; background-color: #f0f0f0; cursor: text;' // Estilo visual
                ]
            ],
            [
                'name' => __('URL de Disparo (Trigger)', 'woowapp-smsenlinea-pro'),
                'type' => 'text', // Usamos 'text' para poder hacerlo readonly
                'id'   => 'wse_pro_cron_trigger_url_display',
                'desc' => __('Copia esta URL y pégala en tu servicio de cron externo.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true,
                'custom_attributes' => [
                    'readonly' => 'readonly',
                    'value' => esc_url(self::get_cron_trigger_url()), // Mostrar la URL completa
                    'onclick' => 'this.select();',
                    'style' => 'width: 100%; max-width: 600px; background-color: #f0f0f0; cursor: text;'
                ]
            ],
            [
                'name' => '', // Sin etiqueta
                'type' => 'button',
                'id' => 'wse_pro_regenerate_cron_key_button',
                'class' => 'button-secondary',
                'value' => __('🔄 Regenerar Clave Secreta', 'woowapp-smsenlinea-pro'),
                'desc' => '<span id="regenerate_key_status" style="margin-left: 10px;"></span>', // Nonce quitado de aquí
                'nonce_html' => wp_nonce_field('wse_regenerate_cron_key_action', 'wse_regenerate_cron_key_nonce', true, false) // Nonce movido aquí
            ],
            // === FIN: Cron Externo ===
            
            ['type' => 'sectionend', 'id' => 'wse_pro_api_settings_end'],
            
            // Sección de Prueba
            [
                'name' => __('Prueba de Envío', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_test_settings_title'
            ],
            [
                'name' => __('Número de Destino', 'woowapp-smsenlinea-pro'),
                'type' => 'text',
                'id' => 'wse_pro_test_number',
                'css' => 'min-width:300px;',
                'placeholder' => __('Ej: 573001234567', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => '',
                'type' => 'button',
                'id' => 'wse_pro_send_test_button',
                'class' => 'button-secondary',
                'value' => __('Enviar Mensaje de Prueba', 'woowapp-smsenlinea-pro'),
                'desc' => '<span id="test_send_status"></span>'
            ],
            
            ['type' => 'sectionend', 'id' => 'wse_pro_test_settings_end'],
        ];
    }

    /**
     * Configuración de Mensajes para Administradores
     */
    private function get_admin_messages_settings() {
        $settings = [
            [
                'name' => __('Notificaciones para Administradores', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_admin_settings_title',
                'desc' => __('Define números y mensajes para administradores.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Números de Administradores', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea',
                'id' => 'wse_pro_admin_numbers',
                'css' => 'width:100%; height:100px;',
                'desc' => __('Uno por línea con código de país (Ej: 573001234567).', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Plantillas de Mensajes para Administradores', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_admin_templates_title_sub'
            ],
        ];

        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            
            $settings[] = [
                'name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($name)),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_admin_' . $slug_clean,
                'default' => 'no'
            ];
            
            $settings[] = [
                'name' => __('Plantilla para Administradores', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_admin_message_' . $slug_clean,
                'css' => 'width:100%; height:75px;',
                'default' => sprintf(
                    __('🔔 Pedido #{order_id} de {customer_fullname} cambió a: %s.', 'woowapp-smsenlinea-pro'),
                    esc_html($name)
                )
            ];
        }
        // === INICIO NUEVA SECCIÓN: NOTIFICACIÓN DE RESEÑA PENDIENTE ===
        $settings[] = [
            'name' => __('Nueva Reseña Pendiente', 'woowapp-smsenlinea-pro'),
            'type' => 'title',
            'id' => 'wse_pro_admin_pending_review_title',
            'desc' => __('Recibe una notificación cuando un cliente envíe una nueva reseña que requiera aprobación.', 'woowapp-smsenlinea-pro')
        ];
        $settings[] = [
            'name' => __('Activar notificación de reseña pendiente', 'woowapp-smsenlinea-pro'),
            'type' => 'checkbox',
            'id' => 'wse_pro_enable_admin_pending_review',
            'default' => 'no'
        ];
        $settings[] = [
            'name' => __('Plantilla para Admin (Reseña Pendiente)', 'woowapp-smsenlinea-pro'),
            'type' => 'textarea_with_pickers',
            'id' => 'wse_pro_admin_message_pending_review',
            'css' => 'width:100%; height:75px;',
            'default' => sprintf(
                __('📝 Nueva reseña de {customer_fullname} para "{first_product_name}" (Pedido #{order_id}). Requiere aprobación. Calificación: %s estrellas.', 'woowapp-smsenlinea-pro'),
                '{review_rating}'
            ),
            'desc' => __('Placeholders disponibles: {customer_fullname}, {order_id}, {first_product_name}, {review_rating}, {review_content}', 'woowapp-smsenlinea-pro')
        ];
        // === FIN NUEVA SECCIÓN ===
        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_admin_settings_end'];
        
        return $settings;
    }

    /**
     * Configuración de Mensajes para Clientes
     */
    private function get_customer_messages_settings() {
        $settings = [
            [
                'name' => __('Plantillas de Mensajes para Clientes', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_notifications_title'
            ]
        ];

        $templates = [
            'note' => [
                'name' => __('Nueva Nota de Pedido', 'woowapp-smsenlinea-pro'),
                'default' => __('Hola {customer_name}, nueva nota en #{order_id}: {note_content}', 'woowapp-smsenlinea-pro')
            ]
        ];

        foreach (wc_get_order_statuses() as $slug => $name) {
            $slug_clean = str_replace('wc-', '', $slug);
            $templates[$slug_clean] = [
                'name' => $name,
                'default' => sprintf(
                    __('Hola {customer_name}, tu pedido #{order_id} cambió a: %s. ¡Gracias!', 'woowapp-smsenlinea-pro'),
                    strtolower($name)
                )
            ];
        }

        foreach ($templates as $key => $template) {
            $settings[] = [
                'name' => sprintf(__('Activar para: %s', 'woowapp-smsenlinea-pro'), esc_html($template['name'])),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_' . $key,
                'default' => 'no'
            ];
            
            $settings[] = [
                'name' => __('Plantilla de Mensaje', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_message_' . $key,
                'css' => 'width:100%; height:75px;',
                'default' => $template['default']
            ];
        }

        $settings[] = ['type' => 'sectionend', 'id' => 'wse_pro_notifications_end'];
        
        return $settings;
    }

    /**
     * Configuración de Notificaciones (Reseñas y Carritos Abandonados)
     */
    private function get_notifications_settings() {
        $settings = [
            // Recordatorio de Reseñas
            [
                'name' => __('Recordatorio de Reseña de Producto', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_review_reminders_title',
                'desc' => __('Envía un mensaje para incentivar reseñas.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Activar recordatorio de reseña', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_review_reminder',
                'desc' => __('<strong>Activar solicitudes automáticas.</strong>', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            [
                'name' => __('Enviar después de', 'woowapp-smsenlinea-pro'),
                'type' => 'number',
                'id' => 'wse_pro_review_reminder_days',
                'desc_tip' => true,
                'desc' => __('días desde "Completado".', 'woowapp-smsenlinea-pro'),
                'custom_attributes' => ['min' => '1'],
                'default' => '7'
            ],
            [
                'name' => __('Plantilla del mensaje (Enlace Web)', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_review_reminder_message',
                'css' => 'width:100%; height:75px;',
                'default' => __('¡Hola {customer_name}! ¿Te importaría dejar una reseña de {first_product_name}? {first_product_review_link}', 'woowapp-smsenlinea-pro')
            ],
            
            ['type' => 'sectionend', 'id' => 'wse_pro_review_reminders_end'],
			// --- INICIO NUEVOS CAMPOS DE CHATBOT (AÑADIR ESTO AQUÍ) ---
            [
                'name' => __('Modo de Captura de Reseña', 'woowapp-smsenlinea-pro'),
                'type' => 'select',
                'id' => 'wse_pro_review_capture_mode',
                'options' => [
                    'link' => __('Enlace a formulario web (Actual)', 'woowapp-smsenlinea-pro'),
                    'chat' => __('Chatbot por WhatsApp (Panel 1)', 'woowapp-smsenlinea-pro')
                ],
                'default' => 'link',
                'desc_tip' => true,
                'desc' => __('Elige cómo el cliente puede enviar su reseña. El modo Chatbot requiere Panel 1 y cron jobs estables.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Pregunta de Calificación (Chatbot)', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_review_chat_rating_question',
                'css' => 'width:100%; height:75px;',
                'default' => __('¡Hola {customer_name}! En una escala de 1 a 5, ¿qué tal te pareció tu pedido #{order_id} de "{first_product_name}"? Responde solo con un número (1 a 5).', 'woowapp-smsenlinea-pro'),
                'desc' => __('Primer mensaje. El cliente debe responder con un número del 1 al 5.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Pregunta de Comentario (Chatbot)', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_review_chat_comment_question',
                'css' => 'width:100%; height:75px;',
                'default' => __('¡Gracias por tu {review_rating} de calificación! Ahora, déjanos un breve comentario o reseña de tu experiencia.', 'woowapp-smsenlinea-pro'),
                'desc' => __('Segundo mensaje. El cliente debe responder con su comentario. El placeholder {review_rating} se reemplazará con la calificación dada.', 'woowapp-smsenlinea-pro')
            ],
            // --- FIN NUEVOS CAMPOS DE CHATBOT ---
            // Nueva opción para hacer obligatoria la calificación
            [
                'name' => __('¿Calificación obligatoria?', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_require_review_rating',
                'desc' => __('<strong>Marcar si la selección de estrellas es obligatoria para enviar la reseña.</strong>', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
        
            // === INICIO NUEVA SECCIÓN: RECOMPENSA POR RESEÑA ===
            [
                'name' => __('🎁 Recompensa por Reseña', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_review_reward_title',
                'desc' => __('Envía un mensaje de agradecimiento y opcionalmente un cupón después de que un cliente deje una reseña.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Activar agradecimiento por reseña', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_review_reward',
                'desc' => __('<strong>Activar mensaje de agradecimiento automático.</strong>', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            [
                'name' => __('Plantilla del mensaje de agradecimiento', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_review_reward_message',
                'css' => 'width:100%; height:90px;',
                'default' => __('¡Muchas gracias por tu reseña, {customer_name}! ✨ Como agradecimiento, aquí tienes un cupón de {coupon_amount} para tu próxima compra: {coupon_code}. ¡Válido hasta {coupon_expires}!', 'woowapp-smsenlinea-pro'),
                'desc' => __('Puedes usar placeholders como {customer_name}, {order_id}, y los de cupón si lo activas abajo.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Activar cupón de recompensa', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_review_reward_coupon_enable',
                'desc' => __('<strong>Generar y enviar un cupón si la reseña cumple el mínimo de estrellas.</strong>', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            [
                'name'        => __('Estrellas mínimas para cupón', 'woowapp-smsenlinea-pro'),
                'id'          => 'wse_pro_review_reward_min_rating',
                'type'        => 'select',
                'options'     => [
                    '1' => __('⭐ (1 estrella o más)', 'woowapp-smsenlinea-pro'),
                    '2' => __('⭐⭐ (2 estrellas o más)', 'woowapp-smsenlinea-pro'),
                    '3' => __('⭐⭐⭐ (3 estrellas o más)', 'woowapp-smsenlinea-pro'),
                    '4' => __('⭐⭐⭐⭐ (4 estrellas o más)', 'woowapp-smsenlinea-pro'),
                    '5' => __('⭐⭐⭐⭐⭐ (Solo 5 estrellas)', 'woowapp-smsenlinea-pro'),
                ],
                'default'     => '4',
                'desc_tip'    => __('El cliente recibirá el cupón solo si su calificación es igual o mayor a esta.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name'    => __('Tipo de Descuento (Cupón)', 'woowapp-smsenlinea-pro'),
                'id'      => 'wse_pro_review_reward_coupon_type',
                'type'    => 'select',
                'options' => [
                    'percent'    => __('Porcentaje (%)', 'woowapp-smsenlinea-pro'),
                    'fixed_cart' => __('Monto Fijo', 'woowapp-smsenlinea-pro'),
                ],
                'default' => 'percent',
            ],
            [
                'name'        => __('Cantidad del Descuento (Cupón)', 'woowapp-smsenlinea-pro'),
                'id'          => 'wse_pro_review_reward_coupon_amount',
                'type'        => 'number',
                'default'     => '15',
                'custom_attributes' => ['min' => '0.01', 'step' => '0.01'],
                'desc_tip'    => __('Ej: 10 para 10% o $10.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name'        => __('Válido por (días) (Cupón)', 'woowapp-smsenlinea-pro'),
                'id'          => 'wse_pro_review_reward_coupon_expiry',
                'type'        => 'number',
                'default'     => '14',
                'custom_attributes' => ['min' => '1'],
                'desc_tip'    => __('¿Cuántos días será válido el cupón desde que se envía?', 'woowapp-smsenlinea-pro')
            ],
            [
                'name'        => __('Prefijo del Cupón (Cupón)', 'woowapp-smsenlinea-pro'),
                'id'          => 'wse_pro_review_reward_coupon_prefix',
                'type'        => 'text',
                'default'     => 'RESEÑA',
                'desc'        => __('Base para el código del cupón, ej: GRACIAS', 'woowapp-smsenlinea-pro'),
                'desc_tip'    => true,
            ],
            // Nuevo campo para mensaje de reseña enviada
            [
                'name' => __('Mensaje Reseña Enviada (Pendiente)', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea',
                'id' => 'wse_pro_review_submitted_message',
                'css' => 'width:100%; height:90px;',
                'default' => __('¡Gracias por tu opinión! %d reseña(s) han sido enviadas y están pendientes de aprobación. Apreciamos mucho tu tiempo y tus comentarios.', 'woowapp-smsenlinea-pro'),
                'desc' => __('Mensaje mostrado al cliente tras enviar la reseña. Usa <code>%d</code> donde quieras mostrar el número de reseñas enviadas.', 'woowapp-smsenlinea-pro'),
                'desc_tip' => true
            ],
        
            ['type' => 'sectionend', 'id' => 'wse_pro_review_reward_end'],
            // === FIN NUEVA SECCIÓN ===

            // Recuperación de Carrito Abandonado
            [
                'name' => __('🛒 Recuperación de Carrito Abandonado', 'woowapp-smsenlinea-pro'),
                'type' => 'title',
                'id' => 'wse_pro_abandoned_cart_title',
                'desc' => __('Configura hasta 3 mensajes progresivos con descuentos crecientes para recuperar ventas.', 'woowapp-smsenlinea-pro')
            ],
            [
                'name' => __('Activar recuperación de carrito', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_enable_abandoned_cart',
                'desc' => __('<strong>Activar sistema de recuperación.</strong>', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            [
                'name' => __('Adjuntar imagen del primer producto', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_abandoned_cart_attach_image',
                'desc' => __('Incluir imagen en mensajes.', 'woowapp-smsenlinea-pro'),
                'default' => 'no'
            ],
            // --- INICIO: CONFIGURACIÓN ROMPEHIELOS (ANTI-BLOQUEO) ---
[
    'name' => __('🛡️ Estrategia Anti-Bloqueo (Rompehielos)', 'woowapp-smsenlinea-pro'),
    'type' => 'title',
    'id' => 'wse_pro_icebreaker_title',
    'desc' => __('Evita bloqueos de WhatsApp enviando un mensaje corto primero a números nuevos. Solo si responden, se enviará el carrito completo.', 'woowapp-smsenlinea-pro')
],
[
    'name' => __('Activar Rompehielos', 'woowapp-smsenlinea-pro'),
    'type' => 'checkbox',
    'id' => 'wse_pro_enable_icebreaker',
    'desc' => __('<strong>Activar verificación de respuesta previa.</strong> (Recomendado)', 'woowapp-smsenlinea-pro'),
    'default' => 'no'
],
[
    'name' => __('Mensaje Rompehielos (Carrito)', 'woowapp-smsenlinea-pro'),
    'type' => 'textarea_with_pickers',
    'id' => 'wse_pro_icebreaker_message_cart',
    'css' => 'width:100%; height:80px;',
    'default' => __('Hola {customer_name}, vemos que tienes un pedido pendiente por {order_total}. Responde *SI* para enviarte el enlace de pago o *NO* para cancelar.', 'woowapp-smsenlinea-pro'),
    'desc' => __('Mensaje corto sin enlaces para iniciar conversación.', 'woowapp-smsenlinea-pro')
],
[
    'name' => __('Palabra Clave: Confirmar', 'woowapp-smsenlinea-pro'),
    'type' => 'text',
    'id' => 'wse_pro_icebreaker_keyword_confirm',
    'default' => 'SI,COMPLETAR,QUIERO',
    'desc' => __('Palabras separadas por comas que el cliente debe escribir para recibir el enlace.', 'woowapp-smsenlinea-pro'),
    'desc_tip' => true
],
[
    'name' => __('Palabra Clave: Cancelar', 'woowapp-smsenlinea-pro'),
    'type' => 'text',
    'id' => 'wse_pro_icebreaker_keyword_cancel',
    'default' => 'NO,CANCELAR,BAJA',
    'desc' => __('Palabras que cancelarán el proceso y evitarán más mensajes.', 'woowapp-smsenlinea-pro'),
    'desc_tip' => true
],
// --- FIN: CONFIGURACIÓN ROMPEHIELOS ---
            ['type' => 'sectionend', 'id' => 'wse_pro_abandoned_cart_general_end'],
        ];

        // Agregar configuración de los 3 mensajes
        for ($i = 1; $i <= 3; $i++) {
            $settings = array_merge($settings, $this->get_cart_message_settings($i));
        }

        return $settings;
    }

    /**
     * Configuración de un mensaje de carrito abandonado
     */
    private function get_cart_message_settings($message_number) {
        $default_times = [1 => 60, 2 => 1, 3 => 3];
        $default_units = [1 => 'minutes', 2 => 'days', 3 => 'days'];
        $default_discounts = [1 => 10, 2 => 15, 3 => 20];
        $default_expiry = [1 => 7, 2 => 5, 3 => 3];
        $default_prefixes = [1 => 'WOOWAPP-M1', 2 => 'WOOWAPP-M2', 3 => 'WOOWAPP-M3'];

        $message_names = [
            1 => __('Primer Mensaje de Recuperación', 'woowapp-smsenlinea-pro'),
            2 => __('Segundo Mensaje de Recuperación', 'woowapp-smsenlinea-pro'),
            3 => __('Tercer Mensaje (Última Oportunidad)', 'woowapp-smsenlinea-pro')
        ];

        $default_messages = [
            1 => __('¡Hola {customer_name}! 👋 Notamos que dejaste productos en tu carrito. ¡Completa tu compra ahora! {checkout_link}', 'woowapp-smsenlinea-pro'),
            2 => __('¡Hola {customer_name}! 🎁 Tus productos te esperan. Usa el código {coupon_code} para {coupon_amount} de descuento. ¡Válido hasta {coupon_expires}! {checkout_link}', 'woowapp-smsenlinea-pro'),
            3 => __('⏰ {customer_name}, ¡ÚLTIMA OPORTUNIDAD! {coupon_amount} de descuento con {coupon_code}. Expira: {coupon_expires}. ¡No lo pierdas! {checkout_link}', 'woowapp-smsenlinea-pro')
        ];

        return [
            // Header visual del mensaje
            [
                'name' => $message_names[$message_number],
                'type' => 'message_header',
                'id' => 'wse_pro_cart_msg_' . $message_number . '_header',
                'message_number' => $message_number
            ],
            
            // Activar mensaje
            [
                'name' => __('Activar este mensaje', 'woowapp-smsenlinea-pro'),
                'type' => 'checkbox',
                'id' => 'wse_pro_abandoned_cart_enable_msg_' . $message_number,
                'desc' => sprintf(__('<strong>Enviar mensaje #%d</strong>', 'woowapp-smsenlinea-pro'), $message_number),
                'default' => 'no'
            ],
            
            // Selector de tiempo con unidades
            [
                'name' => __('⏱️ Enviar después de', 'woowapp-smsenlinea-pro'),
                'type' => 'time_selector',
                'id' => 'wse_pro_abandoned_cart_time_selector_' . $message_number,
                'message_number' => $message_number,
                'default_time' => $default_times[$message_number],
                'default_unit' => $default_units[$message_number],
                'desc' => __('Define cuánto tiempo esperar después de que el cliente abandone el carrito.', 'woowapp-smsenlinea-pro')
            ],
            
            // Plantilla del mensaje
            [
                'name' => __('📝 Plantilla del mensaje', 'woowapp-smsenlinea-pro'),
                'type' => 'textarea_with_pickers',
                'id' => 'wse_pro_abandoned_cart_message_' . $message_number,
                'css' => 'width:100%; height:90px;',
                'default' => $default_messages[$message_number]
            ],
            
            // Configuración de cupón (ACTUALIZADO con prefijo)
            [
                'name' => __('💳 Configuración de Cupón', 'woowapp-smsenlinea-pro'),
                'type' => 'coupon_config',
                'id' => 'wse_pro_coupon_config_' . $message_number,
                'message_number' => $message_number,
                'default_discount' => $default_discounts[$message_number],
                'default_expiry' => $default_expiry[$message_number],
                'default_prefix' => $default_prefixes[$message_number]
            ],
            
            ['type' => 'sectionend', 'id' => 'wse_pro_cart_msg_' . $message_number . '_end'],
        ];
    }

    /**
     * Renderiza el header visual de cada mensaje
     */
    public function render_message_header($value) {
        $icons = [1 => '📧', 2 => '🎁', 3 => '⏰'];
        $colors = [1 => '#6366f1', 2 => '#f59e0b', 3 => '#ef4444'];
        $msg_num = $value['message_number'];
        ?>
        <tr valign="top">
            <td colspan="2" style="padding: 0;">
                <div style="background: linear-gradient(135deg, <?php echo $colors[$msg_num]; ?> 0%, <?php echo $colors[$msg_num]; ?>dd 100%); color: white; padding: 15px 20px; border-radius: 8px; margin: 20px 0 10px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; font-size: 18px; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;"><?php echo $icons[$msg_num]; ?></span>
                        <?php echo esc_html($value['name']); ?>
                    </h3>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderiza el selector de tiempo con unidades
     */
    public function render_time_selector($value) {
        $msg_num = $value['message_number'];
        $time_value = get_option('wse_pro_abandoned_cart_time_' . $msg_num, $value['default_time']);
        $unit_value = get_option('wse_pro_abandoned_cart_unit_' . $msg_num, $value['default_unit']);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input 
                        type="number" 
                        name="wse_pro_abandoned_cart_time_<?php echo $msg_num; ?>" 
                        value="<?php echo esc_attr($time_value); ?>" 
                        min="1" 
                        style="width: 100px;"
                    >
                    <select name="wse_pro_abandoned_cart_unit_<?php echo $msg_num; ?>" style="width: 150px;">
                        <option value="minutes" <?php selected($unit_value, 'minutes'); ?>><?php esc_html_e('Minutos', 'woowapp-smsenlinea-pro'); ?></option>
                        <option value="hours" <?php selected($unit_value, 'hours'); ?>><?php esc_html_e('Horas', 'woowapp-smsenlinea-pro'); ?></option>
                        <option value="days" <?php selected($unit_value, 'days'); ?>><?php esc_html_e('Días', 'woowapp-smsenlinea-pro'); ?></option>
                    </select>
                </div>
                <?php if (!empty($value['desc'])) : ?>
                    <p class="description" style="margin-top: 8px;"><?php echo wp_kses_post($value['desc']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderiza la configuración de cupón (ACTUALIZADO con prefijo)
     */
    public function render_coupon_config($value) {
        $msg_num = $value['message_number'];
        $enable = get_option('wse_pro_abandoned_cart_coupon_enable_' . $msg_num, 'no');
        $prefix = get_option('wse_pro_abandoned_cart_coupon_prefix_' . $msg_num, $value['default_prefix']);
        $type = get_option('wse_pro_abandoned_cart_coupon_type_' . $msg_num, 'percent');
        $amount = get_option('wse_pro_abandoned_cart_coupon_amount_' . $msg_num, $value['default_discount']);
        $expiry = get_option('wse_pro_abandoned_cart_coupon_expiry_' . $msg_num, $value['default_expiry']);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp">
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 2px solid #e5e7eb;">
                    <!-- Checkbox para activar cupón -->
                    <p style="margin: 0 0 15px 0;">
                        <label style="display: flex; align-items: center; gap: 10px; font-weight: 600;">
                            <input 
                                type="checkbox" 
                                name="wse_pro_abandoned_cart_coupon_enable_<?php echo $msg_num; ?>" 
                                value="yes" 
                                <?php checked($enable, 'yes'); ?> 
                                style="width: 20px; height: 20px;"
                            >
                            <span><?php esc_html_e('Incluir cupón de descuento en este mensaje', 'woowapp-smsenlinea-pro'); ?></span>
                        </label>
                    </p>
                    
                    <div class="coupon-options" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                        <!-- NUEVO: Prefijo del cupón -->
                        <p style="margin: 0 0 10px 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php esc_html_e('🏷️ Prefijo del cupón:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <input 
                                type="text" 
                                name="wse_pro_abandoned_cart_coupon_prefix_<?php echo $msg_num; ?>" 
                                value="<?php echo esc_attr($prefix); ?>" 
                                placeholder="<?php echo esc_attr($value['default_prefix']); ?>"
                                style="width: 250px;"
                            >
                            <span style="color: #6b7280; font-size: 13px; margin-left: 8px;">
                                <?php esc_html_e('(Base del código, ej: DESCUENTO, PROMO)', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </p>
                        
                        <!-- Tipo de descuento -->
                        <p style="margin: 0 0 10px 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php esc_html_e('Tipo de descuento:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <select name="wse_pro_abandoned_cart_coupon_type_<?php echo $msg_num; ?>" style="width: 200px;">
                                <option value="percent" <?php selected($type, 'percent'); ?>><?php esc_html_e('Porcentaje (%)', 'woowapp-smsenlinea-pro'); ?></option>
                                <option value="fixed_cart" <?php selected($type, 'fixed_cart'); ?>><?php esc_html_e('Monto Fijo', 'woowapp-smsenlinea-pro'); ?></option>
                            </select>
                        </p>
                        
                        <!-- Cantidad de descuento -->
                        <p style="margin: 0 0 10px 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php esc_html_e('Cantidad de descuento:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <input 
                                type="number" 
                                name="wse_pro_abandoned_cart_coupon_amount_<?php echo $msg_num; ?>" 
                                value="<?php echo esc_attr($amount); ?>" 
                                min="1" 
                                step="0.01" 
                                style="width: 120px;"
                            >
                            <span style="color: #6b7280; font-size: 13px; margin-left: 8px;">
                                <?php esc_html_e('(Ej: 10 para 10% o $10)', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </p>
                        
                        <!-- Días de validez -->
                        <p style="margin: 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">
                                <?php esc_html_e('Válido por:', 'woowapp-smsenlinea-pro'); ?>
                            </label>
                            <input 
                                type="number" 
                                name="wse_pro_abandoned_cart_coupon_expiry_<?php echo $msg_num; ?>" 
                                value="<?php echo esc_attr($expiry); ?>" 
                                min="1" 
                                max="365" 
                                style="width: 80px;"
                            >
                            <span style="margin-left: 8px;"><?php esc_html_e('días', 'woowapp-smsenlinea-pro'); ?></span>
                        </p>
                    </div>
                    
                    <!-- Tip de ayuda -->
                    <div style="margin-top: 15px; padding: 12px; background: white; border-radius: 6px; border-left: 4px solid #6366f1;">
                        <p style="margin: 0; font-size: 13px; color: #6b7280;">
                            <strong style="color: #1f2937;"><?php esc_html_e('💡 Tip:', 'woowapp-smsenlinea-pro'); ?></strong> 
                            <?php esc_html_e('Usa las variables {coupon_code}, {coupon_amount} y {coupon_expires} en tu plantilla para mostrar la información del cupón.', 'woowapp-smsenlinea-pro'); ?>
                        </p>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #9ca3af;">
                            <?php printf(
                                esc_html__('El código generado será: <code>%s-ABC123</code> (donde ABC123 es único)', 'woowapp-smsenlinea-pro'),
                                esc_html($prefix)
                            ); ?>
                        </p>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderiza textarea con pickers de variables y emojis
     */
    public function render_textarea_with_pickers($value) {
        $option_value = get_option($value['id'], $value['default']);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['name']); ?></label>
            </th>
            <td class="forminp forminp-textarea">
                <div class="wse-pro-field-wrapper">
                    <div class="wse-pro-textarea-container">
                        <textarea 
                            name="<?php echo esc_attr($value['id']); ?>" 
                            id="<?php echo esc_attr($value['id']); ?>" 
                            style="<?php echo esc_attr($value['css']); ?>"
                        ><?php echo esc_textarea($option_value); ?></textarea>
                    </div>
                    <div class="wse-pro-pickers-container">
                        <div class="wc-wa-accordion-trigger">
                            <span><?php esc_html_e('Variables y Emojis', 'woowapp-smsenlinea-pro'); ?></span>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        
                        <div class="wc-wa-accordion-content" style="display: none;" data-target-id="<?php echo esc_attr($value['id']); ?>">
                            <!-- Variables -->
                            <div class="wc-wa-picker-group">
                                <strong><?php esc_html_e('Variables:', 'woowapp-smsenlinea-pro'); ?></strong>
                                <?php foreach (WSE_Pro_Placeholders::get_all_placeholders_grouped() as $group => $codes) : ?>
                                    <div class="picker-subgroup">
                                        <em><?php echo esc_html($group); ?>:</em><br>
                                        <?php foreach ($codes as $code) : ?>
                                            <button 
                                                type="button" 
                                                class="button button-small" 
                                                data-value="<?php echo esc_attr($code); ?>"
                                            ><?php echo esc_html($code); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Emojis -->
                            <div class="wc-wa-picker-group">
                                <strong><?php esc_html_e('Emojis:', 'woowapp-smsenlinea-pro'); ?></strong>
                                <?php foreach (WSE_Pro_Placeholders::get_all_emojis_grouped() as $group => $icons) : ?>
                                    <div class="picker-subgroup">
                                        <em><?php echo esc_html($group); ?>:</em><br>
                                        <?php foreach ($icons as $icon) : ?>
                                            <button 
                                                type="button" 
                                                class="button button-small emoji-btn" 
                                                data-value="<?php echo esc_attr($icon); ?>"
                                            ><?php echo esc_html($icon); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Renderiza un botón personalizado
     */
    public function render_button_field($value) {
    $field_description = WC_Admin_Settings::get_field_description($value);
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label for="<?php echo esc_attr($value['id']); ?>"><?php echo esc_html($value['title']); ?></label> <?php // title no se usa usualmente para botones, pero lo dejamos por si acaso ?>
            <?php echo $field_description['tooltip_html']; ?>
        </th>
        <td class="forminp forminp-button"> <?php // <-- Solo este TD ?>
            <button
                type="button"
                id="<?php echo esc_attr($value['id']); ?>"
                class="<?php echo esc_attr($value['class']); ?>"
            ><?php echo esc_html($value['value']); ?></button>
            <?php echo $field_description['description']; ?>
            <?php // Imprimir el nonce si existe en la definición
                  echo isset($value['nonce_html']) ? $value['nonce_html'] : '';
            ?>
        </td> <?php // <-- Fin del único TD ?>
    </tr>
    <?php
}

    /**
     * Encola los scripts y estilos del admin
     */
    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
        
        if (!isset($_GET['tab']) || 'woowapp' !== $_GET['tab']) {
            return;
        }

        wp_enqueue_style(
            'wse-pro-admin-css',
            WSE_PRO_URL . 'assets/css/admin.css',
            [],
            WSE_PRO_VERSION
        );
        
        wp_enqueue_script(
            'wse-pro-admin-js',
            WSE_PRO_URL . 'assets/js/admin.js',
            ['jquery'],
            WSE_PRO_VERSION,
            true
        );
        
        wp_localize_script(
            'wse-pro-admin-js', // Handle del script (nombre con el que se registró)
            'wse_pro_admin_params', // Nombre del objeto que estará disponible en JavaScript
            [ // Inicio del array principal de datos
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wse_pro_send_test_nonce'), // Coma añadida aquí
                // --- INICIO ARRAY i18n ---
                'i18n'     => [
                    'debugModeActive'      => __('%c🚀 WooWApp - Modo Debug Activo', 'woowapp-smsenlinea-pro'),
                    'server'               => __('🖥️  Servidor:', 'woowapp-smsenlinea-pro'),
                    'ajaxUrl'              => __('🔗 AJAX URL:', 'woowapp-smsenlinea-pro'),
                    'noncePresent'         => __('✅ Nonce presente:', 'woowapp-smsenlinea-pro'),
                    'customConfigFound'    => __('%c📋 Config personalizada encontrada', 'woowapp-smsenlinea-pro'),
                    'fieldSelectorsLoaded' => __('%c📊 Selectores de campos cargados', 'woowapp-smsenlinea-pro'),
                    'formFoundWith'        => __('✅ Formulario encontrado con selector:', 'woowapp-smsenlinea-pro'),
                    'checkoutFormNotFound' => __('⚠️  No se encontró formulario de checkout', 'woowapp-smsenlinea-pro'),
                    'noSelectorsFor'       => __('⚠️  No hay selectores configurados para:', 'woowapp-smsenlinea-pro'),
                    'fieldFoundWith'       => __('✅ {fieldName} encontrado con selector:', 'woowapp-smsenlinea-pro'),
                    'fieldNotFound'        => __('❌ Campo NO encontrado:', 'woowapp-smsenlinea-pro'),
                    'fieldValueLog'        => __('✅ {fieldName}: "{value}"', 'woowapp-smsenlinea-pro'),
                    'fieldDiagnostics'     => __('%c🔍 DIAGNÓSTICO DE CAMPOS', 'woowapp-smsenlinea-pro'),
                    'notAvailable'         => __('N/A', 'woowapp-smsenlinea-pro'),
                    'found'                => __('encontrado', 'woowapp-smsenlinea-pro'),
                    'visible'              => __('visible', 'woowapp-smsenlinea-pro'),
                    'type'                 => __('tipo', 'woowapp-smsenlinea-pro'),
                    'value'                => __('valor', 'woowapp-smsenlinea-pro'),
                    'totalFieldsFound'     => __('\n📊 Total de campos encontrados: {foundCount}/{totalCount}', 'woowapp-smsenlinea-pro'),
                    'startingAjax'         => __('%c📤 Iniciando AJAX', 'woowapp-smsenlinea-pro'),
                    'serverResponse'       => __('%c✅ Respuesta del servidor', 'woowapp-smsenlinea-pro'),
                    'dataCapturedSuccess'  => __('%c🎉 Datos capturados exitosamente', 'woowapp-smsenlinea-pro'),
                    'ajaxError'            => __('%c❌ Error AJAX', 'woowapp-smsenlinea-pro'),
                    'requestTimeout'       => __('%c⏱️  Timeout - Petición tardó más de 15s', 'woowapp-smsenlinea-pro'),
                    'processInProgress'    => __('⏳ Ya hay un proceso en curso, esperando...', 'woowapp-smsenlinea-pro'),
                    'noEmailOrPhone'       => __('⏭️  Sin email ni teléfono - No capturar', 'woowapp-smsenlinea-pro'),
                    'duplicateData'        => __('⏭️  Datos iguales a los previos - No enviar', 'woowapp-smsenlinea-pro'),
                    'sendingData'          => __('%c📤 Enviando datos...', 'woowapp-smsenlinea-pro'),
                    'attachingListeners'   => __('%c🔌 Adjuntando listeners a campos...', 'woowapp-smsenlinea-pro'),
                    'fieldChanged'         => __('👁️  Campo cambió:', 'woowapp-smsenlinea-pro'),
                    'select2Changed'       => __('✓ Select2 cambió', 'woowapp-smsenlinea-pro'),
                    'eventCheckoutUpdated' => __('%c🔄 Evento: Checkout actualizado', 'woowapp-smsenlinea-pro'),
                    'eventBlockChanged'    => __('%c🔄 Evento: Bloque WooCommerce cambió', 'woowapp-smsenlinea-pro'),
                    'listenersAttached'    => __('%c✅ {count} listeners adjuntados correctamente', 'woowapp-smsenlinea-pro'),
                    'formNotFoundWaiting'  => __('⏳ Formulario de checkout no encontrado aún. Esperando...', 'woowapp-smsenlinea-pro'),
                    'formFoundSuccess'     => __('%c✅ Formulario de checkout ENCONTRADO', 'woowapp-smsenlinea-pro'),
                    'initialCapture'       => __('%c📌 Ejecutando captura inicial', 'woowapp-smsenlinea-pro'),
                    'periodicCapture'      => __('%c⏰ Captura periódica', 'woowapp-smsenlinea-pro'),
                    'initSuccess'          => __('%c🎉 WooWApp inicializado correctamente', 'woowapp-smsenlinea-pro'),
                    'initByCheckoutUpdate' => __('%c🔄 Inicialización por evento updated_checkout', 'woowapp-smsenlinea-pro'),
                    'initByBlocksLoad'     => __('%c🔄 Inicialización por evento wc_blocks_loaded', 'woowapp-smsenlinea-pro'),
                    'fieldTest'            => __('%c🧪 TEST DE CAMPOS', 'woowapp-smsenlinea-pro'),
                    'sendingTestData'      => __('%c📤 Enviando datos de prueba...', 'woowapp-smsenlinea-pro'),
                    'testTip'              => __('%c💡 Tip: Escribe wseTestFields() en la consola para probar la captura', 'woowapp-smsenlinea-pro'),
                    // Claves para el botón regenerar y otras de admin.js
                    'regenerateKeyConfirm' => __('¿Estás seguro de que quieres generar una nueva clave secreta? La URL anterior dejará de funcionar.', 'woowapp-smsenlinea-pro'),
                    'regenerating' => __('Regenerando...', 'woowapp-smsenlinea-pro'),
                    'insertError' => __('Error al insertar', 'woowapp-smsenlinea-pro'),
                    'enterTestNumber' => __('Por favor, ingresa un número de teléfono para la prueba.', 'woowapp-smsenlinea-pro'),
                    'sending' => __('Enviando...', 'woowapp-smsenlinea-pro'),
                    'testSuccess' => __('✓ Mensaje enviado correctamente', 'woowapp-smsenlinea-pro'),
                    'unknownError' => __('Error desconocido.', 'woowapp-smsenlinea-pro'),
                    'serverConnectionError' => __('✗ Error de conexión con el servidor.', 'woowapp-smsenlinea-pro'),
                    'connectionError' => __('✗ Error de conexión', 'woowapp-smsenlinea-pro'),
                    'digitsOnlyWarning' => __('El número debe contener solo dígitos', 'woowapp-smsenlinea-pro'),
                    'characters' => __(' caracteres', 'woowapp-smsenlinea-pro'),
                    'unsavedChanges' => __('⚠ Cambios sin guardar', 'woowapp-smsenlinea-pro'),
                    'scriptsLoaded' => __('✨ WooWApp Pro Admin Scripts Loaded Successfully!', 'woowapp-smsenlinea-pro')
                ]
                // --- FIN ARRAY i18n ---
            ] // Fin del array principal de datos
        ); // Fin de wp_localize_script
    }
		/**
     * Obtiene (o genera si no existe) la clave secreta para el cron externo.
     * @return string La clave secreta.
     */
    private static function get_cron_secret_key() {
        $key = get_option('wse_pro_cron_secret_key');
        if (empty($key)) {
            $key = wp_generate_password(32, false); // Genera una clave aleatoria segura
            update_option('wse_pro_cron_secret_key', $key);
        }
        return $key;
    }

    /**
     * Genera una nueva clave secreta para el cron externo.
     * @return string La nueva clave secreta.
     */
    public static function regenerate_cron_secret_key() {
        $key = wp_generate_password(32, false);
        update_option('wse_pro_cron_secret_key', $key);
        return $key;
    }

    /**
     * Construye la URL completa para el disparador del cron externo.
     * @return string La URL.
     */
    private static function get_cron_trigger_url() {
        $key = self::get_cron_secret_key();
        $url = add_query_arg([
            'trigger_woowapp_cron' => 'process_carts',
            'key' => $key
        ], home_url('/')); // Usamos home_url('/') para la URL base
        return $url;
    }
	
	/**
     * Manejador AJAX para regenerar la clave secreta del cron.
     */
    public function ajax_regenerate_cron_key() {
    // Verificar nonce y permisos
    check_ajax_referer('wse_regenerate_cron_key_action', 'nonce'); // <-- ESTO ES LO CORRECTO

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => esc_html__('No tienes permisos.', 'woowapp-smsenlinea-pro')]);
    }

    // Regenerar la clave
    $new_key = self::regenerate_cron_secret_key();
    $new_url = self::get_cron_trigger_url(); // Obtener la URL con la nueva clave

    // Devolver la nueva clave y URL para actualizar la interfaz
    wp_send_json_success([
        'new_key' => $new_key,
        'new_url' => $new_url,
        'message' => esc_html__('¡Nueva clave generada!', 'woowapp-smsenlinea-pro')
    ]);
}
}
