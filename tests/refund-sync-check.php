<?php
namespace {
    // A refund deliberately has no shipping or metadata getters.
    class WC_Order_Refund { public function get_type() { return 'shop_order_refund'; } }
    class WC_Order {
        public $meta = array(); public $saved = 0; public $shipping_reads = 0; public $id = 5;
        public function get_type() { return 'shop_order'; }
        public function get_status() { return 'refunded'; }
        public function get_id() { return $this->id; }
        public function get_shipping_first_name() { $this->shipping_reads++; return 'Test'; }
        public function get_shipping_last_name() { return 'Buyer'; }
        public function get_shipping_phone() { return '2025550100'; }
        public function get_billing_phone() { return '2025550100'; }
        public function get_shipping_country() { return 'US'; }
        public function get_shipping_address_1() { return '100 Example Ave'; }
        public function get_shipping_address_2() { return ''; }
        public function get_shipping_postcode() { return '00000'; }
        public function get_meta($key) { return $this->meta[$key] ?? ''; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public function save() { $this->saved++; }
    }
}
namespace Automattic\WooCommerce\Admin\Overrides {
    class OrderRefund extends \WC_Order_Refund {}
    class Order extends \WC_Order {}
}
namespace {
    define('ABSPATH', __DIR__);
    define('HOUR_IN_SECONDS', 3600);
    $options = array(); $queries = array(); $checks = 0;
    $parent = new \Automattic\WooCommerce\Admin\Overrides\Order();
    $refund = new \Automattic\WooCommerce\Admin\Overrides\OrderRefund();
    function add_action() {}
    function add_filter() {}
    function register_deactivation_hook() {}
    function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
    function wp_salt() { return 'test-only'; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function wp_cache_delete() {}
    function wp_generate_uuid4() { static $counter = 0; return 'test-request-' . ++$counter; }
    class Test_Options_DB {
        public $options = 'test_options';
        public function delete($table, $where, $formats) {
            $key = $where['option_name'];
            if (get_option($key) === $where['option_value']) { delete_option($key); return 1; }
            return 0;
        }
    }
    $wpdb = new Test_Options_DB();
    $jobs = array(); $cron = array();
    function wp_clear_scheduled_hook($hook) { unset($GLOBALS['cron'][$hook]); }
    function as_unschedule_all_actions($hook, $args, $group) {
        foreach ($GLOBALS['jobs'] as $id => $job) {
            if ($job['hook'] === $hook && $job['group'] === $group && $job['status'] === 'pending') { unset($GLOBALS['jobs'][$id]); }
        }
    }
    function as_get_scheduled_actions($query, $format) {
        return array_keys(array_filter($GLOBALS['jobs'], function ($job) use ($query) {
            return $job['hook'] === $query['hook'] && $job['group'] === $query['group'] && $job['status'] === $query['status'];
        }));
    }
    function as_has_scheduled_action($hook, $args, $group) {
        foreach ($GLOBALS['jobs'] as $job) { if ($job['hook'] === $hook && $job['group'] === $group) { return true; } }
        return false;
    }
    function as_schedule_recurring_action($time, $interval, $hook, $args, $group) {
        $GLOBALS['jobs'][] = array('hook' => $hook, 'group' => $group, 'status' => 'pending', 'time' => $time, 'interval' => $interval);
    }
    function add_option($key, $value) {
        if (array_key_exists($key, $GLOBALS['options'])) { return false; }
        $GLOBALS['options'][$key] = $value; return true;
    }
    function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_remote_post() { $GLOBALS['auth_calls'] = ($GLOBALS['auth_calls'] ?? 0) + 1; return array('code' => 200, 'body' => '{"access_token":"test-only"}'); }
    function wp_remote_get() {
        if (!empty($GLOBALS['on_google_read'])) { call_user_func($GLOBALS['on_google_read']); }
        if (isset($GLOBALS['sheet_response'])) { return $GLOBALS['sheet_response']; }
        $headers = array('Order ID', 'Shop ID', 'Customer', 'Phone', 'Country Code', 'Address 1', 'Postcode', 'TrackOrder Latest Status', 'TrackOrder URL', 'TrackOrder Checked At');
        $rows = array(
            $headers,
            array('test-1', 'test-shop', 'Test Buyer', '2025550100', 'US', '100 Example Avenue', '00000', 'shipment_in_transit', '', '2026-10-09T00:00:00Z'),
            array('test-2', 'test-shop', 'Test Buyer', '2025550100', 'US', '100 Example Avenue', '00000', 'shipment_delivered', '', '2026-10-09T00:00:00Z'),
        );
        return array('code' => 200, 'body' => json_encode(array('values' => $rows)));
    }
    function is_wp_error() { return false; }
    function wp_remote_retrieve_response_code($response) { return $response['code']; }
    function wp_remote_retrieve_body($response) { return $response['body']; }
    function wc_get_orders($args) {
        $GLOBALS['queries'][] = $args;
        // Simulate a third-party query filter returning refunds despite type restrictions.
        $valid = array($GLOBALS['parent']);
        if (!empty($GLOBALS['duplicate_order'])) { $valid[] = $GLOBALS['duplicate_order']; }
        return (object) array('orders' => $args['page'] === 1 ? array($GLOBALS['refund'], false, new \stdClass()) : $valid, 'max_num_pages' => 2);
    }
    function wc_get_order($id) {
        return (int) $id === 5 ? $GLOBALS['parent'] : ((int) $id === 99 ? $GLOBALS['refund'] : false);
    }
    function current_user_can() { return true; }
    function check_admin_referer() {}
    function sanitize_text_field($value) { return trim($value); }
    function wp_unslash($value) { return $value; }
    function absint($value) { return abs((int) $value); }
    function wp_die($message) { throw new \RuntimeException($message); }
    function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($value) { return esc_html($value); }
    function esc_url($value) { return esc_html($value); }
    class Test_Redirect extends \RuntimeException {}
    function admin_url($path) { return 'https://store.example.invalid/wp-admin/' . $path; }
    function wp_safe_redirect($url) { throw new Test_Redirect($url); }
    function verify_sync($ok, $message) {
        if (!$ok) { throw new \RuntimeException('Failed: ' . $message); }
        $GLOBALS['checks']++;
    }
    require __DIR__ . '/../printify-delivery-status/printify-delivery-status.php';
    // Only an ephemeral, generated test key is used. No production credentials or HTTP.
    $key_options = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
    $windows_config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
    if (PHP_OS_FAMILY === 'Windows' && is_file($windows_config)) { $key_options['config'] = $windows_config; }
    $key = openssl_pkey_new($key_options);
    if (!$key || !openssl_pkey_export($key, $private, null, $key_options)) { throw new \RuntimeException('Cannot generate test key'); }
    $temp = tempnam(sys_get_temp_dir(), 'pds-test-');
    try {
        file_put_contents($temp, json_encode(array('type' => 'service_account', 'client_email' => 'fixture@example.invalid', 'private_key' => $private)));
        $options['pds_config'] = array('path' => $temp, 'sheet' => 'test-sheet', 'tab' => 'printify_trackorder_sync');
        $options['pds_links'] = array('test-shop:test-2' => 99);
        $options['pds_manual_links'] = array('test-shop:test-2' => true);
        verify_sync(PDS_Plugin::is_delivery_order($parent), 'refunded parent order remains eligible');
        verify_sync(!PDS_Plugin::is_delivery_order($refund), 'WooCommerce override refund rejected');
        verify_sync(!PDS_Plugin::is_delivery_order(false) && !PDS_Plugin::is_delivery_order(new \stdClass()), 'invalid results rejected');
        PDS_Plugin::sync();
        verify_sync($options['pds_result']['error'] === '', 'sync finishes despite refund in query results: ' . $options['pds_result']['error']);
        verify_sync($options['pds_result']['rows'] === 2 && $options['pds_result']['review'] === 1, 'invalid previous manual link requires review');
        verify_sync(count($queries) === 2, 'pagination continues past refund-only first page');
        foreach ($queries as $query) { verify_sync($query['type'] === 'shop_order' && $query['return'] === 'objects', 'query explicitly restricts order type'); }
        verify_sync($parent->shipping_reads === 1 && $parent->saved === 1, 'valid order indexed and saved once');
        verify_sync($parent->meta['_pds_shipments']['test-shop:test-1']['status'] === 'shipment_in_transit', 'tracking assigned to parent');
        verify_sync($options['pds_links']['test-shop:test-1'] === 5, 'valid link persisted');
        verify_sync(isset($options['pds_reviews']['test-shop:test-2']), 'refund link not silently reassigned');
        verify_sync(!isset($options['pds_sync_lock']), 'sync lock released');
        $_POST = array('source' => 'test-shop:test-1', 'order_id' => 99);
        try { PDS_Plugin::link(); throw new \RuntimeException('Refund link was accepted'); }
        catch (\RuntimeException $e) { verify_sync(strpos($e->getMessage(), 'Refund records cannot be linked') !== false, 'manual refund link blocked'); }
        // Several independently matched source orders must all link automatically.
        $options['pds_links'] = array(); $options['pds_manual_links'] = array();
        $result = PDS_Plugin::sync();
        verify_sync($result['status'] === 'success' && $result['review'] === 0, 'split orders do not require approval');
        verify_sync(count($parent->meta['_pds_shipments']) === 2, 'both shipments retained separately');
        verify_sync($options['pds_links']['test-shop:test-1'] === 5 && $options['pds_links']['test-shop:test-2'] === 5, 'both automatic links persisted');
        verify_sync($parent->meta['_pds_shipments']['test-shop:test-2']['status'] === 'shipment_delivered', 'second shipment has independent status');
        PDS_Plugin::sync();
        verify_sync(count($parent->meta['_pds_shipments']) === 2, 'repeat sync does not duplicate shipments');
        // A source matching two Woo orders remains ambiguous.
        $duplicate_order = new WC_Order(); $duplicate_order->id = 6;
        $options['pds_links'] = array();
        $result = PDS_Plugin::sync();
        verify_sync($result['review'] === 2 && !$options['pds_links'], 'ambiguous Woo matches still require review');
        unset($duplicate_order);
        $jobs = array(
            'old' => array('hook' => PDS_Plugin::HOOK, 'group' => 'pds', 'status' => 'pending'),
            'active' => array('hook' => PDS_Plugin::HOOK, 'group' => 'pds', 'status' => 'in-progress'),
            'other' => array('hook' => 'other_plugin', 'group' => 'other', 'status' => 'pending'),
        );
        $cron[PDS_Plugin::HOOK] = time(); $cron['other_plugin'] = time();
        $result = PDS_Plugin::manual_sync();
        verify_sync($result['status'] === 'success' && isset($options['pds_result']['time']), 'manual sync completes directly');
        verify_sync(!isset($jobs['old']) && isset($jobs['active'], $jobs['other']), 'only plugin pending jobs cancelled');
        verify_sync(!isset($cron[PDS_Plugin::HOOK]) && isset($cron['other_plugin']), 'only plugin cron cleared');
        $pending = as_get_scheduled_actions(array('hook' => PDS_Plugin::HOOK, 'group' => 'pds', 'status' => 'pending'), 'ids');
        verify_sync(count($pending) === 1 && $jobs[$pending[0]]['interval'] === 3600 && $jobs[$pending[0]]['time'] >= time() + 3599, 'hourly schedule restored even with active action');
        verify_sync(!isset($options['pds_manual_sync_requested']), 'handled manual request cleared');
        $options['pds_sync_lock'] = time(); $active_lock = $options['pds_sync_lock'];
        $before = $auth_calls;
        verify_sync(PDS_Plugin::manual_sync()['status'] === 'busy' && $auth_calls === $before, 'busy click does not start concurrent sync');
        verify_sync($options['pds_sync_lock'] === $active_lock && !empty($options['pds_manual_sync_requested']), 'active lock retained and refresh intent saved');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        PDS_Plugin::resume_manual_sync();
        verify_sync($auth_calls === $before, 'admin POST never refreshes old configuration');
        delete_option('pds_sync_lock'); $_SERVER['REQUEST_METHOD'] = 'GET';
        PDS_Plugin::resume_manual_sync();
        verify_sync($auth_calls === $before + 1 && !isset($options['pds_manual_sync_requested']), 'next admin GET completes busy refresh');
        $saved = $parent->saved;
        $on_google_read = function () { update_option('pds_manual_sync_requested', 'newer-save'); };
        $result = PDS_Plugin::manual_sync();
        verify_sync($result['status'] === 'busy' && $parent->saved === $saved, 'superseded sync does not write old snapshot');
        verify_sync(get_option('pds_manual_sync_requested') === 'newer-save', 'old request cleanup cannot erase newer save');
        unset($on_google_read);
        PDS_Plugin::resume_manual_sync();
        verify_sync($parent->saved === $saved + 1 && !isset($options['pds_manual_sync_requested']), 'newest request completes automatically');
        $options['pds_sync_lock'] = time() - 901;
        verify_sync(PDS_Plugin::manual_sync()['status'] === 'success' && !isset($options['pds_sync_lock']), 'expired lock recovered without manual cleanup');
        $sheet_response = array('code' => 403, 'body' => '{}');
        $saved = $parent->saved;
        verify_sync(PDS_Plugin::manual_sync()['status'] === 'error' && $parent->saved === $saved, 'Google error returned immediately and prior data preserved');
        verify_sync(!isset($options['pds_manual_sync_requested']) && !isset($options['pds_sync_lock']), 'failed attempt clears handled request and lock');
        unset($sheet_response);
        $_POST = array('path' => $temp, 'sheet' => 'new-test-sheet', 'tab' => 'printify_trackorder_sync');
        $before = $auth_calls;
        try { PDS_Plugin::save(); } catch (Test_Redirect $e) { verify_sync(strpos($e->getMessage(), 'sync=success') !== false, 'save redirects with completed result'); }
        verify_sync($auth_calls === $before + 1 && $options['pds_config']['sheet'] === 'new-test-sheet', 'save stores configuration and syncs in same request');
        $before = $auth_calls;
        try { PDS_Plugin::queue(); } catch (Test_Redirect $e) { verify_sync(strpos($e->getMessage(), 'sync=success') !== false, 'sync now redirects with completed result'); }
        verify_sync($auth_calls === $before + 1, 'sync now directly calls sync');
        PDS_Plugin::upgrade_sync();
        verify_sync(!empty($options['pds_manual_sync_requested']), 'upgrade requests configured refresh');
        $request = $options['pds_manual_sync_requested']; PDS_Plugin::upgrade_sync();
        verify_sync($options['pds_manual_sync_requested'] === $request, 'same version does not repeatedly request refresh');
        PDS_Plugin::resume_manual_sync();
        verify_sync(!isset($options['pds_manual_sync_requested']) && $options['pds_result']['status'] === 'success', 'upgrade refresh finishes on first admin GET');
        // Badge-only rendering with a distinct link for each shipment.
        $items = $parent->meta['_pds_shipments']; $n = 0;
        foreach ($items as &$item) { $item['url'] = 'https://trackorder.site/fixture-' . ++$n; $item['number'] = 'HIDDEN-NUMBER'; $item['carrier'] = 'HIDDEN-CARRIER'; $item['needs_review'] = true; }
        unset($item); $parent->meta['_pds_shipments'] = $items;
        ob_start(); PDS_Plugin::column('pds_delivery', $parent); $html = ob_get_clean();
        verify_sync(substr_count($html, '<a ') === 2 && strpos($html, 'fixture-1') !== false && strpos($html, 'fixture-2') !== false, 'each status badge opens its own source URL');
        verify_sync(strpos($html, 'In Transit') !== false && strpos($html, 'Delivered') !== false, 'both shipment statuses displayed');
        verify_sync(strpos($html, 'HIDDEN-') === false && strpos($html, 'Stale') === false && strpos($html, 'Needs review') === false && strpos($html, '2026-') === false, 'tracking and supplementary fields hidden');
        verify_sync(substr_count($html, 'target="_blank" rel="noopener noreferrer"') === 2, 'tracking links open safely in new tabs');
        $parent->meta['_pds_shipments'] = array(array('status' => '<script>bad</script>', 'url' => 'https://malicious.example/'));
        ob_start(); PDS_Plugin::column('pds_delivery', $parent); $html = ob_get_clean();
        verify_sync(strpos($html, '<a ') === false && strpos($html, '<script>') === false, 'invalid URL omitted and status escaped');
        // Canceled retries must not compete with the replacement order, even with stale tracking.
        $parent = new \Automattic\WooCommerce\Admin\Overrides\Order();
        $sheetFixture = function ($fixtureRows) {
            $headers = array('Order ID', 'Shop ID', 'Customer', 'Phone', 'Country Code', 'Address 1', 'Postcode', 'Status', 'TrackOrder Status', 'TrackOrder Latest Status', 'TrackOrder URL', 'TrackOrder Checked At', 'Tracking Number');
            $values = array($headers);
            foreach ($fixtureRows as $row) {
                $row = array_merge(array('Shop ID' => 'test-shop', 'Customer' => 'Test Buyer', 'Phone' => '2025550100', 'Country Code' => 'US', 'Address 1' => '100 Example Ave', 'Postcode' => '00000', 'Status' => 'in-production', 'TrackOrder Latest Status' => 'in-production', 'TrackOrder Checked At' => '2026-10-09T00:00:00Z'), $row);
                $values[] = array_map(function ($header) use ($row) { return $row[$header] ?? ''; }, $headers);
            }
            return array('code' => 200, 'body' => json_encode(array('values' => $values)));
        };
        unset($sheet_response); PDS_Plugin::sync();
        $options['pds_manual_links'] = array('test-shop:test-1' => true, 'test-shop:test-2' => true);
        $sheet_response = $sheetFixture(array(
            array('Order ID' => 'test-1', 'Status' => ' canceled ', 'TrackOrder Latest Status' => 'in-production', 'Tracking Number' => 'CANCELED-NUMBER'),
            array('Order ID' => 'test-2', 'Status' => 'fulfilled', 'TrackOrder Latest Status' => 'shipment_in_transit', 'Tracking Number' => 'ACTIVE-NUMBER'),
        ));
        $result = PDS_Plugin::sync();
        verify_sync($result['status'] === 'success' && $result['rows'] === 1 && $result['review'] === 0, 'canceled row excluded before matching and review');
        verify_sync(count($parent->meta['_pds_shipments']) === 1 && isset($parent->meta['_pds_shipments']['test-shop:test-2']), 'previously stored canceled shipment removed');
        verify_sync($parent->meta['_pds_shipments']['test-shop:test-2']['number'] === 'ACTIVE-NUMBER', 'shipped replacement tracking retained');
        verify_sync(!isset($options['pds_links']['test-shop:test-1']) && !isset($options['pds_manual_links']['test-shop:test-1']) && !isset($options['pds_sources']['test-shop:test-1']), 'canceled links and source data removed');
        verify_sync(!empty($options['pds_manual_links']['test-shop:test-2']), 'active manual link preserved');
        $sheet_response = $sheetFixture(array(
            array('Order ID' => 'test-1', 'Status' => 'CaNcElLeD', 'TrackOrder Latest Status' => 'shipment_delivered'),
            array('Order ID' => 'test-2', 'TrackOrder Latest Status' => 'in-production'),
        ));
        PDS_Plugin::sync();
        verify_sync(count($parent->meta['_pds_shipments']) === 1 && $parent->meta['_pds_shipments']['test-shop:test-2']['status'] === 'in-production', 'cancelled spelling skipped while production replacement retained');
        $sheet_response = $sheetFixture(array(
            array('Order ID' => 'test-1', 'Status' => '', 'TrackOrder Latest Status' => 'cancelled'),
            array('Order ID' => 'test-2', 'TrackOrder Latest Status' => 'shipment_delivered'),
        ));
        verify_sync(PDS_Plugin::sync()['rows'] === 1, 'tracking latest cancellation also excluded');
        $sheet_response = $sheetFixture(array(
            array('Order ID' => 'test-1', 'Status' => '', 'TrackOrder Status' => 'canceled', 'TrackOrder Latest Status' => 'in-production'),
            array('Order ID' => 'test-2'),
        ));
        verify_sync(PDS_Plugin::sync()['rows'] === 1, 'tracking overall cancellation overrides stale latest tracking');
        $parent->meta['_pds_shipments']['other-shop:test-1'] = array('status' => 'shipment_in_transit');
        $sheet_response = $sheetFixture(array(array('Order ID' => 'test-1', 'Status' => 'canceled'), array('Order ID' => 'test-2', 'Status' => 'cancelled')));
        $result = PDS_Plugin::sync();
        verify_sync($result['status'] === 'success' && $result['rows'] === 0 && $result['review'] === 0, 'all-canceled sheet is a successful cleanup');
        verify_sync(count($parent->meta['_pds_shipments']) === 1 && isset($parent->meta['_pds_shipments']['other-shop:test-1']), 'cleanup preserves unrelated shop source with same Order ID');
        verify_sync(!$options['pds_links'] && !$options['pds_manual_links'] && !$options['pds_sources'] && !$options['pds_reviews'], 'all canceled source options cleared');
        // Same source ID on a canceled row and an active row must work in either order.
        $duplicateRows = array(array('Order ID' => 'test-1', 'Status' => 'canceled'), array('Order ID' => 'test-1', 'Status' => 'in-production', 'Tracking Number' => 'REPLACEMENT'));
        foreach (array($duplicateRows, array_reverse($duplicateRows)) as $rowOrder) {
            $sheet_response = $sheetFixture($rowOrder); $result = PDS_Plugin::sync();
            verify_sync($result['status'] === 'success' && $result['rows'] === 1 && $parent->meta['_pds_shipments']['test-shop:test-1']['number'] === 'REPLACEMENT', 'active same-ID row survives canceled row regardless of position');
        }
        $oldMeta = $parent->meta; $oldLinks = $options['pds_links'];
        $sheet_response = $sheetFixture(array(array('Order ID' => 'test-1', 'Status' => 'canceled'), array('Order ID' => 'test-2'), array('Order ID' => 'test-2')));
        $result = PDS_Plugin::sync();
        verify_sync($result['status'] === 'error' && $parent->meta === $oldMeta && $options['pds_links'] === $oldLinks, 'invalid active duplicates preserve old tracking before cancellation cleanup');
        $sheet_response = $sheetFixture(array(array('Order ID' => 'test-1', 'Status' => 'canceled')));
        $on_google_read = function () { update_option('pds_manual_sync_requested', 'newer-cancel-save'); };
        $result = PDS_Plugin::manual_sync();
        verify_sync($result['status'] === 'busy' && $parent->meta === $oldMeta && $options['pds_links'] === $oldLinks, 'superseded sync cannot delete canceled tracking');
        unset($on_google_read);
        PDS_Plugin::resume_manual_sync();
        verify_sync(!isset($parent->meta['_pds_shipments']['test-shop:test-1']) && !isset($options['pds_links']['test-shop:test-1']), 'latest authorized sync removes canceled tracking');
        $options['pds_links'] = array('test-shop:test-1' => 99);
        verify_sync(PDS_Plugin::sync()['status'] === 'success' && !$options['pds_links'], 'canceled old refund link removed without refund getters');
        unset($sheet_response); PDS_Plugin::sync();
        verify_sync(isset($parent->meta['_pds_shipments']['test-shop:test-1'], $parent->meta['_pds_shipments']['test-shop:test-2']), 'active sources can be matched again after cancellation is removed');
        echo "$checks refund, split shipment, immediate sync, badge and cancellation checks passed\n";
    } finally { if (is_file($temp)) { unlink($temp); } }
}
