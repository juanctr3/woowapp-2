<?php
/**
 * Maneja la lógica de reemplazo de placeholders para los mensajes y define las variables disponibles.
 *
 * @package WooWApp
 * @version 2.2.2
 * @MODIFIED: Corregida la función get_first_cart_item_image_url para usar product_id.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Pro_Placeholders {

    /**
     * Reemplaza todos los placeholders en una plantilla con datos de un pedido.
     * @param string   $template La plantilla con placeholders.
     * @param WC_Order $order    El objeto del pedido.
     * @param array    $extras   Placeholders adicionales (ej. para notas).
     * @return string            La plantilla con los valores reemplazados.
     */
    public static function replace($template, $order, $extras = []) {
        $placeholders = self::get_placeholder_values($order, $extras);
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }
    
    /**
     * Reemplaza placeholders para mensajes de carritos abandonados.
     * MEJORADO: Ahora usa los nuevos campos de billing y formatos mejorados
     * @param string   $template La plantilla del mensaje.
     * @param stdClass $cart_row El objeto de la fila de la base de datos del carrito.
     * @param array    $coupon_data Datos del cupón generado (opcional).
     * @return string            El mensaje con los valores reemplazados.
     */
    public static function replace_for_cart($template, $cart_row, $coupon_data = null) {
        $cart_contents = maybe_unserialize($cart_row->cart_contents);
        
        // Lista de productos en formato estándar (con precios)
        $items_list = '';
        // Lista de productos formato simple (solo nombres)
        $items_list_simple = '';
        // Lista de productos formato detallado (con cantidades y precios individuales)
        $items_list_detailed = '';
        // Primer producto
        $first_product_name = '';
        // Contador de productos
        $product_count = 0;
        $total_items = 0;

        if (is_array($cart_contents) && !empty($cart_contents)) {
            $product_index = 0;
            
            foreach ($cart_contents as $item) {
                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    continue;
                }
                
                $product_id = $item['product_id'];
                $variation_id = isset($item['variation_id']) ? $item['variation_id'] : 0;
                $quantity = $item['quantity'];
                
                // Obtener producto
                $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
                
                if (!$product) {
                    continue;
                }
                
                $product_name = $product->get_name();
                $price = $product->get_price();
                $line_total = $price * $quantity;
                
                // Guardar primer producto
                if ($product_index === 0) {
                    $first_product_name = $product_name;
                }
                
                // Agregar variaciones si existen
                $variation_text = '';
                if ($variation_id > 0 && isset($item['variation']) && is_array($item['variation'])) {
                    $variations = [];
                    foreach ($item['variation'] as $attr_name => $attr_value) {
                        $attr_name_clean = str_replace(['attribute_', 'pa_'], '', $attr_name);
                        $attr_name_clean = ucfirst(str_replace('-', ' ', $attr_name_clean));
                        $variations[] = $attr_name_clean . ': ' . $attr_value;
                    }
                    if (!empty($variations)) {
                        $variation_text = ' (' . implode(', ', $variations) . ')';
                    }
                }
                
                // FORMATO ESTÁNDAR: Para {cart_items}
                $items_list .= '  - ' . $product_name . $variation_text . ' (x' . $quantity . ') - ' . 
                              self::clean_for_whatsapp(wc_price($line_total)) . "\n";
                
                // FORMATO SIMPLE: Para {cart_items_simple}
                $items_list_simple .= '• ' . $product_name . ($quantity > 1 ? " (x{$quantity})" : '') . "\n";
                
                // FORMATO DETALLADO: Para {cart_items_detailed}
                $items_list_detailed .= '🛒 ' . $product_name . $variation_text . "\n";
                $items_list_detailed .= '   ' . __('Cantidad:', 'woowapp-smsenlinea-pro') . ' ' . $quantity . ' x ' . 
                                       self::clean_for_whatsapp(wc_price($price)) . ' = ' . 
                                       self::clean_for_whatsapp(wc_price($line_total)) . "\n\n";
                
                $product_count++;
                $total_items += $quantity;
                $product_index++;
            }
        }
        
        // Obtener nombre del cliente (priorizar billing_first_name)
        $customer_name = '';
        $customer_lastname = '';
        $customer_email = '';
        $customer_phone = '';
        
        // NUEVO: Usar los campos de billing capturados
        if (!empty($cart_row->billing_first_name)) {
            $customer_name = $cart_row->billing_first_name;
        } elseif (!empty($cart_row->first_name)) {
            $customer_name = $cart_row->first_name;
        }
        
        if (!empty($cart_row->billing_last_name)) {
            $customer_lastname = $cart_row->billing_last_name;
        }
        
        if (!empty($cart_row->billing_email)) {
            $customer_email = $cart_row->billing_email;
        }
        
        if (!empty($cart_row->billing_phone)) {
            $customer_phone = $cart_row->billing_phone;
        } elseif (!empty($cart_row->phone)) {
            $customer_phone = $cart_row->phone;
        }
        
        // Fallback: buscar en checkout_data o user_id
        if (empty($customer_name) && !empty($cart_row->checkout_data)) {
            parse_str($cart_row->checkout_data, $checkout_fields);
            $customer_name = $checkout_fields['billing_first_name'] ?? '';
        }
        
        if (empty($customer_name) && $cart_row->user_id) {
            $user_info = get_userdata($cart_row->user_id);
            if ($user_info) {
                $customer_name = $user_info->first_name;
            }
        }

        // Generar enlace de recuperación
        $recovery_link = add_query_arg('recover-cart-wse', $cart_row->recovery_token, wc_get_checkout_url());
        
        // NUEVO: Enlace corto (sin parámetros visibles)
        $recovery_link_short = add_query_arg('recover-cart-wse', $cart_row->recovery_token, home_url());

        // Preparar valores base
        $values = [
            // Tienda
            '{shop_name}' => get_bloginfo('name'),
            '{shop_url}' => home_url(),
            
            // Cliente - NUEVOS campos mejorados
            '{customer_name}' => trim($customer_name),
            '{first_name}' => trim($customer_name), // Alias
            '{last_name}' => trim($customer_lastname),
            '{customer_fullname}' => trim($customer_name . ' ' . $customer_lastname),
            '{billing_email}' => $customer_email,
            '{billing_phone}' => $customer_phone,
            
            // Productos - Formatos múltiples
            '{cart_items}' => trim($items_list), // Formato original
            '{cart_items_simple}' => trim($items_list_simple), // NUEVO: Solo nombres
            '{cart_items_detailed}' => trim($items_list_detailed), // NUEVO: Detallado con emojis
            '{product_list}' => trim($items_list_detailed), // Alias
            '{first_product_name}' => $first_product_name,
            
            // Contadores - NUEVOS
            '{product_count}' => $product_count, // Cantidad de productos diferentes
            '{item_count}' => $total_items, // Cantidad total de items
            '{cart_item_count}' => $total_items, // Alias
            
            // Totales
            '{cart_total}' => self::clean_for_whatsapp(wc_price($cart_row->cart_total)),
            '{cart_total_raw}' => number_format($cart_row->cart_total, 2),
            
            // Enlaces
            '{checkout_link}' => $recovery_link, // Original
            '{recovery_link}' => $recovery_link, // Alias más claro
            '{recovery_link_short}' => $recovery_link_short, // NUEVO: Enlace corto
        ];

        // Agregar variables de cupón si existe
        if ($coupon_data && is_array($coupon_data)) {
            if (isset($coupon_data['coupon_code'])) {
                $values['{coupon_code}'] = $coupon_data['coupon_code'];
            } elseif (isset($coupon_data['code'])) {
                $values['{coupon_code}'] = $coupon_data['code'];
            } else {
                $values['{coupon_code}'] = '';
            }
            
            if (isset($coupon_data['formatted_discount'])) {
                $values['{coupon_amount}'] = $coupon_data['formatted_discount'];
                $values['{discount_amount}'] = $coupon_data['formatted_discount'];
            } elseif (isset($coupon_data['discount_type']) && isset($coupon_data['amount'])) {
                if ($coupon_data['discount_type'] === 'percent') {
                    $values['{coupon_amount}'] = $coupon_data['amount'] . '%';
                    $values['{discount_amount}'] = $coupon_data['amount'] . '%';
                } else {
                    $values['{coupon_amount}'] = self::clean_for_whatsapp(wc_price($coupon_data['amount']));
                    $values['{discount_amount}'] = self::clean_for_whatsapp(wc_price($coupon_data['amount']));
                }
            } else {
                $values['{coupon_amount}'] = '';
                $values['{discount_amount}'] = '';
            }
            
            if (isset($coupon_data['formatted_expiry'])) {
                $values['{coupon_expires}'] = $coupon_data['formatted_expiry'];
            } else {
                $values['{coupon_expires}'] = '';
            }
        } else {
            // Si no hay cupón, reemplazar con vacío
            $values['{coupon_code}'] = '';
            $values['{coupon_amount}'] = '';
            $values['{coupon_expires}'] = '';
            $values['{discount_amount}'] = '';
        }

        // Asegurar que todos los placeholders definidos tengan valor
        $all_placeholders = self::get_all_placeholders_grouped();
        foreach ($all_placeholders as $group) {
            foreach ($group as $placeholder) {
                if (!isset($values[$placeholder])) {
                    $values[$placeholder] = '';
                }
            }
        }

        return str_replace(array_keys($values), array_values($values), $template);
    }

    /**
     * Limpia una cadena de texto para ser segura y legible en WhatsApp.
     * @param string $string El texto a limpiar.
     * @return string        El texto limpio.
     */
    private static function clean_for_whatsapp($string) {
        if (empty($string)) return '';
        $text = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Obtiene la URL de la imagen del primer producto de un PEDIDO.
     * @param WC_Order $order El objeto del pedido.
     * @return string         La URL de la imagen o una cadena vacía.
     */
    public static function get_first_product_image_url($order) {
        if (!$order || empty($order->get_items())) return '';
        
        $first_item = reset($order->get_items());
        $product = $first_item->get_product();
        if (!$product) return '';

        $image_id = $product->get_image_id();
        if (!$image_id && $product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image_id = $parent_product->get_image_id();
            }
        }
        return $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    }

    /**
     * Obtiene la URL de la imagen del primer producto de un CARRITO.
     * @param string $cart_contents Contenido del carrito serializado.
     * @return string                 La URL de la imagen o una cadena vacía.
     */
    public static function get_first_cart_item_image_url($cart_contents) {
        $cart_array = maybe_unserialize($cart_contents);
        if (empty($cart_array) || !is_array($cart_array)) {
            return '';
        }

        $first_item = reset($cart_array);
        
        // --- INICIO DE LA CORRECCIÓN ---
        // El carrito en la BD no tiene el objeto 'data', debemos usar el 'product_id'
        if (!isset($first_item['product_id'])) {
            return ''; // No hay producto
        }

        $product_id = absint($first_item['product_id']);
        $variation_id = isset($first_item['variation_id']) ? absint($first_item['variation_id']) : 0;
        
        // Obtener el producto (variación o simple)
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        
        if (!$product) {
            return ''; // El producto ya no existe
        }
        // --- FIN DE LA CORRECCIÓN ---

        $image_id = $product->get_image_id();
        
        // Si es una variación sin imagen, buscar la del padre
        if (!$image_id && $product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            if ($parent_product) {
                $image_id = $parent_product->get_image_id();
            }
        }
        
        // Si aún no hay imagen (ej. producto simple sin imagen), no devolvemos nada
        if (!$image_id) {
            return '';
        }

        return wp_get_attachment_image_url($image_id, 'full');
    }
    
    /**
     * Construye el array de valores para todos los placeholders a partir de un pedido.
     * @param WC_Order $order El objeto del pedido.
     * @param array    $extras Placeholders adicionales.
     * @return array           Un array asociativo de placeholder => valor.
     */
    private static function get_placeholder_values($order, $extras = []) {
        $items = $order->get_items();
        $first_item = !empty($items) ? reset($items) : null;
        $first_product = $first_item ? $first_item->get_product() : null;

        $items_list_with_price = '';
        $items_list_no_price = '';
        $items_list_sku = '';
        foreach ($items as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : __('N/A', 'woowapp-smsenlinea-pro');
            $price = self::clean_for_whatsapp(wc_price($item->get_total()));
            $items_list_with_price .= '  - ' . $item->get_name() . ' (x' . $item->get_quantity() . ") - " . $price . "\n";
            $items_list_no_price .= '  - ' . $item->get_name() . ' (x' . $item->get_quantity() . ")\n";
            $items_list_sku .= '  - ' . $item->get_name() . ' (SKU: ' . $sku . ') (x' . $item->get_quantity() . ")\n";
        }
        
        $my_account_link = wc_get_page_permalink('myaccount') ?: '';
        $payment_link = $order->get_checkout_payment_url() ?: '';
        $first_product_link = $first_product ? $first_product->get_permalink() : '';
        
        $review_page_slug = 'escribir-resena';
        $first_product_review_link = add_query_arg([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key()
        ], home_url('/' . $review_page_slug . '/'));

        $values = [
            '{shop_name}' => get_bloginfo('name'),
            '{shop_url}' => home_url(),
            '{my_account_link}' => $my_account_link,
            '{order_id}' => $order->get_order_number(),
            '{order_status}' => wc_get_order_status_name($order->get_status()),
            '{order_date}' => wc_format_datetime($order->get_date_created()),
            '{order_total}' => self::clean_for_whatsapp($order->get_formatted_order_total()),
            '{order_subtotal}' => self::clean_for_whatsapp($order->get_subtotal_to_display()),
            '{order_total_raw}' => $order->get_total(),
            '{order_currency}' => html_entity_decode(get_woocommerce_currency_symbol()),
            '{order_items}' => trim($items_list_with_price),
            '{order_items_no_price}' => trim($items_list_no_price),
            '{order_items_sku}' => trim($items_list_sku),
            '{order_item_count}' => $order->get_item_count(),
            '{order_shipping_total}' => self::clean_for_whatsapp(wc_price($order->get_shipping_total())),
            '{order_tax_total}' => self::clean_for_whatsapp(wc_price($order->get_total_tax())),
            '{order_discount_total}' => self::clean_for_whatsapp(wc_price($order->get_discount_total())),
            '{order_admin_url}' => $order->get_edit_order_url(),
            '{payment_method}' => $order->get_payment_method_title(),
            '{payment_link}' => $payment_link,
            '{shipping_method}' => $order->get_shipping_method(),
            '{customer_name}' => $order->get_billing_first_name(),
            '{customer_lastname}' => $order->get_billing_last_name(),
            '{customer_fullname}' => $order->get_formatted_billing_full_name(),
            '{billing_email}' => $order->get_billing_email(),
            '{billing_phone}' => $order->get_billing_phone(),
            '{billing_address}' => self::clean_for_whatsapp($order->get_formatted_billing_address()),
            '{shipping_address}' => self::clean_for_whatsapp($order->get_formatted_shipping_address()),
            '{customer_note}' => $order->get_customer_note(),
            '{first_product_name}' => $first_product ? $first_product->get_name() : '',
            '{first_product_link}' => $first_product_link,
            '{first_product_review_link}' => $first_product_review_link,
            '{product_image_url}' => self::get_first_product_image_url($order),
        ];

        return array_merge($values, $extras);
    }

    /**
     * Define los placeholders disponibles para la UI del admin, agrupados por categoría.
     * MEJORADO: Agregados nuevos placeholders para carritos abandonados
     * @return array
     */
    public static function get_all_placeholders_grouped() {
        return [
            __('Tienda', 'woowapp-smsenlinea-pro') => [
                '{shop_name}', 
                '{shop_url}', 
                '{my_account_link}'
            ],
            __('Pedido (General)', 'woowapp-smsenlinea-pro') => [
                '{order_id}', 
                '{order_status}', 
                '{order_date}', 
                '{order_admin_url}'
            ],
            __('Pedido (Totales)', 'woowapp-smsenlinea-pro') => [
                '{order_total}', 
                '{order_subtotal}', 
                '{order_shipping_total}', 
                '{order_tax_total}', 
                '{order_discount_total}', 
                '{order_currency}', 
                '{order_total_raw}'
            ],
            __('Pedido (Listas de Productos)', 'woowapp-smsenlinea-pro') => [
                '{order_items}', 
                '{order_items_no_price}', 
                '{order_items_sku}', 
                '{order_item_count}'
            ],
            __('Pagos y Envíos', 'woowapp-smsenlinea-pro') => [
                '{payment_method}', 
                '{payment_link}', 
                '{shipping_method}'
            ],
            __('Cliente', 'woowapp-smsenlinea-pro') => [
                '{customer_name}', 
                '{first_name}', 
                '{last_name}', 
                '{customer_lastname}', 
                '{customer_fullname}', 
                '{billing_email}', 
                '{billing_phone}', 
                '{customer_note}'
            ],
            __('Direcciones', 'woowapp-smsenlinea-pro') => [
                '{billing_address}', 
                '{shipping_address}'
            ],
            __('Producto (Primer Ítem)', 'woowapp-smsenlinea-pro') => [
                '{first_product_name}', 
                '{first_product_link}', 
                '{first_product_review_link}', 
                '{product_image_url}'
            ],
            __('Reseñas', 'woowapp-smsenlinea-pro') => [
                '{review_rating}',
                '{review_content}',
                '{review_moderation_link}',
                '{first_product_review_link}',
            ],            
            __('Carrito Abandonado - General', 'woowapp-smsenlinea-pro') => [
                '{cart_total}',
                '{cart_total_raw}',
                '{product_count}',
                '{item_count}',
                '{cart_item_count}',
            ],
            __('Carrito Abandonado - Productos', 'woowapp-smsenlinea-pro') => [
                '{cart_items}',
                '{cart_items_simple}',
                '{cart_items_detailed}',
                '{product_list}',
                '{first_product_name}',
            ],
            __('Carrito Abandonado - Enlaces', 'woowapp-smsenlinea-pro') => [
                '{checkout_link}',
                '{recovery_link}',
                '{recovery_link_short}',
            ],
            __('Cupones de Descuento', 'woowapp-smsenlinea-pro') => [
                '{coupon_code}', 
                '{coupon_amount}', 
                '{coupon_expires}', 
                '{discount_amount}'
            ]
        ];
    }
    
    /**
     * Define los emojis disponibles para la UI del admin, agrupados por categoría.
     * MEJORADO: Agregados muchos más emojis útiles
     * @return array
     */
    public static function get_all_emojis_grouped() {
        return [
            __('Caras y Emociones', 'woowapp-smsenlinea-pro') => [
                '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', 
                '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', 
                '😘', '😗', '😙', '😚', '😋', '😛', '😜', '😝', 
                '🤪', '🤨', '🧐', '🤓', '😎', '🤩', '🥳', '😏', 
                '😒', '😞', '😔', '😟', '😕', '🙁', '😣', '😖', 
                '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡', 
                '🤬', '🤯', '😳', '🥵', '🥶', '😱', '😨', '😰', 
                '😥', '😢', '🤗', '🤔', '🤭', '🤫', '🤥', '😶'
            ],
            __('Gestos y Personas', 'woowapp-smsenlinea-pro') => [
                '👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤌', '🤏', 
                '✌️', '🤞', '🫰', '🤟', '🤘', '🤙', '👈', '👉', 
                '👆', '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', 
                '🤜', '👏', '🙌', '👐', '🤲', '🤸', '🫲', '🫳', 
                '🦵', '🦶', '👂', '👃', '🧠', '🦷', '🦴', '👅', 
                '👄', '🫀', '🫁', '🦵', '🦶', '👂', '👃', '👁️', 
                '👀', '🫂', '👶', '👧', '🧒', '👦', '👨', '🧑', 
                '👩', '👴', '👵', '🧓', '👨‍⚕️', '👩‍⚕️', '👨‍🎓', '👩‍🎓'
            ],
            __('Comercio y Compras', 'woowapp-smsenlinea-pro') => [
                '🛒', '🛍️', '🎁', '🎀', '🎉', '🎊', '🎈', '🎂', 
                '📦', '📮', '📪', '📫', '📬', '📭', '📤', '📥', 
                '💰', '💵', '💴', '💶', '💷', '💸', '💳', '💎', 
                '⚖️', '🧾', '💹', '💲', '✅', '✔️', '☑️', '✨', 
                '🔥', '🌟', '⭐', '🏆', '🥇', '🥈', '🥉', '🎖️'
            ],
            __('Objetos y Tecnología', 'woowapp-smsenlinea-pro') => [
                '📱', '📲', '💻', '⌨️', '🖥️', '🖨️', '🖱️', '🖲️', 
                '🕹️', '☎️', '📟', '📠', '📺', '📻', '🎙️', '🎚️', 
                '🎛️', '⏱️', '⏲️', '⏰', '🕰️', '⌚', '⏳', '📡', 
                '🔔', '🔕', '📢', '📣', '📯', '📞', '☎️', '📟', 
                '📠', '🔋', '🔌', '💡', '🔦', '🕯️', '📕', '📖', 
                '📗', '📘', '📙', '📚', '📓', '📒', '📏', '📐', 
                '📌', '📍', '📎', '📏', '📐', '📌', '🗂️', '🗃️', 
                '🗳️', '🗄️', '📫', '📪', '📬', '📭', '📮', '📯'
            ],
            __('Transporte y Viajes', 'woowapp-smsenlinea-pro') => [
                '🚗', '🚕', '🚙', '🚌', '🚎', '🏎️', '🚓', '🚑', 
                '🚒', '🚐', '🛻', '🚚', '🚛', '🚜', '🏍️', '🏎️', 
                '🛵', '🦯', '🦽', '🦼', '🛴', '🚲', '🛴', '🛹', 
                '🛼', '🚏', '⛽', '🚨', '🚥', '🚦', '🛑', '🚧', 
                '⚓', '⛵', '🚤', '🛳️', '🛶', '✈️', '🛩️', '🛫', 
                '🛬', '🪂', '💺', '🚁', '🚟', '🚠', '🚡', '🛰️', 
                '🚀', '🛸', '🚁', '🚂', '🚃', '🚄', '🚅', '🚆'
            ],
            __('Tiempo y Clima', 'woowapp-smsenlinea-pro') => [
                '⏰', '⏱️', '⏲️', '⌚', '🕰️', '⌛', '⏳', '📡', 
                '🌍', '🌎', '🌏', '🗺️', '🗿', '🗽', '🗼', '🏔️', 
                '⛰️', '🌋', '⛰️', '🏔️', '🗻', '🏕️', '⛺', '⛺', 
                '🏠', '🏡', '🏘️', '🏚️', '🏗️', '🏭', '🏢', '🏬', 
                '🏣', '🏤', '🏥', '🏦', '🏧', '🏨', '🏪', '🏫', 
                '🏩', '💒', '🏛️', '⛪', '🕌', '🕍', '🛕', '🛖', 
                '🗼', '🗽', '🗿', '⛩️', '🛤️', '🛣️', '🛢️', '⛽'
            ],
            __('Símbolos y Alertas', 'woowapp-smsenlinea-pro') => [
                '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', 
                '🤎', '💔', '💕', '💞', '💓', '💗', '💖', '💘', 
                '💝', '💟', '💜', '💛', '💚', '💙', '💔', '❣️', 
                '💕', '💞', '💓', '💗', '💖', '💘', '💝', '❤️', 
                '✅', '✔️', '☑️', '❌', '❎', '❓', '❕', '❔', 
                '⚠️', '🔱', '⚡', '🔥', '💥', '⭐', '🌟', '✨', 
                '➡️', '⬅️', '⬆️', '⬇️', '↗️', '↘️', '↙️', '↖️', 
                '↕️', '↔️', '↩️', '↪️', '⤴️', '⤵️', '🔄', '🔃'
            ],
            __('Animales y Naturaleza', 'woowapp-smsenlinea-pro') => [
                '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', 
                '🐨', '🯁', '🦁', '🐮', '🐷', '🐸', '🐵', '🙈', 
                '🙉', '🙊', '🐒', '🐔', '🐧', '🐦', '🐤', '🦆', 
                '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', 
                '🪱', '🐛', '🦋', '🐌', '🐞', '🐜', '🪰', '🐢', 
                '🐍', '🐙', '🦑', '🦐', '🦞', '🦀', '🐡', '🐠', 
                '🐟', '🐬', '🐳', '🐋', '🦈', '🐊', '🐅', '🐆', 
                '🦓', '🦍', '🦧', '🐘', '🦛', '🦏', '🐪', '🐫'
            ],
            __('Comida y Bebida', 'woowapp-smsenlinea-pro') => [
                '🍏', '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', 
                '🍓', '🫐', '🍈', '🍒', '🍑', '🥭', '🍍', '🥥', 
                '🥑', '🍆', '🍅', '🌶️', '🌽', '🥒', '🥬', '🥦', 
                '🧄', '🧅', '🍄', '🥜', '🌰', '💐', '🎂', '🍰', 
                '🎂', '🍮', '🍭', '🍬', '🍫', '🍿', '🍩', '🍪', 
                '🌰', '🥧', '🍗', '🍖', '🌭', '🍔', '🍟', '🍕', 
                '🌮', '🌯', '🥪', '🥙', '🧆', '🍜', '🍝', '🍠', 
                '🍱', '🥘', '🍛', '🍲', '🥣', '🥗', '🍿', '🧈'
            ],
            __('Deporte y Actividades', 'woowapp-smsenlinea-pro') => [
                '⚽', '🀄', '🈸', '🉐', '🈹', '🈵', '🈴', '🈺', 
                '🎯', '🎳', '🎮', '🎰', '🧩', '🚗', '🚕', '🚙', 
                '🚌', '🚎', '🏎️', '🚓', '🚑', '🚒', '🚐', '🛻', 
                '🚚', '🚛', '🚜', '🏍️', '🏎️', '🛵', '🦯', '🦽', 
                '🦼', '🛴', '🚲', '🛴', '🛹', '🛼', '🚏', '⛽', 
                '🚨', '🚥', '🚦', '🛑', '🚧', '⚓', '⛵', '🚤'
            ],
            __('Celebraciones y Eventos', 'woowapp-smsenlinea-pro') => [
                '🎃', '🎄', '🎆', '🎇', '🧨', '✨', '🎈', '🎉', 
                '🎊', '🎋', '🎍', '🎎', '🎏', '🎐', '🎑', '🧧', 
                '🎀', '🎁', '🎗️', '🎟️', '🎫', '🎖️', '🏆', '🏅', 
                '🥇', '🥈', '🥉', '⚽', '⚾', '🥎', '🎾', '🏐', 
                '🏈', '🏉', '🥏', '🎳', '🏓', '🏸', '🏒', '🏑', 
                '🥍', '🏏', '🥅', '⛳', '⛸️', '🎣', '🎽', '🎿', 
                '🛷', '🥌', '🎯', '🪀', '🪃', '🎪', '🎨', '🎬'
            ]
        ];
    }
}