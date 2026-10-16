<?php
/**
 * DevDome Tools: opt-in usage reports ("Help improve DevDome", core 1.7.12, owner decision 2026-09-11).
 *
 * OFF until the site admin ticks the box in a plugin's Settings; nothing is sent before that. When on, a plugin
 * sends ONE small report to devdome.com after a job finishes (a backup, a restore, a scan): the plugin, library,
 * WordPress and PHP versions, whether the site is a multisite, a hashed site id (SHA-256 of the home URL with a
 * random per-site salt: the address itself never leaves the site and cannot be recovered from the hash), the names
 * and versions of the active plugins and theme, the job type, how long it took and a failure code when it failed.
 * Never file contents, paths, e-mail addresses, the site address or any visitor data. Disclosed under External
 * services in every plugin readme that offers the box.
 *
 * Plugin side: print devdcorev1_telemetry_input($plugin, $input_name) inside the plugin's own settings form next to
 * devdcorev1_telemetry_text(), save with devdcorev1_telemetry_set($plugin, !empty($_POST[$input_name])), and call
 * devdcorev1_telemetry_send($plugin, $version, array('type' => 'backup', 'duration_s' => 42, 'failed' => array()))
 * when a job finishes. send() answers false without any request while the box is unticked.
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_telemetry_endpoint')) {
    function devdcorev1_telemetry_endpoint()
    {
        return 'https://devdome.com/api/plugin/telemetry';
    }
}

if (!function_exists('devdcorev1_telemetry_enabled')) {
    /** True only when THIS plugin's box is ticked (one shared option, one key per plugin slug). */
    function devdcorev1_telemetry_enabled($plugin)
    {
        $plugin = sanitize_key((string) $plugin);
        $o = get_option('devdcorev1_telemetry', array());
        return is_array($o) && !empty($o[$plugin]);
    }
}

if (!function_exists('devdcorev1_telemetry_set')) {
    /** Tick or untick for one plugin; proved by reading the option back past the cache. @return bool landed */
    function devdcorev1_telemetry_set($plugin, $on)
    {
        $plugin = sanitize_key((string) $plugin);
        if ('' === $plugin) {
            return false;
        }
        $o = get_option('devdcorev1_telemetry', array());
        $o = is_array($o) ? $o : array();
        if ($on) {
            $o[$plugin] = 1;
        } else {
            unset($o[$plugin]);
        }
        update_option('devdcorev1_telemetry', $o, false);
        wp_cache_delete('devdcorev1_telemetry', 'options');
        $back = get_option('devdcorev1_telemetry', array());
        $back = is_array($back) ? $back : array();
        return $on ? !empty($back[$plugin]) : empty($back[$plugin]);
    }
}

if (!function_exists('devdcorev1_telemetry_site_hash')) {
    /**
     * SHA-256 of the home URL with a random per-site salt (made once, never sent). An empty answer means the salt
     * could not be stored; the sender refuses rather than send an unsalted hash.
     */
    function devdcorev1_telemetry_site_hash()
    {
        $salt = (string) get_option('devdcorev1_telemetry_salt', '');
        if (strlen($salt) < 32) {
            add_option('devdcorev1_telemetry_salt', wp_generate_password(48, false, false), '', false);
            wp_cache_delete('devdcorev1_telemetry_salt', 'options');
            $salt = (string) get_option('devdcorev1_telemetry_salt', '');
            if (strlen($salt) < 32) {
                return '';
            }
        }
        return hash('sha256', $salt . '|' . home_url('/'));
    }
}

if (!function_exists('devdcorev1_telemetry_inventory')) {
    /** Active plugins (name + version, never the file path) and the active theme (name + version). */
    function devdcorev1_telemetry_inventory()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = (array) get_plugins();
        $active = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $plugins = array();
        foreach (array_unique($active) as $file) {
            if (!isset($all[$file]) || !is_array($all[$file])) {
                continue;
            }
            $plugins[] = array(
                'name'    => substr(sanitize_text_field((string) (isset($all[$file]['Name']) ? $all[$file]['Name'] : '')), 0, 80),
                'version' => substr(preg_replace('/[^0-9A-Za-z.\-+]/', '', (string) (isset($all[$file]['Version']) ? $all[$file]['Version'] : '')), 0, 20),
            );
            if (count($plugins) >= 100) {
                break;
            }
        }
        $theme = wp_get_theme();
        return array(
            'plugins' => $plugins,
            'theme'   => array(
                'name'    => substr(sanitize_text_field((string) $theme->get('Name')), 0, 80),
                'version' => substr(preg_replace('/[^0-9A-Za-z.\-+]/', '', (string) $theme->get('Version')), 0, 20),
            ),
        );
    }
}

if (!function_exists('devdcorev1_telemetry_payload')) {
    /**
     * The whole report. $job: type (a-z_), duration_s (int), steps (name => seconds, optional), failed (codes, optional).
     * No key here ever carries an address, a path, an e-mail or free text from the site.
     */
    function devdcorev1_telemetry_payload($plugin, $version, $job)
    {
        $job   = is_array($job) ? $job : array();
        $steps = array();
        if (!empty($job['steps']) && is_array($job['steps'])) {
            foreach (array_slice($job['steps'], 0, 20, true) as $name => $sec) {
                $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $name));
                if ('' !== $name) {
                    $steps[$name] = max(0, (int) $sec);
                }
            }
        }
        $failed = array();
        if (!empty($job['failed']) && is_array($job['failed'])) {
            foreach (array_slice(array_values($job['failed']), 0, 10) as $code) {
                $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $code));
                if ('' !== $code) {
                    $failed[] = substr($code, 0, 40);
                }
            }
        }
        $inv = devdcorev1_telemetry_inventory();
        return array(
            'v'          => 1,
            'plugin'     => sanitize_key((string) $plugin),
            'version'    => substr(preg_replace('/[^0-9A-Za-z.\-+]/', '', (string) $version), 0, 20),
            'core'       => defined('DEVDCOREV1_VERSION') ? (string) DEVDCOREV1_VERSION : '',
            'wp'         => (string) get_bloginfo('version'),
            'php'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'multisite'  => is_multisite() ? 1 : 0,
            'site_hash'  => devdcorev1_telemetry_site_hash(),
            'plugins'    => $inv['plugins'],
            'theme'      => $inv['theme'],
            'job_type'   => substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) (isset($job['type']) ? $job['type'] : ''))), 0, 40),
            'duration_s' => max(0, (int) (isset($job['duration_s']) ? $job['duration_s'] : 0)),
            'steps'      => (object) $steps,
            'failed'     => $failed,
        );
    }
}

if (!function_exists('devdcorev1_telemetry_send')) {
    /** One non-blocking POST, only while the plugin's box is ticked. @return bool a request was made */
    function devdcorev1_telemetry_send($plugin, $version, $job)
    {
        if (!devdcorev1_telemetry_enabled($plugin)) {
            return false;
        }
        $body = devdcorev1_telemetry_payload($plugin, $version, $job);
        if ('' === $body['site_hash'] || '' === $body['job_type']) {
            return false;
        }
        wp_remote_post(devdcorev1_telemetry_endpoint(), array(
            'timeout'    => 3,
            'blocking'   => false,
            // WordPress's default User-Agent is "WordPress/x.y; <home url>": the address would travel in a header while
            // the body promises it never leaves. A fixed, URL-free agent instead (Codex, telemetry round 1).
            'user-agent' => 'DevDome-Telemetry/' . (defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '1.0'),
            'headers'    => array('Content-Type' => 'application/json'),
            'body'       => wp_json_encode($body),
        ));
        return true;
    }
}

if (!function_exists('devdcorev1_telemetry_text')) {
    /** The consent sentence shown next to the box; the readme's External services entry says the same in substance. */
    function devdcorev1_telemetry_text()
    {
        return 'Off until you turn it on. When on, the plugin sends one report to devdome.com after a job finishes: the plugin, library, WordPress and PHP versions, whether this is a multisite, a hashed site id (never your address), the names and versions of your active plugins and theme, the job type, how long it took and a failure code if it failed. Never file contents, paths, emails or your site address. Untick to stop.';
    }
}

if (!function_exists('devdcorev1_telemetry_input')) {
    /** The checkbox only (escaped); the plugin wraps it in its own label and prints the text and policy links itself. */
    function devdcorev1_telemetry_input($plugin, $input_name)
    {
        return '<input type="checkbox" class="dd-check" name="' . esc_attr((string) $input_name) . '" value="1"' . (devdcorev1_telemetry_enabled($plugin) ? ' checked="checked"' : '') . '>';
    }
}
