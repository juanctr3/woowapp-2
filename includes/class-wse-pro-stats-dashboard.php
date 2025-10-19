<?php
/**
 * Dashboard de Estadísticas - WooWApp Pro
 * Métricas de conversión, clicks y rendimiento
 */

if (!defined('ABSPATH')) exit;

class WSE_Pro_Stats_Dashboard {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_stats_menu'], 60);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_stats_scripts']);
    }
    
    /**
     * Agregar menú de estadísticas
     */
    public function add_stats_menu() {
        add_submenu_page(
            'woocommerce',
            __('Estadísticas WooWApp', 'woowapp-smsenlinea-pro'),
            __('📊 Estadísticas WooWApp', 'woowapp-smsenlinea-pro'),
            'manage_woocommerce',
            'wse-pro-stats',
            [$this, 'render_stats_page']
        );
    }
    
    /**
     * Scripts y estilos
     */
    public function enqueue_stats_scripts($hook) {
        if ($hook !== 'woocommerce_page_wse-pro-stats') {
            return;
        }
        
        wp_enqueue_style(
            'wse-pro-stats',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/stats.css',
            [],
            WSE_PRO_VERSION
        );
        
        // Chart.js para gráficos
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        
        wp_enqueue_script(
            'wse-pro-stats',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/stats.js',
            ['jquery', 'chartjs'],
            WSE_PRO_VERSION,
            true
        );
    }
    
    /**
     * Renderizar página de estadísticas
     */
    public function render_stats_page() {
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30';
        $stats = $this->get_stats($period);
        
        ?>
        <div class="wrap wse-stats-wrap">
            <h1><?php _e('📊 Estadísticas de Recuperación de Carritos', 'woowapp-smsenlinea-pro'); ?></h1>
            
            <!-- Selector de Periodo -->
            <div class="wse-stats-header">
                <div class="wse-period-selector">
                    <label><?php _e('📅 Periodo:', 'woowapp-smsenlinea-pro'); ?></label>
                    <select id="wse-period-select" onchange="window.location.href='?page=wse-pro-stats&period='+this.value">
                        <option value="7" <?php selected($period, '7'); ?>><?php esc_html_e('Últimos 7 días', 'woowapp-smsenlinea-pro'); ?></option>
                        <option value="30" <?php selected($period, '30'); ?>><?php esc_html_e('Últimos 30 días', 'woowapp-smsenlinea-pro'); ?></option>
                        <option value="90" <?php selected($period, '90'); ?>><?php esc_html_e('Últimos 90 días', 'woowapp-smsenlinea-pro'); ?></option>
                        <option value="365" <?php selected($period, '365'); ?>><?php esc_html_e('Último año', 'woowapp-smsenlinea-pro'); ?></option>
                    </select>
                </div>
            </div>
            
            <!-- KPIs Principales -->
            <div class="wse-stats-grid">
                <div class="wse-stat-card">
                    <div class="stat-icon">🛒</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Carritos Abandonados', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value"><?php echo number_format($stats['total_carts']); ?></div>
                        <div class="stat-change <?php echo $stats['carts_trend'] >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo $stats['carts_trend'] >= 0 ? '↑' : '↓'; ?> 
                            <?php echo abs($stats['carts_trend']); ?>% <?php _e('vs periodo anterior', 'woowapp-smsenlinea-pro'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="wse-stat-card">
                    <div class="stat-icon">📤</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Mensajes Enviados', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value"><?php echo number_format($stats['total_sent']); ?></div>
                        <div class="stat-detail">
                            <?php esc_html_e('Msg1:', 'woowapp-smsenlinea-pro'); ?> <?php echo $stats['sent_by_message'][1]; ?> | 
                            <?php esc_html_e('Msg2:', 'woowapp-smsenlinea-pro'); ?> <?php echo $stats['sent_by_message'][2]; ?> | 
                            <?php esc_html_e('Msg3:', 'woowapp-smsenlinea-pro'); ?> <?php echo $stats['sent_by_message'][3]; ?>
                        </div>
                    </div>
                </div>
                
                <div class="wse-stat-card highlight">
                    <div class="stat-icon">🔗</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Clicks en Enlaces', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value"><?php echo number_format($stats['total_clicks']); ?></div>
                        <div class="stat-detail">
                            <?php esc_html_e('CTR:', 'woowapp-smsenlinea-pro'); ?> <?php echo number_format($stats['click_rate'], 1); ?>%
                        </div>
                    </div>
                </div>
                
                <div class="wse-stat-card success">
                    <div class="stat-icon">🎉</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Conversiones', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value"><?php echo number_format($stats['total_conversions']); ?></div>
                        <div class="stat-detail">
                            <?php esc_html_e('Tasa:', 'woowapp-smsenlinea-pro'); ?> <?php echo number_format($stats['conversion_rate'], 1); ?>%
                        </div>
                    </div>
                </div>
                
                <div class="wse-stat-card revenue">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Ingresos Recuperados', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value">$<?php echo number_format($stats['recovered_revenue'], 2); ?></div>
                        <div class="stat-detail">
                            <?php esc_html_e('Promedio:', 'woowapp-smsenlinea-pro'); ?> $<?php echo number_format($stats['avg_order_value'], 2); ?>
                        </div>
                    </div>
                </div>
                
                <div class="wse-stat-card">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-content">
                        <div class="stat-label"><?php _e('Tiempo Promedio', 'woowapp-smsenlinea-pro'); ?></div>
                        <div class="stat-value"><?php echo $stats['avg_recovery_time']; ?></div>
                        <div class="stat-detail"><?php _e('Hasta recuperación', 'woowapp-smsenlinea-pro'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Gráficos -->
            <div class="wse-charts-container">
                <div class="wse-chart-card">
                    <h3><?php _e('📈 Conversiones por Día', 'woowapp-smsenlinea-pro'); ?></h3>
                    <canvas id="conversionsChart"></canvas>
                </div>
                
                <div class="wse-chart-card">
                    <h3><?php _e('📊 Rendimiento por Mensaje', 'woowapp-smsenlinea-pro'); ?></h3>
                    <canvas id="messagesChart"></canvas>
                </div>
            </div>
            
            <!-- Tabla de Eventos Recientes -->
            <div class="wse-events-table">
                <h3><?php _e('🕐 Actividad Reciente', 'woowapp-smsenlinea-pro'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Fecha', 'woowapp-smsenlinea-pro'); ?></th>
                            <th><?php esc_html_e('Carrito', 'woowapp-smsenlinea-pro'); ?></th>
                            <th><?php esc_html_e('Evento', 'woowapp-smsenlinea-pro'); ?></th>
                            <th><?php esc_html_e('Mensaje', 'woowapp-smsenlinea-pro'); ?></th>
                            <th><?php esc_html_e('Teléfono', 'woowapp-smsenlinea-pro'); ?></th>
                            <th><?php esc_html_e('Valor', 'woowapp-smsenlinea-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_events'] as $event): ?>
                        <tr>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($event->created_at))); ?></td>
                            <td>#<?php echo esc_html($event->cart_id); ?></td>
                            <td>
                                <span class="event-badge event-<?php echo esc_attr($event->event_type); ?>">
                                    <?php echo esc_html($this->get_event_label($event->event_type)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($event->message_number > 0): ?>
                                    <?php printf(
                                        esc_html__('Mensaje #%d', 'woowapp-smsenlinea-pro'),
                                        (int) $event->message_number
                                    ); ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($this->format_phone($event->phone)); ?></td>
                            <td>
                                <?php if ($event->order_total): ?>
                                    $<?php echo esc_html(number_format((float) $event->order_total, 2)); ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Datos para gráficos -->
            <script type="text/javascript">
                var wsePro_chartData = <?php echo wp_json_encode([
                    'conversions' => $stats['conversions_by_day'],
                    'messages' => $stats['messages_performance']
                ]); ?>;
            </script>
        </div>
        <?php
    }
    
    /**
     * Obtener estadísticas
     */
    private function get_stats($days = 30) {
        global $wpdb;
        
        $carts_table = $wpdb->prefix . 'wse_pro_abandoned_carts';
        $tracking_table = $wpdb->prefix . 'wse_pro_tracking';
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $date_to = current_time('mysql');
        
        // Carritos abandonados
        $total_carts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$carts_table} 
            WHERE created_at >= %s AND created_at <= %s",
            $date_from, $date_to
        ));
        
        // Tendencia de carritos
        $previous_period_from = date('Y-m-d H:i:s', strtotime("-" . ($days * 2) . " days"));
        $previous_period_to = $date_from;
        
        $previous_carts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$carts_table} 
            WHERE created_at >= %s AND created_at < %s",
            $previous_period_from, $previous_period_to
        ));
        
        $carts_trend = $previous_carts > 0 
            ? (($total_carts - $previous_carts) / $previous_carts) * 100 
            : 0;
        
        // Mensajes enviados
        $sent_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT message_number, COUNT(*) as count 
            FROM {$tracking_table} 
            WHERE event_type = 'sent' 
            AND created_at >= %s AND created_at <= %s
            GROUP BY message_number",
            $date_from, $date_to
        ));
        
        $sent_by_message = [1 => 0, 2 => 0, 3 => 0];
        $total_sent = 0;
        
        foreach ($sent_stats as $stat) {
            if ($stat->message_number >= 1 && $stat->message_number <= 3) {
                $sent_by_message[$stat->message_number] = (int) $stat->count;
                $total_sent += (int) $stat->count;
            }
        }
        
        // Clicks
        $total_clicks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tracking_table} 
            WHERE event_type = 'click' 
            AND created_at >= %s AND created_at <= %s",
            $date_from, $date_to
        ));
        
        $click_rate = $total_sent > 0 ? ($total_clicks / $total_sent) * 100 : 0;
        
        // Conversiones
        $total_conversions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tracking_table} 
            WHERE event_type = 'conversion' 
            AND created_at >= %s AND created_at <= %s",
            $date_from, $date_to
        ));
        
        $conversion_rate = $total_sent > 0 ? ($total_conversions / $total_sent) * 100 : 0;
        
        // Ingresos recuperados
        $revenue_data = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(CAST(JSON_EXTRACT(event_data, '$.order_total') AS DECIMAL(10,2))) as total,
                AVG(CAST(JSON_EXTRACT(event_data, '$.order_total') AS DECIMAL(10,2))) as average
            FROM {$tracking_table} 
            WHERE event_type = 'conversion' 
            AND created_at >= %s AND created_at <= %s",
            $date_from, $date_to
        ));
        
        $recovered_revenue = $revenue_data->total ? (float) $revenue_data->total : 0;
        $avg_order_value = $revenue_data->average ? (float) $revenue_data->average : 0;
        
        // Tiempo promedio de recuperación
        $avg_time = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, c.created_at, t.created_at)) as avg_minutes
            FROM {$tracking_table} t
            JOIN {$carts_table} c ON t.cart_id = c.id
            WHERE t.event_type = 'conversion'
            AND t.created_at >= %s AND t.created_at <= %s",
            $date_from, $date_to
        ));
        
        $avg_recovery_time = $avg_time ? $this->format_time($avg_time) : '–';
        
        // Conversiones por día
        $conversions_by_day = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
            FROM {$tracking_table}
            WHERE event_type = 'conversion'
            AND created_at >= %s AND created_at <= %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $date_from, $date_to
        ));
        
        // Rendimiento por mensaje
        $messages_performance = [];
        for ($i = 1; $i <= 3; $i++) {
            $sent = $sent_by_message[$i];
            
            $clicks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT cart_id) 
                FROM {$tracking_table} 
                WHERE event_type = 'click'
                AND cart_id IN (
                    SELECT cart_id FROM {$tracking_table}
                    WHERE event_type = 'sent' AND message_number = %d
                    AND created_at >= %s AND created_at <= %s
                )
                AND created_at >= %s AND created_at <= %s",
                $i, $date_from, $date_to, $date_from, $date_to
            ));
            
            $conversions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT cart_id) 
                FROM {$tracking_table} 
                WHERE event_type = 'conversion'
                AND cart_id IN (
                    SELECT cart_id FROM {$tracking_table}
                    WHERE event_type = 'sent' AND message_number = %d
                    AND created_at >= %s AND created_at <= %s
                )
                AND created_at >= %s AND created_at <= %s",
                $i, $date_from, $date_to, $date_from, $date_to
            ));
            
            $messages_performance[] = [
                'message' => sprintf(
                    __('Mensaje %d', 'woowapp-smsenlinea-pro'),
                    $i
                ),
                'sent' => $sent,
                'clicks' => (int) $clicks,
                'conversions' => (int) $conversions,
                'ctr' => $sent > 0 ? round(($clicks / $sent) * 100, 1) : 0,
                'conversion_rate' => $sent > 0 ? round(($conversions / $sent) * 100, 1) : 0
            ];
        }
        
        // Eventos recientes
        $recent_events = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, 
                JSON_EXTRACT(t.event_data, '$.phone') as phone,
                JSON_EXTRACT(t.event_data, '$.order_total') as order_total
            FROM {$tracking_table} t
            WHERE t.created_at >= %s AND t.created_at <= %s
            ORDER BY t.created_at DESC
            LIMIT 50",
            $date_from, $date_to
        ));
        
        return [
            'total_carts' => (int) $total_carts,
            'carts_trend' => round($carts_trend, 1),
            'total_sent' => $total_sent,
            'sent_by_message' => $sent_by_message,
            'total_clicks' => (int) $total_clicks,
            'click_rate' => round($click_rate, 1),
            'total_conversions' => (int) $total_conversions,
            'conversion_rate' => round($conversion_rate, 1),
            'recovered_revenue' => $recovered_revenue,
            'avg_order_value' => $avg_order_value,
            'avg_recovery_time' => $avg_recovery_time,
            'conversions_by_day' => $conversions_by_day,
            'messages_performance' => $messages_performance,
            'recent_events' => $recent_events
        ];
    }
    
    /**
     * Formatear tiempo en minutos a legible
     */
    private function format_time($minutes) {
        if ($minutes < 60) {
            return sprintf(
                __('%d min', 'woowapp-smsenlinea-pro'),
                round($minutes)
            );
        } elseif ($minutes < 1440) {
            $hours = floor($minutes / 60);
            return sprintf(
                __('%dh %dm', 'woowapp-smsenlinea-pro'),
                $hours,
                round($minutes % 60)
            );
        } else {
            $days = floor($minutes / 1440);
            $hours = floor(($minutes % 1440) / 60);
            return sprintf(
                __('%dd %dh', 'woowapp-smsenlinea-pro'),
                $days,
                $hours
            );
        }
    }
    
    /**
     * Etiqueta de evento
     */
    private function get_event_label($type) {
        $labels = [
            'sent' => __('📤 Enviado', 'woowapp-smsenlinea-pro'),
            'click' => __('🔗 Click', 'woowapp-smsenlinea-pro'),
            'conversion' => __('🎉 Conversión', 'woowapp-smsenlinea-pro')
        ];
        return isset($labels[$type]) ? $labels[$type] : $type;
    }
    
    /**
     * Formatear teléfono
     */
    private function format_phone($phone) {
        $phone = trim(str_replace('"', '', $phone));
        if (strlen($phone) > 6) {
            return substr($phone, 0, -4) . '****';
        }
        return $phone;
    }
}