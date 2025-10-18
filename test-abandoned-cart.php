<?php
/**
 * Script de Debug para Carritos Abandonados
 * 
 * IMPORTANTE: Sube este archivo a la raíz de WordPress y accede vía navegador
 * URL: https://tu-sitio.com/test-abandoned-cart.php
 * 
 * Después de usarlo, ELIMÍNALO por seguridad.
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
    <title>Debug - Carritos Abandonados</title>
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
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🛒 Debug - Sistema de Carritos Abandonados</h1>
    
    <?php
    global $wpdb;
    $table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';
    $coupons_table = $wpdb->prefix . 'wse_pro_coupons_generated';
    
    // Acción: Forzar envío
    if (isset($_GET['action']) && $_GET['action'] === 'force_send' && isset($_GET['cart_id']) && isset($_GET['msg'])) {
        $cart_id = intval($_GET['cart_id']);
        $msg_num = intval($_GET['msg']);
        
        echo '<div class="section">';
        echo '<h2>🚀 Forzando envío de Mensaje #' . $msg_num . ' para Carrito #' . $cart_id . '</h2>';
        
        // Ejecutar el hook manualmente
        do_action('wse_pro_send_abandoned_cart_' . $msg_num, $cart_id);
        
        echo '<div class="success">✅ Hook ejecutado. Verifica los logs en WooCommerce > Estado > Registros (wse-pro-cart)</div>';
        echo '<a href="?refresh=1" class="btn">↻ Recargar Página</a>';
        echo '</div>';
    }
    
    // Acción: Limpiar carrito
    if (isset($_GET['action']) && $_GET['action'] === 'delete_cart' && isset($_GET['cart_id'])) {
        $cart_id = intval($_GET['cart_id']);
        $wpdb->delete($table_name, ['id' => $cart_id]);
        echo '<div class="success">✅ Carrito #' . $cart_id . ' eliminado</div>';
    }
    ?>
    
    <!-- SECCIÓN 1: Configuración Actual -->
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
            echo $time . ' ' . $unit;
            echo ($coupon_enabled === 'yes' ? ' 🎁 Con cupón' : '');
            echo '</td></tr>';
        }
        echo '</table>';
        ?>
    </div>
    
    <!-- SECCIÓN 2: Carritos Abandonados -->
    <div class="section">
        <h2>🛒 Carritos Abandonados (últimos 20)</h2>
        <?php
        $carts = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 20");
        
        if (empty($carts)) {
            echo '<div class="info">ℹ️ No hay carritos abandonados registrados</div>';
        } else {
            echo '<table>';
            echo '<tr><th>ID</th><th>Teléfono</th><th>Nombre</th><th>Total</th><th>Mensajes</th><th>Status</th><th>Creado</th><th>Acciones</th></tr>';
            
            foreach ($carts as $cart) {
                $messages_sent = explode(',', $cart->messages_sent);
                $msg_status = '';
                for ($i = 0; $i < 3; $i++) {
                    if ($messages_sent[$i] == '1') {
                        $msg_status .= '✅';
                    } else {
                        $msg_status .= '⏳';
                    }
                }
                
                echo '<tr>';
                echo '<td><strong>#' . $cart->id . '</strong></td>';
                echo '<td>' . esc_html($cart->phone) . '</td>';
                echo '<td>' . esc_html($cart->first_name) . '</td>';
                echo '<td>$' . number_format($cart->cart_total, 2) . '</td>';
                echo '<td>' . $msg_status . '</td>';
                echo '<td><span style="background: ' . ($cart->status === 'active' ? '#28a745' : '#6c757d') . '; color: white; padding: 2px 8px; border-radius: 3px;">' . $cart->status . '</span></td>';
                echo '<td>' . date('d/m/Y H:i', strtotime($cart->created_at)) . '</td>';
                echo '<td>';
                
                if ($cart->status === 'active') {
                    for ($i = 1; $i <= 3; $i++) {
                        if ($messages_sent[$i-1] != '1' && get_option('wse_pro_abandoned_cart_enable_msg_' . $i, 'no') === 'yes') {
                            echo '<a href="?action=force_send&cart_id=' . $cart->id . '&msg=' . $i . '" class="btn" style="font-size: 12px; padding: 5px 10px;">Enviar Msg ' . $i . '</a> ';
                        }
                    }
                }
                
                echo '<a href="?action=delete_cart&cart_id=' . $cart->id . '" class="btn btn-danger" style="font-size: 12px; padding: 5px 10px;" onclick="return confirm(\'¿Eliminar?\')">🗑️</a>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        ?>
    </div>
    
    <!-- SECCIÓN 3: Cupones Generados -->
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
    
    <!-- SECCIÓN 4: Eventos Cron Programados -->
    <div class="section">
        <h2>⏰ Eventos Cron Programados</h2>
        <?php
        $cron_events = _get_cron_array();
        $cart_events = [];
        
        foreach ($cron_events as $timestamp => $cron) {
            foreach ($cron as $hook => $events) {
                if (strpos($hook, 'wse_pro_send_abandoned_cart_') !== false) {
                    foreach ($events as $event) {
                        $cart_events[] = [
                            'hook' => $hook,
                            'time' => $timestamp,
                            'args' => $event['args']
                        ];
                    }
                }
            }
        }
        
        if (empty($cart_events)) {
            echo '<div class="info">ℹ️ No hay eventos programados actualmente</div>';
        } else {
            echo '<table>';
            echo '<tr><th>Hook</th><th>Carrito ID</th><th>Programado Para</th><th>Tiempo Restante</th></tr>';
            
            foreach ($cart_events as $event) {
                $msg_num = str_replace('wse_pro_send_abandoned_cart_', '', $event['hook']);
                $time_left = $event['time'] - time();
                $time_left_str = '';
                
                if ($time_left > 0) {
                    $hours = floor($time_left / 3600);
                    $minutes = floor(($time_left % 3600) / 60);
                    $time_left_str = $hours . 'h ' . $minutes . 'm';
                } else {
                    $time_left_str = '⚠️ Debería haberse ejecutado';
                }
                
                echo '<tr>';
                echo '<td>Mensaje #' . $msg_num . '</td>';
                echo '<td>#' . $event['args'][0] . '</td>';
                echo '<td>' . date('d/m/Y H:i:s', $event['time']) . '</td>';
                echo '<td>' . $time_left_str . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        ?>
    </div>
    
    <!-- SECCIÓN 5: Últimos Logs -->
    <div class="section">
        <h2>📋 Últimos Logs (wse-pro-cart)</h2>
        <?php
        $log_file = WC_LOG_DIR . 'wse-pro-cart-' . date('Y-m-d') . '.log';
        
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            $lines = explode("\n", $log_content);
            $last_lines = array_slice($lines, -30); // Últimas 30 líneas
            
            echo '<pre>' . esc_html(implode("\n", $last_lines)) . '</pre>';
            echo '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '" class="btn" target="_blank">Ver Todos los Logs</a>';
        } else {
            echo '<div class="info">ℹ️ No hay logs disponibles para hoy. El archivo se creará cuando ocurra un evento.</div>';
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
