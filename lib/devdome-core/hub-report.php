<?php
/**
 * DevDome Tools — Reporting & Alerts (v1 SEAMS, no listener).
 *
 * Defines the suite-wide alert/report contract so providers can emit and future channels
 * (email / Telegram / Slack / webhook — Phase 4) can subscribe. The BASE owns the channels;
 * each provider owns its triggers. v1 ships NO listener and NO channel (no v1 provider emits
 * an alert yet — Site Monitor is unbuilt), so this is signatures + a helper only.
 */

defined('ABSPATH') || exit;

/**
 * Emit a suite alert. Providers call this; channels subscribe to the `devdcorev1_suite_alert`
 * action (Phase 4). function_exists-guarded against a redeclare from another copy/plugin.
 *
 * @param array $alert {slug, severity: info|warn|urgent, title, body, href}
 */
if (!function_exists('devdcorev1_suite_alert')) {
    function devdcorev1_suite_alert($alert)
    {
        if (!is_array($alert) || empty($alert['title'])) {
            return;
        }
        $alert = wp_parse_args($alert, array(
            'slug'     => '',
            'severity' => 'warn',
            'title'    => '',
            'body'     => '',
            'href'     => '',
        ));
        /**
         * Fires when any DevDome provider raises an alert. No core listener in v1 — the
         * email channel (and the first real emitter, Site Monitor uptime) arrive in Phase 4.
         * Channels: add_filter('devdcorev1_suite_alert_channels', …) → {key,label,deliver_cb}.
         * Digests:  apply_filters('devdcorev1_suite_report_sections', []) → each provider's section.
         */
        do_action('devdcorev1_suite_alert', $alert);
    }
}
