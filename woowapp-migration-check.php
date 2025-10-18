<?php
/**
 * Script de Debug para Carritos Abandonados (v2.2.2+)
 * * IMPORTANTE: Sube este archivo a la raíz de WordPress y accede vía navegador
 * URL: https://tu-sitio.com/test-abandoned-cart.php
 * * Después de usarlo, ELIMÍNALO por seguridad.
 * * ACTUALIZADO: 
 * - Añadido botón para forzar el cron principal (wse_pro_process_abandoned_carts).
 * - Eliminados botones obsoletos (force_send).
 * - Corregida la visualización de eventos cron para buscar el cron principal.
 * - Corregida la ruta del archivo de log.
 */

// Cargar WordPress
require_once('wp-load.php');

// Solo permitir a administradores
if (!current_user_can('manage_options')) {
    die('Acceso denegado');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug - Carritos Abandonados (v2.2.2)</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; }
        .section { background: #f5f5f5; padding: 15px; margin: 15px 0; border-radius: 8px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #007cba; color: white; }
        .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #005a87; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #a71d2a; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🛒 Debug - Sistema de Carritos Abandonados (v2.2.2)</h1>
    
    <?php
    global $wpdb;
    $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
    $coupons_table = $wpdb->prefix . 'wse_pro_coupons_generated';
    $tracking_table = $wpdb->prefix . 'wse_pro_tracking'; // Tabla de tracking
    
    // Acción: Forzar el Cron Principal (el método correcto para v2.2.2+)
    if (isset($_GET['action']) && $_GET['action'] === 'force_cron') {
        echo '<div class="section">';
        echo '<h2>🚀 Ejecutando Procesador Principal de Carritos...</h2>';
        
        if (class_exists('WooWApp')) {
            $woowapp_instance = WooWApp::get_instance();
            
            if (method_exists($woowapp_instance, 'process_abandoned_carts_cron')) {
                // Ejecutar la función de procesamiento
                $woowapp_instance->process_abandoned_carts_cron();
                echo '<div class="success">✅ Tarea de procesamiento ejecutada. Verifica los logs y si recibiste el mensaje.</div>';
            } else {
                echo '<div class="error">❌ ERROR: El método "process_abandoned_carts_cron" no existe en la clase "WooWApp".</div>';
            }
        } else {
            echo '<div class="error">❌ ERROR: La clase "WooWApp" no existe. El plugin no parece estar activo.</div>';
        }
        
        echo '<a href="?refresh=1" class="btn">↻ Recargar Página</a>';
        echo '</div>';
    }
    
    // Acción: Limpiar carrito
    if (isset($_GET['action']) && $_GET['action'] === 'delete_cart' && isset($_GET['cart_id'])) {
        $cart_id = intval($_GET['cart_id']);
        $wpdb->delete($table_name, ['id' => $cart_id]);
        $wpdb->delete($tracking_table, ['cart_id' => $cart_id]); // Borrar también tracking
        echo '<div class="success">✅ Carrito #' . $cart_id . ' y su tracking eliminado</div>';
    }
    ?>
    
    <div class="section">
        <h2>⚡ Probar el Cron Principal (Nuevo)</h2>
        <p>El sistema v2.2.2 usa un solo cron ('wse_pro_process_abandoned_carts') que revisa todos los carritos. Usa este botón para ejecutarlo manualmente.</p>
        <a href="?action=force_cron" class="btn btn-success" style="font-size: 16px; padding: 12px 24px;">
            🚀 Forzar Ejecución del Cron Principal
        </a>
    </div>
    
    <div class="section">
        <h2>⚙️ Configuración Actual</h2>
        <?php
        echo '<table>';
        echo '<tr><th>Opción</th><th>Valor</th></tr>';
        
        $enabled = get_option('wse_pro_enable_abandoned_cart', 'no');
        echo '<tr><td><strong>Sistema Activado</strong></td><td>' . ($enabled === 'yes' ? '✅ SÍ' : '❌ NO') . '</td></tr>';
        
        for ($i = 1; $i <= 3; $i++) {
            $msg_enabled = get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no');
            $time = get_option('wse_pro_abandoned_cart_time_' . $i, 0);
            $unit = get_option('wse_pro_abandoned_cart_unit_' . $i, 'minutes');
            $coupon_enabled = get_option('wse_pro_abandoned_cart_coupon_enable_' . $i, 'no');
            
            echo '<tr><td><strong>Mensaje ' . $i . '</strong></td><td>';
            echo ($msg_enabled === 'yes' ? '✅ Activo' : '❌ Inactivo') . ' - ';
            echo '<strong>' . $time . ' ' . $unit . '</strong>';
            echo ($coupon_enabled === 'yes' ? ' 🎁 Con cupón' : '');
            echo '</td></tr>';
        }
        echo '</table>';
        ?>
    </div>
    
    <div class="section">
        <h2>🛒 Carritos Abandonados (últimos 20)</h2>
        <?php
        $carts = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 20");
        
        if (empty($carts)) {
            echo '<div class="info">ℹ️ No hay carritos abandonados registrados</div>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Teléfono / Email</th><th>Nombre</th><th>Total</th><th>Mensajes</th><th>Status</th><th>Creado</th><th>Acciones</th></tr>';
            
            foreach ($carts as $cart) {
                // Formatear mensajes enviados
                $messages_sent = explode(',', $cart->messages_sent);
                $msg_status = '';
                for ($i = 0; $i < 3; $i++) {
                    if (isset($messages_sent[$i]) && $messages_sent[$i] == '1') {
                        $msg_status .= '✅';
                    } else {
                        $msg_status .= '⏳';
                    }
                }
                
                // Formatear estado
                $status_color = '#6c757d'; // gris por defecto
                if ($cart->status === 'active') $status_color = '#28a745'; // verde
                if ($cart->status === 'recovered') $status_color = '#007cba'; // azul
                
                echo '<tr>';
                echo '<td><strong>#' . $cart->id . '</strong></td>';
                echo '<td>' . esc_html($cart->billing_phone ? $cart->billing_phone : $cart->phone) . '<br>' . esc_html($cart->billing_email) . '</td>';
                echo '<td>' . esc_html($cart->billing_first_name ? $cart->billing_first_name : $cart->first_name) . '</td>';
                echo '<td>$' . number_format($cart->cart_total, 2) . '</td>';
                echo '<td><span style="font-size: 18px;">' . $msg_status . '</span></td>';
                echo '<td><span style="background: ' . $status_color . '; color: white; padding: 2px 8px; border-radius: 3px;">' . $cart->status . '</span></td>';
                echo '<td>' . date('d/m/Y H:i', strtotime($cart->created_at)) . '</td>';
                echo '<td>';
                echo '<a href="?action=delete_cart&cart_id=' . $cart->id . '" class="btn btn-danger" style="font-size: 12px; padding: 5px 10px;" onclick="return confirm(\'¿Eliminar?\')">🗑️</a>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>🎁 Cupones Generados (últimos 20)</h2>
        <?php
        $coupons = $wpdb->get_results("SELECT * FROM $coupons_table ORDER BY created_at DESC LIMIT 20");
        
        if (empty($coupons)) {
            echo '<div class="info">ℹ️ No hay cupones generados aún</div>';
        } else {
            echo '<table>';
            echo '<tr><th>Código</th><th>Teléfono</th><th>Carrito</th><th>Tipo</th><th>Descuento</th><th>Usado</th><th>Expira</th></tr>';
            
            foreach ($coupons as $coupon) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($coupon->coupon_code) . '</strong></td>';
                echo '<td>' . esc_html($coupon->customer_phone) . '</td>';
                echo '<td>#' . $coupon->cart_id . ' (Msg ' . $coupon->message_number . ')</td>';
                echo '<td>' . $coupon->discount_type . '</td>';
                echo '<td>' . $coupon->discount_amount . ($coupon->discount_type === 'percent' ? '%' : '$') . '</td>';
                echo '<td>' . ($coupon->used ? '✅ Usado' : '⏳ Pendiente') . '</td>';
                echo '<td>' . date('d/m/Y', strtotime($coupon->expires_at)) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>⏰ Eventos Cron Programados (v2.2.2)</h2>
        <?php
        $cron_hook = 'wse_pro_process_abandoned_carts';
        $next_scheduled = wp_next_scheduled($cron_hook);
        
        if (!$next_scheduled) {
            echo '<div class="error">⚠️ No se encontró el evento cron principal (<strong>' . $cron_hook . '</strong>) programado.</div>';
            echo '<p>Esto es un problema. El plugin no puede revisar los carritos automáticamente. Intenta desactivar y reactivar el plugin "WooWApp" para forzar que se vuelva a programar.</p>';
        } else {
            $time_left = $next_scheduled - time();
            $time_left_str = '';
            
            if ($time_left > 0) {
                $minutes = floor($time_left / 60);
                $seconds = $time_left % 60;
                $time_left_str = 'En ' . $minutes . 'm ' . $seconds . 's';
            } else {
                $time_left_str = '⚠️ ¡Atascado! Debería haberse ejecutado hace ' . absint($time_left) . ' segundos.';
            }
                
            echo '<table>';
            echo '<tr><th>Hook Principal</th><th>Próxima Ejecución</th><th>Tiempo Restante</th></tr>';
            echo '<tr>';
            echo '<td><strong>' . $cron_hook . '</strong></td>';
            echo '<td>' . date('d/m/Y H:i:s', $next_scheduled) . '</td>';
            echo '<td>' . $time_left_str . '</td>';
            echo '</tr>';
            echo '</table>';
            
            if ($time_left <= 0) {
                 echo '<div class="error">⚠️ <strong>Tu WP-Cron parece estar atascado o no funciona.</strong><br>El evento principal está en el pasado. Esto significa que tu sitio no está ejecutando tareas programadas. Debes configurar un "cron job" en tu hosting (cPanel/Plesk) para que visite <code>' . home_url('/wp-cron.php?doing_wp_cron') . '</code> cada 5 minutos.</div>';
            }
        }
        ?>
    </div>
    
    <div class="section">
        <h2>📋 Últimos Logs (wse-pro)</h2>
        <?php
        // Nombre de log correcto de la v2.2.2
        $log_handle = 'wse-pro'; 
        $log_file = WC_LOG_DIR . $log_handle . '-' . date('Y-m-d') . '.log';
        
        if (function_exists('wc_get_log_file_path')) {
            $log_file = wc_get_log_file_path($log_handle);
        }
        
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            $lines = explode("\n", $log_content);
            $last_lines = array_slice($lines, -50); // Últimas 50 líneas
            
            echo '<pre>' . esc_html(implode("\n", $last_lines)) . '</pre>';
            echo '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '" class="btn" target="_blank">Ver Todos los Logs</a>';
        } else {
            echo '<div class="info">ℹ️ No hay logs disponibles para hoy (' . basename($log_file) . '). El archivo se creará cuando ocurra un evento (como enviar un mensaje de prueba o procesar un carrito).</div>';
        }
        ?>
    </div>
    
    <div class="section">
        <h3>⚠️ IMPORTANTE</h3>
        <p><strong>Este archivo es solo para debugging. Elimínalo después de usarlo por seguridad.</strong></p>
        <p>Ubicación: <code><?php echo __FILE__; ?></code></p>
    </div>
</body>
</html>
