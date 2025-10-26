<?php
/**
 * Maneja toda la comunicación con las APIs de SMSenlinea y la lógica de envío de mensajes.
 *
 * @package WooWApp
 * @version 1.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Clase WSE_Pro_API_Handler.
 */
class WSE_Pro_API_Handler {

    /**
     * Identificador para los registros de WooCommerce.
     * @var string
     */
    public static $log_handle = 'wse-pro';

    /**
     * URLs de las APIs.
     * @var string
     */
    private $api_url_panel1_base = 'https://whatsapp.smsenlinea.com/api/send/';
    private $api_url_panel2 = 'https://api.smsenlinea.com/api/qr/rest/send_message';

    /**
     * Array de códigos de país.
     * @var array
     */
    private $country_codes = [];

    /**
     * Constructor. Carga los códigos de país.
     */
    public function __construct() {
        if (file_exists(WSE_PRO_PATH . 'includes/country-codes.php')) {
            $this->country_codes = include(WSE_PRO_PATH . 'includes/country-codes.php');
        }
    }

    /**
     * Maneja el envío de notificaciones cuando cambia el estado de un pedido.
     *
     * @param int      $order_id ID del pedido.
     * @param WC_Order $order Objeto del pedido.
     */
    public function handle_status_change($order_id, $order) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        
        $status = $order->get_status();
        $slug_clean = str_replace('wc-', '', $status);

        // 1. Notificación para el CLIENTE
        if ('yes' === get_option('wse_pro_enable_' . $status, 'no')) {
            $template = get_option('wse_pro_message_' . $status);
            if (!empty($template)) {
                $message = WSE_Pro_Placeholders::replace($template, $order);
                $this->send_message($order->get_billing_phone(), $message, $order, 'customer');
            }
        }
        
        // 2. Notificación para ADMINISTRADORES
        if ('yes' === get_option('wse_pro_enable_admin_' . $slug_clean, 'no')) {
            $admin_numbers_raw = get_option('wse_pro_admin_numbers', '');
            $admin_numbers = array_filter(array_map('trim', explode("\n", $admin_numbers_raw)));

            if (!empty($admin_numbers)) {
                $template = get_option('wse_pro_admin_message_' . $slug_clean);
                if (!empty($template)) {
                    $message = WSE_Pro_Placeholders::replace($template, $order);
                    foreach ($admin_numbers as $number) {
                        $this->send_message($number, $message, $order, 'admin');
                    }
                }
            }
        }
    }
    
    /**
     * Maneja el envío de notificaciones cuando se añade una nueva nota a un pedido.
     *
     * @param array $data Datos de la nota.
     */
    public function handle_new_note($data) {
        if ('yes' !== get_option('wse_pro_enable_note', 'no') || empty($data['order_id'])) {
            return;
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            return;
        }

        $template = get_option('wse_pro_message_note');
        if (empty($template)) {
            return;
        }
        
        $extras = ['{note_content}' => wp_strip_all_tags($data['customer_note'])];
        $message = WSE_Pro_Placeholders::replace($template, $order, $extras);
        $this->send_message($order->get_billing_phone(), $message, $order, 'customer');
    }

    /**
     * Envía un mensaje. Función centralizada que elige el panel y métodos correctos.
     *
     * @param string $phone       Número de teléfono de destino.
     * @param string $message     El mensaje a enviar.
     * @param mixed  $data_source El objeto WC_Order, objeto de carrito, o null.
     * @param string $type        El tipo de destinatario (customer, admin, test).
     * @return array              Respuesta de la operación.
     */
    public function send_message($phone, $message, $data_source = null, $type = 'customer') {
        // --- INICIO: Verificación de Licencia General ---
    if (!function_exists('wse_pro_is_license_active')) {
        // Log de error crítico si la función auxiliar no existe
        $this->log(__('Error Crítico: La función wse_pro_is_license_active() no existe. No se puede verificar la licencia.', 'woowapp-smsenlinea-pro'));
        // Devolver error genérico para no exponer detalles internos
        return ['success' => false, 'message' => __('Error interno de configuración.', 'woowapp-smsenlinea-pro')];
    }

    if (!wse_pro_is_license_active()) {
        $license_page_url = admin_url('options-general.php?page=wse-pro-license');
        $error_message = sprintf(
            /* translators: %s: Link to license activation page */
            esc_html__('Envío bloqueado: La licencia de WooWApp Pro no está activa. Por favor, %sactívala aquí%s.', 'woowapp-smsenlinea-pro'),
            '<a href="' . esc_url($license_page_url) . '">',
            '</a>'
        );

        // Registrar en el log por qué se bloqueó
        $this->log(sprintf(__('Envío bloqueado para %s (Tipo: %s). Razón: Licencia inactiva.', 'woowapp-smsenlinea-pro'), $phone, $type));

        // Añadir nota al pedido si aplica (solo para mensajes relacionados con pedidos)
        if ($data_source && is_a($data_source, 'WC_Order')) {
             $data_source->add_order_note(__('Envío de WhatsApp/SMS bloqueado: Licencia de WooWApp Pro inactiva.', 'woowapp-smsenlinea-pro'));
        }

        // Devolver error indicando el bloqueo
        // Usamos un mensaje simple para la API, el detallado es para el log/nota
        return ['success' => false, 'message' => __('Licencia de WooWApp Pro inactiva.', 'woowapp-smsenlinea-pro')];
    }
    
    $selected_panel = get_option('wse_pro_api_panel_selection', 'panel2');
    
        $selected_panel = get_option('wse_pro_api_panel_selection', 'panel2');
        
        // --- INICIO DE LA CORRECCIÓN ---
        $country = '';
        if ($data_source && 'customer' === $type) {
            if (is_a($data_source, 'WC_Order')) {
                // Es un Pedido
                $country = $data_source->get_billing_country();
            } elseif (is_object($data_source) && isset($data_source->billing_country)) {
                // Es un Carrito Abandonado (nuestro objeto $cart_obj)
                $country = $data_source->billing_country;
            }
        }
        // --- FIN DE LA CORRECCIÓN ---

        $full_phone = $this->format_phone($phone, $country);
        
        if (empty($full_phone) || empty($message)) {
            $this->log(__('Envío cancelado: Número de teléfono o mensaje vacío.', 'woowapp-smsenlinea-pro'));
            return ['success' => false, 'message' => __('Número de teléfono o mensaje vacío.', 'woowapp-smsenlinea-pro')];
        }

        if ($selected_panel === 'panel1') {
            return $this->send_via_panel1($full_phone, $message, $data_source, $type);
        } else {
            return $this->send_via_panel2($full_phone, $message, $data_source, $type);
        }
    }
    
    /**
     * Envía mensaje usando la API del Panel 1 (Clásico) según la documentación.
     *
     * @param string $phone       Número de teléfono formateado.
     * @param string $message     Mensaje a enviar.
     * @param mixed  $data_source Fuente de datos.
     * @param string $type        Tipo de destinatario.
     * @return array
     */
    private function send_via_panel1($phone, $message, $data_source, $type) {
        $secret = get_option('wse_pro_api_secret_panel1');
        $message_type = get_option('wse_pro_message_type_panel1', 'whatsapp');

        if (empty($secret)) {
            $this->log(__('Envío (Panel 1) cancelado: Falta API Secret.', 'woowapp-smsenlinea-pro'));
            return ['success' => false, 'message' => __('Falta el API Secret del Panel 1.', 'woowapp-smsenlinea-pro')];
        }
        
        $body = ['secret' => $secret];
        $endpoint_url = '';

        if ($message_type === 'whatsapp') {
            $endpoint_url = $this->api_url_panel1_base . 'whatsapp';
            $account_id = get_option('wse_pro_whatsapp_account_panel1');

            if (empty($account_id)) {
                return ['success' => false, 'message' => __('Falta el WhatsApp Account ID.', 'woowapp-smsenlinea-pro')];
            }

            $body['account'] = $account_id;
            $body['recipient'] = $phone;

            $image_url = '';
            $attach_image = get_option('wse_pro_attach_product_image', 'no');
            if ($data_source && 'yes' === $attach_image) {
                if (is_a($data_source, 'WC_Order')) {
                    $image_url = WSE_Pro_Placeholders::get_first_product_image_url($data_source);
                } elseif (is_object($data_source) && isset($data_source->cart_contents)) {
                    $image_url = WSE_Pro_Placeholders::get_first_cart_item_image_url($data_source->cart_contents);
                }
            }
            
            if (!empty($image_url)) {
                $body['type'] = 'media';
                $body['message'] = str_replace('{product_image_url}', '', $message);
                $body['media_url'] = $image_url;
                $body['media_type'] = 'image';
            } else {
                $body['type'] = 'text';
                $body['message'] = str_replace('{product_image_url}', '', $message);
            }

        } else { // SMS
            $endpoint_url = $this->api_url_panel1_base . 'sms';
            $mode = get_option('wse_pro_sms_mode_panel1', 'devices');
            $device_id = get_option('wse_pro_sms_device_panel1');

            if (empty($device_id)) {
                return ['success' => false, 'message' => __('Falta el Device / Gateway ID.', 'woowapp-smsenlinea-pro')];
            }

            $body['mode'] = $mode;
            $body['phone'] = $phone;
            $body['message'] = str_replace('{product_image_url}', '', $message);
            if ($mode === 'devices') {
                $body['device'] = $device_id;
            } else {
                $body['gateway'] = $device_id;
            }
        }

        $response = wp_remote_post($endpoint_url, [
            'body' => $body,
            'timeout' => 30
        ]);

        return $this->handle_response($response, $phone, $data_source, $type);
    }

    /**
     * Envía mensaje usando la API del Panel 2 (QR).
     *
     * @param string $phone       Número de teléfono formateado.
     * @param string $message     Mensaje a enviar.
     * @param mixed  $data_source Fuente de datos.
     * @param string $type        Tipo de destinatario.
     * @return array
     */
    private function send_via_panel2($phone, $message, $data_source, $type) {
        $token = get_option('wse_pro_api_token');
        $from = get_option('wse_pro_from_number');

        if (empty($token) || empty($from)) {
            $this->log(__('Envío (Panel 2) cancelado: Faltan credenciales.', 'woowapp-smsenlinea-pro'));
            if ($data_source && is_a($data_source, 'WC_Order')) {
                $data_source->add_order_note(__('Error WhatsApp: Faltan credenciales de API Panel 2.', 'woowapp-smsenlinea-pro'));
            }
            return ['success' => false, 'message' => __('Faltan credenciales de API Panel 2.', 'woowapp-smsenlinea-pro')];
        }

        $image_url = '';
        $attach_image = 'no';
        if ($data_source) {
            if (is_a($data_source, 'WC_Order')) {
                $attach_image = get_option('wse_pro_attach_product_image', 'no');
                if ('yes' === $attach_image) {
                    $image_url = WSE_Pro_Placeholders::get_first_product_image_url($data_source);
                }
            } elseif (is_object($data_source) && isset($data_source->cart_contents)) {
                // Es un objeto de carrito abandonado
                $attach_image = get_option('wse_pro_abandoned_cart_attach_image', 'no');
                if ('yes' === $attach_image) {
                    $image_url = WSE_Pro_Placeholders::get_first_cart_item_image_url($data_source->cart_contents);
                }
            }
        }
        
        $message_type = (!empty($image_url) && 'yes' === $attach_image) ? 'image' : 'text';

        $body = ['requestType' => 'POST', 'token' => $token, 'from' => $from, 'to' => $phone];
        if ('image' === $message_type) {
            $body['messageType'] = 'image';
            $body['imageUrl'] = $image_url;
            $body['caption'] = str_replace('{product_image_url}', '', $message);
        } else {
            $body['messageType'] = 'text';
            $body['text'] = str_replace('{product_image_url}', '', $message);
        }

        $response = wp_remote_post($this->api_url_panel2, ['body' => wp_json_encode($body), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 30]);

        return $this->handle_response($response, $phone, $data_source, $type);
    }
    /**
     * Llama a la API de Panel 1 para obtener mensajes recibidos del cliente.
     *
     * @param string $secret API Secret del Panel 1.
     * @return array|bool Array de mensajes recibidos o false si falla.
     */
    public function get_received_chats_from_panel1($secret) {
        if (empty($secret)) {
            $this->log(__('No se pudo obtener mensajes recibidos: API Secret del Panel 1 vacío.', 'woowapp-smsenlinea-pro'));
            return false;
        }

        $endpoint_url = 'https://whatsapp.smsenlinea.com/api/get/wa.received';
        $query_args = [
            'secret' => $secret,
            'limit' => 50, // Límite por página para no sobrecargar
            'page' => 1
        ];
        
        $url = add_query_arg($query_args, $endpoint_url);

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            $this->log(sprintf(__('Fallo API al obtener chats recibidos: %s', 'woowapp-smsenlinea-pro'), $response->get_error_message()));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['status']) && $body['status'] === 200 && isset($body['data']) && is_array($body['data'])) {
            $this->log(sprintf(__('Obtenidos %d mensajes recibidos del Panel 1.', 'woowapp-smsenlinea-pro'), count($body['data'])));
            return $body['data'];
        }
        
        $error = $body['message'] ?? __('Error desconocido al obtener chats recibidos.', 'woowapp-smsenlinea-pro');
        $this->log(sprintf(__('Fallo al obtener chats recibidos. Razón: %s', 'woowapp-smsenlinea-pro'), $error));
        return false;
    }
    
    /**
     * Formatea un número de teléfono con el código de país si es necesario.
     * PRIORIZA el país del cliente sobre el default.
     *
     * @param string $phone       Número de teléfono.
     * @param string $country_iso Código ISO del país del cliente (ej. 'PE', 'CO').
     * @return string             Número de teléfono formateado.
     */
    private function format_phone($phone, $country_iso) {
        // Limpiar cualquier caracter no numérico del teléfono
        $phone = preg_replace('/[^\d]/', '', $phone);
        if (empty($phone)) {
            return ''; // Si el teléfono queda vacío, no hay nada que formatear
        }

        // Obtener el código de país predeterminado desde los ajustes (será nuestro fallback)
        $default_code = get_option('wse_pro_default_country_code', ''); // Obtiene el valor, o '' si no está definido
        $default_code = preg_replace('/[^\d]/', '', $default_code); // Limpiar también el default code

        $calling_code = null; // Inicializamos el código a usar como nulo

        // --- Lógica de Prioridad ---
        // 1. Intentar obtener el código a partir del país del cliente ($country_iso)
        if (!empty($country_iso) && isset($this->country_codes[$country_iso])) {
            $customer_calling_code = $this->country_codes[$country_iso];
            // Verificación simple: si el teléfono ya empieza con este código, asumimos que está bien
            if (strpos($phone, $customer_calling_code) === 0) {
                 // Usar el método log de esta clase
                 $this->log(sprintf(__('Teléfono %s ya parece incluir el código de país %s para %s. No se añadirá prefijo.', 'woowapp-smsenlinea-pro'), $phone, $customer_calling_code, $country_iso));
                 return $phone; // Devolver sin modificar si ya empieza con el código correcto
            }
            // Si no empieza, guardamos este código como el candidato principal
            $calling_code = $customer_calling_code;
            // Usar el método log de esta clase
            $this->log(sprintf(__('Código de país %s determinado para %s.', 'woowapp-smsenlinea-pro'), $calling_code, $country_iso));
        } else {
             // Usar el método log de esta clase
             $this->log(sprintf(__('No se pudo determinar código de país desde ISO "%s". Se intentará usar el predeterminado.', 'woowapp-smsenlinea-pro'), $country_iso));
        }

        // 2. Si NO obtuvimos un código del país del cliente, Y SI hay un código predeterminado configurado, usar el predeterminado
        if (is_null($calling_code) && !empty($default_code)) {
            // Verificación simple: si el teléfono ya empieza con el código predeterminado
             if (strpos($phone, $default_code) === 0) {
                 // Usar el método log de esta clase
                 $this->log(sprintf(__('Teléfono %s ya parece incluir el código de país predeterminado %s. No se añadirá prefijo.', 'woowapp-smsenlinea-pro'), $phone, $default_code));
                 return $phone; // Devolver sin modificar
             }
            // Si no empieza, usamos el predeterminado como candidato
            $calling_code = $default_code;
            // Usar el método log de esta clase
            $this->log(sprintf(__('Usando código de país predeterminado: %s', 'woowapp-smsenlinea-pro'), $calling_code));
        }

        // --- Aplicación del Código (si encontramos uno) ---
        if (!is_null($calling_code)) {
            // Si tenemos un código candidato ($calling_code) Y el teléfono NO empieza ya con él
            if (strpos($phone, $calling_code) !== 0) {
                // Prependemos el código, quitando ceros iniciales del número original si los hubiera
                $formatted_phone = $calling_code . ltrim($phone, '0');
                // Usar el método log de esta clase
                $this->log(sprintf(__('Formateando teléfono %s a %s usando el código %s.', 'woowapp-smsenlinea-pro'), $phone, $formatted_phone, $calling_code));
                return $formatted_phone;
            } else {
                // Si ya empezaba con el código (verificado antes), lo devolvemos tal cual. Log ya hecho arriba.
                return $phone;
            }
        }

        // 3. Si no pudimos determinar ningún código (ni del cliente, ni predeterminado), devolvemos el número limpio tal cual.
        // Usar el método log de esta clase (podríamos considerarlo un warning, pero log() solo añade a un handle)
        $this->log(sprintf(__('ADVERTENCIA: No se pudo determinar un código de país para %s (ISO: %s, Default: %s). Enviando número tal cual.', 'woowapp-smsenlinea-pro'), $phone, $country_iso, $default_code ?: __('ninguno', 'woowapp-smsenlinea-pro')));
        return $phone;
    }
    
    /**
     * Procesa la respuesta de la API y la registra.
     *
     * @param WP_Error|array $response    Respuesta de wp_remote_post.
     * @param string         $phone       Número de teléfono al que se envió.
     * @param mixed          $data_source Objeto de datos (pedido o carrito).
     * @param string         $type        Tipo de destinatario.
     * @return array                      Resultado de la operación.
     */
    private function handle_response($response, $phone, $data_source, $type = 'customer') {
        $order_id_log = 'N/A';
        
        // Mejorar la identificación del origen
        if ($data_source && is_a($data_source, 'WC_Order')) {
            $order_id_log = '#' . $data_source->get_id();
        } elseif ($data_source && is_object($data_source) && isset($data_source->id)) {
            $order_id_log = 'Cart #' . $data_source->id;
        } elseif (is_null($data_source)) {
            $order_id_log = 'Test';
        }
        
        $recipient_log = ('admin' === $type) ? __('Admin', 'woowapp-smsenlinea-pro') : __('Cliente', 'woowapp-smsenlinea-pro');

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->log(sprintf(
                __('Fallo API (Ref: %s, Dest: %s). Error: %s', 'woowapp-smsenlinea-pro'),
                $order_id_log,
                $recipient_log,
                $error
            ));
            if ($data_source && is_a($data_source, 'WC_Order')) {
                $data_source->add_order_note(sprintf(
                    __('Error WhatsApp (%s): %s', 'woowapp-smsenlinea-pro'),
                    $recipient_log,
                    $error
                ));
            }
            return ['success' => false, 'message' => $error];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        $is_panel1_success = isset($body['status']) && $body['status'] === 'success';
        $is_panel2_success = isset($body['success']) && $body['success'] === true;
        $is_panel1_queued_success = isset($body['message']) && $body['message'] === 'WhatsApp chat has been queued for sending!';

        if ($is_panel1_success || $is_panel2_success || $is_panel1_queued_success) {
            $message_id = $body['data']['messageId'] ?? $body['data']['id'] ?? 'N/A';
            $note = sprintf(
                __('Notificación WhatsApp enviada a %s (%s).', 'woowapp-smsenlinea-pro'),
                $recipient_log,
                $phone
            );
            $this->log(sprintf(
                __('Éxito (Ref: %s, Tel: %s, Dest: %s). ID: %s', 'woowapp-smsenlinea-pro'),
                $order_id_log,
                $phone,
                $recipient_log,
                $message_id
            ));
            
            if ($data_source && is_a($data_source, 'WC_Order')) {
                $data_source->add_order_note($note);
            }
            
            $success_message = $is_panel1_queued_success ? __('Mensaje encolado para envío.', 'woowapp-smsenlinea-pro') : __('Enviado exitosamente.', 'woowapp-smsenlinea-pro');
            
            $success_message = $is_panel1_queued_success ? __('Mensaje encolado para envío.', 'woowapp-smsenlinea-pro') : __('Enviado exitosamente.', 'woowapp-smsenlinea-pro');
            
            // Retornar el message_id para rastreo
            return ['success' => true, 'message' => $success_message, 'message_id' => $message_id]; // <--- REEMPLAZAR ESTA LÍNEA

        } else {
            $error = $body['solution'] ?? $body['message'] ?? __('Error desconocido', 'woowapp-smsenlinea-pro');
            $note = sprintf(
                __('Fallo al enviar WhatsApp a %s (%s). Razón: %s', 'woowapp-smsenlinea-pro'),
                $recipient_log,
                $phone,
                $error
            );
            $this->log(sprintf(
                __('Fallo (Ref: %s, Tel: %s, Dest: %s). Razón: %s', 'woowapp-smsenlinea-pro'),
                $order_id_log,
                $phone,
                $recipient_log,
                $error
            ));
            
            if ($data_source && is_a($data_source, 'WC_Order')) {
                $data_source->add_order_note($note);
            }
            
            return ['success' => false, 'message' => $error];
        }
    }

    /**
     * Escribe un mensaje en el registro de WooCommerce.
     *
     * @param string $message Mensaje para registrar.
     */
    private function log($message) {
        if ('yes' === get_option('wse_pro_enable_log', 'yes') && class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->add(self::$log_handle, $message);
        }
    }

    /**
     * Maneja la llamada AJAX para el botón de prueba de envío.
     */
    public static function ajax_send_test_whatsapp() {
        check_ajax_referer('wse_pro_send_test_nonce', 'security');
        
        $handler = new self();
        $test_number = isset($_POST['test_number']) ? sanitize_text_field($_POST['test_number']) : '';
        $selected_panel = get_option('wse_pro_api_panel_selection', 'panel2');
        
        $panel_name = $selected_panel === 'panel1' ? __('Panel 1', 'woowapp-smsenlinea-pro') : __('Panel 2', 'woowapp-smsenlinea-pro');
        $message_type = get_option('wse_pro_message_type_panel1', 'whatsapp');
        $channel_name = ($selected_panel === 'panel1') ? strtoupper($message_type) : 'WhatsApp';

        $test_message = sprintf(
            __('✅ ¡Mensaje de prueba (%s) desde tu tienda! La API de %s funciona.', 'woowapp-smsenlinea-pro'),
            $channel_name,
            $panel_name
        );
        
        $result = $handler->send_message($test_number, $test_message, null, 'test');
        wp_send_json_success($result);
    }

}




