<?php
/**
 * Script de Limpieza - Eliminar eventos cron con ID #0
 * 
 * IMPORTANTE: 
 * 1. Sube este archivo a la raíz de WordPress
 * 2. Accede: https://demo.smsenlinea.com/cleanup-cron.php
 * 3. Después de usarlo, ELIMÍNALO por seguridad
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
    <title>Limpieza de Eventos Cron</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .btn { background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 10px 5px; }
        .btn:hover { background: #005a87; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #a71d2a; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; }
        h1 { color: #333; }
        .section { background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 8px; }
    </style>
</head>
<body>
    <h1>🧹 Limpieza de Eventos Cron</h1>
    
    <?php
    // Acción: Limpiar eventos con ID #0
    if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
        echo '<div class="section">';
        echo '<h2>Ejecutando limpieza...</h2>';
        
        $cron_events = _get_cron_array();
        $removed_count = 0;
        $events_to_remove = [];
        
        // Buscar eventos con args[0] = 0
        foreach ($cron_events as $timestamp => $cron) {
            foreach ($cron as $hook => $events) {
                if (strpos($hook, 'wse_pro_send_abandoned_cart_') !== false) {
                    foreach ($events as $key => $event) {
                        if (isset($event['args'][0]) && $event['args'][0] == 0) {
                            $events_to_remove[] = [
                                'timestamp' => $timestamp,
                                'hook' => $hook,
                                'args' => $event['args']
                            ];
                        }
                    }
                }
            }
        }
        
        // Eliminar eventos
        foreach ($events_to_remove as $event) {
            $result = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);
            if ($result) {
                $removed_count++;
                echo '<div class="success">✅ Eliminado: ' . $event['hook'] . ' (ID: ' . $event['args'][0] . ')</div>';
            }
        }
        
        if ($removed_count > 0) {
            echo '<div class="success"><strong>✅ Limpieza completada!</strong><br>Se eliminaron ' . $removed_count . ' eventos con ID #0.</div>';
        } else {
            echo '<div class="info">ℹ️ No se encontraron eventos con ID #0.</div>';
        }
        
        echo '<a href="?" class="btn">↻ Recargar</a>';
        echo '<a href="test-abandoned-cart.php" class="btn">Ver Panel Debug</a>';
        echo '</div>';
    } else {
        // Mostrar eventos actuales
        $cron_events = _get_cron_array();
        $cart_events = [];
        $bad_events = 0;
        
        foreach ($cron_events as $timestamp => $cron) {
            foreach ($cron as $hook => $events) {
                if (strpos($hook, 'wse_pro_send_abandoned_cart_') !== false) {
                    foreach ($events as $event) {
                        $cart_id = $event['args'][0] ?? 'N/A';
                        $cart_events[] = [
                            'hook' => $hook,
                            'cart_id' => $cart_id,
                            'time' => $timestamp
                        ];
                        
                        if ($cart_id == 0) {
                            $bad_events++;
                        }
                    }
                }
            }
        }
        
        echo '<div class="section">';
        echo '<h2>📊 Estado Actual</h2>';
        
        if (empty($cart_events)) {
            echo '<div class="info">ℹ️ No hay eventos de carrito abandonado programados.</div>';
        } else {
            echo '<p><strong>Total de eventos:</strong> ' . count($cart_events) . '</p>';
            echo '<p><strong>Eventos con ID #0 (malos):</strong> <span style="color: red; font-size: 20px; font-weight: bold;">' . $bad_events . '</span></p>';
            
            if ($bad_events > 0) {
                echo '<div class="error">';
                echo '⚠️ <strong>Problema detectado:</strong> Hay ' . $bad_events . ' eventos con Carrito ID = #0<br>';
                echo 'Estos eventos no funcionarán y deben ser eliminados.';
                echo '</div>';
                
                echo '<a href="?action=cleanup" class="btn btn-danger">🧹 Limpiar Eventos con ID #0</a>';
            } else {
                echo '<div class="success">✅ Todos los eventos tienen IDs válidos.</div>';
            }
            
            echo '<h3>Lista de Eventos:</h3>';
            echo '<pre>';
            foreach ($cart_events as $event) {
                $status = ($event['cart_id'] == 0) ? '❌ MALO' : '✅ OK';
                echo sprintf(
                    "%s | Hook: %s | Carrito: #%s | Tiempo: %s\n",
                    $status,
                    str_replace('wse_pro_send_abandoned_cart_', 'Mensaje #', $event['hook']),
                    $event['cart_id'],
                    date('d/m/Y H:i:s', $event['time'])
                );
            }
            echo '</pre>';
        }
        
        echo '</div>';
        
        // Verificar archivos del plugin
        echo '<div class="section">';
        echo '<h2>📁 Verificación de Archivos</h2>';
        
        $plugin_path = WP_PLUGIN_DIR . '/woowapp/';
        $files_to_check = [
            'woowapp.php',
            'includes/class-wse-pro-api-handler.php',
            'includes/class-wse-pro-settings.php',
            'includes/class-wse-pro-coupon-manager.php',
        ];
        
        foreach ($files_to_check as $file) {
            $full_path = $plugin_path . $file;
            if (file_exists($full_path)) {
                $modified = date('d/m/Y H:i:s', filemtime($full_path));
                $size = round(filesize($full_path) / 1024, 2);
                echo '<div class="success">✅ ' . $file . '<br><small>Modificado: ' . $modified . ' | Tamaño: ' . $size . ' KB</small></div>';
            } else {
                echo '<div class="error">❌ ' . $file . ' - NO ENCONTRADO</div>';
            }
        }
        
        echo '</div>';
        
        // Verificar versión
        echo '<div class="section">';
        echo '<h2>🔢 Versión del Plugin</h2>';
        
        if (defined('WSE_PRO_VERSION')) {
            $version = WSE_PRO_VERSION;
            if ($version === '1.1') {
                echo '<div class="success">✅ Versión: ' . $version . ' (Correcta)</div>';
            } else {
                echo '<div class="error">⚠️ Versión: ' . $version . ' (Debería ser 1.1)</div>';
            }
        } else {
            echo '<div class="error">❌ No se pudo detectar la versión del plugin</div>';
        }
        
        echo '</div>';
    }
    ?>
    
    <div class="section">
        <h3>🔗 Enlaces Útiles</h3>
        <a href="test-abandoned-cart.php" class="btn">Panel de Debug</a>
        <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=woowapp'); ?>" class="btn">Configuración WooWApp</a>
        <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>" class="btn">Ver Logs WooCommerce</a>
    </div>
    
    <div class="section">
        <h3>⚠️ IMPORTANTE</h3>
        <p><strong>Después de usar este script, ELIMÍNALO por seguridad.</strong></p>
        <p>Ubicación: <code><?php echo __FILE__; ?></code></p>
    </div>
</body>
</html>
