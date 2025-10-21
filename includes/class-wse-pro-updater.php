<?php
/**
 * Clase para gestionar las actualizaciones automáticas de WooWApp Pro.
 * VERSIÓN CORREGIDA Y SIMPLIFICADA
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Auto_Updater {

    private $api_url;
    private $update_identifier;
    private $plugin_version;
    private $license_key;
    private $plugin_file_basename;

    public function __construct($plugin_file, $update_identifier, $plugin_version, $license_key) {
        $this->api_url = 'https://descargas.smsenlinea.com/api/update.php';
        $this->plugin_file_basename = plugin_basename($plugin_file);
        $this->update_identifier = $update_identifier;
        $this->plugin_version = $plugin_version;
        $this->license_key = $license_key;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
    }

    /**
     * Comprueba si hay actualizaciones disponibles.
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked) || empty($this->license_key)) {
            return $transient;
        }

        $response = $this->call_api('plugin_information');

        if ($response && isset($response->new_version) && version_compare($this->plugin_version, $response->new_version, '<')) {
            // El objeto $response ya tiene todo lo que necesitamos, lo asignamos directamente
            $transient->response[$this->plugin_file_basename] = $response;
        }

        return $transient;
    }

    /**
     * Proporciona información detallada del plugin para la ventana modal de "Ver detalles".
     */
    public function plugin_information($result, $action, $args) {
        $request_slug = $args->slug ?? '';

        if ($action !== 'plugin_information' || ($request_slug !== $this->update_identifier && $request_slug !== dirname($this->plugin_file_basename))) {
            return $result;
        }

        $response = $this->call_api('plugin_information');

        if ($response !== false && is_object($response)) {
            // WordPress espera que 'sections' sea un array. Nos aseguramos de que lo sea.
            if (isset($response->sections)) {
                $response->sections = (array) $response->sections;
            }
            return $response; // Devolvemos la respuesta completa de la API
        }

        return $result;
    }

    /**
     * Llama a la API de actualizaciones.
     */
    private function call_api($action) {
        $payload = [
            'timeout' => 20,
            'sslverify' => true,
            'body' => [
                'action'            => $action,
                'update_identifier' => $this->update_identifier,
                'version'           => $this->plugin_version,
                'license_key'       => $this->license_key,
                'domain'            => home_url(),
                'wp_version'        => get_bloginfo('version'),
                'php_version'       => phpversion(),
            ],
        ];

        $request = wp_remote_post($this->api_url, $payload);

        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return false;
        }

        $response = json_decode(wp_remote_retrieve_body($request));

        if (is_object($response) && !empty($response)) {
            $response->plugin = $this->plugin_file_basename;
            return $response;
        }

        return false;
    }
}
