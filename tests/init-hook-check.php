<?php
// Reproduce WordPress do_action('init'): no supplied arguments become one empty string.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
$hooks = array(); $scheduled = array(); $checks = 0;
$backend = $argv[1] ?? 'action-scheduler';
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['hooks'][$hook][$priority][] = array('callback' => $callback, 'accepted_args' => $accepted_args);
}
function add_filter() {}
function register_deactivation_hook() {}
function plugin_basename($file) { return 'printify-delivery-status/printify-delivery-status.php'; }
function wc_get_orders() {}
function get_option($key, $default = false) {
    if ($key === 'pds_tab_migration_101') { return true; }
    if ($key === 'pds_sync_ui_version') { return PDS_Updater::VERSION; }
    return $default;
}
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook]['time'] ?? false; }
function wp_schedule_event($time, $recurrence, $hook) {
    $GLOBALS['scheduled'][$hook] = array('time' => $time, 'recurrence' => $recurrence);
}
if ($backend === 'action-scheduler') {
    function as_has_scheduled_action($hook, $args, $group) { return isset($GLOBALS['scheduled'][$hook]); }
    function as_get_scheduled_actions($args, $format) { return isset($GLOBALS['scheduled'][$args['hook']]) ? array(1) : array(); }
    function as_schedule_recurring_action($time, $interval, $hook, $args, $group) {
        $GLOBALS['scheduled'][$hook] = array('time' => $time, 'interval' => $interval, 'group' => $group);
    }
} elseif ($backend !== 'wp-cron') { throw new RuntimeException('Unknown scheduler backend'); }
function verify_init($condition, $message) {
    if (!$condition) { throw new RuntimeException('Failed: ' . $message); }
    $GLOBALS['checks']++;
}
function wordpress_init() {
    $priorities = $GLOBALS['hooks']['init']; ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback['callback'], array_slice(array(''), 0, $callback['accepted_args']));
        }
    }
}
require __DIR__ . '/../printify-delivery-status/printify-delivery-status.php';
$before = time();
wordpress_init();
verify_init(isset($scheduled[PDS_Plugin::HOOK]), 'init schedules without int plus string fatal');
$job = $scheduled[PDS_Plugin::HOOK];
verify_init($job['time'] >= $before + 60 && $job['time'] <= time() + 60, 'init uses default delay of 60 seconds');
$registration = array_filter($hooks['init'][10], function ($item) { return $item['callback'] === array('PDS_Plugin', 'schedule'); });
verify_init(count($registration) === 1 && reset($registration)['accepted_args'] === 0, 'scheduler receives no init arguments');
wordpress_init();
verify_init($scheduled[PDS_Plugin::HOOK] === $job, 'repeat init preserves existing schedule');
$scheduled = array(); PDS_Plugin::schedule(HOUR_IN_SECONDS);
verify_init($scheduled[PDS_Plugin::HOOK]['time'] >= time() + 3599, 'explicit manual reschedule still uses one-hour delay');
if ($backend === 'action-scheduler') {
    verify_init($scheduled[PDS_Plugin::HOOK]['group'] === 'pds' && $scheduled[PDS_Plugin::HOOK]['interval'] === 3600, 'Action Scheduler hourly group preserved');
} else {
    verify_init($scheduled[PDS_Plugin::HOOK]['recurrence'] === 'hourly', 'WP-Cron hourly fallback preserved');
}
echo "$checks init hook checks passed ($backend)\n";
