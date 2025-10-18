<?php
/**
 * Gestor de Cupones Automáticos para WooWApp
 * 
 * Maneja la creación, tracking y limpieza de cupones de descuento
 * para recuperación de carritos y recompensas por reseñas.
 *
 * VERSIÓN: 2.2.1
 * CHANGELOG:
 * - Agregado método get_latest_coupon_for_cart() (crítico)
 * - Soporte para prefijos personalizables
 * - Validaciones mejoradas
 * - Logging robusto
 * - Métodos auxiliares para estadísticas
 *
 * @package WooWApp
 * @version 2.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Coupon_Manager {

    /**
     * Nombre de la tabla de tracking de cupones
     * @var string
     */
    private static $table_name;

    /**
     * Instancia singleton
     * @var WSE_Pro_Coupon_Manager
     */
    private static $instance = null;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'wse_pro_coupons_generated';
    }

    /**
     * Obtiene la instancia singleton
     * 
     * @return WSE_Pro_Coupon_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Crea la tabla de tracking de cupones al activar el plugin
     */
    public static function create_coupons_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_coupons_generated';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Genera un cupón único de WooCommerce con tracking
     *
     * @param array $args Argumentos del cupón
     * @return array|WP_Error Resultado con código del cupón o error
     */
    public function generate_coupon($args = []) {
        $defaults = [
            'discount_type'   => 'percent',        // 'percent', 'fixed_cart', 'fixed_product'
            'discount_amount' => 10,
            'expiry_days'     => 7,
            'usage_limit'     => 1,
            'customer_phone'  => '',
            'customer_email'  => '',
            'cart_id'         => 0,
            'order_id'        => 0,
            'message_number'  => 0,
            'coupon_type'     => 'cart_recovery',  // 'cart_recovery' o 'review_reward'
            'prefix'          => 'WOOWAPP'         // Prefijo personalizable
        ];

        $args = wp_parse_args($args, $defaults);

        // Validar tipo de descuento
        if (!in_array($args['discount_type'], ['percent', 'fixed_cart', 'fixed_product'])) {
            return new WP_Error(
                'invalid_discount_type',
                __('Tipo de descuento no válido', 'woowapp-smsenlinea-pro')
            );
        }

        // Validar cantidad de descuento
        if ($args['discount_amount'] <= 0) {
            return new WP_Error(
                'invalid_discount_amount',
                __('La cantidad de descuento debe ser mayor a 0', 'woowapp-smsenlinea-pro')
            );
        }

        // Generar código único
        $coupon_code = $this->generate_unique_code($args['prefix'], $args['message_number']);

        // Calcular fecha de expiración
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . $args['expiry_days'] . ' days'));

        // Crear cupón en WooCommerce
        $coupon_id = $this->create_woocommerce_coupon($coupon_code, $args, $expires_at);

        if (is_wp_error($coupon_id)) {
            return $coupon_id;
        }

        // Registrar en la base de datos para tracking
        global $wpdb;
        $inserted = $wpdb->insert(
            self::$table_name,
            [
                'coupon_code'     => $coupon_code,
                'customer_phone'  => $args['customer_phone'],
                'customer_email'  => $args['customer_email'],
                'cart_id'         => $args['cart_id'],
                'order_id'        => $args['order_id'],
                'message_number'  => $args['message_number'],
                'coupon_type'     => $args['coupon_type'],
                'discount_type'   => $args['discount_type'],
                'discount_amount' => $args['discount_amount'],
                'usage_limit'     => $args['usage_limit'],
                'used'            => 0,
                'created_at'      => current_time('mysql'),
                'expires_at'      => $expires_at
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s']
        );

        if (!$inserted) {
            // Si falla el tracking, eliminar el cupón de WooCommerce
            wp_delete_post($coupon_id, true);
            
            $this->log_error(
                'Error al registrar cupón en BD',
                ['coupon_code' => $coupon_code, 'db_error' => $wpdb->last_error]
            );
            
            return new WP_Error(
                'db_error',
                __('Error al registrar el cupón', 'woowapp-smsenlinea-pro')
            );
        }

        // Log de éxito
        $this->log_info(
            "Cupón generado: {$coupon_code} para carrito #{$args['cart_id']}, mensaje #{$args['message_number']}"
        );

        return [
            'success'            => true,
            'coupon_code'        => $coupon_code,
            'coupon_id'          => $coupon_id,
            'discount_amount'    => $args['discount_amount'],
            'discount_type'      => $args['discount_type'],
            'expires_at'         => $expires_at,
            'formatted_discount' => $this->format_discount($args['discount_amount'], $args['discount_type']),
            'formatted_expiry'   => date_i18n(get_option('date_format'), strtotime($expires_at))
        ];
    }

    /**
     * Genera un código único para el cupón
     * ACTUALIZADO: Ahora usa el prefijo configurado por el usuario
     *
     * @param string $prefix Prefijo personalizado del cupón
     * @param int $message_number Número de mensaje (para diferenciación)
     * @return string Código único
     */
    private function generate_unique_code($prefix, $message_number = 0) {
        // Limpiar el prefijo (solo letras, números y guiones)
        $prefix = sanitize_title($prefix);
        
        // Si está vacío, usar default
        if (empty($prefix)) {
            $prefix = 'woowapp-m' . $message_number;
        }
        
        // Generar sufijo único (6 caracteres)
        $suffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        
        // Formato: PREFIX-SUFFIX (ej: DESCUENTO5-A1B2C3)
        $code = $prefix . '-' . $suffix;

        // Verificar que no exista (aunque es altamente improbable)
        $attempts = 0;
        while (get_page_by_title($code, OBJECT, 'shop_coupon') && $attempts < 5) {
            $suffix = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            $code = $prefix . '-' . $suffix;
            $attempts++;
        }
        
        // Si después de 5 intentos sigue existiendo, agregar timestamp
        if ($attempts >= 5) {
            $code = $prefix . '-' . time() . '-' . substr($suffix, 0, 3);
        }

        return $code;
    }

    /**
     * Crea el cupón en WooCommerce
     *
     * @param string $coupon_code Código del cupón
     * @param array $args Argumentos del cupón
     * @param string $expires_at Fecha de expiración
     * @return int|WP_Error ID del cupón o error
     */
    private function create_woocommerce_coupon($coupon_code, $args, $expires_at) {
        try {
            $coupon = new WC_Coupon();
            
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type($args['discount_type']);
            $coupon->set_amount($args['discount_amount']);
            $coupon->set_date_expires(strtotime($expires_at));
            $coupon->set_usage_limit($args['usage_limit']);
            $coupon->set_usage_limit_per_user($args['usage_limit']);
            $coupon->set_individual_use(true);
            
            $coupon->set_description(
                sprintf(
                    __('Cupón automático generado por WooWApp - %s (Mensaje #%d)', 'woowapp-smsenlinea-pro'),
                    $args['coupon_type'],
                    $args['message_number']
                )
            );

            // Si hay email, restringir a ese email
            if (!empty($args['customer_email'])) {
                $coupon->set_email_restrictions([$args['customer_email']]);
            }
            
            // Configuraciones adicionales de seguridad
            $coupon->set_free_shipping(false);
            $coupon->set_exclude_sale_items(false);
            $coupon->set_minimum_amount(0);
            $coupon->set_maximum_amount(0);

            $coupon_id = $coupon->save();
            
            if (!$coupon_id) {
                throw new Exception(__('No se pudo guardar el cupón', 'woowapp-smsenlinea-pro'));
            }
            
            return $coupon_id;
            
        } catch (Exception $e) {
            $this->log_error(
                'Error al crear cupón en WooCommerce',
                ['coupon_code' => $coupon_code, 'error' => $e->getMessage()]
            );
            
            return new WP_Error('coupon_creation_failed', $e->getMessage());
        }
    }

    /**
     * ========================================
     * MÉTODO CRÍTICO - ERA EL QUE FALTABA
     * ========================================
     * 
     * Obtiene el último cupón generado para un carrito específico
     * Este método es llamado por handle_cart_recovery_link() en woowapp.php
     *
     * @param int $cart_id ID del carrito abandonado
     * @return object|null Objeto con información del cupón o null si no existe
     */
    public function get_latest_coupon_for_cart($cart_id) {
        global $wpdb;
        
        // Validar que el cart_id sea válido
        if (empty($cart_id) || $cart_id <= 0) {
            $this->log_error(
                'get_latest_coupon_for_cart: cart_id inválido',
                ['cart_id' => $cart_id]
            );
            return null;
        }
        
        // Verificar que la tabla existe
        if ($wpdb->get_var("SHOW TABLES LIKE '" . self::$table_name . "'") !== self::$table_name) {
            $this->log_error(
                'Tabla de cupones no existe',
                ['table' => self::$table_name]
            );
            return null;
        }
        
        // Buscar el último cupón válido para este carrito
        $coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " 
             WHERE cart_id = %d 
             AND used = 0 
             AND expires_at > NOW() 
             ORDER BY created_at DESC 
             LIMIT 1",
            $cart_id
        ));
        
        if ($coupon) {
            $this->log_info(
                "Cupón encontrado para carrito #{$cart_id}: {$coupon->coupon_code}"
            );
            
            // Verificar que el cupón realmente exista en WooCommerce
            $wc_coupon_id = wc_get_coupon_id_by_code($coupon->coupon_code);
            if (!$wc_coupon_id) {
                $this->log_error(
                    "Cupón existe en BD pero no en WooCommerce",
                    ['coupon_code' => $coupon->coupon_code, 'cart_id' => $cart_id]
                );
                return null;
            }
            
            return $coupon;
        }
        
        $this->log_info(
            "No se encontró cupón válido para carrito #{$cart_id}"
        );
        
        return null;
    }

    /**
     * Obtiene todos los cupones activos de un carrito
     *
     * @param int $cart_id ID del carrito
     * @return array Array de objetos con cupones
     */
    public function get_all_coupons_for_cart($cart_id) {
        global $wpdb;
        
        if (empty($cart_id) || $cart_id <= 0) {
            return [];
        }
        
        $coupons = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " 
             WHERE cart_id = %d 
             AND used = 0 
             AND expires_at > NOW() 
             ORDER BY created_at DESC",
            $cart_id
        ));
        
        return $coupons ? $coupons : [];
    }

    /**
     * Marca un cupón como usado
     *
     * @param string $coupon_code Código del cupón
     * @param int $order_id ID del pedido donde se usó (opcional)
     * @return bool True si se actualizó correctamente
     */
    public function mark_as_used($coupon_code, $order_id = 0) {
        global $wpdb;
        
        $update_data = ['used' => 1];
        $format = ['%d'];
        
        // Si se proporciona order_id, también lo actualizamos
        if ($order_id > 0) {
            $update_data['order_id'] = $order_id;
            $format[] = '%d';
        }
        
        $result = $wpdb->update(
            self::$table_name,
            $update_data,
            ['coupon_code' => $coupon_code],
            $format,
            ['%s']
        );

        if ($result) {
            $this->log_info(
                "Cupón marcado como usado: {$coupon_code}" . 
                ($order_id > 0 ? " en pedido #{$order_id}" : "")
            );
        }

        return ($result !== false);
    }

    /**
     * Alias del método mark_as_used para compatibilidad
     *
     * @param string $coupon_code Código del cupón
     * @param int $order_id ID del pedido donde se usó
     * @return bool True si se actualizó correctamente
     */
    public function mark_coupon_as_used($coupon_code, $order_id = 0) {
        return $this->mark_as_used($coupon_code, $order_id);
    }

    /**
     * Verifica si un cupón existe y es válido
     *
     * @param string $coupon_code Código del cupón
     * @return bool True si el cupón es válido
     */
    public function is_coupon_valid($coupon_code) {
        global $wpdb;
        
        if (empty($coupon_code)) {
            return false;
        }
        
        $coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " 
             WHERE coupon_code = %s 
             AND used = 0 
             AND expires_at > NOW()",
            $coupon_code
        ));
        
        return ($coupon !== null);
    }

    /**
     * Verifica si un cliente ya tiene un cupón activo
     *
     * @param string $phone Teléfono del cliente
     * @param int $cart_id ID del carrito (opcional)
     * @return bool True si ya existe
     */
    public function customer_has_active_coupon($phone, $cart_id = 0) {
        global $wpdb;
        
        $where = "customer_phone = %s AND used = 0 AND expires_at > NOW()";
        $params = [$phone];
        
        if ($cart_id > 0) {
            $where .= " AND cart_id = %d";
            $params[] = $cart_id;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::$table_name . " WHERE " . $where,
                $params
            )
        );

        return $count > 0;
    }

    /**
     * Obtiene el último cupón generado para un cliente
     *
     * @param string $phone Teléfono del cliente
     * @param int $message_number Número de mensaje (opcional)
     * @return object|null Datos del cupón o null
     */
    public function get_customer_latest_coupon($phone, $message_number = 0) {
        global $wpdb;
        
        $where = "customer_phone = %s AND used = 0 AND expires_at > NOW()";
        $params = [$phone];
        
        if ($message_number > 0) {
            $where .= " AND message_number = %d";
            $params[] = $message_number;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::$table_name . " 
                WHERE " . $where . " 
                ORDER BY created_at DESC 
                LIMIT 1",
                $params
            )
        );
    }

    /**
     * Obtiene estadísticas de uso de cupones para un carrito
     *
     * @param int $cart_id ID del carrito
     * @return array Array con estadísticas
     */
    public function get_cart_coupon_stats($cart_id) {
        global $wpdb;
        
        $stats = [
            'total_generated' => 0,
            'total_used'      => 0,
            'total_active'    => 0,
            'total_expired'   => 0
        ];
        
        if (empty($cart_id) || $cart_id <= 0) {
            return $stats;
        }
        
        $stats['total_generated'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " WHERE cart_id = %d",
            $cart_id
        ));
        
        $stats['total_used'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " WHERE cart_id = %d AND used = 1",
            $cart_id
        ));
        
        $stats['total_active'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " 
             WHERE cart_id = %d AND used = 0 AND expires_at > NOW()",
            $cart_id
        ));
        
        $stats['total_expired'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " 
             WHERE cart_id = %d AND used = 0 AND expires_at < NOW()",
            $cart_id
        ));
        
        return $stats;
    }

    /**
     * Limpia cupones expirados (ejecutado por cron job)
     * Solo elimina cupones que expiraron hace más de 7 días
     * 
     * @return int Número de cupones eliminados
     */
    public static function cleanup_expired_coupons() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wse_pro_coupons_generated';

        // Obtener cupones expirados hace más de 7 días
        $expired_coupons = $wpdb->get_results(
            "SELECT * FROM $table_name 
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY) 
            AND used = 0"
        );

        if (empty($expired_coupons)) {
            return 0;
        }

        $deleted_count = 0;
        foreach ($expired_coupons as $coupon_data) {
            // Eliminar de WooCommerce
            $coupon_id = wc_get_coupon_id_by_code($coupon_data->coupon_code);
            if ($coupon_id) {
                wp_delete_post($coupon_id, true);
            }

            // Eliminar de la tabla de tracking
            $result = $wpdb->delete(
                $table_name,
                ['coupon_code' => $coupon_data->coupon_code],
                ['%s']
            );
            
            if ($result) {
                $deleted_count++;
            }
        }

        // Log de limpieza
        if ($deleted_count > 0 && get_option('wse_pro_enable_log') === 'yes') {
            wc_get_logger()->info(
                "Limpieza automática: {$deleted_count} cupones expirados eliminados",
                ['source' => 'woowapp-' . date('Y-m-d')]
            );
        }

        return $deleted_count;
    }

    /**
     * Hook para marcar cupones como usados cuando se aplican a un pedido
     * 
     * @param int $order_id ID del pedido
     */
    public static function track_coupon_usage($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $used_coupons = $order->get_coupon_codes();
        
        if (empty($used_coupons)) {
            return;
        }

        $manager = self::get_instance();
        
        foreach ($used_coupons as $coupon_code) {
            // Verificar si es un cupón generado por WooWApp
            global $wpdb;
            $is_our_coupon = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::$table_name . " WHERE coupon_code = %s",
                $coupon_code
            ));
            
            if ($is_our_coupon > 0) {
                $manager->mark_as_used($coupon_code, $order_id);
            }
        }
    }

    /**
     * Obtiene estadísticas generales de cupones
     *
     * @return array Estadísticas globales
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = [
            'total_generated' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::$table_name
            ),
            'total_used' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::$table_name . " WHERE used = 1"
            ),
            'total_active' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::$table_name . " 
                 WHERE used = 0 AND expires_at > NOW()"
            ),
            'total_expired' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::$table_name . " 
                 WHERE used = 0 AND expires_at < NOW()"
            ),
        ];

        // Calcular tasa de conversión
        $stats['conversion_rate'] = $stats['total_generated'] > 0 
            ? round(($stats['total_used'] / $stats['total_generated']) * 100, 2)
            : 0;

        // Total por tipo de cupón
        $stats['by_type'] = $wpdb->get_results(
            "SELECT coupon_type, COUNT(*) as count, SUM(used) as used_count 
             FROM " . self::$table_name . " 
             GROUP BY coupon_type",
            ARRAY_A
        );

        return $stats;
    }

    /**
     * Obtiene el total de descuento otorgado por cupones usados
     *
     * @return float Total de descuento en moneda del sitio
     */
    public function get_total_discount_given() {
        global $wpdb;
        
        // Solo contar descuentos fijos (no porcentajes)
        $total = $wpdb->get_var(
            "SELECT SUM(discount_amount) FROM " . self::$table_name . " 
             WHERE used = 1 AND discount_type = 'fixed_cart'"
        );
        
        return $total ? (float) $total : 0;
    }

    /**
     * Obtiene cupones por tipo
     *
     * @param string $coupon_type Tipo de cupón ('cart_recovery' o 'review_reward')
     * @param bool $active_only Solo cupones activos
     * @return array Array de cupones
     */
    public function get_coupons_by_type($coupon_type, $active_only = true) {
        global $wpdb;
        
        $where = "coupon_type = %s";
        $params = [$coupon_type];
        
        if ($active_only) {
            $where .= " AND used = 0 AND expires_at > NOW()";
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " 
             WHERE " . $where . " 
             ORDER BY created_at DESC",
            $params
        ));
    }

    /**
     * Formatea el descuento para mostrar
     *
     * @param float $amount Cantidad
     * @param string $type Tipo de descuento
     * @return string Descuento formateado
     */
    private function format_discount($amount, $type) {
        if ($type === 'percent') {
            return $amount . '%';
        } else {
            return wc_price($amount);
        }
    }

    /**
     * ========================================
     * MÉTODOS DE LOGGING
     * ========================================
     */

    /**
     * Registra un mensaje informativo
     * 
     * @param string $message Mensaje a registrar
     * @param array $context Contexto adicional
     */
    private function log_info($message, $context = []) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info(
                $message,
                array_merge(['source' => 'woowapp-' . date('Y-m-d')], $context)
            );
        }
    }

    /**
     * Registra un error
     * 
     * @param string $message Mensaje de error
     * @param array $context Contexto adicional
     */
    private function log_error($message, $context = []) {
        if (get_option('wse_pro_enable_log') === 'yes' && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error(
                $message,
                array_merge(['source' => 'woowapp-' . date('Y-m-d')], $context)
            );
        }
    }

    /**
     * ========================================
     * MÉTODOS DE UTILIDAD
     * ========================================
     */

    /**
     * Obtiene información detallada de un cupón por su código
     * 
     * @param string $coupon_code Código del cupón
     * @return array|null Información del cupón o null
     */
    public function get_coupon_info($coupon_code) {
        global $wpdb;
        
        if (empty($coupon_code)) {
            return null;
        }
        
        $coupon_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " WHERE coupon_code = %s",
            $coupon_code
        ), ARRAY_A);
        
        if (!$coupon_data) {
            return null;
        }
        
        // Agregar información adicional
        $coupon_data['is_valid'] = $this->is_coupon_valid($coupon_code);
        $coupon_data['is_expired'] = (strtotime($coupon_data['expires_at']) < time());
        $coupon_data['formatted_discount'] = $this->format_discount(
            $coupon_data['discount_amount'],
            $coupon_data['discount_type']
        );
        $coupon_data['formatted_expiry'] = date_i18n(
            get_option('date_format'),
            strtotime($coupon_data['expires_at'])
        );
        
        return $coupon_data;
    }

    /**
     * Verifica la salud de la tabla de cupones
     * Útil para diagnóstico
     * 
     * @return array Estado de la tabla
     */
    public function check_table_health() {
        global $wpdb;
        
        $health = [
            'table_exists' => false,
            'total_records' => 0,
            'indexes_ok' => false,
            'issues' => []
        ];
        
        // Verificar existencia de tabla
        $table_exists = $wpdb->get_var(
            "SHOW TABLES LIKE '" . self::$table_name . "'"
        ) === self::$table_name;
        
        $health['table_exists'] = $table_exists;
        
        if (!$table_exists) {
            $health['issues'][] = 'Tabla no existe';
            return $health;
        }
        
        // Contar registros
        $health['total_records'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$table_name
        );
        
        // Verificar índices
        $indexes = $wpdb->get_results(
            "SHOW INDEX FROM " . self::$table_name,
            ARRAY_A
        );
        
        $required_indexes = ['PRIMARY', 'coupon_code', 'cart_id'];
        $existing_indexes = array_column($indexes, 'Key_name');
        
        $health['indexes_ok'] = empty(array_diff($required_indexes, $existing_indexes));
        
        if (!$health['indexes_ok']) {
            $missing = array_diff($required_indexes, $existing_indexes);
            $health['issues'][] = 'Índices faltantes: ' . implode(', ', $missing);
        }
        
        return $health;
    }
}
