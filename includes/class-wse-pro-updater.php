<?php
/**
 * Clase para gestionar las actualizaciones automáticas de WooWApp Pro.
 * Adaptado de la documentación de descargas.smsenlinea.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Auto_Updater {

    private $api_url;
    private $plugin_slug;
    private $plugin_version;
    private $license_key;
    private $plugin_file_basename;

    public function __construct($plugin_file, $plugin_slug, $plugin_version, $license_key) {
        $this->api_url = 'https://descargas.smsenlinea.com/api/update.php';
        $this->plugin_file_basename = plugin_basename($plugin_file); // Usa la ruta del archivo principal
        $this->plugin_slug = $plugin_slug;
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
        // Si WordPress no está comprobando, salir
        if (empty($transient->checked)) {
            return $transient;
        }

        // Si no hay clave de licencia, no buscar actualizaciones premium
        if (empty($this->license_key)) {
             error_log('WooWApp Updater: No license key found, skipping premium update check.');
            return $transient;
        }

        // Llamar a nuestra API para obtener la información de la última versión
        $response = $this->call_api('plugin_information');

        // Log de la respuesta de la API de actualización
        error_log('WooWApp Updater API Response: ' . print_r($response, true));


        // Si la llamada falló o no devolvió un objeto válido, salir
        if ($response === false || !is_object($response) || !isset($response->new_version)) {
            error_log('WooWApp Updater: Failed to get update information from API or invalid response.');
            return $transient;
        }

        // Comparar la versión actual con la nueva versión
        if (version_compare($this->plugin_version, $response->new_version, '<')) {
            // Hay una nueva versión disponible, añadirla al transient de WordPress
            // Asegurarse de que el objeto tenga las propiedades esperadas por WordPress
            $update_info = (object)[
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file_basename,
                'new_version' => $response->new_version,
                'url'         => $response->homepage ?? '', // URL del plugin
                'package'     => $response->download_link ?? '', // URL de descarga del ZIP
                'icons'       => (array) ($response->icons ?? []),
                'banners'     => (array) ($response->banners ?? []),
                'tested'      => $response->tested ?? '', // Versión de WP probada
                'requires_php'=> $response->requires_php ?? '', // Versión de PHP requerida
            ];

            $transient->response[$this->plugin_file_basename] = $update_info;
             error_log('WooWApp Updater: Update available! Version ' . $response->new_version);

        } else {
             error_log('WooWApp Updater: Plugin is up to date (Current: ' . $this->plugin_version . ', Latest: ' . $response->new_version . ')');
        }

        return $transient;
    }

    /**
     * Proporciona información detallada del plugin para la ventana modal de "Ver detalles".
     */
    public function plugin_information($result, $action, $args) {
         // Solo actuar si se pide información de nuestro plugin y la acción es 'plugin_information'
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result; // Devolver $result sin modificar
        }

         // Si no hay clave de licencia, no mostrar información premium
        if (empty($this->license_key)) {
             error_log('WooWApp Updater (Info): No license key, cannot fetch premium details.');
            return $result; // Podríamos devolver un error o simplemente no hacer nada
        }

        // Llamar a la API para obtener los detalles
        $response = $this->call_api('plugin_information');

         // Si la llamada falló o no devolvió un objeto válido, devolver $result original
        if ($response === false || !is_object($response)) {
             error_log('WooWApp Updater (Info): Failed to get plugin details from API.');
            return $result;
        }

        // Rellenar el objeto $result con la información de la API
        // WordPress espera un objeto con propiedades específicas
        $result = (object)[
            'name'              => $response->name ?? $this->plugin_slug,
            'slug'              => $this->plugin_slug,
            'version'           => $response->new_version ?? $this->plugin_version,
            'author'            => $response->author ?? '',
            'author_profile'    => $response->author_profile ?? '',
            'homepage'          => $response->homepage ?? '',
            'requires'          => $response->requires ?? '', // Versión mínima de WP
            'tested'            => $response->tested ?? '', // Versión de WP probada
            'requires_php'      => $response->requires_php ?? '',
            'download_link'     => $response->download_link ?? '',
            'trunk'             => $response->download_link ?? '', // A veces se usa trunk como sinónimo
            'last_updated'      => $response->last_updated ?? '',
            'sections'          => (array) ($response->sections ?? []), // Changelog, description, etc.
            'banners'           => (array) ($response->banners ?? []),
            'icons'             => (array) ($response->icons ?? []),
        ];
        error_log('WooWApp Updater (Info): Successfully fetched plugin details from API.');

        return $result;
    }

    /**
     * Llama a la API de actualizaciones.
     */
    private function call_api($action) {
        $payload = [
             'timeout' => 15,
            'body' => [
                'action'        => $action,
                'plugin_slug'   => $this->plugin_slug,
                'version'       => $this->plugin_version,
                'license_key'   => $this->license_key,
                 'domain'        => home_url(), // Enviar dominio también para verificación
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
        $response = json_decode($body); // La API de ejemplo devuelve un objeto, no un array

        error_log('WooWApp Updater API Response - Raw Body: ' . $body);


        // Verificar si la respuesta es un objeto JSON válido y no está vacío
        if (is_object($response) && !empty($response)) {
            // Importante: La API debe incluir la propiedad 'plugin' con el basename correcto
            // Si no lo hace, lo añadimos nosotros aquí.
            if (!isset($response->plugin)) {
                $response->plugin = $this->plugin_file_basename;
            }
            return $response;
        } elseif ($response === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log('WooWApp Updater API Error: Invalid JSON response.');
        } else {
             error_log('WooWApp Updater API Error: Empty or unexpected response format.');
        }


        return false;
    }
}
