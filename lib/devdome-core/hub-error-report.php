<?php
/**
 * DevDome Tools — "Report this error" (core 1.7.0, owner 2026-09-14).
 *
 * One click on an error banner or a failed-action dialog sends a report to DevDome support, no form: the plugin and
 * its version, the core, WordPress and PHP versions, the error text, the screen, the last log lines the plugin hands
 * over (filter `devdcorev1_error_report_log`), the site host and the admin e-mail as the reply-to address. Everything
 * passes the plugin's redactor (filter `devdcorev1_error_report_redact`) before it leaves the site. User-initiated
 * only, never automatic; disclosed under "External services" in every plugin readme.
 *
 * Plugin side: call devdcorev1_error_report_assets() from the plugin's admin_enqueue_scripts on its own screens and
 * print devdcorev1_error_report_button($plugin, $version, $error, $screen) next to the error; in JS call
 * window.devdcorev1ReportError({plugin, version, error, screen}, buttonElement) from a failed-action dialog.
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_error_report_endpoint')) {
    function devdcorev1_error_report_endpoint()
    {
        return 'https://devdome.com/api/plugin/error-report';
    }
}

if (!function_exists('devdcorev1_error_report_assets')) {
    /** The button script + nonce on a handle-only registration (same pattern as the hub assets). */
    function devdcorev1_error_report_assets()
    {
        if (wp_script_is('devdcorev1-report', 'enqueued')) {
            return;
        }
        $ver = defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '1.0';
        wp_register_script('devdcorev1-report', false, array(), $ver, true);
        wp_enqueue_script('devdcorev1-report');
        wp_localize_script('devdcorev1-report', 'devdcorev1Report', array(
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('devdcorev1_error_report'),
            'sending' => 'Sending the report...',
            'sent'    => 'Sent. Thank you, DevDome support will look at it.',
            'failed'  => 'The report could not be sent. Email support@devdome.com and paste the error text.',
        ));
        wp_add_inline_script('devdcorev1-report', devdcorev1_error_report_js());
        wp_register_style('devdcorev1-report', false, array(), $ver);
        wp_enqueue_style('devdcorev1-report');
        // [hidden] must win over the display rule (core 1.7.1, owner 2026-09-15): the plugins' confirm dialogs hide this span
        // with the hidden attribute and the inline-flex rule used to keep the button visible on every "Please confirm".
        wp_add_inline_style('devdcorev1-report', '.ddc-report[hidden]{display:none!important}.ddc-report{display:inline-flex;align-items:center;gap:6px;margin-left:12px;vertical-align:middle}.ddc-report-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border:1px solid #fecaca;border-radius:6px;background:#fff;color:#991b1b;font-size:12px;font-weight:600;line-height:1;cursor:pointer;white-space:nowrap}.ddc-report-btn:hover{background:#fef2f2}.ddc-report-btn[disabled]{opacity:.6;cursor:default}.ddc-report-btn svg{width:13px;height:13px}.ddc-report-note{font-size:12px;font-weight:600;color:#374151}.ddc-report-note.is-ok{color:#059669}.ddc-report-note.is-err{color:#b91c1c}');
    }
}

if (!function_exists('devdcorev1_error_report_js')) {
    function devdcorev1_error_report_js()
    {
        return <<<'JS'
(function () {
    var cfg = window.devdcorev1Report || {};
    function send(ctx, btn) {
        if (!cfg.ajax || !cfg.nonce) { return; }
        var wrap = btn && btn.parentNode && btn.parentNode.classList && btn.parentNode.classList.contains('ddc-report') ? btn.parentNode : null;
        var note = wrap ? wrap.querySelector('.ddc-report-note') : null;
        if (!note && wrap) { note = document.createElement('span'); note.className = 'ddc-report-note'; wrap.appendChild(note); }
        if (btn) { btn.disabled = true; }
        if (note) { note.className = 'ddc-report-note'; note.textContent = cfg.sending || 'Sending...'; }
        var body = new FormData();
        body.append('action', 'devdcorev1_error_report');
        body.append('nonce', cfg.nonce);
        body.append('context', JSON.stringify(ctx || {}));
        fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (j) {
            var ok = j && j.success;
            if (note) { note.className = 'ddc-report-note ' + (ok ? 'is-ok' : 'is-err'); note.textContent = ok ? (cfg.sent || 'Sent.') : ((j && j.data && typeof j.data === 'string') ? j.data : (cfg.failed || 'Could not send.')); }
            if (btn && !ok) { btn.disabled = false; }
            if (btn && ok) { btn.textContent = 'Reported'; }
        }).catch(function () {
            if (note) { note.className = 'ddc-report-note is-err'; note.textContent = cfg.failed || 'Could not send.'; }
            if (btn) { btn.disabled = false; }
        });
    }
    window.devdcorev1ReportError = send;
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-ddc-report]') : null;
        if (!btn) { return; }
        e.preventDefault();
        var ctx = {};
        try { ctx = JSON.parse(btn.getAttribute('data-ddc-report') || '{}'); } catch (err) { ctx = {}; }
        send(ctx, btn);
    });
})();
JS;
    }
}

if (!function_exists('devdcorev1_error_report_button')) {
    /** Returns the button HTML (escaped): print it right after the error text of a banner. */
    function devdcorev1_error_report_button($plugin, $version, $error, $screen = '')
    {
        $ctx = array(
            'plugin'  => sanitize_key((string) $plugin),
            'version' => (string) $version,
            'error'   => (string) $error,
            'screen'  => (string) $screen,
        );
        $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>';
        return '<span class="ddc-report"><button type="button" class="ddc-report-btn" data-ddc-report="' . esc_attr(wp_json_encode($ctx)) . '">' . $svg . 'Report this error' . '</button></span>';
    }
}

if (!function_exists('devdcorev1_error_report_handle')) {
    /** admin-ajax: build the redacted report and post it to DevDome. */
    function devdcorev1_error_report_handle()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer('devdcorev1_error_report', 'nonce');
        $raw = isset($_POST['context']) && is_string($_POST['context']) ? wp_unslash($_POST['context']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitized field by field below
        $ctx = json_decode($raw, true);
        $ctx = is_array($ctx) ? $ctx : array();
        $plugin  = sanitize_key(isset($ctx['plugin']) ? (string) $ctx['plugin'] : '');
        $version = sanitize_text_field(isset($ctx['version']) ? (string) $ctx['version'] : '');
        $error   = sanitize_textarea_field(isset($ctx['error']) ? (string) $ctx['error'] : '');
        $screen  = sanitize_text_field(isset($ctx['screen']) ? (string) $ctx['screen'] : '');
        if ($plugin === '' || $error === '') {
            wp_send_json_error('Nothing to report.', 400);
        }
        $redact = function ($text) use ($plugin) {
            return (string) apply_filters('devdcorev1_error_report_redact', (string) $text, $plugin);
        };
        $lines = apply_filters('devdcorev1_error_report_log', array(), $plugin);
        $log   = array();
        foreach ((array) $lines as $l) {
            if (count($log) >= 30) {
                break;
            }
            $log[] = substr($redact(sanitize_textarea_field((string) $l)), 0, 300);
        }
        $state = function_exists('devdcorev1_connection_state') ? devdcorev1_connection_state(false, false) : array();
        $host  = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $body  = array(
            'plugin'       => $plugin,
            'version'      => substr($version, 0, 20),
            'core_version' => defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '',
            'wp_version'   => get_bloginfo('version'),
            'php_version'  => PHP_VERSION,
            'multisite'    => is_multisite() ? 1 : 0,
            'locale'       => get_locale(),
            'error'        => substr($redact($error), 0, 4000),
            'screen'       => substr($screen, 0, 200),
            'log'          => $log,
            'site'         => $host,
            'site_id'      => (string) get_option('devdcorev1_site_id', ''),
            'account_id'   => is_array($state) && !empty($state['account_id']) ? (string) $state['account_id'] : '',
            'admin_email'  => sanitize_email((string) get_option('admin_email', '')),
        );
        $resp = wp_remote_post(devdcorev1_error_report_endpoint(), array(
            'timeout' => 12,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($resp)) {
            wp_send_json_error('The report could not be sent (' . $resp->get_error_message() . '). Email support@devdome.com and paste the error text.', 502);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && is_array($data) && isset($data['ok']) && true === $data['ok']) { // strict, like disconnect
            wp_send_json_success(array('sent' => true));
        }
        $why = is_array($data) && !empty($data['error']) ? sanitize_text_field((string) $data['error']) : ('HTTP ' . $code);
        wp_send_json_error('The report could not be sent (' . $why . '). Email support@devdome.com and paste the error text.', 502);
    }
    add_action('wp_ajax_devdcorev1_error_report', 'devdcorev1_error_report_handle');
}
