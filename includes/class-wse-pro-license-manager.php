<?php
/**
 * Clase para gestionar la licencia de WooWApp Pro.
 * Adaptado de la documentación de descargas.smsenlinea.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_License_Manager {

    private $plugin_slug;
    private $option_group = 'wse_pro_license_options'; // Cambiado
    private $page_slug    = 'wse-pro-license';         // Cambiado
    private $option_key   = 'wse_pro_license_key';     // Cambiado
    private $option_status= 'wse_pro_license_status';  // Cambiado

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        add_action('admin_menu', [$this, 'add_license_page']);
        add_action('admin_init', [$this, 'register_settings']);
        // Opcional: Añadir un enlace a la página de licencia desde la lista de plugins
        add_filter('plugin_action_links_' . plugin_basename(WSE_PRO_FILE), [$this, 'add_settings_link']);
    }

    public function add_license_page() {
        add_options_page(
            __('Licencia de WooWApp Pro', 'woowapp-smsenlinea-pro'), // Cambiado y traducible
            __('WooWApp Pro Licencia', 'woowapp-smsenlinea-pro'),    // Cambiado y traducible
            'manage_options',
            $this->page_slug,
            [$this, 'render_license_page']
        );
    }

    public function render_license_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ajustes de Licencia de WooWApp Pro', 'woowapp-smsenlinea-pro'); // Cambiado ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections($this->page_slug);
                submit_button(__('Guardar y Activar Licencia', 'woowapp-smsenlinea-pro')); // Traducible
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Desactivar Licencia', 'woowapp-smsenlinea-pro'); ?></h2>
            <p><?php esc_html_e('Si deseas usar tu licencia en otro sitio, desactívala aquí primero.', 'woowapp-smsenlinea-pro'); ?></p>
            <form method="post" action="" id="wse-deactivate-license-form">
                <?php wp_nonce_field('wse_deactivate_license_nonce', 'wse_deactivate_nonce'); ?>
                <input type="hidden" name="wse_action" value="deactivate_license">
                <?php submit_button(__('Desactivar Licencia en este sitio', 'woowapp-smsenlinea-pro'), 'delete', 'wse_deactivate_submit', false); ?>
            </form>
        </div>
        <?php
        // Script simple para manejar la desactivación
        if (isset($_POST['wse_action']) && $_POST['wse_action'] === 'deactivate_license') {
            $this->handle_deactivation_request();
        }
    }

    public function register_settings() {
        register_setting(
            $this->option_group,
            $this->option_key, // Usar la opción correcta
            [$this, 'sanitize_and_validate_license']
        );
        add_settings_section(
            'wse_pro_license_section', // Cambiado
            __('Estado de la Activación', 'woowapp-smsenlinea-pro'), // Traducible
            [$this, 'render_section_text'],
            $this->page_slug
        );
        add_settings_field(
            'wse_pro_license_key_field', // Cambiado
            __('Clave de Licencia', 'woowapp-smsenlinea-pro'), // Traducible
            [$this, 'render_license_key_field'],
            $this->page_slug,
            'wse_pro_license_section' // Cambiado
        );
    }

    public function render_section_text() {
        $status = get_option($this->option_status, 'inactive');
        $license_key = get_option($this->option_key, '');

        if ($status === 'active' && !empty($license_key)) {
            echo '<p style="color: green; font-weight: bold;">' . esc_html__('Tu licencia está activa.', 'woowapp-smsenlinea-pro') . '</p>'; // Traducible
            // Opcional: Mostrar botón para verificar estado
            echo '<form method="post" action="" style="display:inline-block; margin-left: 10px;">';
            wp_nonce_field('wse_check_license_nonce', 'wse_check_nonce');
            echo '<input type="hidden" name="wse_action" value="check_license">';
            submit_button(__('Verificar Estado', 'woowapp-smsenlinea-pro'), 'secondary', 'wse_check_submit', false);
            echo '</form>';
        } else {
            echo '<p style="color: red; font-weight: bold;">' . esc_html__('Tu licencia está inactiva.', 'woowapp-smsenlinea-pro') . '</p>'; // Traducible
            if (!empty($license_key)) {
                 echo '<p>' . esc_html__('Intenta guardar de nuevo la clave para reactivarla o contacta con soporte.', 'woowapp-smsenlinea-pro') . '</p>';
            }
        }
         // Manejar verificación si se envió el formulario
        if (isset($_POST['wse_action']) && $_POST['wse_action'] === 'check_license') {
            $this->handle_check_request();
        }
    }

    public function render_license_key_field() {
        $license_key = get_option($this->option_key, '');
        echo "<input type='text' name='" . esc_attr($this->option_key) . "' value='" . esc_attr($license_key) . "' class='regular-text' placeholder='" . esc_attr__('Introduce tu clave de licencia aquí', 'woowapp-smsenlinea-pro') . "' />";
    }

    public function sanitize_and_validate_license($new_key) {
        $old_key = get_option($this->option_key);
        $new_key = sanitize_text_field(trim($new_key)); // Limpiar la nueva clave

        // Si la clave no ha cambiado, no hacer nada
        if ($old_key === $new_key) {
            return $old_key;
        }

        // Si la clave antigua existía y la nueva está vacía o es diferente, desactivar la antigua
        if (!empty($old_key) && $old_key !== $new_key) {
            $this->call_api('deactivate', $old_key);
            update_option($this->option_status, 'inactive'); // Marcar como inactiva inmediatamente
            add_settings_error($this->option_group, 'deactivated', __('Licencia anterior desactivada.', 'woowapp-smsenlinea-pro'), 'updated');
        }

        // Si la nueva clave está vacía, simplemente borrarla y marcar como inactiva
        if (empty($new_key)) {
            update_option($this->option_status, 'inactive');
            return ''; // Devolver vacío para borrar la opción
        }

        // Intentar activar la nueva clave
        $response = $this->call_api('activate', $new_key);

        if (isset($response['success']) && $response['success']) {
            update_option($this->option_status, 'active');
            add_settings_error($this->option_group, 'activated', __('¡Licencia activada con éxito!', 'woowapp-smsenlinea-pro'), 'updated');
            return $new_key; // Guardar la nueva clave
        } else {
            // Falló la activación
            update_option($this->option_status, 'inactive');
            $error_code = $response['error'] ?? 'unknown';
            $error_message = sprintf(
                /* translators: %s: Código de error de la API */
                esc_html__('Error al activar la licencia. Código: %s', 'woowapp-smsenlinea-pro'),
                esc_html($error_code)
            );
             // Mensajes de error más amigables (opcional, basado en los códigos de error de tu API)
            switch ($error_code) {
                case 'invalid_key':
                    $error_message = __('La clave de licencia introducida no es válida.', 'woowapp-smsenlinea-pro');
                    break;
                case 'limit_reached':
                     $error_message = __('Se ha alcanzado el límite de activaciones para esta licencia.', 'woowapp-smsenlinea-pro');
                    break;
                case 'key_expired':
                     $error_message = __('Esta licencia ha expirado.', 'woowapp-smsenlinea-pro');
                    break;
                // Añadir más casos según los errores de tu API
            }

            add_settings_error($this->option_group, 'error', $error_message, 'error');
            return ''; // No guardar la clave inválida
        }
    }

    private function call_api($action, $license_key) {
        $api_url = 'https://descargas.smsenlinea.com/api/license.php';
        $request_body = [
            'action'        => $action,
            'license_key'   => $license_key,
            'plugin_slug'   => $this->plugin_slug,
            'domain'        => home_url(), // Dominio del sitio actual
        ];

        // Añadir contexto para debug
        error_log('WooWApp License API Call - URL: ' . $api_url . ' | Payload: ' . print_r($request_body, true));


        $response = wp_remote_post($api_url, [
            'timeout' => 15, // Aumentar timeout un poco
            'body' => $request_body,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('WooWApp License API Error (wp_remote_post): ' . $error_message);
            return ['success' => false, 'error' => 'request_failed', 'message' => $error_message];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Log de la respuesta cruda
        error_log('WooWApp License API Response - Raw Body: ' . $body);


        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log('WooWApp License API Error: Invalid JSON response.');
             return ['success' => false, 'error' => 'invalid_response', 'message' => 'Invalid JSON response from server.'];
        }

        return $data; // Devuelve el array decodificado
    }

     // Manejar solicitud de desactivación
    private function handle_deactivation_request() {
        if (!isset($_POST['wse_deactivate_nonce']) || !wp_verify_nonce($_POST['wse_deactivate_nonce'], 'wse_deactivate_license_nonce')) {
             add_settings_error($this->option_group, 'nonce_fail', __('Fallo de seguridad al desactivar.', 'woowapp-smsenlinea-pro'), 'error');
             return;
        }
         if (!current_user_can('manage_options')) {
            add_settings_error($this->option_group, 'perm_fail', __('No tienes permisos para desactivar.', 'woowapp-smsenlinea-pro'), 'error');
            return;
        }

        $current_key = get_option($this->option_key);
        if (empty($current_key)) {
            add_settings_error($this->option_group, 'no_key', __('No hay licencia activa para desactivar.', 'woowapp-smsenlinea-pro'), 'warning');
            return;
        }

        $response = $this->call_api('deactivate', $current_key);

        if (isset($response['success']) && $response['success']) {
            delete_option($this->option_key);
            update_option($this->option_status, 'inactive');
            add_settings_error($this->option_group, 'deactivated_ok', __('Licencia desactivada correctamente en este sitio.', 'woowapp-smsenlinea-pro'), 'updated');
        } else {
             $error_code = $response['error'] ?? 'unknown';
             add_settings_error($this->option_group, 'deactivate_fail', sprintf(__('Error al desactivar la licencia. Código: %s', 'woowapp-smsenlinea-pro'), $error_code), 'error');
        }
    }

     // Manejar solicitud de verificación
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
            add_settings_error($this->option_group, 'no_key_check', __('No hay licencia para verificar.', 'woowapp-smsenlinea-pro'), 'warning');
            return;
        }

        $response = $this->call_api('check', $current_key);

        if (isset($response['success']) && $response['success']) {
            update_option($this->option_status, 'active'); // Reconfirmar estado activo
            add_settings_error($this->option_group, 'check_ok', __('La licencia está activa y válida.', 'woowapp-smsenlinea-pro'), 'updated');
        } else {
             update_option($this->option_status, 'inactive'); // Marcar como inactiva si la verificación falla
             $error_code = $response['error'] ?? 'unknown';
             add_settings_error($this->option_group, 'check_fail', sprintf(__('La verificación de licencia falló. Código: %s', 'woowapp-smsenlinea-pro'), $error_code), 'error');
        }
    }

     // Añadir enlace de ajustes en la página de plugins
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=' . $this->page_slug) . '">' . __('Ajustes de Licencia', 'woowapp-smsenlinea-pro') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
