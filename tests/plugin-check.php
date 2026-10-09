<?php
define('ABSPATH', __DIR__);
function add_action() {}
function add_filter() {}
function register_deactivation_hook() {}
function wp_salt() { return 'test-only'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
require __DIR__ . '/../printify-delivery-status/printify-delivery-status.php';
$checks = array(
    PDS_Plugin::address_matches(array('Address 1' => '100 Example Avenue', 'Postcode' => '00000', 'Country Code' => 'US'), array('Address 1' => '100 example ave.', 'Postcode' => '00000', 'Country Code' => 'US')),
    !PDS_Plugin::address_matches(array('Address 1' => '100 Example Avenue'), array('Address 1' => '101 Example Ave')),
    !PDS_Plugin::address_matches(array('Address 2' => 'Apt 649'), array('Address 2' => 'Apt 650')),
    PDS_Plugin::address_matches(array('Address 1' => '', 'Postcode' => '', 'Country Code' => 'US'), array('Address 1' => '123 Main St', 'Postcode' => '01234', 'Country Code' => 'US')),
    !PDS_Plugin::address_matches(array('Postcode' => '01234'), array('Postcode' => '1234')),
    !PDS_Plugin::address_matches(array('Country Code' => 'US'), array('Country Code' => 'CA')),
    PDS_Plugin::postcode_key('SW1A 1AA') === 'SW1A1AA',
    PDS_Plugin::key(' Test   Buyer ', '+1 (202) 555-0100', 'US') === PDS_Plugin::key('test buyer', '2025550100', 'US'),
    PDS_Plugin::key('Test', '', 'US') === '',
    PDS_Plugin::key('', '2025550100', 'US') === '',
    PDS_Plugin::phone_key('+44 1234567890', 'GB') === '441234567890',
    PDS_Plugin::key('Test Buyer', '2025550101', 'US') !== PDS_Plugin::key('Test Other', '2025550101', 'US'),
    PDS_Plugin::safe_url('https://trackorder.site/token') === 'https://trackorder.site/token',
    PDS_Plugin::safe_url('https://trackorder.site.evil.example/token') === '',
    PDS_Plugin::safe_url('javascript:alert(1)') === '',
);
foreach ($checks as $i => $ok) { if (!$ok) { fwrite(STDERR, 'Failed check ' . $i); exit(1); } }
echo count($checks) . " checks passed\n";
