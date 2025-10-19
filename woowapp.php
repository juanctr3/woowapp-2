<?php
/**
 * Plugin Name:       WooWApp
 * Plugin URI:        https://smsenlinea.com
 * Description:       Una solución robusta para enviar notificaciones de WhatsApp a los clientes de WooCommerce utilizando la API de SMSenlinea. Incluye recordatorios de reseñas y recuperación de carritos abandonados con cupones personalizables.
 * Version:           2.2.2
 * Author:            smsenlinea
 * Author URI:        https://smsenlinea.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woowapp-smsenlinea-pro
 * Domain Path:       /languages
 * WC requires at least: 3.0.0
 * WC tested up to:   8.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constantes del plugin
define('WSE_PRO_VERSION', '2.2.2');
define('WSE_PRO_DB_VERSION', '2.2.2');
define('WSE_PRO_PATH', plugin_dir_path(__FILE__));
define('WSE_PRO_URL', plugin_dir_url(__FILE__));

// Hooks de activación y desactivación
register_activation_hook(__FILE__, ['WooWApp', 'on_activation']);
register_deactivation_hook(__FILE__, ['WooWApp', 'on_deactivation']);

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Clase principal del Plugin WooWApp
 */
final class WooWApp {

    private static $instance;
    private static $abandoned_cart_table_name;
    private static $tracking_table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        self::$abandoned_cart_table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
        self::$tracking_table_name = $wpdb->prefix . 'wse_pro_tracking';
        
        // 🆕 Cargar compatibilidad de servidor
        add_action('plugins_loaded', [$this, 'load_server_compatibility'], 1);
        
        add_action('plugins_loaded', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade_database'], 5);
    }

    /**
     * 🆕 Cargar compatibilidad de servidor
     */
    public function load_server_compatibility() {
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-field-detector.php';
    }

    /**
     * ========================================
     * ACTIVACIÓN Y CONFIGURACIÓN INICIAL
     * ========================================
     */

    public static function on_activation() {
        self::create_database_tables();
        self::create_review_page();
        self::schedule_cron_events();
        update_option('wse_pro_db_version', WSE_PRO_DB_VERSION);
        flush_rewrite_rules();
        
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                'WooWApp v' . WSE_PRO_VERSION . ' activado correctamente',
                ['source' => 'woowapp-' . date('Y-m-d')]
            );
        }
    }

    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = self::$abandoned_cart_table_name;
        $coupons_table = $wpdb->prefix . 'wse_pro_coupons_generated';
        $tracking_table = self::$tracking_table_name;

        // TABLA DE CARRITOS ABANDONADOS
        $sql_carts = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(191) NOT NULL,
            first_name VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(40) NOT NULL,
            cart_contents LONGTEXT NOT NULL,
            cart_total DECIMAL(10, 2) NOT NULL,
            checkout_data LONGTEXT DEFAULT NULL,
            billing_first_name VARCHAR(100) DEFAULT '',
            billing_last_name VARCHAR(100) DEFAULT '',
            billing_email VARCHAR(255) DEFAULT '',
            billing_phone VARCHAR(50) DEFAULT '',
            billing_address_1 VARCHAR(255) DEFAULT '',
            billing_city VARCHAR(100) DEFAULT '',
            billing_state VARCHAR(100) DEFAULT '',
            billing_postcode VARCHAR(20) DEFAULT '',
            billing_country VARCHAR(2) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            messages_sent VARCHAR(20) DEFAULT '0,0,0',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            recovery_token VARCHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recovery_token (recovery_token),
            KEY session_id (session_id),
            KEY phone (phone),
            KEY billing_email (billing_email),
            KEY status (status),
            KEY updated_at (updated_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        // TABLA DE CUPONES
        $sql_coupons = "CREATE TABLE IF NOT EXISTS $coupons_table (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            coupon_code VARCHAR(50) NOT NULL,
            customer_phone VARCHAR(40) DEFAULT NULL,
            customer_email VARCHAR(100) DEFAULT NULL,
            cart_id BIGINT(20) DEFAULT NULL,
            order_id BIGINT(20) DEFAULT NULL,
            message_number INT DEFAULT 0,
            coupon_type VARCHAR(20) NOT NULL,
            discount_type VARCHAR(20) NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            usage_limit INT DEFAULT 1,
            used TINYINT DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY coupon_code (coupon_code),
            KEY customer_phone (customer_phone),
            KEY customer_email (customer_email),
            KEY cart_id (cart_id),
            KEY order_id (order_id),
            KEY used (used),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        // TABLA DE TRACKING
        $sql_tracking = "CREATE TABLE IF NOT EXISTS $tracking_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cart_id BIGINT(20) UNSIGNED NOT NULL,
            message_number TINYINT(1) NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            event_data LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY cart_id (cart_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_carts);
        dbDelta($sql_coupons);
        dbDelta($sql_tracking);
    }

    public function maybe_upgrade_database() {
        $current_db_version = get_option('wse_pro_db_version', '0');
        
        // SIEMPRE verificar integridad de la BD
        $this->verify_and_repair_database();
        
        if (version_compare($current_db_version, WSE_PRO_DB_VERSION, '<')) {
            $this->upgrade_database($current_db_version);
            update_option('wse_pro_db_version', WSE_PRO_DB_VERSION);
            
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info(
                    "Base de datos migrada de v{$current_db_version} a v" . WSE_PRO_DB_VERSION,
                    ['source' => 'woowapp-' . date('Y-m-d')]
                );
            }
        }
        
        $this->ensure_cron_scheduled();
    }
    
    /**
     * Verifica y repara la estructura de BD automáticamente
     */
    private function verify_and_repair_database() {
        global $wpdb;
        $table_name = self::$abandoned_cart_table_name;
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            self::create_database_tables();
            return;
        }
        
        // Obtener columnas actuales
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $column_names = array_column($columns, 'Field');
        
        // Lista de columnas requeridas v2.2.2
        $required_columns = [
            'billing_first_name' => "VARCHAR(100) DEFAULT ''",
            'billing_last_name' => "VARCHAR(100) DEFAULT ''",
            'billing_email' => "VARCHAR(255) DEFAULT ''",
            'billing_phone' => "VARCHAR(50) DEFAULT ''",
            'billing_address_1' => "VARCHAR(255) DEFAULT ''",
            'billing_city' => "VARCHAR(100) DEFAULT ''",
            'billing_state' => "VARCHAR(100) DEFAULT ''",
            'billing_postcode' => "VARCHAR(20) DEFAULT ''",
            'billing_country' => "VARCHAR(2) DEFAULT ''",
            'messages_sent' => "VARCHAR(20) DEFAULT '0,0,0'",
        ];
        
        // Agregar columnas faltantes
        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $column_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
            }
        }
        
        // Eliminar columnas obsoletas
        $obsolete_columns = ['scheduled_time'];
        foreach ($obsolete_columns as $column) {
            if (in_array($column, $column_names)) {
                $wpdb->query("ALTER TABLE $table_name DROP COLUMN $column");
            }
        }
        
        // Verificar tabla de tracking
        $tracking_exists = $wpdb->get_var("SHOW TABLES LIKE '" . self::$tracking_table_name . "'") === self::$tracking_table_name;
        if (!$tracking_exists) {
            self::create_database_tables();
        }
    }

    private function upgrade_database($from_version) {
        global $wpdb;
        $table_name = self::$abandoned_cart_table_name;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            self::create_database_tables();
            return;
        }

        $columns = $wpdb->get_results("DESCRIBE $table_name");
        $column_names = array_column($columns, 'Field');
        
        // Agregar messages_sent
        if (!in_array('messages_sent', $column_names)) {
            $wpdb->query(
                "ALTER TABLE $table_name 
                 ADD COLUMN messages_sent VARCHAR(20) DEFAULT '0,0,0' 
                 AFTER status"
            );
            
            $wpdb->query(
                "UPDATE $table_name 
                 SET messages_sent = '0,0,0' 
                 WHERE messages_sent IS NULL OR messages_sent = ''"
            );
        }
        
        // Agregar campos de billing
        $billing_fields = [
            'billing_first_name' => "VARCHAR(100) DEFAULT '' AFTER checkout_data",
            'billing_last_name' => "VARCHAR(100) DEFAULT '' AFTER billing_first_name",
            'billing_email' => "VARCHAR(255) DEFAULT '' AFTER billing_last_name",
            'billing_phone' => "VARCHAR(50) DEFAULT '' AFTER billing_email",
            'billing_address_1' => "VARCHAR(255) DEFAULT '' AFTER billing_phone",
            'billing_city' => "VARCHAR(100) DEFAULT '' AFTER billing_address_1",
            'billing_state' => "VARCHAR(100) DEFAULT '' AFTER billing_city",
            'billing_postcode' => "VARCHAR(20) DEFAULT '' AFTER billing_state",
            'billing_country' => "VARCHAR(2) DEFAULT '' AFTER billing_postcode",
        ];
        
        foreach ($billing_fields as $field => $definition) {
            if (!in_array($field, $column_names)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $field $definition");
            }
        }
        
        // Eliminar columna obsoleta
        if (in_array('scheduled_time', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name DROP COLUMN scheduled_time");
        }
        
        // Agregar índices
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
        $index_names = array_column($indexes, 'Key_name');
        
        if (!in_array('updated_at', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY updated_at (updated_at)");
        }
        
        if (!in_array('phone', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY phone (phone)");
        }
        
        if (!in_array('billing_email', $index_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD KEY billing_email (billing_email)");
        }
        
        // Crear tabla de tracking
        self::create_database_tables();
    }

    private function ensure_cron_scheduled() {
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __('Cada 5 minutos', 'woowapp-smsenlinea-pro')
                ];
            }
            return $schedules;
        });
        
        if (!wp_next_scheduled('wse_pro_process_abandoned_carts')) {
            wp_schedule_event(time(), 'five_minutes', 'wse_pro_process_abandoned_carts');
        }
        
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
    }

    private static function schedule_cron_events() {
        wp_clear_scheduled_hook('wse_pro_process_abandoned_carts');
        wp_clear_scheduled_hook('wse_pro_cleanup_coupons');
        
        if (!wp_next_scheduled('wse_pro_process_abandoned_carts')) {
            wp_schedule_event(time(), 'five_minutes', 'wse_pro_process_abandoned_carts');
        }
        
        if (!wp_next_scheduled('wse_pro_cleanup_coupons')) {
            wp_schedule_event(time(), 'daily', 'wse_pro_cleanup_coupons');
        }
    }

    private static function create_review_page() {
        $review_page_slug = 'escribir-resena';
        $existing_page = get_page_by_path($review_page_slug);
        
        if (null === $existing_page) {
            $page_id = wp_insert_post([
                'post_title'   => __('Escribir Reseña', 'woowapp-smsenlinea-pro'),
                'post_name'    => $review_page_slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[woowapp_review_form]',
            ]);
            
            if ($page_id && !is_wp_error($page_id)) {
                update_option('wse_pro_review_page_id', $page_id);
            }
        } elseif (empty(get_option('wse_pro_review_page_id'))) {
            update_option('wse_pro_review_page_id', $existing_page->ID);
        }
    }

    /**
     * ========================================
     * INICIALIZACIÓN DEL PLUGIN
     * ========================================
     */

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'missing_wc_notice']);
            return;
        }
        
        load_plugin_textdomain(
            'woowapp-smsenlinea-pro',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
        
        $this->includes();
        $this->init_classes();
        
        add_action('admin_notices', [$this, 'check_review_page_exists']);
        
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __('Cada 5 minutos', 'woowapp-smsenlinea-pro'),
                ];
            }
            return $schedules;
        });
    }

    public function includes() {
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-field-detector.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-server-compatibility.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-settings.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-api-handler.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-placeholders.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-coupon-manager.php';
        require_once WSE_PRO_PATH . 'includes/class-wse-pro-stats-dashboard.php';
    }

    public function init_classes() {
        new WSE_Pro_Settings();
        WSE_Pro_Server_Compatibility::get_instance();
        WSE_Pro_Stats_Dashboard::get_instance();
        
        // Agregar página de diagnóstico en admin
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_diagnostic_menu'], 99);
            add_action('admin_init', [$this, 'handle_diagnostic_actions']);
        }
        
        if (isset($_GET['action']) && $_GET['action'] === 'recreate_review_page' && is_admin()) {
            self::create_review_page();
            flush_rewrite_rules();
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=woowapp&section=notifications'));
            exit;
        }

        // Hook para enviar recompensa al aprobar reseña ===
add_action('transition_comment_status', [$this, 'send_reward_on_review_approval'], 10, 3);

        // Notificaciones
        add_action('woocommerce_new_customer_note', [$this, 'trigger_new_note_notification'], 10, 1);
        
        foreach (array_keys(wc_get_order_statuses()) as $status) {
            $status_clean = str_replace('wc-', '', $status);
            add_action(
                'woocommerce_order_status_' . $status_clean,
                [$this, 'trigger_status_change_notification'],
                10,
                2
            );
        }
        
        add_action('woocommerce_order_status_completed', [$this, 'schedule_review_reminder'], 10, 1);
        add_action('wse_pro_send_review_reminder_event', [$this, 'send_review_reminder_notification'], 10, 1);
        
        // Carrito abandonado
        if ('yes' === get_option('wse_pro_enable_abandoned_cart', 'no')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
            // add_action('wp_ajax_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
           // add_action('wp_ajax_nopriv_wse_pro_capture_cart', [$this, 'capture_cart_via_ajax']);
            add_action('woocommerce_new_order', [$this, 'cancel_abandoned_cart_reminder'], 10, 1);
            add_action('wse_pro_process_abandoned_carts', [$this, 'process_abandoned_carts_cron']);
            add_action('template_redirect', [$this, 'handle_cart_recovery_link']);
            add_filter('woocommerce_checkout_get_value', [$this, 'populate_checkout_fields'], 10, 2);
            
            add_filter('default_checkout_billing_country', [$this, 'default_billing_country'], 10, 1);
            add_filter('default_checkout_billing_state', [$this, 'default_billing_state'], 10, 1);
        }

        // Hook para notificar admin de reseña pendiente ===
add_action('wp_insert_comment', [$this, 'notify_admin_on_pending_review'], 10, 2);

        //Hook para enviar recompensa al aprobar reseña ===
add_action('transition_comment_status', [$this, 'send_reward_on_review_approval'], 10, 3);

        // Tracking de conversiones
        add_action('woocommerce_order_status_completed', [$this, 'track_conversion'], 10, 1);

        // Cupones
        add_action('wse_pro_cleanup_coupons', [WSE_Pro_Coupon_Manager::class, 'cleanup_expired_coupons']);
        add_action('woocommerce_checkout_order_processed', [WSE_Pro_Coupon_Manager::class, 'track_coupon_usage'], 10, 1);
        
        // Reseñas
        add_shortcode('woowapp_review_form', [$this, 'render_review_form_shortcode']);
        add_filter('the_content', [$this, 'handle_custom_review_page_content']);
        add_filter('woocommerce_order_actions', [$this, 'add_manual_review_request_action']);
        add_action('woocommerce_order_action_wse_send_review_request', [$this, 'process_manual_review_request_action']);
        // AÑADIR ESTO: Hooks para el botón "Forzar Envío" del script de debug
add_action('wse_pro_send_abandoned_cart_1', [$this, 'debug_force_send_message'], 10, 1);
add_action('wse_pro_send_abandoned_cart_2', [$this, 'debug_force_send_message'], 10, 1);
add_action('wse_pro_send_abandoned_cart_3', [$this, 'debug_force_send_message'], 10, 1);
    }

    /**
     * ========================================
     * CAPTURA Y PROCESAMIENTO DE CARRITOS
     * ========================================
     */

    public function enqueue_frontend_scripts() {
        if (is_checkout() && !is_wc_endpoint_url('order-received')) {
            // 🆕 Detectar server type
            $server_compat = WSE_Pro_Server_Compatibility::get_instance();
            $server_info = $server_compat->get_server_info();
            
            wp_enqueue_script(
                'wse-pro-cart-capture',
                WSE_PRO_URL . 'assets/js/cart-capture.js',
                ['jquery'],
                WSE_PRO_VERSION,
                true
            );
            
            wp_localize_script('wse-pro-cart-capture', 'wseProCapture', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wse_pro_capture_cart_nonce'),
                'debug'    => defined('WP_DEBUG') && WP_DEBUG
            ]);

            // 🆕 Pasar info del servidor al HTML
            echo '<script>';
            echo 'document.documentElement.setAttribute("data-server-type", "' . esc_attr($server_info['server_type']) . '");';
            echo 'document.documentElement.setAttribute("data-wse-debug", "' . (defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false') . '");';
            echo '</script>';
        }
    }

    /**
     * 🔧 CAPTURA DE CARRITO - VERSIÓN CORREGIDA v2.2.2
     */
    public function capture_cart_via_ajax() {
        try {
            // 🔍 Verificar nonce (pero no fallar si no está presente)
            if (isset($_POST['nonce'])) {
                check_ajax_referer('wse_pro_capture_cart_nonce', 'nonce', false);
            }
            
            // 🔍 Verificar WooCommerce
            if (!function_exists('WC') || !WC()->cart) {
                $this->log_error('WooCommerce no disponible en capture_cart_via_ajax');
                wp_send_json_success(['captured' => false, 'error' => 'WooCommerce unavailable']);
                return;
            }

            // 🔍 Obtener datos de billing DIRECTAMENTE del POST
            $billing_data = [
                'billing_email'      => isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '',
                'billing_phone'      => isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '',
                'billing_first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '',
                'billing_last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '',
                'billing_address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
                'billing_city'       => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
                'billing_state'      => isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '',
                'billing_postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
                'billing_country'    => isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '',
            ];

            // 🔍 Validar que tengamos al menos email O teléfono
            if (empty($billing_data['billing_email']) && empty($billing_data['billing_phone'])) {
                $this->log_info('Sin email ni teléfono - no capturar');
                wp_send_json_success(['captured' => false]);
                return;
            }

            // 🔍 Verificar carrito
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                $this->log_info('Carrito vacío - no capturar');
                wp_send_json_success(['captured' => false]);
                return;
            }

            // 🔍 Guardar en BD
            $saved = $this->save_cart_to_database_safe($billing_data);
            
            if ($saved) {
                $this->log_info('✅ Carrito capturado exitosamente');
                wp_send_json_success(['captured' => true]);
                return;
            } else {
                $this->log_error('❌ Error al guardar en BD');
                wp_send_json_success(['captured' => false]);
                return;
            }

        } catch (Exception $e) {
            $this->log_error('Excepción en capture_cart_via_ajax: ' . $e->getMessage());
            wp_send_json_success(['captured' => false, 'error' => $e->getMessage()]);
            return;
        }
    }

    /**
     * 🔧 Guardar carrito de forma SEGURA y UNIVERSAL
     */
    private function save_cart_to_database_safe($billing_data) {
        try {
            global $wpdb;
            
            $session_id = WC()->session ? WC()->session->get_customer_id() : 0;
            $user_id = get_current_user_id();
            $cart = WC()->cart;
            
            // Preparar datos del carrito
            $cart_data = [
                'user_id' => $user_id,
                'session_id' => $session_id,
                'first_name' => $billing_data['billing_first_name'],
                'phone' => $billing_data['billing_phone'],
                'cart_contents' => maybe_serialize($cart->get_cart()),
                'cart_total' => floatval($cart->get_total('edit')),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'recovery_token' => bin2hex(random_bytes(16)),
                'status' => 'active',
                'messages_sent' => '0,0,0',
                'billing_email' => $billing_data['billing_email'],
                'billing_phone' => $billing_data['billing_phone'],
                'billing_first_name' => $billing_data['billing_first_name'],
                'billing_last_name' => $billing_data['billing_last_name'],
                'billing_address_1' => $billing_data['billing_address_1'],
                'billing_city' => $billing_data['billing_city'],
                'billing_state' => $billing_data['billing_state'],
                'billing_postcode' => $billing_data['billing_postcode'],
                'billing_country' => $billing_data['billing_country'],
            ];
            
            // Preparar formatos
            $format = [];
            foreach ($cart_data as $value) {
                if (is_int($value)) {
                    $format[] = '%d';
                } elseif (is_float($value)) {
                    $format[] = '%f';
                } else {
                    $format[] = '%s';
                }
            }
            
            // Insertar
            $result = $wpdb->insert(
                $wpdb->prefix . 'wse_pro_abandoned_carts',
                $cart_data,
                $format
            );
            
            if ($result === false) {
                $this->log_error('Error en INSERT: ' . $wpdb->last_error);
                return false;
            }
            
            $cart_id = $wpdb->insert_id;
            $this->log_info("✅ Carrito guardado - ID: {$cart_id}, Phone: " . $billing_data['billing_phone'] . ", Email: " . $billing_data['billing_email'] . ", Nombre: " . $billing_data['billing_first_name']);
            
            return true;
            
        } catch (Exception $e) {
            $this->log_error('Excepción en save_cart_to_database_safe: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 🔧 PROCESAMIENTO DE CARRITOS - VERSIÓN CORREGIDA v2.2.2
     */
    public function process_abandoned_carts_cron() {
        global $wpdb;
        
        $this->log_info('=== INICIANDO PROCESAMIENTO DE CARRITOS ===');
        
        // Obtener carritos activos
        $active_carts = $wpdb->get_results(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " 
             WHERE status = 'active' 
             ORDER BY created_at ASC"
        );
        
        if (empty($active_carts)) {
            $this->log_info('No hay carritos activos para procesar');
            return;
        }
        
        $this->log_info('Carritos activos encontrados: ' . count($active_carts));
        
        foreach ($active_carts as $cart) {
            $this->process_single_cart($cart);
        }
        
        $this->log_info('=== PROCESAMIENTO COMPLETADO ===');
    }

    /**
     * 🔧 PROCESAR CARRITO INDIVIDUAL - VERSIÓN CORREGIDA
     */
    private function process_single_cart($cart) {
        $cart_id = $cart->id;
        $created_at = strtotime($cart->created_at);
        $current_time = current_time('timestamp');
        $minutes_elapsed = floor(($current_time - $created_at) / 60);
        
        $this->log_info("Procesando carrito #{$cart_id} - {$minutes_elapsed} minutos desde creación");
        
        // Verificar cada mensaje (1, 2, 3)
        for ($i = 1; $i <= 3; $i++) {
            // ✅ FIX: Usar el nombre correcto de las opciones
            $message_enabled = get_option("wse_pro_abandoned_cart_enable_msg_{$i}", 'no');
            $message_delay = (int) get_option("wse_pro_abandoned_cart_time_{$i}", 60);
            $message_unit = get_option("wse_pro_abandoned_cart_unit_{$i}", 'minutes');
            
            if ($message_enabled !== 'yes') {
                $this->log_info("→ Mensaje #{$i} desactivado");
                continue;
            }
            
            // Calcular delay en minutos
            $delay_in_minutes = $message_delay;
            if ($message_unit === 'hours') {
                $delay_in_minutes = $message_delay * 60;
            } elseif ($message_unit === 'days') {
                $delay_in_minutes = $message_delay * 1440;
            }
            
            // Verificar si es momento de enviar
            if ($minutes_elapsed >= $delay_in_minutes) {
                // Verificar si ya se envió
                $messages_sent = explode(',', $cart->messages_sent);
                $already_sent = isset($messages_sent[$i - 1]) && $messages_sent[$i - 1] == '1';
                
                if (!$already_sent) {
                    $this->log_info("→ Mensaje #{$i} debe enviarse (delay: {$delay_in_minutes} min)");
                    $this->send_abandoned_cart_message($cart, $i);
                    break;
                } else {
                    $this->log_info("→ Mensaje #{$i} ya fue enviado");
                }
            } else {
                $remaining = $delay_in_minutes - $minutes_elapsed;
                $this->log_info("→ Mensaje #{$i} faltan {$remaining} minutos (delay: {$delay_in_minutes} min)");
            }
        }
    }

    /**
     * 🔧 ENVIAR MENSAJE - VERSIÓN COMPLETAMENTE REESCRITA v2.2.2
     */
    private function send_abandoned_cart_message($cart_row, $message_number) {
        global $wpdb;
        
        $this->log_info("📤 Iniciando envío mensaje #{$message_number} para carrito #{$cart_row->id}");
        
        // 1. Validar estado del carrito
        if ($cart_row->status !== 'active') {
            $this->log_warning("⚠️ Carrito #{$cart_row->id} no está activo (status: {$cart_row->status})");
            return false;
        }
        
        // 2. Verificar que el mensaje no se haya enviado
        $messages_sent = explode(',', $cart_row->messages_sent);
        if (isset($messages_sent[$message_number - 1]) && $messages_sent[$message_number - 1] == '1') {
            $this->log_info("⚠️ Mensaje #{$message_number} ya enviado anteriormente");
            return false;
        }
        
        // 3. Obtener plantilla
        $template = get_option('wse_pro_abandoned_cart_message_' . $message_number);
        if (empty($template)) {
            $this->log_error("❌ ERROR: Plantilla mensaje #{$message_number} vacía");
            return false;
        }
        
        // 4. Generar cupón si está habilitado
        $coupon_data = null;
        $coupon_enabled = get_option("wse_pro_abandoned_cart_coupon_enable_{$message_number}", 'no');
        
        if ($coupon_enabled === 'yes') {
            $coupon_manager = WSE_Pro_Coupon_Manager::get_instance();
            
            $prefix = get_option(
                "wse_pro_abandoned_cart_coupon_prefix_{$message_number}",
                'woowapp-m' . $message_number
            );
            
            $coupon_result = $coupon_manager->generate_coupon([
                'discount_type'   => get_option("wse_pro_abandoned_cart_coupon_type_{$message_number}", 'percent'),
                'discount_amount' => (float) get_option("wse_pro_abandoned_cart_coupon_amount_{$message_number}", 10),
                'expiry_days'     => (int) get_option("wse_pro_abandoned_cart_coupon_expiry_{$message_number}", 7),
                'customer_phone'  => $cart_row->phone,
                'customer_email'  => $cart_row->billing_email,
                'cart_id'         => $cart_row->id,
                'message_number'  => $message_number,
                'coupon_type'     => 'cart_recovery',
                'prefix'          => $prefix
            ]);
            
            if (!is_wp_error($coupon_result)) {
                $coupon_data = $coupon_result;
                $this->log_info("🎁 Cupón generado: {$coupon_result['code']}");
            }
        }
        
        // 5. Reemplazar placeholders
        $message = WSE_Pro_Placeholders::replace_for_cart($template, $cart_row, $coupon_data);
        
        $this->log_info("📝 Mensaje preparado: " . substr($message, 0, 100) . "...");
        
        // 6. Crear objeto para API
$cart_obj = (object)[
    'id' => $cart_row->id,
    'phone' => !empty($cart_row->billing_phone) ? $cart_row->billing_phone : $cart_row->phone, // <-- Usamos el teléfono correcto
    'cart_contents' => $cart_row->cart_contents,
    'billing_country' => $cart_row->billing_country // <-- AÑADIR ESTA LÍNEA (lee el país del carrito)
];
        
        // 7. Enviar mensaje
        $api_handler = new WSE_Pro_API_Handler();
        // Usar billing_phone si existe, si no, usar phone.
$phone_to_send = !empty($cart_row->billing_phone) ? $cart_row->billing_phone : $cart_row->phone;
        // --- INICIO CÓDIGO COOLDOWN DE 2 HORAS ---
$tracking_table = $wpdb->prefix . 'wse_pro_tracking';
$two_hours_ago = date('Y-m-d H:i:s', current_time('timestamp') - (2 * HOUR_IN_SECONDS));

// Buscar el último mensaje ENVIADO a este número
$last_sent_time = $wpdb->get_var($wpdb->prepare(
    "SELECT created_at FROM {$tracking_table} 
     WHERE event_type = 'sent' 
     AND JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.phone')) = %s
     ORDER BY created_at DESC 
     LIMIT 1",
    $phone_to_send 
));

if ($last_sent_time && $last_sent_time > $two_hours_ago) {
    // Si encontramos un envío reciente (menos de 2 horas), abortamos.
    $time_diff = human_time_diff(strtotime($last_sent_time), current_time('timestamp'));
    $this->log_info("🚫 Cooldown activo para {$phone_to_send}. Último envío hace {$time_diff}. Abortando mensaje #{$message_number} para carrito #{$cart_row->id}.");
    return false; // Salimos de la función send_abandoned_cart_message
}
// --- FIN CÓDIGO COOLDOWN ---
$result = $api_handler->send_message($phone_to_send, $message, $cart_obj, 'customer');;
        
        // 8. Procesar resultado
        if ($result['success']) {
            // Actualizar estado en BD
            $messages_sent[$message_number - 1] = '1';
            
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['messages_sent' => implode(',', $messages_sent)],
                ['id' => $cart_row->id],
                ['%s'],
                ['%d']
            );
            
            $this->log_info("✅ Mensaje #{$message_number} ENVIADO a {$cart_row->phone}");
            
            // Registrar en tracking
            $this->track_event($cart_row->id, $message_number, 'sent', [
                'phone' => $cart_row->phone,
                'coupon' => $coupon_data ? $coupon_data['code'] : ''
            ]);
            
            return true;
        } else {
            $error = isset($result['message']) ? $result['message'] : 'Error desconocido';
            $this->log_error("❌ ERROR al enviar mensaje: {$error}");
            return false;
        }
    }

    /**
     * ========================================
     * RECUPERACIÓN DE CARRITO
     * ========================================
     */

    public function handle_cart_recovery_link() {
        if (!isset($_GET['recover-cart-wse'])) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            $this->log_error('WooCommerce no disponible en recuperación');
            wp_die(__('Error: WooCommerce no está disponible. Por favor, contacta al administrador.', 'woowapp-smsenlinea-pro'));
            return;
        }

        try {
            global $wpdb;
            $token = sanitize_text_field($_GET['recover-cart-wse']);
            
            $this->log_info("🔗 Intento de recuperación - Token: {$token}");

            $cart_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE recovery_token = %s",
                $token
            ));

            if (!$cart_row) {
                $this->log_warning("Token no válido: {$token}");
                wc_add_notice(
                    __('El enlace de recuperación no es válido o ha expirado.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            // Registrar click
            $this->track_event($cart_row->id, 0, 'click', []);
            
            // Marcar en sesión que viene de recuperación
            WC()->session->set('wse_recovering_cart', [
                'cart_id' => $cart_row->id,
                'timestamp' => current_time('timestamp'),
                'phone' => $cart_row->phone
            ]);

            if ($cart_row->status === 'recovered') {
                $this->log_info("Carrito ya recuperado - ID: {$cart_row->id}");
                wc_add_notice(
                    __('Este carrito ya fue recuperado anteriormente.', 'woowapp-smsenlinea-pro'),
                    'notice'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            WC()->cart->empty_cart();

            $cart_contents = maybe_unserialize($cart_row->cart_contents);
            
            if (!is_array($cart_contents) || empty($cart_contents)) {
                $this->log_error("Carrito vacío - ID: {$cart_row->id}");
                wc_add_notice(
                    __('El carrito está vacío o no se pudo recuperar.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            $products_restored = 0;
            $products_failed = 0;

            foreach ($cart_contents as $item) {
                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    $products_failed++;
                    continue;
                }

                $product_id = absint($item['product_id']);
                $quantity = absint($item['quantity']);
                $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
                $variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];

                $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
                
                if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                    $products_failed++;
                    $this->log_warning("Producto no disponible - ID: {$product_id}, Var: {$variation_id}");
                    continue;
                }

                $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
                
                if ($added) {
                    $products_restored++;
                } else {
                    $products_failed++;
                }
            }

            $this->log_info("Recuperación - ID: {$cart_row->id}, Restaurados: {$products_restored}, Fallidos: {$products_failed}");

            // Restaurar datos del cliente en WC_Customer
            $customer = WC()->customer;
            if ($customer) {
                // Billing
                if (!empty($cart_row->billing_first_name)) $customer->set_billing_first_name($cart_row->billing_first_name);
                if (!empty($cart_row->billing_last_name)) $customer->set_billing_last_name($cart_row->billing_last_name);
                if (!empty($cart_row->billing_email)) $customer->set_billing_email($cart_row->billing_email);
                if (!empty($cart_row->billing_phone)) $customer->set_billing_phone($cart_row->billing_phone);
                if (!empty($cart_row->billing_address_1)) $customer->set_billing_address_1($cart_row->billing_address_1);
                if (!empty($cart_row->billing_city)) $customer->set_billing_city($cart_row->billing_city);
                
                // País PRIMERO, luego estado
                if (!empty($cart_row->billing_country)) {
                    $customer->set_billing_country($cart_row->billing_country);
                    $this->log_info("País restaurado: {$cart_row->billing_country}");
                }
                
                if (!empty($cart_row->billing_state)) {
                    $customer->set_billing_state($cart_row->billing_state);
                    $this->log_info("Estado restaurado: {$cart_row->billing_state}");
                }
                
                if (!empty($cart_row->billing_postcode)) $customer->set_billing_postcode($cart_row->billing_postcode);
                
                // Guardar todo
                $customer->save();
                
                // Guardar también en sesión de WooCommerce
                if (WC()->session) {
                    $checkout_fields = [
                        'billing_first_name' => $cart_row->billing_first_name,
                        'billing_last_name' => $cart_row->billing_last_name,
                        'billing_email' => $cart_row->billing_email,
                        'billing_phone' => $cart_row->billing_phone,
                        'billing_address_1' => $cart_row->billing_address_1,
                        'billing_city' => $cart_row->billing_city,
                        'billing_country' => $cart_row->billing_country,
                        'billing_state' => $cart_row->billing_state,
                        'billing_postcode' => $cart_row->billing_postcode,
                    ];
                    
                    // Filtrar valores vacíos
                    $checkout_fields = array_filter($checkout_fields, function($value) {
                        return !empty($value);
                    });
                    
                    WC()->session->set('wse_prefill_checkout_fields', $checkout_fields);
                    
                    $this->log_info("Datos guardados en sesión WC - País: " . ($cart_row->billing_country ?? 'no definido'));
                }
            }

            // Aplicar cupón
            try {
                $coupon_manager = WSE_Pro_Coupon_Manager::get_instance();
                $coupon = $coupon_manager->get_latest_coupon_for_cart($cart_row->id);
                
                if ($coupon && !WC()->cart->has_discount($coupon->coupon_code)) {
                    $applied = WC()->cart->apply_coupon($coupon->coupon_code);
                    
                    if ($applied) {
                        wc_add_notice(
                            sprintf(
                                __('¡Cupón "%s" aplicado exitosamente! 🎁', 'woowapp-smsenlinea-pro'),
                                $coupon->coupon_code
                            ),
                            'success'
                        );
                    }
                }
            } catch (Exception $e) {
                $this->log_warning("Error aplicando cupón: " . $e->getMessage());
            }

            if ($products_restored > 0) {
                wc_add_notice(
                    sprintf(
                        _n(
                            '¡Tu carrito ha sido restaurado con %d producto! 🛒',
                            '¡Tu carrito ha sido restaurado con %d productos! 🛒',
                            $products_restored,
                            'woowapp-smsenlinea-pro'
                        ),
                        $products_restored
                    ),
                    'success'
                );
            }

            if ($products_failed > 0) {
                wc_add_notice(
                    sprintf(
                        _n(
                            '%d producto ya no está disponible.',
                            '%d productos ya no están disponibles.',
                            $products_failed,
                            'woowapp-smsenlinea-pro'
                        ),
                        $products_failed
                    ),
                    'notice'
                );
            }

            if ($products_restored === 0) {
                wc_add_notice(
                    __('No se pudieron restaurar los productos de tu carrito.', 'woowapp-smsenlinea-pro'),
                    'error'
                );
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            wp_safe_redirect(wc_get_checkout_url());
            exit();

        } catch (Exception $e) {
            $this->log_error("Error en recuperación: " . $e->getMessage());
            wc_add_notice(
                __('Ocurrió un error al recuperar tu carrito. Por favor, contacta al soporte.', 'woowapp-smsenlinea-pro'),
                'error'
            );
            wp_safe_redirect(wc_get_cart_url());
            exit();
        }
    }

    public function populate_checkout_fields($value, $input) {
        $customer = WC()->customer;
        
        if (!$customer) {
            return $value;
        }

        $field_map = [
            'billing_first_name' => 'get_billing_first_name',
            'billing_last_name'  => 'get_billing_last_name',
            'billing_email'      => 'get_billing_email',
            'billing_phone'      => 'get_billing_phone',
            'billing_address_1'  => 'get_billing_address_1',
            'billing_city'       => 'get_billing_city',
            'billing_state'      => 'get_billing_state',
            'billing_postcode'   => 'get_billing_postcode',
            'billing_country'    => 'get_billing_country',
        ];

        if (isset($field_map[$input]) && method_exists($customer, $field_map[$input])) {
            $customer_value = $customer->{$field_map[$input]}();
            if (!empty($customer_value)) {
                return $customer_value;
            }
        }

        return $value;
    }

    public function cancel_abandoned_cart_reminder($order_id) {
        $session_id = WC()->session->get_customer_id();
        
        if (empty($session_id)) {
            return;
        }
        
        global $wpdb;
        
        $active_cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . " 
             WHERE session_id = %s AND status = 'active'",
            $session_id
        ));

        if ($active_cart) {
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['status' => 'recovered'],
                ['id' => $active_cart->id]
            );
            
            $this->log_info("Carrito marcado como recuperado al crear pedido #{$order_id}");
        }
    }

    /**
     * ========================================
     * 🆕 SISTEMA DE TRACKING
     * ========================================
     */

    private function track_event($cart_id, $message_number, $event_type, $event_data = []) {
        global $wpdb;
        
        $wpdb->insert(
            self::$tracking_table_name,
            [
                'cart_id' => $cart_id,
                'message_number' => $message_number,
                'event_type' => $event_type,
                'event_data' => json_encode($event_data),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
        
        $this->log_info("📊 Tracking: {$event_type} registrado para carrito #{$cart_id}");
    }

    public function track_conversion($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $phone = $order->get_billing_phone();
        if (empty($phone)) return;
        
        global $wpdb;
        
        // Buscar carrito abandonado asociado
        $cart = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$abandoned_cart_table_name . "
            WHERE phone = %s AND status = 'active'
            ORDER BY created_at DESC LIMIT 1",
            $phone
        ));
        
        if ($cart) {
            // Registrar conversión
            $this->track_event($cart->id, 0, 'conversion', [
                'order_id' => $order_id,
                'order_total' => $order->get_total(),
                'phone' => $phone
            ]);
            
            // Marcar carrito como recuperado
            $wpdb->update(
                self::$abandoned_cart_table_name,
                ['status' => 'recovered'],
                ['id' => $cart->id],
                ['%s'],
                ['%d']
            );
            
            $this->log_info("🎉 CONVERSIÓN registrada - Carrito #{$cart->id} → Pedido #{$order_id}");
        }
    }

    /**
     * ========================================
     * NOTIFICACIONES DE PEDIDOS
     * ========================================
     */

    public function trigger_status_change_notification($order_id, $order) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_status_change($order_id, $order);
    }

    public function trigger_new_note_notification($data) {
        $api_handler = new WSE_Pro_API_Handler();
        $api_handler->handle_new_note($data);
    }

    /**
     * ========================================
     * RECORDATORIOS DE RESEÑAS
     * ========================================
     */

    public function schedule_review_reminder($order_id) {
        wp_clear_scheduled_hook('wse_pro_send_review_reminder_event', [$order_id]);
        
        if ('yes' !== get_option('wse_pro_enable_review_reminder', 'no')) {
            return;
        }
        
        $delay_days = (int) get_option('wse_pro_review_reminder_days', 7);
        
        if ($delay_days <= 0) {
            return;
        }
        
        $time_to_send = time() + ($delay_days * DAY_IN_SECONDS);
        wp_schedule_single_event($time_to_send, 'wse_pro_send_review_reminder_event', [$order_id]);
    }

    public function send_review_reminder_notification($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $template = get_option('wse_pro_review_reminder_message');
        
        if (empty($template)) {
            return;
        }
        
        $api_handler = new WSE_Pro_API_Handler();
        $message = WSE_Pro_Placeholders::replace($template, $order);
        $api_handler->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    public function render_review_form_shortcode($atts) {
        return $this->get_review_form_html();
    }

   // Dentro de la clase WooWApp en woowapp.php

private function get_review_form_html() {
    global $wpdb; // Necesitamos acceso a la base de datos

    // --- INICIO PROCESAMIENTO DEL FORMULARIO ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wse_review_nonce']) && isset($_POST['review_order_id'])) {
        if (!wp_verify_nonce($_POST['wse_review_nonce'], 'wse_submit_review')) {
            return '<div class="woocommerce-error">' . __('Error de seguridad. Inténtalo de nuevo.', 'woowapp-smsenlinea-pro') . '</div>';
        }

        $order_id = absint($_POST['review_order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            return '<div class="woocommerce-error">' . __('Pedido no válido.', 'woowapp-smsenlinea-pro') . '</div>';
        }

        $reviews_submitted = 0;
        $reviews_failed = 0;
        $reviews_skipped = 0;
        // Ya no necesitamos $highest_rating ni $coupon_generated_for_any aquí

        // Procesar cada producto enviado en el formulario
        if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
            foreach ($_POST['product_id'] as $item_id => $product_id_for_review) {

                $item_id = absint($item_id);
                $product_id_for_review = absint($product_id_for_review);
                $rating = isset($_POST['review_rating'][$item_id]) ? absint($_POST['review_rating'][$item_id]) : 0;
                $comment_text = isset($_POST['review_comment'][$item_id]) ? sanitize_textarea_field(trim($_POST['review_comment'][$item_id])) : '';

                // --- MODIFICACIÓN: Saltar si no hay calificación NI comentario ---
                if ($rating === 0 && empty($comment_text)) {
                    continue; // Saltar este producto si no se dejó reseña
                }
                // --- FIN MODIFICACIÓN ---

                // --- INICIO VALIDACIÓN RESEÑA DUPLICADA ---
                $existing_comments = get_comments([
                    'post_id' => $product_id_for_review,
                    'author_email' => $order->get_billing_email(),
                    'type' => 'review',
                    'count' => true
                ]);

                if ($existing_comments > 0) {
                    $reviews_skipped++;
                    $this->log_info("Reseña duplicada omitida para producto #{$product_id_for_review} por {$order->get_billing_email()} (pedido #{$order_id})");
                    continue;
                }
                // --- FIN VALIDACIÓN RESEÑA DUPLICADA ---

                $product = wc_get_product($product_id_for_review);
                if (!$product) {
                    $reviews_failed++;
                    continue;
                }

                // Preparar datos de la reseña
                $verified = wc_customer_bought_product($order->get_billing_email(), $order->get_user_id(), $product_id_for_review);
                $commentdata = [
                    'comment_post_ID'      => $product_id_for_review,
                    'comment_author'       => $order->get_billing_first_name(),
                    'comment_author_email' => $order->get_billing_email(),
                    'comment_author_url'   => '',
                    'comment_content'      => $comment_text,
                    'comment_agent'        => 'WooWApp',
                    'comment_date'         => current_time('mysql'),
                    'user_id'              => $order->get_user_id() ?: 0,
                    // --- MODIFICACIÓN: Cambiar a pendiente ---
                    'comment_approved'     => 0, // 0 = Pendiente, 1 = Aprobado
                    // --- FIN MODIFICACIÓN ---
                    'comment_type'         => 'review',
                    'comment_meta'         => [
                        'rating'   => $rating ?: 5, // Default a 5 si solo hay comentario
                        'verified' => $verified ? 1 : 0,
                        // --- NUEVO: Guardar Order ID ---
                        'order_id' => $order_id,
                        // --- FIN NUEVO ---
                    ],
                ];

                // Insertar la reseña
                $comment_id = wp_insert_comment($commentdata);

                if ($comment_id && !is_wp_error($comment_id)) {
                    // --- MODIFICACIÓN: Eliminar actualización de contador (se hará al aprobar) ---
                    // wc_update_product_review_count($product_id_for_review); // Comentado o eliminado
                    // --- FIN MODIFICACIÓN ---
                    $reviews_submitted++;
                } else {
                    $reviews_failed++;
                     $error_msg = is_wp_error($comment_id) ? $comment_id->get_error_message() : 'Error desconocido al guardar reseña.';
                     $this->log_error("Fallo al guardar reseña para producto #{$product_id_for_review} (pedido #{$order_id}). Razón: {$error_msg}");
                }
            } // Fin foreach producto
        } // Fin if isset($_POST['product_id'])

        // --- MODIFICACIÓN: ELIMINAMOS EL BLOQUE DE ENVÍO DE AGRADECIMIENTO/CUPÓN ---
        // El bloque que empezaba con: if ($reviews_submitted > 0 && 'yes' === get_option('wse_pro_enable_review_reward', 'no')) { ... }
        // HA SIDO ELIMINADO COMPLETAMENTE.

        // --- MOSTRAR MENSAJE DE ÉXITO AJUSTADO ---
        $success_message = '<div class="woowapp-review-success" style="background-color: #e6f7ff; border-left: 4px solid #1890ff; padding: 20px; margin: 20px 0; border-radius: 4px;">'; // Color azul para pendiente
        $success_message .= '<h4 style="margin-top: 0; color: #0050b3;">' . __('¡Gracias por tu opinión!', 'woowapp-smsenlinea-pro') . '</h4>';
        if ($reviews_submitted > 0) {
             // Mensaje indicando que está pendiente
             $success_message .= '<p>' . sprintf(_n('Tu reseña ha sido enviada y está pendiente de aprobación.', '%d reseñas han sido enviadas y están pendientes de aprobación.', $reviews_submitted, 'woowapp-smsenlinea-pro'), $reviews_submitted) . ' ' . __('Apreciamos mucho tu tiempo y tus comentarios.', 'woowapp-smsenlinea-pro') . '</p>';
        }
        if ($reviews_skipped > 0) {
            $success_message .= '<p style="color: #718096; font-size: small;">' . sprintf(_n('Se omitió %d reseña porque ya habías dejado una opinión para ese producto.', 'Se omitieron %d reseñas porque ya habías dejado una opinión para esos productos.', $reviews_skipped, 'woowapp-smsenlinea-pro'), $reviews_skipped) . '</p>';
        }
         if ($reviews_failed > 0) {
             $success_message .= '<p style="color: #c53030; font-size: small;">' . sprintf(_n('Hubo un problema al guardar %d reseña.', 'Hubo un problema al guardar %d reseñas.', $reviews_failed, 'woowapp-smsenlinea-pro'), $reviews_failed) . '</p>';
        }
        // --- MODIFICACIÓN: Eliminar mensaje sobre cupón enviado ---
        // El bloque if ($coupon_generated_for_any && $coupon_data) { ... } ha sido eliminado.
        // --- FIN MODIFICACIÓN ---
         $success_message .= '<p><a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button">' . __('Volver a la tienda', 'woowapp-smsenlinea-pro') . '</a></p>';
        $success_message .= '</div>';

        return $success_message;

    } // --- FIN PROCESAMIENTO DEL FORMULARIO ---

    // --- INICIO MOSTRAR EL FORMULARIO ---
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

    if ($order_id > 0 && !empty($order_key)) {
        $order = wc_get_order($order_id);

        if ($order && $order->key_is_valid($order_key)) {
            $html = '<div class="woowapp-review-container">';
            $html .= '<h3>' . sprintf(
                __('Deja una reseña para los productos de tu pedido #%s', 'woowapp-smsenlinea-pro'),
                $order->get_order_number()
            ) . '</h3>';

            // Abrir el formulario principal
            $html .= '<form method="post" class="woowapp-review-form">';
            $html .= wp_nonce_field('wse_submit_review', 'wse_review_nonce', true, false);
            $html .= '<input type="hidden" name="review_order_id" value="' . esc_attr($order_id) . '" />';

            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                if (!$product) continue;
                $product_id_for_review = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();

                // --- INICIO VALIDACIÓN RESEÑA EXISTENTE ---
                 $existing_comments = get_comments([
                    'post_id' => $product_id_for_review,
                    'author_email' => $order->get_billing_email(),
                    'type' => 'review',
                    'count' => true
                ]);
                // --- FIN VALIDACIÓN RESEÑA EXISTENTE ---


                $html .= '<div class="review-form-wrapper" style="border:1px solid #ddd; padding:20px; margin-bottom:20px; border-radius: 5px; display: flex; gap: 20px;">'; // Flex para imagen y form

                // --- NUEVO: Mostrar Imagen del Producto ---
                $image_id = $product->get_image_id();
                if (!$image_id && $product->is_type('variation')) {
                    $parent_product = wc_get_product($product->get_parent_id());
                    if ($parent_product) $image_id = $parent_product->get_image_id();
                }
                // Usamos 'woocommerce_thumbnail' para mejor rendimiento que 'thumbnail' o 'full'
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail');
                $html .= '<div class="review-product-image" style="flex-shrink: 0;">';
                // Ajustamos tamaño a 100x100
                $html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($product->get_name()) . '" width="100" height="100" style="border-radius: 4px; object-fit: cover; border: 1px solid #eee;"/>';
                $html .= '</div>';
                // --- FIN NUEVO ---

                $html .= '<div class="review-product-fields" style="flex-grow: 1;">'; // Contenedor para los campos
                $html .= '<h4>' . esc_html($product->get_name()) . '</h4>';

                if ($existing_comments > 0) {
                     $html .= '<p class="woocommerce-info">' . __('Ya has dejado una reseña para este producto.', 'woowapp-smsenlinea-pro') . '</p>';
                } else {
                    // Solo mostrar campos si no hay reseña previa
                    $html .= '<input type="hidden" name="product_id[' . esc_attr($item_id) . ']" value="' . esc_attr($product_id_for_review) . '" />';

                    $html .= '<p class="comment-form-rating">';
                    $html .= '<label for="review_rating-' . esc_attr($item_id) . '">' . __('Tu calificación', 'woowapp-smsenlinea-pro') . '</label>'; // Quitamos el * de requerido
                    // Dejar rating opcional si hay comentario, agregamos opción vacía
                    $html .= '<select name="review_rating[' . esc_attr($item_id) . ']" id="review_rating-' . esc_attr($item_id) . '" style="width: auto;">';
                    $html .= '<option value="">' . __('Selecciona...', 'woowapp-smsenlinea-pro') . '</option>'; // Opción vacía
                    $html .= '<option value="5">⭐⭐⭐⭐⭐</option>';
                    $html .= '<option value="4">⭐⭐⭐⭐</option>';
                    $html .= '<option value="3">⭐⭐⭐</option>';
                    $html .= '<option value="2">⭐⭐</option>';
                    $html .= '<option value="1">⭐</option>';
                    $html .= '</select></p>';

                    $html .= '<p class="comment-form-comment">';
                    $html .= '<label for="review_comment-' . esc_attr($item_id) . '">' . __('Tu reseña', 'woowapp-smsenlinea-pro') . '</label>'; // Ya era opcional
                    $html .= '<textarea name="review_comment[' . esc_attr($item_id) . ']" id="review_comment-' . esc_attr($item_id) . '" cols="45" rows="4" style="width:100%;"></textarea>';
                    $html .= '</p>';
                }
                $html .= '</div>'; // Fin review-product-fields
                $html .= '</div>'; // Fin review-form-wrapper
            } // Fin foreach item

            // Botón de envío principal fuera del loop
             $html .= '<p class="form-submit">';
             // Texto del botón ajustado
             $html .= '<input name="submit_reviews" type="submit" class="submit button button-primary" value="' . __('Enviar Reseñas Pendientes', 'woowapp-smsenlinea-pro') . '" />';
             $html .= '</p>';
            $html .= '</form>'; // Cerrar el formulario principal

            $html .= '</div>'; // Fin woowapp-review-container
            return $html;
        } else {
            return '<div class="woocommerce-error">' .
                   __('El enlace de reseña no es válido o ha caducado.', 'woowapp-smsenlinea-pro') .
                   '</div>';
        }
    }

    return '<div class="woocommerce-info">' .
           __('Para dejar una reseña, por favor usa el enlace proporcionado en el mensaje.', 'woowapp-smsenlinea-pro') .
           '</div>';
    // --- FIN MOSTRAR EL FORMULARIO ---
}
    public function handle_custom_review_page_content($content) {
        $page_id = get_option('wse_pro_review_page_id');
        
        if (!is_page($page_id) || has_shortcode($content, 'woowapp_review_form')) {
            return $content;
        }
        
        return $content . $this->get_review_form_html();
    }

    public function add_manual_review_request_action($actions) {
        $actions['wse_send_review_request'] = __('Enviar solicitud de reseña por WhatsApp/SMS', 'woowapp-smsenlinea-pro');
        return $actions;
    }

    public function process_manual_review_request_action($order) {
        $template = get_option('wse_pro_review_reminder_message');
        
        if (!empty($template)) {
            $this->send_review_reminder_notification($order->get_id());
            $order->add_order_note(__('Solicitud de reseña enviada manualmente al cliente.', 'woowapp-smsenlinea-pro'));
        } else {
            $order->add_order_note(__('Fallo al enviar solicitud de reseña: La plantilla de mensaje está vacía.', 'woowapp-smsenlinea-pro'));
        }
    }
/**
 * NUEVA FUNCIÓN: Permite que el script test-abandoned-cart.php funcione
 */
public function debug_force_send_message($cart_id) {
    global $wpdb;

    $this->log_info("DEBUG: Forzando envío para carrito #{$cart_id}");

    $cart_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . self::$abandoned_cart_table_name . " WHERE id = %d",
        $cart_id
    ));

    if (!$cart_row) {
        $this->log_error("DEBUG: Carrito #{$cart_id} no encontrado para forzar envío.");
        return;
    }

    // Extraer el número de mensaje del hook actual
    $current_hook = current_action(); // Ej: 'wse_pro_send_abandoned_cart_1'
    $message_number = (int) str_replace('wse_pro_send_abandoned_cart_', '', $current_hook);

    if ($message_number < 1 || $message_number > 3) {
         $this->log_error("DEBUG: Número de mensaje '{$message_number}' no válido.");
        return;
    }

    // Llamar a la función de envío real
    $this->send_abandoned_cart_message($cart_row, $message_number);
}
    /**
     * ========================================
     * UTILIDADES Y LOGGING
     * ========================================
     */
    
    public function add_diagnostic_menu() {
        add_submenu_page(
            'woocommerce',
            __('Diagnóstico WooWApp', 'woowapp-smsenlinea-pro'),
            __('🔍 Diagnóstico WooWApp', 'woowapp-smsenlinea-pro'),
            'manage_woocommerce',
            'woowapp-diagnostic',
            [$this, 'render_diagnostic_page']
        );
    }
    
    public function render_diagnostic_page() {
        global $wpdb;
        
        ?>
        <div class="wrap">
            <h1>🔍 Diagnóstico WooWApp Pro v<?php echo WSE_PRO_VERSION; ?></h1>
            
            <?php if (isset($_GET['repaired'])): ?>
            <div class="notice notice-success">
                <p><strong>✅ Reparación completada exitosamente.</strong></p>
            </div>
            <?php endif; ?>
            
            <!-- Información del Plugin -->
            <div class="card" style="margin-top:20px;">
                <h2>📦 Información del Plugin</h2>
                <table class="widefat">
                    <tr>
                        <th>Versión Plugin</th>
                        <td><?php echo WSE_PRO_VERSION; ?></td>
                        <td><?php echo WSE_PRO_VERSION === '2.2.2' ? '✅' : '❌'; ?></td>
                    </tr>
                    <tr>
                        <th>Versión BD</th>
                        <td><?php echo get_option('wse_pro_db_version', '0'); ?></td>
                        <td><?php echo get_option('wse_pro_db_version') === '2.2.2' ? '✅' : '⚠️'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Estructura de Base de Datos -->
            <div class="card" style="margin-top:20px;">
                <h2>🗄️ Estructura de Base de Datos</h2>
                <?php
                $table = self::$abandoned_cart_table_name;
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
                
                if ($table_exists) {
                    $columns = $wpdb->get_results("DESCRIBE $table");
                    $column_names = array_column($columns, 'Field');
                    
                    $required = [
                        'id', 'session_id', 'phone', 'cart_contents', 'cart_total',
                        'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
                        'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                        'status', 'messages_sent', 'created_at', 'updated_at', 'recovery_token'
                    ];
                    
                    $missing = array_diff($required, $column_names);
                    $obsolete = array_intersect(['scheduled_time'], $column_names);
                    ?>
                    
                    <table class="widefat">
                        <tr>
                            <th>Tabla Carritos</th>
                            <td><?php echo count($column_names); ?> columnas</td>
                            <td><?php echo empty($missing) ? '✅' : '❌'; ?></td>
                        </tr>
                        <?php if (!empty($missing)): ?>
                        <tr>
                            <th>Columnas Faltantes</th>
                            <td colspan="2" style="color:#ef4444;">
                                <?php echo implode(', ', $missing); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($obsolete)): ?>
                        <tr>
                            <th>Columnas Obsoletas</th>
                            <td colspan="2" style="color:#f59e0b;">
                                <?php echo implode(', ', $obsolete); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php
                    $tracking_exists = $wpdb->get_var("SHOW TABLES LIKE '" . self::$tracking_table_name . "'") === self::$tracking_table_name;
                    ?>
                    <table class="widefat" style="margin-top:10px;">
                        <tr>
                            <th>Tabla Tracking</th>
                            <td><?php echo $tracking_exists ? 'Existe' : 'No existe'; ?></td>
                            <td><?php echo $tracking_exists ? '✅' : '❌'; ?></td>
                        </tr>
                    </table>
                    
                    <?php if (!empty($missing) || !empty($obsolete) || !$tracking_exists): ?>
                    <div style="margin-top:20px;">
                        <form method="post" action="">
                            <?php wp_nonce_field('woowapp_repair', 'woowapp_repair_nonce'); ?>
                            <input type="hidden" name="action" value="repair_database">
                            <button type="submit" class="button button-primary button-large">
                                🔧 Reparar Base de Datos
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                <?php } else { ?>
                    <p style="color:#ef4444;">❌ La tabla de carritos no existe.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('woowapp_repair', 'woowapp_repair_nonce'); ?>
                        <input type="hidden" name="action" value="repair_database">
                        <button type="submit" class="button button-primary">
                            🔧 Crear Tablas
                        </button>
                    </form>
                <?php } ?>
            </div>
            
            <!-- Estadísticas -->
            <div class="card" style="margin-top:20px;">
                <h2>📊 Estadísticas Generales</h2>
                <?php
                $total_carts = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$abandoned_cart_table_name);
                $active_carts = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$abandoned_cart_table_name . " WHERE status = 'active'");
                $recovered = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$abandoned_cart_table_name . " WHERE status = 'recovered'");
                
                $tracking_exists = $wpdb->get_var("SHOW TABLES LIKE '" . self::$tracking_table_name . "'") === self::$tracking_table_name;
                if ($tracking_exists) {
                    $total_sent = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$tracking_table_name . " WHERE event_type = 'sent'");
                    $total_clicks = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$tracking_table_name . " WHERE event_type = 'click'");
                    $total_conversions = $wpdb->get_var("SELECT COUNT(*) FROM " . self::$tracking_table_name . " WHERE event_type = 'conversion'");
                } else {
                    $total_sent = $total_clicks = $total_conversions = 0;
                }
                ?>
                <table class="widefat">
                    <tr>
                        <th>Total Carritos</th>
                        <td><?php echo number_format($total_carts); ?></td>
                    </tr>
                    <tr>
                        <th>Carritos Activos</th>
                        <td><?php echo number_format($active_carts); ?></td>
                    </tr>
                    <tr>
                        <th>Carritos Recuperados</th>
                        <td><?php echo number_format($recovered); ?></td>
                    </tr>
                    <tr>
                        <th>Mensajes Enviados</th>
                        <td><?php echo number_format($total_sent); ?></td>
                    </tr>
                    <tr>
                        <th>Clicks en Enlaces</th>
                        <td><?php echo number_format($total_clicks); ?></td>
                    </tr>
                    <tr>
                        <th>Conversiones</th>
                        <td><?php echo number_format($total_conversions); ?></td>
                    </tr>
                </table>
                
                <?php if ($total_sent > 0): ?>
                <div style="margin-top:20px;">
                    <a href="<?php echo admin_url('admin.php?page=wse-pro-stats'); ?>" class="button button-primary">
                        📊 Ver Dashboard Completo
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .card {
                background: white;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .widefat th {
                width: 250px;
                font-weight: 600;
            }
        </style>
        <?php
    }
    
    public function handle_diagnostic_actions() {
        if (!isset($_POST['action']) || !isset($_POST['woowapp_repair_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['woowapp_repair_nonce'], 'woowapp_repair')) {
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        switch ($_POST['action']) {
            case 'repair_database':
                $this->verify_and_repair_database();
                self::create_database_tables();
                wp_redirect(admin_url('admin.php?page=woowapp-diagnostic&repaired=1'));
                exit;
        }
    }

    public function check_review_page_exists() {
        $page_id = get_option('wse_pro_review_page_id');
        
        if (!$page_id || get_post_status($page_id) !== 'publish') {
            $screen = get_current_screen();
            
            if ($screen && $screen->id === 'woocommerce_page_wc-settings') {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>WooWApp:</strong> ';
                echo __('La página de reseñas no existe o no está publicada. ', 'woowapp-smsenlinea-pro');
                echo '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=woowapp&action=recreate_review_page')) . '">';
                echo __('Haz clic aquí para recrearla', 'woowapp-smsenlinea-pro');
                echo '</a></p></div>';
            }
        }
    }

    public function missing_wc_notice() {
        echo '<div class="error"><p>';
        echo '<strong>' . esc_html__('WooWApp', 'woowapp-smsenlinea-pro') . ':</strong> ';
        echo esc_html__('Este plugin requiere que WooCommerce esté instalado y activo para funcionar.', 'woowapp-smsenlinea-pro');
        echo '</p></div>';
    }

    private function log_info($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->info($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }

    private function log_warning($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }

    private function log_error($message) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, ['source' => 'woowapp-' . date('Y-m-d')]);
        }
    }

    public static function on_deactivation() {
        wp_clear_scheduled_hook('wse_pro_process_abandoned_carts');
        wp_clear_scheduled_hook('wse_pro_cleanup_coupons');
        
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info(
                'WooWApp desactivado',
                ['source' => 'woowapp-' . date('Y-m-d')]
            );
        }
    }
    /**
 * NUEVA FUNCIÓN: Notifica a los administradores cuando se envía una nueva reseña
 * y queda pendiente de aprobación.
 *
 * @param int        $comment_id     El ID del comentario recién insertado.
 * @param WP_Comment $comment_object El objeto del comentario recién insertado.
 */
public function notify_admin_on_pending_review($comment_id, $comment_object) {
    // Verificar si es una reseña de producto ('review') y está pendiente ('0')
    if ($comment_object->comment_type === 'review' && $comment_object->comment_approved == '0') {

        $this->log_info("Detectada nueva reseña pendiente #{$comment_id}. Verificando notificación para admin...");

        // Verificar si la notificación para admin está activa en los ajustes
        if ('yes' !== get_option('wse_pro_enable_admin_pending_review', 'no')) {
            $this->log_info("Notificación de reseña pendiente para admin DESACTIVADA. No se enviará mensaje.");
            return; // Notificación desactivada, no hacemos nada.
        }

        // Obtener los números de teléfono de los administradores desde los ajustes
        $admin_numbers_raw = get_option('wse_pro_admin_numbers', '');
        $admin_numbers = array_filter(array_map('trim', explode("\n", $admin_numbers_raw)));

        if (empty($admin_numbers)) {
            $this->log_warning("No se envió notificación de reseña pendiente #{$comment_id}: No hay números de admin configurados.");
            return; // No hay admins a quien notificar.
        }

        // Obtener la plantilla del mensaje para el admin desde los ajustes
        $template = get_option('wse_pro_admin_message_pending_review');
        if (empty($template)) {
            $this->log_warning("No se envió notificación de reseña pendiente #{$comment_id}: La plantilla del mensaje para admin está vacía.");
            return; // Plantilla vacía.
        }

        // Obtener datos necesarios para rellenar los placeholders del mensaje
        // Usamos el 'order_id' que guardamos en el paso anterior
        $order_id = get_comment_meta($comment_id, 'order_id', true);
        $order = $order_id ? wc_get_order($order_id) : null; // Obtener el objeto del pedido si tenemos ID
        $product = wc_get_product($comment_object->comment_post_ID); // Obtener el producto reseñado
        $rating = get_comment_meta($comment_id, 'rating', true); // Obtener la calificación

        // Creamos un array con placeholders adicionales específicos para esta notificación
        $extras = [
            // Usamos el nombre que el cliente puso en la reseña
            '{customer_fullname}' => $comment_object->comment_author,
            // Mostramos el ID del pedido asociado
            '{order_id}'          => $order_id ?: __('N/A', 'woowapp-smsenlinea-pro'),
            // Nombre del producto que se reseñó
            '{first_product_name}'=> $product ? $product->get_name() : __('Producto Desconocido', 'woowapp-smsenlinea-pro'),
            // Calificación dada
            '{review_rating}'     => $rating ?: __('N/A', 'woowapp-smsenlinea-pro'),
            // El texto de la reseña
            '{review_content}'    => $comment_object->comment_content,
            // Un enlace directo para que el admin pueda aprobar/editar la reseña
            '{review_moderation_link}' => admin_url('comment.php?action=editcomment&c=' . $comment_id),
        ];

        // Si pudimos obtener el objeto $order, lo usamos como base para placeholders generales (como {shop_name})
        // Si no (porque no se guardó el order_id por alguna razón), usamos un objeto vacío para evitar errores.
        $message_source = $order ?: (object)[];

        // Reemplazamos los placeholders en la plantilla con los valores reales
        $message = WSE_Pro_Placeholders::replace($template, $message_source, $extras);

        $this->log_info("Preparando envío de notificación de reseña #{$comment_id} a administradores...");

        // Creamos una instancia para manejar el envío por API
        $api_handler = new WSE_Pro_API_Handler();

        // Enviamos el mensaje a cada número de administrador configurado
        foreach ($admin_numbers as $number) {
            $result = $api_handler->send_message($number, $message, $message_source, 'admin');
            if($result['success']) {
                $this->log_info("Notificación de reseña #{$comment_id} enviada a admin ({$number}).");
            } else {
                $this->log_error("FALLO al enviar notificación de reseña #{$comment_id} a admin ({$number}). Razón: " . $result['message']);
            }
        }
    } else {
        // Log si el comentario no es una reseña o no está pendiente (solo para debug si es necesario)
        // $this->log_info("Comentario #{$comment_id} insertado, pero no es una reseña pendiente (Tipo: {$comment_object->comment_type}, Estado: {$comment_object->comment_approved}). No se notifica al admin.");
    }
}
    /**
 * NUEVA FUNCIÓN: Envía el mensaje de agradecimiento y/o cupón
 * cuando una reseña es aprobada por un administrador.
 *
 * @param string     $new_status El nuevo estado del comentario ('approved', 'spam', 'trash', etc.).
 * @param string     $old_status El estado anterior del comentario.
 * @param WP_Comment $comment    El objeto del comentario que cambió de estado.
 */
public function send_reward_on_review_approval($new_status, $old_status, $comment) {
    // Solo actuar si:
    // 1. El nuevo estado es 'aprobado'.
    // 2. El estado anterior NO era 'aprobado' (para evitar que se ejecute múltiples veces si se edita una reseña ya aprobada).
    // 3. El tipo de comentario es 'review'.
    if ($new_status === 'approved' && $old_status !== 'approved' && $comment->comment_type === 'review') {

        $comment_id = $comment->comment_ID;
        $this->log_info("Detectada aprobación de reseña #{$comment_id}. Verificando envío de recompensa...");

        // Verificar si la función de recompensa está activada en los ajustes
        if ('yes' !== get_option('wse_pro_enable_review_reward', 'no')) { //
            $this->log_info("Envío de recompensa por reseña DESACTIVADO. No se hará nada para reseña #{$comment_id}.");
            return; // Recompensa desactivada.
        }

        // Recuperamos el ID del pedido que guardamos en los metadatos del comentario
        $order_id = get_comment_meta($comment_id, 'order_id', true); //
        if (empty($order_id)) {
            // Si no encontramos el ID del pedido, no podemos continuar (no sabemos a quién enviar ni qué cupón dar).
            $this->log_warning("No se envió recompensa para reseña #{$comment_id}: Falta el 'order_id' en los metadatos del comentario.");
            return;
        }

        // Intentamos obtener el objeto del pedido usando el ID
        $order = wc_get_order($order_id); //
        if (!$order) {
            // Si el pedido ya no existe o el ID es incorrecto.
            $this->log_warning("No se envió recompensa para reseña #{$comment_id}: Pedido #{$order_id} no encontrado.");
            return;
        }

        // --- INICIO: Mecanismo para evitar envíos múltiples por el MISMO PEDIDO ---
        // Usaremos un metadato en el *pedido* para marcar si ya se envió la recompensa.
        $reward_sent_key = '_wse_review_reward_sent'; // Nombre del metadato que usaremos
        // Comprobamos si este metadato ya existe y tiene valor 'yes' para este pedido
        if (get_post_meta($order_id, $reward_sent_key, true) === 'yes') { //
            // Si ya existe, significa que ya enviamos la recompensa (probablemente por otra reseña del mismo pedido aprobada antes).
            $this->log_info("Recompensa para pedido #{$order_id} ya enviada anteriormente (aunque la reseña #{$comment_id} acaba de ser aprobada). No se enviará de nuevo.");
            // Actualizamos el contador de reseñas ahora que esta también está aprobada.
            wc_update_product_review_count($comment->comment_post_ID); //
            return; // Salimos para no enviar otro mensaje/cupón.
        }
        // --- FIN: Evitar envíos múltiples ---

        // Obtenemos datos necesarios de la reseña y del pedido
        $rating = get_comment_meta($comment_id, 'rating', true); // La calificación
        $customer_phone = $order->get_billing_phone(); // El teléfono del cliente (del pedido)

        // Si no hay teléfono en el pedido, no podemos enviar.
        if (empty($customer_phone)) {
            $this->log_warning("No se envió recompensa para pedido #{$order_id} (reseña #{$comment_id}): Teléfono del cliente vacío en el pedido.");
            return;
        }

        $this->log_info("Preparando recompensa para pedido #{$order_id} (reseña #{$comment_id}, rating: {$rating}). Teléfono: {$customer_phone}");

        // Variables para el cupón (si aplica)
        $coupon_data = null; // Aquí guardaremos los datos si se genera
        $enable_coupon = get_option('wse_pro_review_reward_coupon_enable', 'no'); // ¿Está activada la opción de dar cupón?
        $min_rating = (int) get_option('wse_pro_review_reward_min_rating', 4); // ¿Cuál es el mínimo de estrellas requerido?

        // Comprobamos si debemos generar un cupón
        if ('yes' === $enable_coupon && $rating >= $min_rating) {
            $this->log_info("Generando cupón de recompensa para pedido #{$order_id} (rating {$rating} >= {$min_rating})...");
            $coupon_manager = WSE_Pro_Coupon_Manager::get_instance(); // Obtenemos el gestor de cupones
            // Preparamos los argumentos para crear el cupón
            $coupon_args = [ //
                'discount_type'   => get_option('wse_pro_review_reward_coupon_type', 'percent'), //
                'discount_amount' => (float) get_option('wse_pro_review_reward_coupon_amount', 15), //
                'expiry_days'     => (int) get_option('wse_pro_review_reward_coupon_expiry', 14), //
                'customer_phone'  => $customer_phone,
                'customer_email'  => $order->get_billing_email(), // Restringir por email también
                'order_id'        => $order_id, // Asociar al pedido
                'coupon_type'     => 'review_reward', // Marcar tipo
                'prefix'          => get_option('wse_pro_review_reward_coupon_prefix', 'RESEÑA') // Usar prefijo
            ];
            // Intentamos generar el cupón
            $coupon_result = $coupon_manager->generate_coupon($coupon_args); //

            // Verificamos si se generó correctamente
            if (!is_wp_error($coupon_result) && isset($coupon_result['success']) && $coupon_result['success']) { //
                $coupon_data = $coupon_result; // Guardamos los datos del cupón generado
                $this->log_info("Cupón recompensa {$coupon_data['coupon_code']} generado exitosamente para pedido #{$order_id}.");
            } else {
                 // Si hubo un error al generar
                 $error_msg = is_wp_error($coupon_result) ? $coupon_result->get_error_message() : 'Error desconocido al generar cupón.';
                 $this->log_error("Fallo al generar cupón recompensa (pedido #{$order_id}, reseña #{$comment_id}). Razón: {$error_msg}");
                 // Continuamos sin cupón, solo se enviará el mensaje de agradecimiento.
            }
        } else {
             if ('yes' === $enable_coupon) {
                 $this->log_info("No se genera cupón para pedido #{$order_id}: el rating ({$rating}) es menor al mínimo requerido ({$min_rating}).");
             } else {
                  $this->log_info("La generación de cupones por reseña está desactivada. No se genera cupón para pedido #{$order_id}.");
             }
        }

        // Preparar y enviar el mensaje (de agradecimiento, con o sin cupón)
        $template = get_option('wse_pro_review_reward_message'); // Obtenemos la plantilla del mensaje
        if (!empty($template)) {
            $api_handler = new WSE_Pro_API_Handler(); // Obtenemos el manejador de API
            $placeholders_extras = []; // Array para placeholders adicionales

            // Si generamos un cupón, añadimos sus datos a los placeholders
            if ($coupon_data) { //
                 $placeholders_extras['{coupon_code}'] = $coupon_data['coupon_code'] ?? '';
                 $placeholders_extras['{coupon_amount}'] = $coupon_data['formatted_discount'] ?? '';
                 $placeholders_extras['{coupon_expires}'] = $coupon_data['formatted_expiry'] ?? '';
            } else {
                // Si no hay cupón, nos aseguramos que esos placeholders queden vacíos
                 $placeholders_extras['{coupon_code}'] = '';
                 $placeholders_extras['{coupon_amount}'] = '';
                 $placeholders_extras['{coupon_expires}'] = '';
            }
            // Agregamos también placeholders de la reseña en sí
            $placeholders_extras['{review_rating}'] = $rating ?: __('N/A', 'woowapp-smsenlinea-pro'); //
            $placeholders_extras['{review_content}'] = $comment->comment_content; //

            // Generamos el mensaje final reemplazando todos los placeholders
            $message = WSE_Pro_Placeholders::replace($template, $order, $placeholders_extras); //

            $this->log_info("Enviando mensaje de agradecimiento/recompensa a {$customer_phone} para pedido #{$order_id}...");

            // Intentamos enviar el mensaje
            $result = $api_handler->send_message($customer_phone, $message, $order, 'customer'); //

            // Verificamos si el envío fue exitoso
            if ($result['success']) { //
                 $this->log_info("Mensaje agradecimiento/recompensa ENVIADO exitosamente para pedido #{$order_id} tras aprobación de reseña #{$comment_id}.");
                 // --- IMPORTANTE: Marcamos el pedido para no enviar la recompensa de nuevo ---
                 update_post_meta($order_id, $reward_sent_key, 'yes'); //
                 // --- FIN IMPORTANTE ---
            } else {
                 // Si falló el envío del mensaje
                 $this->log_error("FALLO al enviar mensaje agradecimiento/recompensa para pedido #{$order_id} (reseña #{$comment_id}). Razón API: {$result['message']}"); //
                 // NO marcamos el pedido, para que se intente de nuevo si otra reseña se aprueba.
            }

        } else {
             // Si la plantilla del mensaje está vacía en los ajustes
             $this->log_warning("No se envió agradecimiento/recompensa (pedido #{$order_id}, reseña #{$comment_id}): La plantilla del mensaje está vacía en los ajustes.");
             // NO marcamos el pedido.
        }

        // --- IMPORTANTE: Actualizamos el contador de reseñas del producto AHORA que está aprobada ---
        wc_update_product_review_count($comment->comment_post_ID); //
        $this->log_info("Contador de reseñas actualizado para producto #{$comment->comment_post_ID}.");
        // --- FIN IMPORTANTE ---
    }
}
    
    /**
 * NUEVA FUNCIÓN: Envía el mensaje de agradecimiento y/o cupón
 * cuando una reseña es aprobada por un administrador.
 *
 * @param string     $new_status El nuevo estado del comentario ('approved', 'spam', 'trash', etc.).
 * @param string     $old_status El estado anterior del comentario.
 * @param WP_Comment $comment    El objeto del comentario que cambió de estado.
 */
public function send_reward_on_review_approval($new_status, $old_status, $comment) {
    // Solo actuar si:
    // 1. El nuevo estado es 'aprobado'.
    // 2. El estado anterior NO era 'aprobado' (para evitar que se ejecute múltiples veces si se edita una reseña ya aprobada).
    // 3. El tipo de comentario es 'review'.
    if ($new_status === 'approved' && $old_status !== 'approved' && $comment->comment_type === 'review') {

        $comment_id = $comment->comment_ID;
        $this->log_info("Detectada aprobación de reseña #{$comment_id}. Verificando envío de recompensa...");

        // Verificar si la función de recompensa está activada en los ajustes
        if ('yes' !== get_option('wse_pro_enable_review_reward', 'no')) {
            $this->log_info("Envío de recompensa por reseña DESACTIVADO. No se hará nada para reseña #{$comment_id}.");
            return; // Recompensa desactivada.
        }

        // Recuperamos el ID del pedido que guardamos en los metadatos del comentario
        $order_id = get_comment_meta($comment_id, 'order_id', true);
        if (empty($order_id)) {
            // Si no encontramos el ID del pedido, no podemos continuar (no sabemos a quién enviar ni qué cupón dar).
            $this->log_warning("No se envió recompensa para reseña #{$comment_id}: Falta el 'order_id' en los metadatos del comentario.");
            return;
        }

        // Intentamos obtener el objeto del pedido usando el ID
        $order = wc_get_order($order_id);
        if (!$order) {
            // Si el pedido ya no existe o el ID es incorrecto.
            $this->log_warning("No se envió recompensa para reseña #{$comment_id}: Pedido #{$order_id} no encontrado.");
            return;
        }

        // --- INICIO: Mecanismo para evitar envíos múltiples por el MISMO PEDIDO ---
        // Usaremos un metadato en el *pedido* para marcar si ya se envió la recompensa.
        $reward_sent_key = '_wse_review_reward_sent'; // Nombre del metadato que usaremos
        // Comprobamos si este metadato ya existe y tiene valor 'yes' para este pedido
        if (get_post_meta($order_id, $reward_sent_key, true) === 'yes') {
            // Si ya existe, significa que ya enviamos la recompensa (probablemente por otra reseña del mismo pedido aprobada antes).
            $this->log_info("Recompensa para pedido #{$order_id} ya enviada anteriormente (aunque la reseña #{$comment_id} acaba de ser aprobada). No se enviará de nuevo.");
            // Actualizamos el contador de reseñas ahora que esta también está aprobada.
            wc_update_product_review_count($comment->comment_post_ID);
            return; // Salimos para no enviar otro mensaje/cupón.
        }
        // --- FIN: Evitar envíos múltiples ---

        // Obtenemos datos necesarios de la reseña y del pedido
        $rating = get_comment_meta($comment_id, 'rating', true); // La calificación
        $customer_phone = $order->get_billing_phone(); // El teléfono del cliente (del pedido)

        // Si no hay teléfono en el pedido, no podemos enviar.
        if (empty($customer_phone)) {
            $this->log_warning("No se envió recompensa para pedido #{$order_id} (reseña #{$comment_id}): Teléfono del cliente vacío en el pedido.");
            return;
        }

        $this->log_info("Preparando recompensa para pedido #{$order_id} (reseña #{$comment_id}, rating: {$rating}). Teléfono: {$customer_phone}");

        // Variables para el cupón (si aplica)
        $coupon_data = null; // Aquí guardaremos los datos si se genera
        $enable_coupon = get_option('wse_pro_review_reward_coupon_enable', 'no'); // ¿Está activada la opción de dar cupón?
        $min_rating = (int) get_option('wse_pro_review_reward_min_rating', 4); // ¿Cuál es el mínimo de estrellas requerido?

        // Comprobamos si debemos generar un cupón
        if ('yes' === $enable_coupon && $rating >= $min_rating) {
            $this->log_info("Generando cupón de recompensa para pedido #{$order_id} (rating {$rating} >= {$min_rating})...");
            $coupon_manager = WSE_Pro_Coupon_Manager::get_instance(); // Obtenemos el gestor de cupones
            // Preparamos los argumentos para crear el cupón
            $coupon_args = [
                'discount_type'   => get_option('wse_pro_review_reward_coupon_type', 'percent'),
                'discount_amount' => (float) get_option('wse_pro_review_reward_coupon_amount', 15),
                'expiry_days'     => (int) get_option('wse_pro_review_reward_coupon_expiry', 14),
                'customer_phone'  => $customer_phone,
                'customer_email'  => $order->get_billing_email(), // Restringir por email también
                'order_id'        => $order_id, // Asociar al pedido
                'coupon_type'     => 'review_reward', // Marcar tipo
                'prefix'          => get_option('wse_pro_review_reward_coupon_prefix', 'RESEÑA') // Usar prefijo
            ];
            // Intentamos generar el cupón
            $coupon_result = $coupon_manager->generate_coupon($coupon_args);

            // Verificamos si se generó correctamente
            if (!is_wp_error($coupon_result) && isset($coupon_result['success']) && $coupon_result['success']) {
                $coupon_data = $coupon_result; // Guardamos los datos del cupón generado
                $this->log_info("Cupón recompensa {$coupon_data['coupon_code']} generado exitosamente para pedido #{$order_id}.");
            } else {
                 // Si hubo un error al generar
                 $error_msg = is_wp_error($coupon_result) ? $coupon_result->get_error_message() : 'Error desconocido al generar cupón.';
                 $this->log_error("Fallo al generar cupón recompensa (pedido #{$order_id}, reseña #{$comment_id}). Razón: {$error_msg}");
                 // Continuamos sin cupón, solo se enviará el mensaje de agradecimiento.
            }
        } else {
             if ('yes' === $enable_coupon) {
                 $this->log_info("No se genera cupón para pedido #{$order_id}: el rating ({$rating}) es menor al mínimo requerido ({$min_rating}).");
             } else {
                  $this->log_info("La generación de cupones por reseña está desactivada. No se genera cupón para pedido #{$order_id}.");
             }
        }

        // Preparar y enviar el mensaje (de agradecimiento, con o sin cupón)
        $template = get_option('wse_pro_review_reward_message'); // Obtenemos la plantilla del mensaje
        if (!empty($template)) {
            $api_handler = new WSE_Pro_API_Handler(); // Obtenemos el manejador de API
            $placeholders_extras = []; // Array para placeholders adicionales

            // Si generamos un cupón, añadimos sus datos a los placeholders
            if ($coupon_data) {
                 $placeholders_extras['{coupon_code}'] = $coupon_data['coupon_code'] ?? '';
                 $placeholders_extras['{coupon_amount}'] = $coupon_data['formatted_discount'] ?? '';
                 $placeholders_extras['{coupon_expires}'] = $coupon_data['formatted_expiry'] ?? '';
            } else {
                // Si no hay cupón, nos aseguramos que esos placeholders queden vacíos
                 $placeholders_extras['{coupon_code}'] = '';
                 $placeholders_extras['{coupon_amount}'] = '';
                 $placeholders_extras['{coupon_expires}'] = '';
            }
            // Agregamos también placeholders de la reseña en sí
            $placeholders_extras['{review_rating}'] = $rating ?: __('N/A', 'woowapp-smsenlinea-pro');
            $placeholders_extras['{review_content}'] = $comment->comment_content;

            // Generamos el mensaje final reemplazando todos los placeholders
            $message = WSE_Pro_Placeholders::replace($template, $order, $placeholders_extras);

            $this->log_info("Enviando mensaje de agradecimiento/recompensa a {$customer_phone} para pedido #{$order_id}...");

            // Intentamos enviar el mensaje
            $result = $api_handler->send_message($customer_phone, $message, $order, 'customer');

            // Verificamos si el envío fue exitoso
            if ($result['success']) {
                 $this->log_info("Mensaje agradecimiento/recompensa ENVIADO exitosamente para pedido #{$order_id} tras aprobación de reseña #{$comment_id}.");
                 // --- IMPORTANTE: Marcamos el pedido para no enviar la recompensa de nuevo ---
                 update_post_meta($order_id, $reward_sent_key, 'yes');
                 // --- FIN IMPORTANTE ---
            } else {
                 // Si falló el envío del mensaje
                 $this->log_error("FALLO al enviar mensaje agradecimiento/recompensa para pedido #{$order_id} (reseña #{$comment_id}). Razón API: {$result['message']}");
                 // NO marcamos el pedido, para que se intente de nuevo si otra reseña se aprueba.
            }

        } else {
             // Si la plantilla del mensaje está vacía en los ajustes
             $this->log_warning("No se envió agradecimiento/recompensa (pedido #{$order_id}, reseña #{$comment_id}): La plantilla del mensaje está vacía en los ajustes.");
             // NO marcamos el pedido.
        }

        // --- IMPORTANTE: Actualizamos el contador de reseñas del producto AHORA que está aprobada ---
        wc_update_product_review_count($comment->comment_post_ID);
        $this->log_info("Contador de reseñas actualizado para producto #{$comment->comment_post_ID}.");
        // --- FIN IMPORTANTE ---
    }
}
}
// Nueva función para manejar la captura de carrito sin duplicados
add_action('wp_ajax_wse_pro_capture_cart', 'handle_cart_capture');
add_action('wp_ajax_nopriv_wse_pro_capture_cart', 'handle_cart_capture');

function handle_cart_capture() {
    if (!check_ajax_referer('wse_pro_capture_cart_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce inválido']);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wse_pro_abandoned_carts';

    // Obtener datos del POST
    $phone = sanitize_text_field($_POST['billing_phone'] ?? '');
    $email = sanitize_email($_POST['billing_email'] ?? '');
    $first_name = sanitize_text_field($_POST['billing_first_name'] ?? '');
    $last_name = sanitize_text_field($_POST['billing_last_name'] ?? '');
    $address_1 = sanitize_text_field($_POST['billing_address_1'] ?? '');
    $city = sanitize_text_field($_POST['billing_city'] ?? '');
    $state = sanitize_text_field($_POST['billing_state'] ?? '');
    $postcode = sanitize_text_field($_POST['billing_postcode'] ?? '');
    $country = sanitize_text_field($_POST['billing_country'] ?? '');
    
    // Obtener sesión de WooCommerce
    $session_id = WC()->session ? WC()->session->get_customer_id() : '';

    $existing = null;
    
    // --- NUEVA LÓGICA DE BÚSQUEDA (CORREGIDA) ---
    if (!empty($phone) || !empty($email)) {
        // Caso 1: Tenemos teléfono o email. Buscar por ellos.
        $query_parts = [];
        $params = [];
        
        if (!empty($phone)) {
            $query_parts[] = "phone = %s";
            $params[] = $phone;
        }
        if (!empty($email)) {
            $query_parts[] = "billing_email = %s";
            $params[] = $email;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE (" . implode(' OR ', $query_parts) . ")
             AND status = 'active' 
             ORDER BY id DESC LIMIT 1",
            $params
        ));
    } 
    
    if (!$existing) {
        // Caso 2: No se encontró por phone/email, O están vacíos. Buscar por session_id.
        // Solo buscar por session_id si NO está vacío (para no agrupar a todos los invitados)
        if (!empty($session_id)) {
             $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE session_id = %s
                 AND status = 'active' 
                 ORDER BY id DESC LIMIT 1",
                $session_id
            ));
        }
    }
    // --- FIN NUEVA LÓGICA ---

    $now = current_time('mysql');
    $cart_contents = serialize(WC()->cart ? WC()->cart->get_cart() : []);
    $cart_total = WC()->cart ? WC()->cart->get_total('edit') : 0;
    
    // Preparamos el array de datos
    $cart_data = [
        'first_name' => $first_name,
        'phone' => $phone, // <-- Corregido
        'cart_contents' => $cart_contents,
        'cart_total' => $cart_total,
        'billing_first_name' => $first_name,
        'billing_last_name' => $last_name,
        'billing_email' => $email,
        'billing_phone' => $phone, // <-- Corregido
        'billing_address_1' => $address_1,
        'billing_city' => $city,
        'billing_state' => $state,
        'billing_postcode' => $postcode,
        'billing_country' => $country,
        'updated_at' => $now,
    ];

    if ($existing) {
        // Actualizar el existente
        $wpdb->update(
            $table,
            $cart_data,
            ['id' => $existing->id]
        );
        wp_send_json_success(['message' => 'Carrito actualizado', 'captured' => true]);
    } else {
        // Insertar nuevo
        $cart_data['session_id'] = $session_id;
        $cart_data['user_id'] = get_current_user_id();
        $cart_data['status'] = 'active';
        $cart_data['messages_sent'] = '0,0,0';
        $cart_data['created_at'] = $now;
        $cart_data['recovery_token'] = wp_generate_uuid4();
        
        $wpdb->insert($table, $cart_data);
        wp_send_json_success(['message' => 'Carrito capturado', 'captured' => true]);
    }
}
// Inicializar el plugin
WooWApp::get_instance();
















