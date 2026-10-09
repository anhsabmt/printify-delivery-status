<?php
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
$cache = array(); $calls = 0; $response = array('code' => 200, 'body' => '');
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttl'] = $ttl; }
function wp_remote_get($url, $args) { $GLOBALS['calls']++; $GLOBALS['request'] = array($url, $args); return $GLOBALS['response']; }
function is_wp_error($response) { return $response === 'error'; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function add_filter() {}
function add_action() {}
function plugin_basename($file) { return 'printify-delivery-status/' . basename($file); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../printify-delivery-status/includes/class-pds-updater.php';
PDS_Updater::boot('/plugins/printify-delivery-status/printify-delivery-status.php');
$passed = 0;
function verify($ok, $message) {
    if (!$ok) { fwrite(STDERR, "Failed: $message\n"); exit(1); }
    $GLOBALS['passed']++;
}
function release_fixture($tag = 'v1.0.4') {
    return array('tag_name' => $tag, 'draft' => false, 'prerelease' => false,
        'body' => '<script>alert(1)</script>', 'published_at' => '2026-10-09T00:00:00Z',
        'assets' => array(array('name' => 'printify-delivery-status.zip', 'state' => 'uploaded', 'size' => 8000,
            'browser_download_url' => 'https://github.com/anhsabmt/printify-delivery-status/releases/download/' . $tag . '/printify-delivery-status.zip')));
}
$fixture = release_fixture();
$valid = PDS_Updater::parse_release($fixture);
verify($valid['version'] === '1.0.4', 'release version');
foreach (array('draft', 'prerelease') as $key) {
    $bad = $fixture; $bad[$key] = true; verify(PDS_Updater::parse_release($bad) === false, "reject $key");
}
foreach (array('v1.0.4-beta', '../v1.0.4', 'garbage') as $tag) {
    verify(PDS_Updater::parse_release(release_fixture($tag)) === false, 'reject malformed tag');
}
$bad = $fixture; $bad['assets'][0]['browser_download_url'] = 'https://evil.example/plugin.zip';
verify(PDS_Updater::parse_release($bad) === false, 'reject external download');
$bad = $fixture; $bad['assets'][0]['browser_download_url'] = str_replace('anhsabmt/', 'another-user/', $valid['package']);
verify(PDS_Updater::parse_release($bad) === false, 'reject other repo');
$bad = $fixture; $bad['assets'][0]['size'] = 0;
verify(PDS_Updater::parse_release($bad) === false, 'reject empty ZIP');
$bad = $fixture; $bad['assets'] = array();
verify(PDS_Updater::parse_release($bad) === false, 'reject missing asset');
$response['body'] = json_encode($fixture);
verify(PDS_Updater::latest() === $valid && $calls === 1, 'fetch release');
verify(PDS_Updater::latest() === $valid && $calls === 1, 'cache avoids repeated HTTP');
verify($ttl === 3600 && !isset($request[1]['headers']['Authorization']), 'hourly cache without token');
verify(PDS_Updater::update('original', array(), 'another/plugin.php', array()) === 'original', 'unrelated plugin unaffected');
$update = PDS_Updater::update(false, array(), 'printify-delivery-status/printify-delivery-status.php', array());
verify($update['version'] === '1.0.4' && $update['package'] === $valid['package'], 'WordPress update fields');
verify(PDS_Updater::info('original', 'plugin_information', (object) array('slug' => 'another')) === 'original', 'unrelated modal unaffected');
$info = PDS_Updater::info(false, 'plugin_information', (object) array('slug' => PDS_Updater::SLUG));
verify(strpos($info->sections['changelog'], '<script>') === false && strpos($info->sections['changelog'], '&lt;script&gt;') !== false, 'escape release HTML');
foreach (array(array('code' => 403, 'body' => ''), array('code' => 200, 'body' => 'invalid JSON'), 'error') as $failed) {
    $cache = array(); $response = $failed;
    verify(PDS_Updater::latest() === false && $ttl === 300, 'safe HTTP failure');
    $before = $calls; verify(PDS_Updater::latest() === false && $calls === $before, 'failure backoff');
    verify(PDS_Updater::update(false, array(), 'printify-delivery-status/printify-delivery-status.php', array()) === false, 'no phantom updates');
}
echo "$passed updater checks passed\n";
