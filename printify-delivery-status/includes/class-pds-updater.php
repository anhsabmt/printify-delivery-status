<?php
if (!defined('ABSPATH')) { exit; }

/** Public GitHub releases; no hosting token or customer information is sent. */
final class PDS_Updater {
    const VERSION = '1.0.7';
    const SLUG = 'printify-delivery-status';
    const REPO = 'anhsabmt/printify-delivery-status';
    const CACHE = 'pds_github_release';
    private static $file;

    public static function boot($file) {
        self::$file = $file;
        add_filter('update_plugins_github.com', array(__CLASS__, 'update'), 10, 4);
        add_filter('plugins_api', array(__CLASS__, 'info'), 10, 3);
        add_filter('plugin_action_links_' . plugin_basename($file), array(__CLASS__, 'actions'));
        add_action('admin_post_pds_check_update', array(__CLASS__, 'check'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    /** Reject drafts, previews, malformed tags and packages outside this repository. */
    public static function parse_release($release) {
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) { return false; }
        $tag = $release['tag_name'] ?? '';
        if (!is_string($tag) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match)) { return false; }
        $package = 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . self::SLUG . '.zip';
        foreach (($release['assets'] ?? array()) as $asset) {
            if (($asset['name'] ?? '') === self::SLUG . '.zip'
                && ($asset['browser_download_url'] ?? '') === $package
                && ($asset['state'] ?? '') === 'uploaded'
                && (int) ($asset['size'] ?? 0) > 0) {
                return array(
                    'version' => $match[1], 'package' => $package,
                    'url' => 'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
                    'notes' => is_string($release['body'] ?? null) ? $release['body'] : '',
                    'published' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
                );
            }
        }
        return false;
    }

    public static function latest() {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) { return $cached; }
        if ($cached === 'unavailable') { return false; }
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
            'timeout' => 15, 'redirection' => 0,
            'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => self::SLUG . '/' . self::VERSION),
        ));
        $release = false;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = self::parse_release(json_decode(wp_remote_retrieve_body($response), true));
        }
        set_transient(self::CACHE, $release ?: 'unavailable', $release ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        return $release;
    }

    public static function update($update, $data, $file, $locales) {
        if ($file !== plugin_basename(self::$file)) { return $update; }
        $release = self::latest();
        if (!$release) { return $update; }
        return array(
            'id' => 'https://github.com/' . self::REPO, 'slug' => self::SLUG,
            'version' => $release['version'], 'url' => $release['url'], 'package' => $release['package'],
            'requires' => '5.8', 'requires_php' => '7.4',
        );
    }

    public static function info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) { return $result; }
        $release = self::latest();
        if (!$release) { return $result; }
        return (object) array(
            'name' => 'Printify Delivery Status', 'slug' => self::SLUG,
            'version' => $release['version'], 'author' => 'Artful Avenues LLC',
            'homepage' => 'https://github.com/' . self::REPO,
            'requires' => '5.8', 'requires_php' => '7.4', 'last_updated' => $release['published'],
            'download_link' => $release['package'],
            'sections' => array(
                'description' => '<p>Display delivery statuses from your configured Google Sheet in WooCommerce.</p>',
                'changelog' => '<pre style="white-space:pre-wrap">' . esc_html($release['notes']) . '</pre>',
            ),
        );
    }

    public static function actions($links) {
        if (current_user_can('update_plugins')) {
            $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=pds_check_update'), 'pds_check_update')) . '">Check for updates</a>';
        }
        return $links;
    }

    public static function check() {
        if (!current_user_can('update_plugins')) { wp_die('Access denied'); }
        check_admin_referer('pds_check_update');
        delete_transient(self::CACHE);
        $release = self::latest();
        delete_site_transient('update_plugins');
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_plugins();
        $status = !$release ? 'unavailable' : (version_compare($release['version'], self::VERSION, '>') ? 'available' : 'current');
        wp_safe_redirect(admin_url('plugins.php?pds_update=' . $status));
        exit;
    }

    public static function notice() {
        if (!current_user_can('update_plugins') || !isset($_GET['pds_update'])) { return; }
        $messages = array(
            'available' => 'A new Printify Delivery Status version is available. Click Update now in the plugin row.',
            'current' => 'Printify Delivery Status is up to date.',
            'unavailable' => 'Could not check GitHub releases. Try Check for updates again later.',
        );
        $status = sanitize_key(wp_unslash($_GET['pds_update']));
        if (isset($messages[$status])) {
            echo '<div class="notice notice-' . ($status === 'unavailable' ? 'warning' : 'info') . ' is-dismissible"><p>' . esc_html($messages[$status]) . '</p></div>';
        }
    }
}
