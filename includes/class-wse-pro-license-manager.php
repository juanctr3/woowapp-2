<?php
/**
 * Clase para gestionar la licencia de WooWApp Pro.
 * Adaptado de la documentación v2 de descargas.smsenlinea.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_License_Manager {

    private $plugin_slug; // Este será WSE_PRO_PUBLIC_SLUG
    private $option_group = 'wse_pro_license_options'; // Prefijo para opciones
    private $page_slug    = 'wse-pro-license';         // Slug para la página de ajustes
    private $option_key   = 'wse_pro_license_key';     // Opción donde se guarda la clave
    private $option_status= 'wse_pro_license_status';  // Opción donde se guarda el estado (active/inactive)

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        add_action('admin_menu', [$this, 'add_license_page']);
        add_action('admin_init', [$this, 'register_settings']);
        // Añadir enlace de ajustes en la página de plugins
        add_filter('plugin_action_links_' . plugin_basename(WSE_PRO_FILE), [$this, 'add_settings_link']);

        // Hook para manejar la desactivación manual
         add_action('admin_post_wse_pro_deactivate_license', [$this, 'handle_manual_deactivation']);
    }

    public function add_license_page() {
        // Añadir página bajo el menú "Ajustes" de WordPress
        add_options_page(
            __('Licencia de WooWApp Pro', 'woowapp-smsenlinea-pro'), // Título de la página
            __('WooWApp Pro Licencia', 'woowapp-smsenlinea-pro'),    // Título en el menú
            'manage_options',                                       // Capacidad requerida
            $this->page_slug,                                       // Slug único de la página
            [$this, 'render_license_page']                          // Función que muestra el contenido
        );
    }

    public function render_license_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ajustes de Licencia de WooWApp Pro', 'woowapp-smsenlinea-pro'); ?></h1>
            <?php settings_errors(); // Muestra mensajes de error/éxito ?>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group); // Seguridad y campos ocultos necesarios
                do_settings_sections($this->page_slug); // Muestra las secciones y campos registrados
                submit_button(__('Guardar Cambios y Activar', 'woowapp-smsenlinea-pro')); // Botón principal
                ?>
            </form>

            <hr style="margin: 20px 0;">

            <?php // Sección para desactivar manualmente (si la licencia está activa)
            $status = get_option($this->option_status, 'inactive');
            if ($status === 'active') : ?>
                <h2><?php esc_html_e('Desactivar Licencia', 'woowapp-smsenlinea-pro'); ?></h2>
                <p><?php esc_html_e('Si deseas usar tu licencia en otro sitio web, desactívala aquí primero para liberarla.', 'woowapp-smsenlinea-pro'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wse_pro_deactivate_license">
                    <?php wp_nonce_field('wse_deactivate_license_nonce', 'wse_deactivate_nonce'); ?>
                    <?php submit_button(__('Desactivar Licencia en este sitio', 'woowapp-smsenlinea-pro'), 'delete', 'wse_deactivate_submit', false, ['onclick' => 'return confirm("' . esc_js(__('¿Estás seguro? Esto desactivará las actualizaciones automáticas.', 'woowapp-smsenlinea-pro')) . '");']); ?>
                </form>
            <?php endif; ?>

        </div>
        <?php
    }

    public function register_settings() {
        // Registra la opción donde se guarda la clave
        register_setting(
            $this->option_group,                      // Grupo de opciones
            $this->option_key,                        // Nombre de la opción
            [$this, 'sanitize_and_validate_license'] // Función que se ejecuta al guardar
        );

        // Añade una sección a la página de ajustes
        add_settings_section(
            'wse_pro_license_section',               // ID único de la sección
            __('Estado de la Activación', 'woowapp-smsenlinea-pro'), // Título de la sección
            [$this, 'render_section_text'],          // Función que muestra texto introductorio (opcional)
            $this->page_slug                          // Página donde se muestra
        );

        // Añade el campo para introducir la clave
        add_settings_field(
            'wse_pro_license_key_field',             // ID único del campo
            __('Clave de Licencia', 'woowapp-smsenlinea-pro'),   // Etiqueta del campo
            [$this, 'render_license_key_field'],     // Función que muestra el input
            $this->page_slug,                         // Página donde se muestra
            'wse_pro_license_section'                // Sección a la que pertenece
        );
    }

    public function render_section_text() {
        $status = get_option($this->option_status, 'inactive');
        if ($status === 'active') {
            echo '<p style="color: green; font-weight: bold;">' . esc_html__('Tu licencia está activa. ¡Gracias por usar nuestro plugin!', 'woowapp-smsenlinea-pro') . '</p>';
            // Añadir botón para verificar manualmente
             echo '<form method="post" action="" style="display:inline-block; margin-left: 10px;">';
             wp_nonce_field('wse_check_license_nonce', 'wse_check_nonce');
             echo '<input type="hidden" name="wse_action" value="check_license">';
             submit_button(__('Verificar Estado Ahora', 'woowapp-smsenlinea-pro'), 'secondary', 'wse_check_submit', false);
             echo '</form>';
        } else {
            echo '<p style="color: red; font-weight: bold;">' . esc_html__('Tu licencia está inactiva. Introduce una clave válida para activar las funcionalidades completas, el soporte y las actualizaciones.', 'woowapp-smsenlinea-pro') . '</p>';
        }
         // Manejar verificación si se envió el formulario
        if (isset($_POST['wse_action']) && $_POST['wse_action'] === 'check_license') {
            $this->handle_check_request();
        }
    }

    public function render_license_key_field() {
        $license_key = get_option($this->option_key, '');
        echo "<input type='text' name='" . esc_attr($this->option_key) . "' value='" . esc_attr($license_key) . "' class='regular-text' placeholder='" . esc_attr__('Pega tu clave de licencia aquí', 'woowapp-smsenlinea-pro') . "' style='width: 350px;' />";
    }

    /**
     * Sanitiza y valida la clave al guardar.
     * Llama a la API para activar/desactivar.
     */
    public function sanitize_and_validate_license($new_key) {
        $old_key = get_option($this->option_key);
        $new_key = sanitize_text_field(trim($new_key));

        // Si la clave vieja existe y la nueva es diferente (o vacía), desactivar la vieja primero
        if ($old_key && $new_key !== $old_key) {
            error_log('WooWApp License: Deactivating old key ' . substr($old_key, 0, 5) . '...');
            $this->call_api('deactivate', $old_key);
            update_option($this->option_status, 'inactive'); // Marcar inactiva por si falla la activación nueva
             add_settings_error($this->option_group, 'deactivated', __('Licencia anterior desactivada.', 'woowapp-smsenlinea-pro'), 'info'); // Usar info
        }

        // Si la nueva clave está vacía, borrarla y asegurar estado inactivo
        if (empty($new_key)) {
             update_option($this->option_status, 'inactive');
             add_settings_error($this->option_group, 'cleared', __('Ajustes guardados. La licencia ha sido desactivada.', 'woowapp-smsenlinea-pro'), 'updated');
             return ''; // Devuelve vacío para borrar la opción
        }

         // Si la clave no ha cambiado y ya estaba activa, no hacer nada más
         $current_status = get_option($this->option_status);
         if ($new_key === $old_key && $current_status === 'active') {
             return $old_key; // Mantener la clave existente
         }


        // Intentar activar la nueva clave
        error_log('WooWApp License: Attempting to activate key ' . substr($new_key, 0, 5) . '...');
        $response = $this->call_api('activate', $new_key);

        if (isset($response['success']) && $response['success']) {
            update_option($this->option_status, 'active');
            add_settings_error($this->option_group, 'activated', __('¡Licencia activada con éxito!', 'woowapp-smsenlinea-pro'), 'updated');
            error_log('WooWApp License: Activation successful for key ' . substr($new_key, 0, 5));
            return $new_key; // Guardar la nueva clave válida
        } else {
            // Falló la activación
            update_option($this->option_status, 'inactive');
            $error_code = $response['error'] ?? 'unknown';
            $api_message = $response['message'] ?? ''; // Mensaje adicional de la API
            $error_message = $this->get_friendly_error_message($error_code, $api_message);

            add_settings_error($this->option_group, 'error', $error_message, 'error');
            error_log('WooWApp License: Activation FAILED for key ' . substr($new_key, 0, 5) . '. Error: ' . $error_code . ' | API Msg: ' . $api_message);
            return ''; // No guardar la clave inválida
        }
    }

    /**
     * Llama a la API de licencias.
     */
    private function call_api($action, $license_key) {
        $api_url = 'https://descargas.smsenlinea.com/api/license.php';
        $request_body = [
            'action'        => $action,
            'license_key'   => $license_key,
            'plugin_slug'   => $this->plugin_slug,
            'domain'        => home_url(),
        ];

        error_log('WooWApp License API Call - URL: ' . $api_url . ' | Payload: ' . print_r($request_body, true));

        $response = wp_remote_post($api_url, [
            'timeout' => 20,
            'sslverify' => true, // Importante para HTTPS
            'body' => $request_body,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('WooWApp License API Error (wp_remote_post): ' . $error_message);
            return ['success' => false, 'error' => 'request_failed', 'message' => $error_message];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('WooWApp License API Response - HTTP Code: ' . $http_code . ' | Raw Body: ' . $body);

        if ($http_code !== 200) {
             error_log('WooWApp License API Error: Server returned HTTP code ' . $http_code);
             return ['success' => false, 'error' => 'server_error_' . $http_code, 'message' => 'Server error: ' . $http_code];
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log('WooWApp License API Error: Invalid JSON response.');
             return ['success' => false, 'error' => 'invalid_response', 'message' => 'Invalid JSON response from server.'];
        }

        return $data; // Devuelve el array decodificado
    }

    /**
     * Devuelve un mensaje de error más amigable basado en el código de la API.
     */
    private function get_friendly_error_message($error_code, $api_message = '') {
         $message = sprintf(
            /* translators: %s: Código de error de la API */
            esc_html__('Error al activar la licencia. Código: %s', 'woowapp-smsenlinea-pro'),
            esc_html($error_code)
        );

        switch ($error_code) {
            case 'invalid_license_key':
                $message = __('La clave de licencia introducida no existe o es inválida.', 'woowapp-smsenlinea-pro');
                break;
            case 'license_plugin_mismatch':
                 $message = __('Esta clave de licencia es válida, pero pertenece a otro producto.', 'woowapp-smsenlinea-pro');
                break;
             case 'license_not_active':
                 $message = __('Esta licencia no está activa. Revisa su estado en tu cuenta o contacta con soporte.', 'woowapp-smsenlinea-pro');
                break;
            case 'license_expired':
                 $message = __('Esta licencia ha expirado. Por favor, renuévala para continuar.', 'woowapp-smsenlinea-pro');
                break;
            case 'activation_limit_reached':
                 $message = __('Se ha alcanzado el límite de activaciones para esta licencia. Desactívala en otro sitio o mejora tu plan.', 'woowapp-smsenlinea-pro');
                break;
            case 'request_failed':
                $message = __('Error de conexión al intentar contactar con el servidor de licencias. Revisa tu conexión a internet.', 'woowapp-smsenlinea-pro') . ' (' . esc_html($api_message) . ')';
                break;
             case 'invalid_response':
             case (preg_match('/^server_error_/', $error_code) ? true : false): // Para errores HTTP
                $message = __('El servidor de licencias devolvió una respuesta inesperada. Inténtalo más tarde o contacta con soporte.', 'woowapp-smsenlinea-pro');
                break;
        }
         return $message;
    }


    // Manejar solicitud de desactivación manual vía admin-post
    public function handle_manual_deactivation() {
         if (!isset($_POST['wse_deactivate_nonce']) || !wp_verify_nonce($_POST['wse_deactivate_nonce'], 'wse_deactivate_license_nonce')) {
             wp_die(esc_html__('Fallo de seguridad.', 'woowapp-smsenlinea-pro'));
         }
         if (!current_user_can('manage_options')) {
             wp_die(esc_html__('No tienes permisos.', 'woowapp-smsenlinea-pro'));
         }

        $current_key = get_option($this->option_key);
        if (!empty($current_key)) {
            $response = $this->call_api('deactivate', $current_key);
            if (isset($response['success']) && $response['success']) {
                delete_option($this->option_key);
                update_option($this->option_status, 'inactive');
                // Añadir un aviso temporal
                add_settings_error($this->option_group, 'manual_deactivated_ok', __('Licencia desactivada correctamente en este sitio.', 'woowapp-smsenlinea-pro'), 'updated');
            } else {
                 $error_code = $response['error'] ?? 'unknown';
                 add_settings_error($this->option_group, 'manual_deactivate_fail', sprintf(__('Error al desactivar la licencia. Código: %s', 'woowapp-smsenlinea-pro'), $error_code), 'error');
            }
        } else {
             add_settings_error($this->option_group, 'manual_no_key', __('No había licencia activa para desactivar.', 'woowapp-smsenlinea-pro'), 'warning');
        }

        // Guardar los mensajes para que se muestren después de la redirección
        set_transient('settings_errors', get_settings_errors(), 30);

        // Redirigir de vuelta a la página de licencia
        wp_safe_redirect(admin_url('options-general.php?page=' . $this->page_slug));
        exit;
    }

    // Manejar solicitud de verificación manual
    private function handle_check_request() {
         if (!isset($_POST['wse_check_nonce']) || !wp_verify_nonce($_POST['wse_check_nonce'], 'wse_check_license_nonce')) {
            add_settings_error($this->option_group, 'nonce_fail', __('Fallo de seguridad al verificar.', 'woowapp-smsenlinea-pro'), 'error');
            return;
         }
         if (!current_user_can('manage_options')) {
            add_settings_error($this->option_group, 'perm_fail', __('No tienes permisos para verificar.', 'woowapp-smsenlinea-pro'), 'error');
            return;
        }

        $current_key = get_option($this->option_key);
        if (empty($current_key)) {
            add_settings_error($this->option_group, 'no_key_check', __('No hay licencia guardada para verificar.', 'woowapp-smsenlinea-pro'), 'warning');
            return;
        }

        $response = $this->call_api('check', $current_key);

        if (isset($response['success']) && $response['success']) {
            update_option($this->option_status, 'active'); // Reconfirmar estado activo
            add_settings_error($this->option_group, 'check_ok', __('Verificación exitosa: La licencia está activa y válida para este dominio.', 'woowapp-smsenlinea-pro'), 'updated');
        } else {
             update_option($this->option_status, 'inactive'); // Marcar como inactiva si la verificación falla
             $error_code = $response['error'] ?? 'unknown';
             $error_message = $this->get_friendly_error_message($error_code, $response['message'] ?? '');
             add_settings_error($this->option_group, 'check_fail', sprintf(__('La verificación de licencia falló: %s', 'woowapp-smsenlinea-pro'), $error_message), 'error');
        }
    }


    // Añadir enlace de ajustes en la página de plugins
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . $this->page_slug)) . '">' . __('Ajustes de Licencia', 'woowapp-smsenlinea-pro') . '</a>';
        array_unshift($links, $settings_link); // Añadir al principio de la lista
        return $links;
    }
}
