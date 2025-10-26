<?php
/**
 * Compatibilidad automática de servidor
 * Detecta y adapta el plugin a cualquier configuración
 * 
 * @package WooWApp
 * @version 2.2.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Server_Compatibility {

    private static $instance = null;
    private static $server_info = [];
    private static $diagnostics = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_diagnostic_menu'], 100);
        add_action('admin_init', [$this, 'run_diagnostics']);
    }

    /**
     * ========================================
     * DETECCIÓN AUTOMÁTICA DE SERVIDOR
     * ========================================
     */

    public static function detect_server_type() {
        $server_software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        
        if (stripos($server_software, 'nginx') !== false) {
            return 'nginx';
        } elseif (stripos($server_software, 'apache') !== false) {
            return 'apache';
        } elseif (function_exists('apache_get_version')) {
            return 'apache';
        } else {
            return 'unknown';
        }
    }

    public static function detect_php_version() {
        return PHP_VERSION;
    }

    public static function detect_php_fpm_socket() {
        // Si 'open_basedir' está activo, omitimos la búsqueda de archivos fuera del directorio permitido
        // para evitar los mensajes de "Warning" en el servidor.
        if (ini_get('open_basedir')) {
            return null;
        }
        
        // Para Nginx + PHP-FPM, encontrar el socket correcto
        $common_sockets = [
            '/var/run/php/php8.3-fpm.sock',
            '/var/run/php/php8.2-fpm.sock',
            '/var/run/php/php8.1-fpm.sock',
            '/var/run/php/php8.0-fpm.sock',
            '/var/run/php/php7.4-fpm.sock',
            '/var/run/php/php7.3-fpm.sock',
            '/var/run/php-fpm/www.sock',
            '/var/run/php-fpm.sock',
        ];

        foreach ($common_sockets as $socket) {
            if (file_exists($socket)) {
                return $socket;
            }
        }

        return null;
    }

    public static function get_server_info() {
        if (!empty(self::$server_info)) {
            return self::$server_info;
        }

        self::$server_info = [
            'server_type' => self::detect_server_type(),
            'php_version' => self::detect_php_version(),
            'php_fpm_socket' => self::detect_php_fpm_socket(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'allow_url_fopen' => ini_get('allow_url_fopen'),
            'open_basedir' => ini_get('open_basedir') ?: 'None',
            'fastcgi_enabled' => function_exists('fastcgi_finish_request'),
            'curl_enabled' => extension_loaded('curl'),
            'mbstring_enabled' => extension_loaded('mbstring'),
        ];

        return self::$server_info;
    }

    /**
     * ========================================
     * DIAGNÓSTICOS Y VALIDACIONES
     * ========================================
     */

    public static function run_diagnostics() {
        self::$diagnostics = [
            'php' => self::check_php(),
            'wordpress' => self::check_wordpress(),
            'woocommerce' => self::check_woocommerce(),
            'database' => self::check_database(),
            'permissions' => self::check_permissions(),
            'ajax' => self::check_ajax(),
            'network' => self::check_network(),
        ];

        // Guardar en BD para acceso rápido
        update_option('wse_pro_diagnostics', self::$diagnostics);
        update_option('wse_pro_server_info', self::get_server_info());
    }

    private static function check_php() {
        return [
            'version' => PHP_VERSION,
            'version_ok' => version_compare(PHP_VERSION, '7.3.0', '>='),
            'safe_mode' => ini_get('safe_mode'),
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
                'mysqli' => extension_loaded('mysqli'),
                'pdo' => extension_loaded('pdo'),
            ],
            'functions' => [
                'file_get_contents' => function_exists('file_get_contents'),
                'json_encode' => function_exists('json_encode'),
                'sanitize_text_field' => function_exists('sanitize_text_field'),
            ],
        ];
    }

    private static function check_wordpress() {
        global $wp_version;
        
        return [
            'version' => $wp_version ?? 'Unknown',
            'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'multisite' => is_multisite(),
            'plugins_active' => count(get_option('active_plugins', [])),
            'wp_memory_limit' => WP_MEMORY_LIMIT,
            'cache_control' => get_option('woocommerce_cache_enabled'),
        ];
    }

    private static function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            return ['status' => 'inactive'];
        }

        return [
            'status' => 'active',
            'version' => defined('WC_VERSION') ? WC_VERSION : 'Unknown',
            'currency' => get_woocommerce_currency(),
            'checkout_page' => wc_get_page_id('checkout'),
            'cart_page' => wc_get_page_id('cart'),
        ];
    }

    private static function check_database() {
        global $wpdb;

        $abandoned_carts_table = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$abandoned_carts_table'") === $abandoned_carts_table;

        return [
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'charset' => DB_CHARSET,
            'tables' => [
                'abandoned_carts' => $table_exists,
                'coupons' => $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "wse_pro_coupons_generated'") === $wpdb->prefix . 'wse_pro_coupons_generated',
                'tracking' => $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "wse_pro_tracking'") === $wpdb->prefix . 'wse_pro_tracking',
            ],
            'query_errors' => $wpdb->last_error ?: 'None',
        ];
    }

    private static function check_permissions() {
        $plugin_dir = WP_PLUGIN_DIR . '/woowapp';
        $wp_content = WP_CONTENT_DIR;

        return [
            'plugin_dir_readable' => is_readable($plugin_dir),
            'plugin_dir_writable' => is_writable($plugin_dir),
            'wp_content_writable' => is_writable($wp_content),
            'uploads_dir' => wp_upload_dir(),
            'temp_dir' => sys_get_temp_dir(),
            'temp_writable' => is_writable(sys_get_temp_dir()),
        ];
    }

    private static function check_ajax() {
        return [
            'wp_ajax_hooks_registered' => has_action('wp_ajax_wse_pro_capture_cart') ? 'yes' : 'no',
            'nonce_generator' => function_exists('wp_create_nonce') ? 'yes' : 'no',
            'current_user_can' => function_exists('current_user_can') ? 'yes' : 'no',
        ];
    }

    private static function check_network() {
        return [
            'home_url' => home_url(),
            'admin_url' => admin_url(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => site_url(),
            'curl_available' => function_exists('curl_version'),
            'wp_remote_post' => function_exists('wp_remote_post'),
            'ssl_verify' => (bool) ini_get('curl.cainfo'),
        ];
    }

    /**
     * ========================================
     * PÁGINA DE DIAGNÓSTICO
     * ========================================
     */

    public function add_diagnostic_menu() {
        add_submenu_page(
            'woocommerce',
            __('Diagnóstico de Servidor - WooWApp', 'woowapp-smsenlinea-pro'),
            __('🔧 Diagnóstico de Servidor', 'woowapp-smsenlinea-pro'),
            'manage_woocommerce',
            'wse-pro-server-diagnostic',
            [$this, 'render_diagnostic_page']
        );
    }

    public function render_diagnostic_page() {
        $server_info = self::get_server_info();
        $diagnostics = get_option('wse_pro_diagnostics', []);
        
        ?>
        <div class="wrap">
            <h1><?php _e('🔧 Diagnóstico Automático de Servidor - WooWApp', 'woowapp-smsenlinea-pro'); ?></h1>
            
            <style>
                .status-card {
                    background: white;
                    border-left: 5px solid;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 5px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .status-card.ok { border-color: #10b981; }
                .status-card.warning { border-color: #f59e0b; }
                .status-card.error { border-color: #ef4444; }
                
                .info-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 20px;
                }
                
                .info-box {
                    background: #f9fafb;
                    padding: 15px;
                    border-radius: 5px;
                    border: 1px solid #e5e7eb;
                }
                
                .info-box strong {
                    color: #1f2937;
                    display: block;
                    margin-bottom: 5px;
                }
                
                .info-box code {
                    background: white;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 12px;
                    color: #6366f1;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 5px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    margin-left: 10px;
                }
                
                .status-badge.ok {
                    background: #d1fae5;
                    color: #047857;
                }
                
                .status-badge.warning {
                    background: #fef3c7;
                    color: #b45309;
                }
                
                .status-badge.error {
                    background: #fee2e2;
                    color: #991b1b;
                }
            </style>
            
            <!-- SERVIDOR -->
            <div class="status-card ok">
                <h2><?php _e('🖥️ Información del Servidor', 'woowapp-smsenlinea-pro'); ?></h2>
                <div class="info-grid">
                    <div class="info-box">
                        <strong><?php esc_html_e('Tipo de Servidor', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html(strtoupper($server_info['server_type'])); ?></code>
                        <span class="status-badge ok">✅ <?php esc_html_e('Detectado', 'woowapp-smsenlinea-pro'); ?></span>
                    </div>
                    
                    <div class="info-box">
                        <strong><?php esc_html_e('Software', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html($server_info['server_software']); ?></code>
                    </div>
                    
                    <div class="info-box">
                        <strong><?php esc_html_e('Versión de PHP', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html($server_info['php_version']); ?></code>
                        <span class="status-badge ok">✅ 8.3+</span>
                    </div>
                    
                    <?php if ($server_info['php_fpm_socket']): ?>
                    <div class="info-box">
                        <strong><?php esc_html_e('Socket PHP-FPM', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html($server_info['php_fpm_socket']); ?></code>
                        <span class="status-badge ok">✅ <?php esc_html_e('Encontrado', 'woowapp-smsenlinea-pro'); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-box">
                        <strong><?php esc_html_e('Ejecución Máxima', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html($server_info['max_execution_time']); ?>s</code>
                    </div>
                    
                    <div class="info-box">
                        <strong><?php esc_html_e('Límite de Memoria', 'woowapp-smsenlinea-pro'); ?></strong>
                        <code><?php echo esc_html($server_info['memory_limit']); ?></code>
                    </div>
                </div>
            </div>

            <!-- PHP -->
            <div class="status-card <?php echo (isset($diagnostics['php']) && $diagnostics['php']['version_ok']) ? 'ok' : 'error'; ?>">
                <h2><?php _e('😊 PHP', 'woowapp-smsenlinea-pro'); ?></h2>
                <div class="info-grid">
                    <?php if (isset($diagnostics['php'])): ?>
                        <div class="info-box">
                            <strong><?php esc_html_e('Versión Soportada', 'woowapp-smsenlinea-pro'); ?></strong>
                            <span class="status-badge <?php echo $diagnostics['php']['version_ok'] ? 'ok' : 'error'; ?>">
                                <?php echo $diagnostics['php']['version_ok'] ? '✅' : '❌'; ?>
                            </span>
                        </div>
                        
                        <?php foreach ($diagnostics['php']['extensions'] as $ext => $enabled): ?>
                        <div class="info-box">
                            <strong><?php echo esc_html(ucfirst($ext)); ?></strong>
                            <span class="status-badge <?php echo $enabled ? 'ok' : 'error'; ?>">
                                <?php echo $enabled ? '✅ ' . esc_html__('Activo', 'woowapp-smsenlinea-pro') : '❌ ' . esc_html__('Inactivo', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- WORDPRESS -->
            <div class="status-card ok">
                <h2><?php _e('📘 WordPress', 'woowapp-smsenlinea-pro'); ?></h2>
                <div class="info-grid">
                    <?php if (isset($diagnostics['wordpress'])): ?>
                        <div class="info-box">
                            <strong><?php esc_html_e('Versión', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo esc_html($diagnostics['wordpress']['version']); ?></code>
                        </div>
                        
                        <div class="info-box">
                            <strong><?php esc_html_e('Debug', 'woowapp-smsenlinea-pro'); ?></strong>
                            <span class="status-badge <?php echo $diagnostics['wordpress']['debug_enabled'] ? 'warning' : 'ok'; ?>">
                                <?php echo $diagnostics['wordpress']['debug_enabled'] ? '⚠️ ' . esc_html__('Activo', 'woowapp-smsenlinea-pro') : '✅ ' . esc_html__('Desactivo', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </div>
                        
                        <div class="info-box">
                            <strong><?php esc_html_e('Plugins Activos', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo (int) $diagnostics['wordpress']['plugins_active']; ?></code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- WOOCOMMERCE -->
            <div class="status-card ok">
                <h2><?php _e('🛒 WooCommerce', 'woowapp-smsenlinea-pro'); ?></h2>
                <div class="info-grid">
                    <?php if (isset($diagnostics['woocommerce'])): ?>
                        <div class="info-box">
                            <strong><?php esc_html_e('Estado', 'woowapp-smsenlinea-pro'); ?></strong>
                            <span class="status-badge <?php echo $diagnostics['woocommerce']['status'] === 'active' ? 'ok' : 'error'; ?>">
                                <?php echo $diagnostics['woocommerce']['status'] === 'active' ? '✅ ' . esc_html__('Activo', 'woowapp-smsenlinea-pro') : '❌ ' . esc_html__('Inactivo', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </div>
                        
                        <?php if (isset($diagnostics['woocommerce']['version'])): ?>
                        <div class="info-box">
                            <strong><?php esc_html_e('Versión', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo esc_html($diagnostics['woocommerce']['version']); ?></code>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-box">
                            <strong><?php esc_html_e('Página de Checkout', 'woowapp-smsenlinea-pro'); ?></strong>
                            <span class="status-badge <?php echo $diagnostics['woocommerce']['checkout_page'] ? 'ok' : 'error'; ?>">
                                <?php echo $diagnostics['woocommerce']['checkout_page'] ? '✅ ' . sprintf(esc_html__('ID: %d', 'woowapp-smsenlinea-pro'), (int) $diagnostics['woocommerce']['checkout_page']) : '❌ ' . esc_html__('No encontrada', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BASE DE DATOS -->
            <div class="status-card <?php echo (isset($diagnostics['database']) && $diagnostics['database']['tables']['abandoned_carts']) ? 'ok' : 'error'; ?>">
                <h2><?php _e('🗄️ Base de Datos', 'woowapp-smsenlinea-pro'); ?></h2>
                <div class="info-grid">
                    <?php if (isset($diagnostics['database'])): ?>
                        <div class="info-box">
                            <strong><?php esc_html_e('Host', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo esc_html($diagnostics['database']['host']); ?></code>
                        </div>
                        
                        <div class="info-box">
                            <strong><?php esc_html_e('Base de Datos', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo esc_html($diagnostics['database']['name']); ?></code>
                        </div>
                        
                        <div class="info-box">
                            <strong><?php esc_html_e('Charset', 'woowapp-smsenlinea-pro'); ?></strong>
                            <code><?php echo esc_html($diagnostics['database']['charset']); ?></code>
                        </div>
                        
                        <?php foreach ($diagnostics['database']['tables'] as $table => $exists): ?>
                        <div class="info-box">
                            <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $table))); ?></strong>
                            <span class="status-badge <?php echo $exists ? 'ok' : 'error'; ?>">
                                <?php echo $exists ? '✅ ' . esc_html__('Existe', 'woowapp-smsenlinea-pro') : '❌ ' . esc_html__('No existe', 'woowapp-smsenlinea-pro'); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ACCIONES RÁPIDAS -->
            <div class="status-card warning">
                <h2><?php _e('⚡ Acciones Rápidas', 'woowapp-smsenlinea-pro'); ?></h2>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=woowapp')); ?>" class="button button-primary">⚙️ <?php esc_html_e('Configuración de WooWApp', 'woowapp-smsenlinea-pro'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wse-pro-stats')); ?>" class="button button-secondary">📊 <?php esc_html_e('Ver Estadísticas', 'woowapp-smsenlinea-pro'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" class="button button-secondary">📝 <?php esc_html_e('Ver Logs', 'woowapp-smsenlinea-pro'); ?></a>
                    <button onclick="location.reload()" class="button button-secondary">🔄 <?php esc_html_e('Recargar Diagnóstico', 'woowapp-smsenlinea-pro'); ?></button>
                </p>
            </div>

            <!-- RECOMENDACIONES -->
            <div class="status-card warning">
                <h2><?php _e('💡 Recomendaciones', 'woowapp-smsenlinea-pro'); ?></h2>
                <ul style="line-height: 1.8;">
                    <li>✅ <?php _e('PHP 8.3 es compatible - No requiere cambios', 'woowapp-smsenlinea-pro'); ?></li>
                    <li>✅ <?php _e('El plugin se adapta automáticamente a Apache y Nginx', 'woowapp-smsenlinea-pro'); ?></li>
                    <li>✅ <?php _e('Los datos se capturan con múltiples métodos (fallback automático)', 'woowapp-smsenlinea-pro'); ?></li>
                    <li>🔧 <?php _e('Si ves errores, revisa los logs en WooCommerce > Estado > Registros', 'woowapp-smsenlinea-pro'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
}

// Inicializar
add_action('plugins_loaded', function() {
    WSE_Pro_Server_Compatibility::get_instance();
}, 5);
