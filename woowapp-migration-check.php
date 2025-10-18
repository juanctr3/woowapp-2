<?php
/**
 * Script de Diagnóstico y Migración - WooWApp
 * 
 * Coloca este archivo en la raíz de WordPress y accede vía:
 * https://tudominio.com/woowapp-migration-check.php
 * 
 * IMPORTANTE: Elimina este archivo después de usarlo por seguridad
 */

// Cargar WordPress
require_once('wp-load.php');

// Verificar que el usuario sea administrador
if (!current_user_can('manage_options')) {
    die('Acceso denegado. Debes ser administrador.');
}

global $wpdb;
$table_name = $wpdb->prefix . 'wse_pro_abandoned_carts';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WooWApp - Diagnóstico y Migración</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #25D366;
            padding-bottom: 10px;
        }
        h2 {
            color: #34495e;
            margin-top: 30px;
        }
        .status {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #34495e;
            color: white;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .button {
            background: #25D366;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            margin: 10px 5px 10px 0;
        }
        .button:hover {
            background: #128C7E;
        }
        .button.danger {
            background: #e74c3c;
        }
        .button.danger:hover {
            background: #c0392b;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        pre {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 WooWApp - Diagnóstico y Migración</h1>
        
        <?php
        // 1. VERIFICAR EXISTENCIA DE LA TABLA
        echo "<h2>1️⃣ Verificación de Tabla</h2>";
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if ($table_exists) {
            echo '<div class="status success">✅ La tabla <code>' . $table_name . '</code> existe</div>';
        } else {
            echo '<div class="status error">❌ La tabla <code>' . $table_name . '</code> NO existe</div>';
            echo '<p>Activa y desactiva el plugin WooWApp para crear la tabla automáticamente.</p>';
            exit;
        }
        
        // 2. VERIFICAR COLUMNAS
        echo "<h2>2️⃣ Verificación de Columnas</h2>";
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        
        $required_columns = [
            'id', 'session_key', 'cart_contents', 'cart_total', 'email', 'phone',
            'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
            'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode',
            'billing_country', 'status', 'messages_sent', 'created_at', 'updated_at'
        ];
        
        $existing_columns = array_column($columns, 'Field');
        $missing_columns = array_diff($required_columns, $existing_columns);
        
        if (empty($missing_columns)) {
            echo '<div class="status success">✅ Todas las columnas requeridas existen</div>';
        } else {
            echo '<div class="status warning">⚠️ Faltan columnas: <code>' . implode(', ', $missing_columns) . '</code></div>';
            
            // BOTÓN DE MIGRACIÓN
            if (isset($_POST['run_migration'])) {
                echo "<h3>Ejecutando migración...</h3>";
                
                $billing_fields = [
                    'billing_first_name' => "VARCHAR(100) DEFAULT '' AFTER phone",
                    'billing_last_name' => "VARCHAR(100) DEFAULT '' AFTER billing_first_name",
                    'billing_email' => "VARCHAR(255) DEFAULT '' AFTER billing_last_name",
                    'billing_phone' => "VARCHAR(50) DEFAULT '' AFTER billing_email",
                    'billing_address_1' => "VARCHAR(255) DEFAULT '' AFTER billing_phone",
                    'billing_city' => "VARCHAR(100) DEFAULT '' AFTER billing_address_1",
                    'billing_state' => "VARCHAR(100) DEFAULT '' AFTER billing_city",
                    'billing_postcode' => "VARCHAR(20) DEFAULT '' AFTER billing_state",
                    'billing_country' => "VARCHAR(2) DEFAULT '' AFTER billing_postcode",
                ];
                
                foreach ($missing_columns as $column) {
                    if (isset($billing_fields[$column])) {
                        $sql = "ALTER TABLE $table_name ADD COLUMN $column {$billing_fields[$column]}";
                        $result = $wpdb->query($sql);
                        
                        if ($result !== false) {
                            echo '<div class="status success">✅ Columna <code>' . $column . '</code> agregada exitosamente</div>';
                        } else {
                            echo '<div class="status error">❌ Error al agregar <code>' . $column . '</code>: ' . $wpdb->last_error . '</div>';
                        }
                    } elseif ($column === 'messages_sent') {
                        $sql = "ALTER TABLE $table_name ADD COLUMN messages_sent VARCHAR(20) DEFAULT '0,0,0' AFTER status";
                        $result = $wpdb->query($sql);
                        
                        if ($result !== false) {
                            echo '<div class="status success">✅ Columna <code>messages_sent</code> agregada exitosamente</div>';
                        } else {
                            echo '<div class="status error">❌ Error al agregar <code>messages_sent</code>: ' . $wpdb->last_error . '</div>';
                        }
                    }
                }
                
                echo '<div class="status success">✅ Migración completada. Recarga la página para verificar.</div>';
                echo '<a href="' . $_SERVER['PHP_SELF'] . '" class="button">Recargar Página</a>';
            } else {
                echo '<form method="post">';
                echo '<button type="submit" name="run_migration" class="button">🚀 Ejecutar Migración Ahora</button>';
                echo '</form>';
            }
        }
        
        // 3. MOSTRAR ESTRUCTURA ACTUAL
        echo "<h2>3️⃣ Estructura Actual de la Tabla</h2>";
        echo '<table>';
        echo '<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Clave</th><th>Por Defecto</th></tr>';
        foreach ($columns as $column) {
            echo '<tr>';
            echo '<td><code>' . $column->Field . '</code></td>';
            echo '<td>' . $column->Type . '</td>';
            echo '<td>' . $column->Null . '</td>';
            echo '<td>' . $column->Key . '</td>';
            echo '<td>' . $column->Default . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        // 4. VERIFICAR DATOS DE EJEMPLO
        echo "<h2>4️⃣ Datos de Ejemplo</h2>";
        $sample_carts = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5");
        
        if (!empty($sample_carts)) {
            echo '<div class="status success">✅ Se encontraron ' . count($sample_carts) . ' registros</div>';
            echo '<table>';
            echo '<tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Teléfono</th>
                    <th>Nombre</th>
                    <th>Total</th>
                    <th>Estado</th>
                    <th>Mensajes</th>
                    <th>Creado</th>
                  </tr>';
            foreach ($sample_carts as $cart) {
                echo '<tr>';
                echo '<td>' . $cart->id . '</td>';
                echo '<td>' . $cart->email . '</td>';
                echo '<td>' . $cart->phone . '</td>';
                echo '<td>' . (isset($cart->billing_first_name) ? $cart->billing_first_name . ' ' . $cart->billing_last_name : 'N/A') . '</td>';
                echo '<td>$' . number_format($cart->cart_total, 2) . '</td>';
                echo '<td>' . $cart->status . '</td>';
                echo '<td>' . (isset($cart->messages_sent) ? $cart->messages_sent : 'N/A') . '</td>';
                echo '<td>' . $cart->created_at . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<div class="status warning">⚠️ No hay registros en la tabla todavía</div>';
        }
        
        // 5. VERIFICAR CONFIGURACIÓN DEL PLUGIN
        echo "<h2>5️⃣ Configuración de Prefijos de Cupón</h2>";
        for ($i = 1; $i <= 3; $i++) {
            $prefix = get_option('wse_pro_abandoned_cart_coupon_prefix_' . $i, 'woowapp-m' . $i);
            $enabled = get_option('wse_pro_abandoned_cart_coupon_enable_' . $i, 'no');
            
            echo '<div class="status ' . ($enabled === 'yes' ? 'success' : 'warning') . '">';
            echo '📋 Mensaje ' . $i . ': Prefijo = <code>' . $prefix . '</code> | ';
            echo 'Estado = ' . ($enabled === 'yes' ? '✅ Activado' : '❌ Desactivado');
            echo '</div>';
        }
        
        // 6. INSTRUCCIONES
        echo "<h2>6️⃣ Próximos Pasos</h2>";
        echo '<div class="status success">';
        echo '<p><strong>Si todo está en verde:</strong></p>';
        echo '<ol>';
        echo '<li>Elimina este archivo por seguridad</li>';
        echo '<li>Ve a WooCommerce → Ajustes → WooWApp</li>';
        echo '<li>Configura los prefijos de cupón personalizados en la sección Notificaciones</li>';
        echo '<li>Haz una prueba creando un carrito abandonado</li>';
        echo '</ol>';
        echo '</div>';
        
        echo '<div class="status warning">';
        echo '<p><strong>⚠️ IMPORTANTE - Elimina este archivo:</strong></p>';
        echo '<p>Este script contiene información sensible. Elimínalo después de verificar:</p>';
        echo '<pre>rm ' . __FILE__ . '</pre>';
        echo '</div>';
        ?>
        
        <form method="post" style="margin-top: 30px;">
            <button type="submit" name="delete_file" class="button danger" 
                    onclick="return confirm('¿Estás seguro de que quieres eliminar este archivo?')">
                🗑️ Eliminar Este Archivo de Diagnóstico
            </button>
        </form>
        
        <?php
        if (isset($_POST['delete_file'])) {
            if (unlink(__FILE__)) {
                echo '<div class="status success">✅ Archivo eliminado exitosamente. Serás redirigido...</div>';
                echo '<script>setTimeout(function(){ window.location.href = "' . admin_url() . '"; }, 2000);</script>';
            } else {
                echo '<div class="status error">❌ No se pudo eliminar el archivo. Hazlo manualmente.</div>';
            }
        }
        ?>
    </div>
</body>
</html>
