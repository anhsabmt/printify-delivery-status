<?php
/**
 * Plugin Name: Printify Delivery Status
 * Description: Read private Google Sheets and display Printify delivery status in WooCommerce.
 * Version: 1.0.5
 * Plugin URI: https://github.com/anhsabmt/printify-delivery-status
 * Update URI: https://github.com/anhsabmt/printify-delivery-status
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-pds-updater.php';
PDS_Updater::boot(__FILE__);

final class PDS_Plugin {
    const HOOK = 'pds_hourly_sync';
    const SHEET = '';
    public static function is_delivery_order($order) {
        return $order instanceof WC_Order && !($order instanceof WC_Order_Refund) && $order->get_type() === 'shop_order';
    }
    public static function name_key($value) {
        return preg_replace('/\s+/u', ' ', trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value)));
    }
    public static function phone_key($value, $country = '') {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strtoupper($country) === 'US' || strtoupper($country) === 'CA') {
            if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') { $digits = substr($digits, 1); }
        }
        return $digits;
    }
    public static function key($name, $phone, $country) {
        $name = self::name_key($name); $phone = self::phone_key($phone, $country);
        return $name && $phone ? hash_hmac('sha256', $name . '|' . $phone, wp_salt('auth')) : '';
    }
    public static function address_key($value) {
        $value = self::name_key((string) $value);
        $value = preg_replace('/[.,]/u', '', $value);
        $aliases = array('street' => 'st', 'avenue' => 'ave', 'road' => 'rd', 'lane' => 'ln', 'drive' => 'dr', 'boulevard' => 'blvd', 'court' => 'ct');
        return preg_replace_callback('/\b(street|avenue|road|lane|drive|boulevard|court)\b/u', function ($m) use ($aliases) { return $aliases[$m[0]]; }, $value);
    }
    public static function postcode_key($value) {
        return strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));
    }
    public static function address_matches($source, $target) {
        $a = self::address_key($source['Address 1'] ?? '');
        $p = self::postcode_key($source['Postcode'] ?? '');
        $country = strtoupper(trim($source['Country Code'] ?? ''));
        // Incomplete source addresses retain name + phone matching, but known conflicts still reject.
        if ($a && $a !== self::address_key($target['Address 1'] ?? '')) { return false; }
        if ($p && $p !== self::postcode_key($target['Postcode'] ?? '')) { return false; }
        if ($country && $country !== strtoupper(trim($target['Country Code'] ?? ''))) { return false; }
        $a2 = self::address_key($source['Address 2'] ?? '');
        if ($a2 && $a2 !== self::address_key($target['Address 2'] ?? '')) { return false; }
        return true;
    }
    public static function boot() {
        add_action('init', array(__CLASS__, 'upgrade'), 5);
        add_action('init', array(__CLASS__, 'upgrade_sync'), 6);
        add_action('admin_init', array(__CLASS__, 'resume_manual_sync'));
        add_action('before_woocommerce_init', function () {
            if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
        add_action('init', array(__CLASS__, 'schedule'));
        add_action(self::HOOK, array(__CLASS__, 'sync'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_pds_settings', array(__CLASS__, 'save'));
        add_action('admin_post_pds_sync', array(__CLASS__, 'queue'));
        add_action('admin_post_pds_link', array(__CLASS__, 'link'));
        add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'columns'), 100);
        add_filter('manage_woocommerce_page_wc-orders_columns', array(__CLASS__, 'columns'), 100);
        add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'legacy_column'), 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'panel'));
    }
    public static function schedule($delay = 60) {
        if (!function_exists('wc_get_orders')) { return; }
        if (function_exists('as_schedule_recurring_action') && function_exists('as_has_scheduled_action')) {
            // A running action must not prevent restoring the next hourly run.
            $pending = function_exists('as_get_scheduled_actions')
                ? as_get_scheduled_actions(array('hook' => self::HOOK, 'args' => array(), 'group' => 'pds', 'status' => 'pending', 'per_page' => 1), 'ids')
                : as_has_scheduled_action(self::HOOK, array(), 'pds');
            if (!$pending) { as_schedule_recurring_action(time() + $delay, HOUR_IN_SECONDS, self::HOOK, array(), 'pds'); }
        } elseif (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + $delay, 'hourly', self::HOOK); }
    }
    public static function stop() {
        wp_clear_scheduled_hook(self::HOOK);
        if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions(self::HOOK, array(), 'pds'); }
    }
    private static function guard() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Access denied'); }
    }
    private static function config() {
        return wp_parse_args(get_option('pds_config', array()), array('path' => '', 'sheet' => self::SHEET, 'tab' => 'printify_trackorder_sync'));
    }
    public static function upgrade() {
        if (get_option('pds_tab_migration_101')) { return; }
        $config = get_option('pds_config', array());
        if (is_array($config) && (!isset($config['tab']) || $config['tab'] === '' || $config['tab'] === 'printify_multi_orders')) {
            $config['tab'] = 'printify_trackorder_sync';
            update_option('pds_config', $config, false);
        }
        update_option('pds_tab_migration_101', 1, false);
    }
    public static function upgrade_sync() {
        if (get_option('pds_sync_ui_version') === PDS_Updater::VERSION) { return; }
        self::stop(); self::schedule(HOUR_IN_SECONDS);
        $c = self::config();
        if (!empty($c['path']) && !empty($c['sheet'])) {
            update_option('pds_manual_sync_requested', wp_generate_uuid4(), false);
        }
        update_option('pds_sync_ui_version', PDS_Updater::VERSION, false);
    }
    private static function manual_request() {
        // Read changes made by a concurrent Save, including previously absent options.
        wp_cache_delete('pds_manual_sync_requested', 'options');
        wp_cache_delete('notoptions', 'options');
        return (string) get_option('pds_manual_sync_requested', '');
    }
    private static function finish_manual_request($request) {
        if (!$request) { return; }
        // Delete only the request this sync handled. A newer Save must survive.
        global $wpdb;
        $wpdb->delete($wpdb->options, array('option_name' => 'pds_manual_sync_requested', 'option_value' => $request), array('%s', '%s'));
        wp_cache_delete('pds_manual_sync_requested', 'options');
        wp_cache_delete('notoptions', 'options');
    }
    public static function manual_sync() {
        // Cancel this plugin's pending jobs and restore its hourly schedule.
        self::stop(); self::schedule(HOUR_IN_SECONDS);
        update_option('pds_manual_sync_requested', wp_generate_uuid4(), false);
        return self::sync();
    }
    public static function resume_manual_sync() {
        // Never sync old settings before the Save POST handler has saved new settings.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && current_user_can('manage_woocommerce') && self::manual_request()) {
            self::sync();
        }
    }
    private static function token($path) {
        if (!$path || !is_readable($path)) { throw new Exception('Service account file is not readable. Configure its server path.'); }
        $c = json_decode(file_get_contents($path), true);
        if (!is_array($c) || ($c['type'] ?? '') !== 'service_account' || empty($c['client_email']) || empty($c['private_key'])) { throw new Exception('Invalid service account file.'); }
        if (!function_exists('openssl_sign')) { throw new Exception('PHP OpenSSL is required.'); }
        $enc = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $now = time();
        $jwt = $enc(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . $enc(wp_json_encode(array('iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600)));
        if (!openssl_sign($jwt, $signature, $c['private_key'], OPENSSL_ALGO_SHA256)) { throw new Exception('Cannot sign Google authentication request.'); }
        $r = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 30, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . $enc($signature))));
        if (is_wp_error($r)) { throw new Exception('Google authentication connection failed.'); }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if (wp_remote_retrieve_response_code($r) !== 200 || empty($data['access_token'])) { throw new Exception('Google authentication failed. Check service account configuration.'); }
        return $data['access_token'];
    }
    public static function sync() {
        $request = self::manual_request();
        if (!function_exists('wc_get_orders')) {
            $result = array('time' => time(), 'status' => 'error', 'error' => 'WooCommerce must be active to sync.');
            update_option('pds_result', $result, false); self::finish_manual_request($request); return $result;
        }
        $lock = time();
        if (!add_option('pds_sync_lock', $lock, '', false)) {
            if (time() - (int) get_option('pds_sync_lock') < 900) { return array('status' => 'busy'); }
            delete_option('pds_sync_lock');
            if (!add_option('pds_sync_lock', $lock, '', false)) { return array('status' => 'busy'); }
        }
        try {
            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            $c = self::config(); $token = self::token($c['path']);
            $range = "'" . str_replace("'", "''", $c['tab']) . "'!A:AZ";
            $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($c['sheet']) . '/values/' . rawurlencode($range);
            $r = wp_remote_get($url, array('timeout' => 45, 'headers' => array('Authorization' => 'Bearer ' . $token)));
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { throw new Exception('Cannot read Google Sheet. Check spreadsheet ID and Viewer permission.'); }
            $d = json_decode(wp_remote_retrieve_body($r), true);
            if (!isset($d['values']) || count($d['values']) < 2) { throw new Exception('Sheet has no data; previous tracking was preserved.'); }
            $headers = array_shift($d['values']);
            foreach (array('Order ID', 'Customer', 'Phone', 'Country Code', 'TrackOrder Latest Status', 'TrackOrder URL', 'TrackOrder Checked At') as $h) {
                if (!in_array($h, $headers, true)) { throw new Exception('Missing required header: ' . $h); }
            }
            // Build one index per sync; never query Google while rendering Orders.
            $index = array(); $addresses = array(); $page = 1;
            do {
                $orders = wc_get_orders(array('type' => 'shop_order', 'return' => 'objects', 'limit' => 100, 'page' => $page++, 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC'));
                foreach ($orders->orders as $o) {
                    // Refunds have no shipping getters; also guard results changed by other plugins.
                    if (!self::is_delivery_order($o)) { continue; }
                    $name = trim($o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name());
                    $phone = method_exists($o, 'get_shipping_phone') ? $o->get_shipping_phone() : '';
                    if (!$phone) { $phone = $o->get_billing_phone(); }
                    $key = self::key($name, $phone, $o->get_shipping_country());
                    if ($key) { $index[$key][] = $o->get_id(); }
                    $addresses[$o->get_id()] = array('Address 1' => $o->get_shipping_address_1(), 'Address 2' => $o->get_shipping_address_2(), 'Postcode' => $o->get_shipping_postcode(), 'Country Code' => $o->get_shipping_country());
                }
            } while ($page <= (int) $orders->max_num_pages);
            $links = get_option('pds_links', array()); $sources = array(); $group = array(); $reviews = array();
            $manual = get_option('pds_manual_links', array());
            foreach ($d['values'] as $row) {
                $v = array(); foreach ($headers as $i => $h) { $v[$h] = isset($row[$i]) ? (string) $row[$i] : ''; }
                if (empty($v['Order ID'])) { continue; }
                $id = ($v['Shop ID'] ?? $v['Shop'] ?? '') . ':' . $v['Order ID'];
                if (isset($sources[$id])) { throw new Exception('Duplicate source Order ID. Previous data was preserved.'); }
                $key = self::key($v['Customer'], $v['Phone'], $v['Country Code']);
                $matches = $key ? ($index[$key] ?? array()) : array();
                $matches = array_values(array_filter($matches, function ($oid) use ($v, $addresses) { return self::address_matches($v, $addresses[$oid]); }));
                $source = array('id' => $id, 'printify_id' => $v['Order ID'], 'status' => $v['TrackOrder Latest Status'], 'url' => self::safe_url($v['TrackOrder URL']), 'number' => $v['Tracking Number'] ?? '', 'carrier' => $v['Tracking Carrier'] ?? '', 'updated' => $v['TrackOrder Latest Update'] ?? '', 'checked' => $v['TrackOrder Checked At'], 'candidates' => $matches);
                $sources[$id] = $source;
                if (isset($links[$id])) {
                    $oid = (int) $links[$id];
                    $linked = wc_get_order($oid);
                    if (self::is_delivery_order($linked) && (!empty($manual[$id]) || (isset($addresses[$oid]) && self::address_matches($v, $addresses[$oid])))) {
                        $group[$oid][$id] = $source;
                    } else {
                        $reviews[$id] = $source;
                        if (!self::is_delivery_order($linked)) { continue; }
                        $old = $linked; $old_items = $old->get_meta('_pds_shipments');
                        if (is_array($old_items) && isset($old_items[$id])) {
                            $old_items[$id]['needs_review'] = true;
                            $old->update_meta_data('_pds_shipments', $old_items); $old->save();
                        }
                    }
                }
                elseif (count($matches) === 1) { $group[$matches[0]][$id] = $source; }
                else { $reviews[$id] = $source; }
            }
            // Independently matched source orders can share one WooCommerce order.
            // Keep each shipment under its own Shop ID + Printify Order ID.
            // A Save during the Google read/indexing invalidates this old snapshot.
            if ($request !== self::manual_request()) { return array('status' => 'busy'); }
            foreach ($group as $oid => $items) {
                if (!$items) { continue; }
                $o = wc_get_order($oid);
                if (!self::is_delivery_order($o)) { throw new Exception('Linked WooCommerce order is unavailable. Previous tracking was preserved.'); }
                $existing = $o->get_meta('_pds_shipments');
                $existing = is_array($existing) ? $existing : array();
                foreach ($items as $id => $item) { $existing[$id] = $item; $links[$id] = $oid; }
                $o->update_meta_data('_pds_shipments', $existing); $o->update_meta_data('_pds_synced', time()); $o->save();
            }
            update_option('pds_links', $links, false); update_option('pds_sources', $sources, false); update_option('pds_reviews', $reviews, false);
            $result = array('time' => time(), 'status' => 'success', 'error' => '', 'rows' => count($sources), 'review' => count($reviews));
            update_option('pds_result', $result, false); return $result;
        } catch (Throwable $e) {
            $result = array('time' => time(), 'status' => 'error', 'error' => $e->getMessage());
            update_option('pds_result', $result, false); return $result;
        }
        finally { self::finish_manual_request($request); delete_option('pds_sync_lock'); }
    }
    public static function safe_url($url) {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === 'trackorder.site' && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : '';
    }
    public static function columns($cols) {
        // Append after existing columns, matching the requested position after Origin.
        $cols['pds_delivery'] = 'Delivery Status'; return $cols;
    }
    public static function legacy_column($col, $id) { self::column($col, wc_get_order($id)); }
    public static function column($col, $order) {
        if ($col !== 'pds_delivery' || !$order) { return; }
        $items = $order->get_meta('_pds_shipments');
        if (!$items || !is_array($items)) { echo '<span style="color:#777">No match</span>'; return; }
        foreach ($items as $item) {
            $labels = array('in-production' => 'In Production', 'shipment_pre_transit' => 'Awaiting Carrier', 'shipment_in_transit' => 'In Transit', 'shipment_delivered' => 'Delivered', 'fulfilled' => 'Fulfilled');
            $status = $item['status'] ?? ''; $label = $labels[$status] ?? ('Unknown: ' . ($status ?: 'No status'));
            $color = $status === 'shipment_delivered' ? '#d9efda' : '#e5edf7';
            $url = self::safe_url($item['url'] ?? '');
            $style = 'display:inline-block;padding:4px 8px;border-radius:4px;background:' . $color;
            echo '<div style="margin-bottom:6px">';
            if ($url) {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" style="' . esc_attr($style . ';text-decoration:none') . '">' . esc_html($label) . '</a>';
            } else {
                echo '<span style="' . esc_attr($style) . '">' . esc_html($label) . '</span>';
            }
            echo '</div>';
        }
    }
    public static function panel($order) { echo '<div><h3>Printify Delivery</h3>'; self::column('pds_delivery', $order); echo '</div>'; }
    public static function menu() { add_submenu_page('woocommerce', 'Printify Delivery', 'Printify Delivery', 'manage_woocommerce', 'pds', array(__CLASS__, 'screen')); }
    public static function save() {
        self::guard(); check_admin_referer('pds_settings');
        $c = array('path' => sanitize_text_field(wp_unslash($_POST['path'] ?? '')), 'sheet' => sanitize_text_field(wp_unslash($_POST['sheet'] ?? '')), 'tab' => sanitize_text_field(wp_unslash($_POST['tab'] ?? '')));
        update_option('pds_config', $c, false);
        $result = self::manual_sync();
        wp_safe_redirect(admin_url('admin.php?page=pds&sync=' . $result['status'])); exit;
    }
    public static function queue() {
        self::guard(); check_admin_referer('pds_sync');
        $result = self::manual_sync();
        wp_safe_redirect(admin_url('admin.php?page=pds&sync=' . $result['status'])); exit;
    }
    public static function link() {
        self::guard(); check_admin_referer('pds_link');
        $id = sanitize_text_field(wp_unslash($_POST['source'] ?? '')); $oid = absint($_POST['order_id'] ?? 0);
        $sources = get_option('pds_sources', array()); $o = wc_get_order($oid);
        if (!self::is_delivery_order($o) || !isset($sources[$id])) { wp_die('Invalid source or WooCommerce order ID. Refund records cannot be linked.'); }
        $links = get_option('pds_links', array());
        if (isset($links[$id]) && (int) $links[$id] !== $oid) {
            $old = wc_get_order($links[$id]);
            if ($old) { $items = $old->get_meta('_pds_shipments'); if (is_array($items)) { unset($items[$id]); $old->update_meta_data('_pds_shipments', $items); $old->save(); } }
        }
        $links[$id] = $oid; update_option('pds_links', $links, false);
        $manual = get_option('pds_manual_links', array()); $manual[$id] = true; update_option('pds_manual_links', $manual, false);
        $items = $o->get_meta('_pds_shipments'); $items = is_array($items) ? $items : array(); $items[$id] = $sources[$id];
        $o->update_meta_data('_pds_shipments', $items); $o->save();
        $reviews = get_option('pds_reviews', array()); unset($reviews[$id]); update_option('pds_reviews', $reviews, false);
        wp_safe_redirect(admin_url('admin.php?page=pds')); exit;
    }
    public static function screen() {
        self::guard(); $c = self::config(); $r = get_option('pds_result', array());
        echo '<div class="wrap"><h1>Printify Delivery</h1><p>Match shipping recipient name + phone, then verify available shipping address fields. Address conflicts and ambiguous matches require review. Manual links override automatic matching. Tracking does not change WooCommerce order status.</p>';
        $waiting = self::manual_request();
        if ($waiting) {
            echo '<div class="notice notice-info"><p>A sync is already running. Your refresh request is saved and will start as soon as it finishes. This page refreshes automatically.</p></div><script>setTimeout(function(){window.location.reload();},3000);</script>';
        } elseif (isset($_GET['sync']) && $r) {
            echo '<div class="notice notice-' . (!empty($r['error']) ? 'error' : 'success') . '"><p>' . esc_html($r['error'] ?: 'Sync complete. Delivery statuses are updated.') . '</p></div>';
        }
        if ($r) { echo '<p>Last completed attempt: ' . esc_html(wp_date('Y-m-d H:i:s', $r['time'])) . ' — ' . esc_html($r['error'] ?: ('Success; rows: ' . $r['rows'] . '; needs review: ' . $r['review'])) . '</p>'; }
        echo '<p>Saving settings also syncs immediately. The buttons wait for sync to finish and then show the result.</p>';
        echo '<form method="post" onsubmit="this.querySelector(\'input[type=submit]\').disabled=true;this.querySelector(\'input[type=submit]\').value=\'Saving and syncing…\';" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pds_settings">'; wp_nonce_field('pds_settings');
        foreach (array('path' => 'Service account JSON path on this server', 'sheet' => 'Spreadsheet ID', 'tab' => 'Sheet tab') as $key => $label) {
            echo '<p><label>' . esc_html($label) . '<br><input class="large-text" name="' . esc_attr($key) . '" value="' . esc_attr($c[$key]) . '"></label></p>';
        }
        submit_button('Save & sync now'); echo '</form><form method="post" onsubmit="this.querySelector(\'input[type=submit]\').disabled=true;this.querySelector(\'input[type=submit]\').value=\'Syncing…\';" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pds_sync">'; wp_nonce_field('pds_sync'); submit_button('Sync now', 'secondary'); echo '</form>';
        echo '<h2>Review / manual links</h2><p>Multiple source orders matching one WooCommerce order are grouped automatically. Only unmatched, ambiguous or conflicting sources need review. Source IDs and candidate WooCommerce internal IDs are shown below.</p><table class="widefat"><thead><tr><th>Printify source</th><th>Status</th><th>Candidates</th><th>Link to WooCommerce ID</th></tr></thead><tbody>';
        foreach (get_option('pds_sources', array()) as $id => $s) {
            echo '<tr><td>' . esc_html($id) . '</td><td>' . esc_html($s['status']) . '</td><td>' . esc_html(implode(', ', $s['candidates'])) . '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pds_link"><input type="hidden" name="source" value="' . esc_attr($id) . '">'; wp_nonce_field('pds_link');
            $links = get_option('pds_links', array()); echo '<input type="number" min="1" required name="order_id" value="' . esc_attr($links[$id] ?? '') . '"><button class="button">Save link</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
PDS_Plugin::boot();
register_deactivation_hook(__FILE__, array('PDS_Plugin', 'stop'));
