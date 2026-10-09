<?php
namespace {
    // A refund deliberately has no shipping or metadata getters.
    class WC_Order_Refund { public function get_type() { return 'shop_order_refund'; } }
    class WC_Order {
        public $meta = array(); public $saved = 0; public $shipping_reads = 0;
        public function get_type() { return 'shop_order'; }
        public function get_status() { return 'refunded'; }
        public function get_id() { return 5; }
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
    function add_option($key, $value) {
        if (array_key_exists($key, $GLOBALS['options'])) { return false; }
        $GLOBALS['options'][$key] = $value; return true;
    }
    function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_remote_post() { return array('code' => 200, 'body' => '{"access_token":"test-only"}'); }
    function wp_remote_get() {
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
        return (object) array('orders' => $args['page'] === 1 ? array($GLOBALS['refund'], false, new \stdClass()) : array($GLOBALS['parent']), 'max_num_pages' => 2);
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
        echo "$checks refund sync checks passed\n";
    } finally { if (is_file($temp)) { unlink($temp); } }
}
