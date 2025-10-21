<?php
/**
 * Clase para gestionar las actualizaciones automáticas de WooWApp Pro.
 * Adaptado de la documentación v2 de descargas.smsenlinea.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Auto_Updater {

    private $api_url;
    private $update_identifier; // <-- CAMBIO: Usa identificador de actualización
    private $plugin_version;
    private $license_key;
    private $plugin_file_basename;

    // Constructor actualizado para recibir $update_identifier
    public function __construct($plugin_file, $update_identifier, $plugin_version, $license_key) {
        $this->api_url = 'https://descargas.smsenlinea.com/api/update.php';
        $this->plugin_file_basename = plugin_basename($plugin_file);
        $this->update_identifier = $update_identifier; // <-- CAMBIO: Guardar identificador
        $this->plugin_version = $plugin_version;
        $this->license_key = $license_key;

        // Hooks para comprobar actualizaciones
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        // Hooks para mostrar información detallada de la actualización (changelog, etc.)
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
    }

    /**
     * Comprueba si hay actualizaciones disponibles.
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        if (empty($this->license_key)) {
            error_log('WooWApp Updater: No license key found, skipping premium update check.');
            return $transient;
        }

        // Llamar a nuestra API para obtener la información de la última versión
        $response = $this->call_api('plugin_information');
        error_log('WooWApp Updater Check API Response: ' . print_r($response, true));

        if ($response && isset($response->new_version) && version_compare($this->plugin_version, $response->new_version, '<')) {
            // Hay una nueva versión disponible, añadirla al transient
            // Asegurarse de que el objeto tenga las propiedades esperadas por WordPress
            $response->plugin = $this->plugin_file_basename; // Asegurarse que esta propiedad existe
             // Comprobar si package URL existe y es válida
             if (empty($response->package) || !filter_var($response->package, FILTER_VALIDATE_URL)) {
                error_log('WooWApp Updater Error: Invalid or missing package URL in API response for version ' . $response->new_version);
                // No añadir al transient si no hay link de descarga válido
             } else {
                $transient->response[$this->plugin_file_basename] = $response;
                error_log('WooWApp Updater: Update available! Version ' . $response->new_version . ' | Package: ' . $response->package);
             }
        } else {
             $latest_version = $response->new_version ?? 'N/A';
             error_log('WooWApp Updater: Plugin is up to date (Current: ' . $this->plugin_version . ', Latest: ' . $latest_version . ') or API check failed.');
        }

        return $transient;
    }

    /**
     * Proporciona información detallada del plugin para la ventana modal de "Ver detalles".
     */
    // --- INICIO DEL CÓDIGO NUEVO Y CORRECTO ---
public function plugin_information($result, $action, $args) {
    $request_slug = $args->slug ?? '';

    // Solo actuar si WordPress está pidiendo información para nuestro plugin
    if ($action !== 'plugin_information' || ($request_slug !== $this->update_identifier)) {
        return $result; // Si no es nuestro plugin, no hacemos nada
    }

    if (empty($this->license_key)) {
        return $result; // No hay licencia, no hay detalles
    }

    // Llamar a nuestra API para obtener todos los detalles del plugin
    $response = $this->call_api('plugin_information');
    error_log('WooWApp Updater Info API Response: ' . print_r($response, true));

    // Si la API respondió con un objeto válido, lo devolvemos directamente.
    // WordPress se encargará de mostrar toda la información.
    if ($response !== false && is_object($response)) {

        // WordPress espera que la sección 'sections' sea un array. Nos aseguramos de que lo sea.
        if (isset($response->sections)) {
            $response->sections = (array) $response->sections;
        }

        return $response; // ¡Esta es la corrección clave!
    }

    // Si algo falló en la llamada a la API, devolvemos el resultado original.
    return $result;
}
// --- FIN DEL CÓDIGO NUEVO Y CORRECTO ---

    /**
     * Llama a la API de actualizaciones.
     */
    private function call_api($action) {
        $payload = [
            'timeout' => 20,
            'sslverify' => true,
            'body' => [
                'action'            => $action,
                'update_identifier' => $this->update_identifier, // <-- CAMBIO: Enviar identificador
                'version'           => $this->plugin_version,
                'license_key'       => $this->license_key,
                'domain'            => home_url(), // Enviar dominio para validación adicional
                'wp_version'        => get_bloginfo('version'), // Versión de WP del sitio
                'php_version'       => phpversion(), // Versión de PHP del sitio
            ],
        ];

        error_log('WooWApp Updater API Call - URL: ' . $this->api_url . ' | Payload: ' . print_r($payload['body'], true));

        $request = wp_remote_post($this->api_url, $payload);

        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            $error_message = is_wp_error($request) ? $request->get_error_message() : 'HTTP Code ' . wp_remote_retrieve_response_code($request);
            error_log('WooWApp Updater API Error (wp_remote_post): ' . $error_message);
            return false;
        }

        $body = wp_remote_retrieve_body($request);
        // La API de actualizaciones devuelve un objeto JSON serializado
        $response = json_decode($body); // Decodificar como objeto

        error_log('WooWApp Updater API Response - Raw Body: ' . $body);

        // Verificar si la respuesta es un objeto JSON válido y no está vacío
        if (is_object($response) && !empty($response)) {
            // Añadir la propiedad 'plugin' que WordPress espera
            $response->plugin = $this->plugin_file_basename;
            return $response;
        } elseif ($response === null && json_last_error() !== JSON_ERROR_NONE) {
             error_log('WooWApp Updater API Error: Invalid JSON response.');
        } else {
             error_log('WooWApp Updater API Error: Empty or unexpected response format.');
        }

        return false;
    }
}
