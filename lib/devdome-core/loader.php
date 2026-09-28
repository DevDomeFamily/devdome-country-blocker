<?php
/**
 * devdome-core version-guarded loader (the Action-Scheduler pattern).
 *
 * Every DevDome plugin ships its OWN vendored copy of this folder and does, early
 * in its main file:
 *     require_once __DIR__ . '/lib/devdome-core/loader.php';
 *
 * Each copy REGISTERS its version + library path without loading anything. On
 * `plugins_loaded` (very early) the loader picks the HIGHEST version present across
 * all installed DevDome plugins and loads that single copy. So:
 *   - one plugin installed  -> its own copy loads (works standalone)
 *   - several installed      -> only the newest copy loads; they share one cron/cache
 *
 * The loader class is intentionally tiny and STABLE so an older copy's loader can
 * safely load a newer copy's library. Bump BOTH this register() literal and
 * DEVDCOREV1_VERSION in devdome-core.php together on every release.
 */

defined('ABSPATH') || exit;

if (!class_exists('DEVDCOREV1_Loader')) {
    class DEVDCOREV1_Loader
    {
        /** @var array<string,string> version => absolute library path */
        private static $copies = array();
        private static $loaded = false;

        public static function register($version, $file)
        {
            self::$copies[(string) $version] = $file;
        }

        public static function load()
        {
            if (self::$loaded) {
                return;
            }
            self::$loaded = true;
            if (empty(self::$copies)) {
                return;
            }
            uksort(self::$copies, 'version_compare'); // ascending
            end(self::$copies);                       // highest version wins
            $file = current(self::$copies);
            if ($file && is_readable($file)) {
                require_once $file;
            }
        }
    }

    add_action('plugins_loaded', array('DEVDCOREV1_Loader', 'load'), -100);
}

DEVDCOREV1_Loader::register('1.7.6', __DIR__ . '/devdome-core.php');
