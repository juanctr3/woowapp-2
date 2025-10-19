<?php
/**
 * Detección Automática de Campos de Formulario
 * Identifica campos de checkout independientemente del tema
 * 
 * @package WooWApp
 * @version 2.2.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Field_Detector {

    private static $instance = null;
    private static $detected_fields = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_field_config_page'], 100);
        add_action('admin_init', [$this, 'save_field_config']);
        add_action('wp_enqueue_scripts', [$this, 'detect_checkout_fields']);
        add_action('woocommerce_checkout_init', [$this, 'run_field_detection']);
    }

    /**
     * ========================================
     * DETECCIÓN AUTOMÁTICA DE CAMPOS
     * ========================================
     */

    public function run_field_detection() {
        if (!is_checkout()) {
            return;
        }

        global $wpdb;
        
        // Primero intentar obtener configuración guardada
        $saved_config = get_option('wse_pro_field_selectors', []);
        
        if (!empty($saved_config) && isset($saved_config['last_detected'])) {
            // Si tiene configuración guardada y es reciente (menos de 7 días), usarla
            if ((time() - $saved_config['last_detected']) < (7 * DAY_IN_SECONDS)) {
                self::$detected_fields = $saved_config;
                return;
            }
        }

        // Si no hay configuración o es muy antigua, detectar de nuevo
        $detected = $this->detect_fields();
        $detected['last_detected'] = time();
        
        update_option('wse_pro_field_selectors', $detected);
        self::$detected_fields = $detected;
        
        // Log de detección
        $this->log_detection($detected);
    }

    /**
     * 🔍 Función principal de detección
     */
    private function detect_fields() {
        $billing_fields = [
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

        $detected = [];

        foreach ($billing_fields as $field_name) {
            $selectors = $this->find_field_selectors($field_name);
            if (!empty($selectors)) {
                $detected[$field_name] = $selectors;
            }
        }

        return $detected;
    }

    /**
     * 🎯 Encontrar selectores para un campo específico
     * Intenta múltiples métodos de localización
     */
    private function find_field_selectors($field_name) {
        $selectors = [];

        // 1️⃣ Por atributo name exacto
        $selectors[] = 'input[name="' . $field_name . '"]';
        $selectors[] = 'select[name="' . $field_name . '"]';
        $selectors[] = 'textarea[name="' . $field_name . '"]';

        // 2️⃣ Por ID - Ambas formas (guión bajo y guión)
        $id_underscore = $field_name;
        $id_hyphen = str_replace('_', '-', $field_name);
        
        $selectors[] = '#' . $id_underscore;
        $selectors[] = '#' . $id_hyphen;

        // 3️⃣ Por clase WooCommerce
        $selectors[] = '.woocommerce-' . $id_hyphen . ' input';
        $selectors[] = '.woocommerce-' . $id_hyphen . ' select';
        $selectors[] = '.woocommerce-' . $id_underscore . ' input';

        // 4️⃣ Por atributo data-field-name (custom)
        $selectors[] = '[data-field-name="' . $field_name . '"]';
        $selectors[] = '[data-fieldname="' . $field_name . '"]';

        // 5️⃣ Por placeholder (para campos comunes)
        $placeholder_map = [
            'billing_email' => ['email', 'correo'],
            'billing_phone' => ['teléfono', 'telefono', 'phone', 'celular', 'móvil'],
            'billing_first_name' => ['primer nombre', 'nombre', 'first name'],
            'billing_last_name' => ['apellido', 'last name'],
            'billing_address_1' => ['dirección', 'address', 'calle'],
            'billing_city' => ['ciudad', 'city'],
            'billing_state' => ['estado', 'state', 'departamento', 'provincia'],
            'billing_postcode' => ['código postal', 'postcode', 'zip'],
            'billing_country' => ['país', 'country'],
        ];

        if (isset($placeholder_map[$field_name])) {
            foreach ($placeholder_map[$field_name] as $placeholder) {
                $selectors[] = 'input[placeholder*="' . $placeholder . '"]';
                $selectors[] = 'select[placeholder*="' . $placeholder . '"]';
            }
        }

        // 6️⃣ Por aria-label
        $selectors[] = '[aria-label*="' . str_replace('_', ' ', $field_name) . '"]';

        // 7️⃣ Por label asociado (si es input)
        $label_text_map = [
            'billing_email' => ['email', 'correo electrónico'],
            'billing_phone' => ['teléfono', 'celular', 'phone'],
            'billing_first_name' => ['nombre', 'first name'],
            'billing_last_name' => ['apellido', 'last name'],
            'billing_address_1' => ['dirección', 'address'],
            'billing_city' => ['ciudad', 'city'],
            'billing_state' => ['estado', 'estado/provincia'],
            'billing_postcode' => ['código postal', 'postal'],
            'billing_country' => ['país', 'country'],
        ];

        if (isset($label_text_map[$field_name])) {
            foreach ($label_text_map[$field_name] as $label_text) {
                $selectors[] = 'label:contains("' . $label_text . '") ~ input';
                $selectors[] = 'label:contains("' . $label_text . '") ~ select';
            }
        }

        // 8️⃣ Por clase genérica billing
        $field_short = str_replace('billing_', '', $field_name);
        $selectors[] = '.billing-' . $field_short;
        $selectors[] = '.billing_' . $field_short;

        return $selectors;
    }

    /**
     * 🔍 Logging de detección
     */
    private function log_detection($detected) {
        if (function_exists('wc_get_logger')) {
            $message = __('🔍 Campos detectados automáticamente:', 'woowapp-smsenlinea-pro') . "\n";
            foreach ($detected as $field => $selectors) {
                if ($field !== 'last_detected') {
                    $message .= sprintf(
                        __('- %s: %s', 'woowapp-smsenlinea-pro'),
                        $field,
                        implode(' | ', array_slice($selectors, 0, 2))
                    ) . "\n";
                }
            }
            wc_get_logger()->info($message, ['source' => 'woowapp-field-detection']);
        }
    }

    /**
     * ========================================
     * ENQUEUE SCRIPT CON CONFIG DINÁMICA
     * ========================================
     */

    public function detect_checkout_fields() {
        if (!is_checkout() || is_wc_endpoint_url('order-received')) {
            return;
        }

        // Obtener configuración guardada
        $field_config = get_option('wse_pro_field_selectors', []);
        
        // Si no hay configuración, usar detección rápida
        if (empty($field_config) || !isset($field_config['last_detected'])) {
            $field_config = [];
            // Usar los selectores por defecto más comunes
            $field_config['billing_email'] = [
                '#billing_email',
                '#billing-email',
                'input[name="billing_email"]',
            ];
            $field_config['billing_phone'] = [
                '#billing_phone',
                '#billing-phone',
                'input[name="billing_phone"]',
                'input[name="phone"]',
            ];
            $field_config['billing_first_name'] = [
                '#billing_first_name',
                '#billing-first-name',
                'input[name="billing_first_name"]',
            ];
            $field_config['billing_last_name'] = [
                '#billing_last_name',
                '#billing-last-name',
                'input[name="billing_last_name"]',
            ];
            $field_config['billing_address_1'] = [
                '#billing_address_1',
                '#billing-address-1',
                'input[name="billing_address_1"]',
                'textarea[name="billing_address_1"]',
            ];
            $field_config['billing_city'] = [
                '#billing_city',
                '#billing-city',
                'input[name="billing_city"]',
            ];
            $field_config['billing_state'] = [
                '#billing_state',
                '#billing-state',
                'select[name="billing_state"]',
                'input[name="billing_state"]',
            ];
            $field_config['billing_postcode'] = [
                '#billing_postcode',
                '#billing-postcode',
                'input[name="billing_postcode"]',
            ];
            $field_config['billing_country'] = [
                '#billing_country',
                '#billing-country',
                'select[name="billing_country"]',
            ];
        }

        // Remover la fecha de detección antes de enviar al frontend
        $config_to_send = array_filter($field_config, function($key) {
            return $key !== 'last_detected';
        }, ARRAY_FILTER_USE_KEY);

        wp_localize_script('wse-pro-cart-capture', 'wseFieldConfig', $config_to_send);
    }

    /**
     * ========================================
     * PÁGINA DE CONFIGURACIÓN EN ADMIN
     * ========================================
     */

    public function add_field_config_page() {
        add_submenu_page(
            'woocommerce',
            __('Configuración de Campos - WooWApp', 'woowapp-smsenlinea-pro'),
            __('⚙️ Campos de Captura', 'woowapp-smsenlinea-pro'),
            'manage_woocommerce',
            'wse-pro-field-config',
            [$this, 'render_field_config_page']
        );
    }

    public function render_field_config_page() {
        $field_config = get_option('wse_pro_field_selectors', []);
        $last_detected = isset($field_config['last_detected']) ? $field_config['last_detected'] : 0;
        
        ?>
        <div class="wrap">
            <h1><?php _e('⚙️ Configuración de Campos de Captura - WooWApp', 'woowapp-smsenlinea-pro'); ?></h1>
            
            <div class="notice notice-info" style="margin-top:20px;">
                <p>
                    <strong><?php _e('ℹ️ Información:', 'woowapp-smsenlinea-pro'); ?></strong> 
                    <?php _e('Los campos se detectan automáticamente. Si quieres personalizarlos, puedes editar los selectores CSS aquí.', 'woowapp-smsenlinea-pro'); ?>
                </p>
            </div>

            <?php if ($last_detected > 0): ?>
            <div class="notice notice-success">
                <p>
                    <?php printf(
                        esc_html__('✅ Última detección: %s atrás', 'woowapp-smsenlinea-pro'),
                        esc_html(human_time_diff($last_detected))
                    ); ?>
                </p>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e('Campo', 'woowapp-smsenlinea-pro'); ?></th>
                        <th style="width: 50%;"><?php esc_html_e('Selectores CSS', 'woowapp-smsenlinea-pro'); ?></th>
                        <th style="width: 25%;"><?php esc_html_e('Acción', 'woowapp-smsenlinea-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $billing_fields = [
                        'billing_email' => __('Email', 'woowapp-smsenlinea-pro'),
                        'billing_phone' => __('Teléfono', 'woowapp-smsenlinea-pro'),
                        'billing_first_name' => __('Nombre', 'woowapp-smsenlinea-pro'),
                        'billing_last_name' => __('Apellido', 'woowapp-smsenlinea-pro'),
                        'billing_address_1' => __('Dirección', 'woowapp-smsenlinea-pro'),
                        'billing_city' => __('Ciudad', 'woowapp-smsenlinea-pro'),
                        'billing_state' => __('Estado/Provincia', 'woowapp-smsenlinea-pro'),
                        'billing_postcode' => __('Código Postal', 'woowapp-smsenlinea-pro'),
                        'billing_country' => __('País', 'woowapp-smsenlinea-pro'),
                    ];

                    foreach ($billing_fields as $field_key => $field_label):
                        $selectors = isset($field_config[$field_key]) ? $field_config[$field_key] : [];
                        $selectors_text = implode(' | ', $selectors);
                        $status = !empty($selectors) ? '✅' : '❌';
                    ?>
                    <tr>
                        <td><strong><?php echo $status; ?> <?php echo esc_html($field_label); ?></strong></td>
                        <td>
                            <code style="display: block; padding: 10px; background: #f5f5f5; border-radius: 3px; word-break: break-word;">
                                <?php echo esc_html($selectors_text); ?>
                            </code>
                        </td>
                        <td>
                            <button 
                                type="button" 
                                class="button wse-test-selector" 
                                data-field="<?php echo esc_attr($field_key); ?>"
                                data-selectors='<?php echo esc_attr(wp_json_encode($selectors)); ?>'
                            >
                                🧪 <?php esc_html_e('Probar', 'woowapp-smsenlinea-pro'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">
                <h3><?php _e('🔧 Personalizar Selectores', 'woowapp-smsenlinea-pro'); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field('wse_pro_field_config', 'nonce'); ?>

                    <table class="wp-list-table widefat" style="margin-bottom:20px;">
                        <tbody>
                            <?php foreach ($billing_fields as $field_key => $field_label): ?>
                            <tr>
                                <th style="width: 25%;"><?php echo esc_html($field_label); ?></th>
                                <td>
                                    <textarea 
                                        name="wse_field_config[<?php echo esc_attr($field_key); ?>]"
                                        rows="3"
                                        style="width: 100%; font-family: monospace; font-size: 12px;"
                                        placeholder="<?php esc_attr_e('Ingresa selectores separados por saltos de línea. Ej: #billing_phone', 'woowapp-smsenlinea-pro'); ?>"
                                    ><?php 
                                        if (isset($field_config[$field_key])) {
                                            echo esc_textarea(implode("\n", $field_config[$field_key]));
                                        } 
                                    ?></textarea>
                                    <p style="margin-top: 5px; font-size: 12px; color: #666;">
                                        💡 <?php _e('Usa selectores CSS. Ejemplo:', 'woowapp-smsenlinea-pro'); ?> <code>#billing_phone</code>, <code>input[name="phone"]</code>
                                    </p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit" name="save_field_config" class="button button-primary button-large">
                        💾 <?php esc_html_e('Guardar Configuración Personalizada', 'woowapp-smsenlinea-pro'); ?>
                    </button>
                    
                    <button type="submit" name="reset_field_config" class="button button-secondary" 
                            style="margin-left: 10px;">
                        🔄 <?php esc_html_e('Detectar Automáticamente', 'woowapp-smsenlinea-pro'); ?>
                    </button>
                </form>
            </div>

            <!-- Test Modal -->
            <div id="wse-test-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 20px rgba(0,0,0,0.3); z-index: 9999; min-width: 400px;">
                <h3 id="wse-test-title"></h3>
                <p id="wse-test-result" style="padding: 15px; background: #f5f5f5; border-radius: 3px; margin: 20px 0;"></p>
                <button type="button" class="button" onclick="document.getElementById('wse-test-modal').style.display='none';">
                    <?php esc_html_e('Cerrar', 'woowapp-smsenlinea-pro'); ?>
                </button>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.wse-test-selector').on('click', function() {
                    const field = $(this).data('field');
                    const selectors = $(this).data('selectors');
                    
                    // Ir a checkout
                    const checkoutUrl = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                    
                    // Guardar en sessionStorage para prueba
                    sessionStorage.setItem('wse_test_field', field);
                    sessionStorage.setItem('wse_test_selectors', JSON.stringify(selectors));
                    
                    // Abrir en nueva pestaña
                    window.open(checkoutUrl + '?wse-test-field=' + field, '_blank');
                });
            });
            </script>
        </div>
        <?php
    }

    public function save_field_config() {
        if (!isset($_POST['save_field_config']) && !isset($_POST['reset_field_config'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wse_pro_field_config')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_POST['reset_field_config'])) {
            // Limpiar configuración para forzar detección nueva
            delete_option('wse_pro_field_selectors');
            wp_safe_redirect(admin_url('admin.php?page=wse-pro-field-config&reset=1'));
            exit;
        }

        if (isset($_POST['wse_field_config'])) {
            $config = [];
            
            foreach ($_POST['wse_field_config'] as $field => $selectors_text) {
                $selectors_text = sanitize_textarea_field($selectors_text);
                $selectors = array_filter(array_map('trim', explode("\n", $selectors_text)));
                
                if (!empty($selectors)) {
                    $config[$field] = $selectors;
                }
            }

            $config['last_detected'] = time();
            update_option('wse_pro_field_selectors', $config);
            
            wp_safe_redirect(admin_url('admin.php?page=wse-pro-field-config&saved=1'));
            exit;
        }
    }
}

// Inicializar
add_action('plugins_loaded', function() {
    WSE_Pro_Field_Detector::get_instance();
}, 5);