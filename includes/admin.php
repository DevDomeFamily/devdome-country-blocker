<?php
/**
 * Admin: submenu under the shared DevDome menu, scoped enqueue, the self-POST handlers (settings save,
 * database download, clear statistics; capability + nonce + guard window each, PRG) and the tabbed
 * screen (Overview, Settings) on the shared `.dd-app` design system (DESIGN.md 14, 16, 19, 21-26).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

/* ----------------------------- menu + enqueue --------------------------- */

function devdcoun_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'DevDome Country Blocker',
        'Country Blocker',
        devdcoun_capability(),
        DEVDCOUN_PAGE,
        'devdcoun_render_page',
        8
    );
}
add_action('admin_menu', 'devdcoun_menu');

function devdcoun_enqueue_assets($hook)
{
    if (strpos($hook, DEVDCOUN_PAGE) === false) {
        return;
    }
    $css = DEVDCOUN_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDCOUN_VERSION;
    wp_enqueue_style('devdcoun-ui', DEVDCOUN_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_enqueue_style('dashicons');
    wp_add_inline_style('devdcoun-ui', devdcoun_page_css());

    $js   = DEVDCOUN_DIR . 'assets/dc-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDCOUN_VERSION;
    wp_enqueue_script('devdcoun-admin', DEVDCOUN_URL . 'assets/dc-admin.js', array(), $jver, true);
    // Blocked visits per country over the last 30 days, for the count next to each selected country (DESIGN.md 28).
    $counts = array();
    foreach ((array) devdcoun_top_countries(300) as $row) {
        $counts[(string) $row['country']] = (int) $row['n'];
    }
    wp_localize_script('devdcoun-admin', 'DevdCoun', array(
        'countries' => devdcoun_countries(),
        'counts'    => (object) $counts,
        'i18n'      => array(
            'noMatches' => __('No matches', 'devdome-country-blocker'),
            'remove'    => __('Remove', 'devdome-country-blocker'),
            'add'       => __('+ Add', 'devdome-country-blocker'),
            /* translators: %d is the number of selected countries. */
            'selected'  => __('Selected Countries (%d)', 'devdome-country-blocker'),
            'blocked'   => __('Blocked 30 Days:', 'devdome-country-blocker'),
        ),
    ));
    if (function_exists('devdcorev1_error_report_assets')) {
        devdcorev1_error_report_assets(); // "Report this error" on the error banners (core 1.7.0, DESIGN.md 26)
    }
}
add_action('admin_enqueue_scripts', 'devdcoun_enqueue_assets');

/** Page-scoped CSS on top of the shared bundle (no inline <style> blocks in the markup). */
function devdcoun_page_css()
{
    return '
        .dd-app .pt-3 { padding-top:.75rem; }
        .dd-app .pb-6 { padding-bottom:1.5rem; }
        .dd-app .py-6 { padding-top:1.5rem; padding-bottom:1.5rem; }
        .dd-app .dd-pill-warn { border-color:#fde68a; background:#fffbeb; color:#b45309; }
        .dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
        .dd-app h1.text-xl { font-size:1.25rem; line-height:1.75rem; font-weight:700; }
        .dd-app .dd-btn:disabled, .dd-app .dd-btn-primary:disabled { opacity:.45; cursor:not-allowed; }
        .dd-app .dd-btn .dashicons { margin-right:5px; }
        .dd-app .dd-tabpanel { display:none; }
        .dd-app .dd-tabpanel.is-active { display:block; }
        .dd-app .dd-tabs { overflow-x:auto; flex-wrap:nowrap; scrollbar-width:thin; }
        .dd-app .dc-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; }
        .dd-app .dc-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .dc-bug-btn svg { width:16px; height:16px; }
        .dd-app .dc-bug-btn:focus { outline:none; box-shadow:none; }
        .dd-app .dc-intro { font-size:13px; color:#6b7280; margin:0 0 14px; max-width:66%; } /* DESIGN.md 22.2 */
        .dd-app .dd-stat-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
        .dd-app .dc-stats-4 { grid-template-columns:repeat(4,minmax(0,1fr)); }
        .dd-app .dc-refresh { display:flex; align-items:center; justify-content:flex-end; gap:10px; margin:10px 0 24px; }
        .dd-app .dc-refresh .dd-hint { margin:0; }
        /* Settings rows = the Analytics form-table, 1:1 (DESIGN.md 25). */
        .dd-app .form-table { margin:0; width:100%; border-collapse:collapse; }
        .dd-app .form-table th { width:210px; padding-right:1.5rem; text-align:left; font-weight:700; color:#4b5563; }
        .dd-app .form-table td, .dd-app .form-table th { border-bottom:1px solid #f9fafb; padding-top:.75rem; padding-bottom:1.25rem; vertical-align:top; font-size:.875rem; line-height:1.25rem; }
        .dd-app .form-table td { color:#374151; }
        .dd-app .form-table tr:last-child > td, .dd-app .form-table tr:last-child > th { border-bottom:0; }
        .dd-app .form-table input[type=checkbox], .dd-app .form-table input[type=radio] { margin:0; height:1rem; width:1rem; cursor:pointer; color:#4f46e5; }
        .dd-app .form-table .dd-hint { margin:6px 0 0; }
        /* Every text field the same width: the section 28 picker width (owner 2026-09-17). */
        .dd-app .form-table .dd-textarea, .dd-app .form-table input.dd-input { display:block; width:100%; max-width:640px; box-sizing:border-box; }
        /* Phones: the label sits above its field instead of a sideways scroll (a scrolling wrapper clips the info boxes). */
        @media (max-width:782px) {
            .dd-app .form-table, .dd-app .form-table tbody, .dd-app .form-table tr, .dd-app .form-table th, .dd-app .form-table td { display:block; width:auto; }
            .dd-app .form-table th { border-bottom:0; padding:.75rem 0 .25rem; }
            .dd-app .form-table td { padding-top:0; }
        }
        /* Settings hint + info icon (DESIGN.md 21.6). */
        .dd-app .dd-tip { position:relative; display:inline-flex; align-items:center; vertical-align:middle; margin-left:4px; }
        .dd-app .dd-tip .dashicons { cursor:help; color:#818cf8; font-size:15px; width:15px; height:15px; line-height:15px; }
        .dd-app .dd-tip:hover .dashicons { color:#4f46e5; }
        .dd-app .dd-tip-box { pointer-events:none; visibility:hidden; opacity:0; position:absolute; bottom:100%; left:50%; z-index:9999; margin-bottom:.5rem; width:max-content; max-width:280px; transform:translateX(-50%); border-radius:.5rem; border:1px solid #e0e7ff; background:#eef2ff; padding:.625rem; text-align:left; font-size:.75rem; line-height:1.625; font-weight:400; color:#3730a3; box-shadow:0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1); transition:opacity .15s; }
        .dd-app .dd-tip:hover .dd-tip-box { visibility:visible; opacity:1; }
        /* An idle hover box takes no room: invisible boxes near the right edge used to widen the page (x-scroll at 1100px). */
        .dd-app .dd-tip .dd-tip-box { display:none; }
        .dd-app .dd-tip:hover .dd-tip-box { display:block; }
        /* Country selection = DESIGN.md 28 (the Redirect Manager geo picker 1:1): search, suggestions, selected rows, Remove All. */
        .dd-app .dc-cc-search { position:relative; max-width:640px; }
        .dd-app .dc-cc-search input { width:100%; max-width:none; }
        .dd-app #dc-country-list { position:absolute; left:0; right:0; top:calc(100% + 4px); margin-top:0; width:auto; min-width:0; z-index:30; max-height:274px; overflow:auto; }
        .dd-app #dc-country-list .dd-geo-opt { display:flex; justify-content:space-between; align-items:center; gap:10px; cursor:pointer; height:42px; box-sizing:border-box; padding:6px 12px; font-size:13px; color:#374151; }
        .dd-app #dc-country-list .dd-geo-opt:hover { background:#f3f4f6; }
        .dd-app #dc-country-list .dd-geo-add { color:#4f46e5; font-weight:600; font-size:12px; white-space:nowrap; }
        .dd-app #dc-country-list .dd-geo-empty { padding:10px 12px; color:#6b7280; font-size:13px; }
        .dd-app #dc-selected-countries, .dd-app #dc-countries-removeall { max-width:640px; }
        .dd-app .dd-sel-group { margin-top:12px; }
        .dd-app .dd-sel-head { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
        .dd-app .dd-sel-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:6px 10px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; margin-bottom:4px; font-size:13px; color:#374151; word-break:break-all; }
        .dd-app .dd-sel-hits { color:#6b7280; font-size:12px; font-weight:600; white-space:nowrap; }
        .dd-app .dd-sel-hits-num { color:#4f46e5; font-weight:700; }
        .dd-app .dd-sel-x { cursor:pointer; color:#ef4444; font-weight:600; font-size:12px; flex-shrink:0; padding:3px 10px; border:1px solid #fecaca; border-radius:6px; background:#fff; line-height:1.2; }
        .dd-app .dd-sel-x:hover { background:#fef2f2; border-color:#fca5a5; }
        .dd-app .dd-removeall { margin-top:8px; font-size:12px; }
        .dd-app .dd-removeall-btn { cursor:pointer; color:#ef4444; font-weight:600; font-size:12px; padding:3px 10px; border:1px solid #fecaca; border-radius:6px; background:#fff; line-height:1.2; }
        .dd-app .dd-removeall-btn:hover { background:#fef2f2; border-color:#fca5a5; }
        .dd-app .dd-removeall-confirm { color:#6b7280; font-weight:600; }
        .dd-app .dd-removeall-confirm a { cursor:pointer; text-decoration:underline; }
        .dd-app .dd-removeall-confirm .yes { color:#ef4444; }
        .dd-app .dd-removeall-confirm .no { color:#6b7280; }
        /* Radio groups one under another; the field of an option sits right under it. */
        .dd-app .dd-opts.dd-opts-col { flex-direction:column; align-items:flex-start; row-gap:.625rem; }
        .dd-app .dd-opts.dd-opts-col > div { width:100%; }
        .dd-app .dc-opt-sub { margin:8px 0 2px; } /* same left and right edge as every other field (owner 2026-09-20) */
        .dd-app .dc-opt-sub .dd-hint { margin:6px 0 0; }
        /* Top countries bars. */
        .dd-app .dc-bar-row { display:flex; align-items:center; gap:10px; margin:0 0 8px; }
        .dd-app .dc-bar-name { width:200px; font-size:13px; color:#374151; }
        .dd-app .dc-bar-wrap { flex:1; height:8px; background:#e5e7eb; border-radius:99px; overflow:hidden; }
        .dd-app .dc-bar { display:block; height:100%; background:#4f46e5; }
        .dd-app .dc-bar-num { width:70px; text-align:right; font-variant-numeric:tabular-nums; font-size:13px; color:#374151; }
        .dd-app .dc-attrib { color:#6b7280; font-size:12px; margin:12px 0 0; }
        .dd-app .dc-attrib a { color:#6b7280; }
        /* Data lists = DESIGN.md 19 (.dd-list), the Analytics dashboard table 1:1; pager = 21.8. */
        .dd-app .dd-list-wrap { overflow-x:auto; border-top:1px solid #e5e7eb; }
        .dd-app .dd-list { width:100%; border-collapse:separate; border-spacing:0; }
        .dd-app .dd-list thead th, .dd-app .dd-list thead .dd-th { width:auto; background:#f9fafb; padding:8px 12px; text-align:left; font-size:12px; line-height:16px; font-weight:500; color:#374151; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
        .dd-app .dd-list tbody td, .dd-app .dd-list tbody .dd-td { padding:10px 12px; font-size:13px; line-height:18px; color:#374151; vertical-align:middle; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
        .dd-app .dd-list th:last-child, .dd-app .dd-list td:last-child { border-right:0; }
        .dd-app .dd-list tbody tr:last-child td { border-bottom:0; }
        .dd-app .dd-list tbody tr:hover td { background:#f8fbff; }
        .dd-app .dc-path { white-space:normal; word-break:break-all; }
        .dd-app .dc-list-card { padding:0; overflow:hidden; }
        .dd-app .dc-list-card .dd-list-wrap { border-top:0; }
        .dd-app .dc-pagination { display:flex; flex-wrap:nowrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-top:1px solid #e5e7eb; }
        .dd-app .dc-pagination:empty { display:none; }
        .dd-app .dc-pg-count { white-space:nowrap; font-size:12.5px; color:#3c4043; }
        .dd-app .dc-pg-right { display:flex; flex-wrap:nowrap; align-items:center; gap:12px; flex:none; }
        .dd-app .dc-pg-per { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#3c4043; }
        .dd-app .dc-pg-btns { display:flex; align-items:center; gap:4px; }
        .dd-app .page-btn { min-width:30px; height:30px; padding:0 8px; border-radius:6px; border:1px solid #dadce0; background:#fff; color:#3c4043; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .12s, border-color .12s, color .12s; }
        .dd-app .page-btn:hover:not(:disabled) { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
        .dd-app .page-btn.active { background:#1967d2; border-color:#1967d2; color:#fff; }
        .dd-app .page-btn:disabled { color:#bdc1c6; cursor:not-allowed; }
        .dd-app .page-btn:focus { outline:none; box-shadow:none; }
        .dd-app .page-gap { color:#94a3b8; padding:0 4px; font-size:13px; user-select:none; }
        .dd-app .page-jump-wrap { position:relative; display:block; }
        .dd-app .page-jump-menu { position:absolute; bottom:calc(100% + 6px); right:0; width:78px; max-height:336px; overflow-y:auto; overscroll-behavior:contain; background:#fff; border:1px solid #dadce0; border-radius:10px; box-shadow:0 12px 32px rgba(15,23,42,.14), 0 2px 8px rgba(15,23,42,.06); padding:4px; z-index:40; display:flex; flex-direction:column; }
        .dd-app .page-jump-menu.hidden { display:none; }
        .dd-app .pjm-item { border:0; background:none; font-family:inherit; font-size:13px; font-weight:500; padding:8px 10px; border-radius:6px; cursor:pointer; color:#3c4043; text-align:center; flex:none; }
        .dd-app .pjm-item:hover { background:#f8fafc; }
        .dd-app .pjm-item.active { background:#e8f0fe; color:#1967d2; font-weight:700; }
        /* Typed-word confirmation (DESIGN.md 23). */
        .dd-app .dc-typed-confirm { margin-top:8px; border-top:1px solid #f3f4f6; padding-top:14px; }
        .dd-app .dc-typed-warn { margin:0 0 8px; font-weight:600; color:#374151; }
        .dd-app .dc-typed-ask { margin:0 0 8px; color:#374151; }
        .dd-app .dc-typed-input { max-width:240px; margin-bottom:10px; }
        .dd-app .dc-typed-btns { display:flex; gap:10px; }
        /* Readability (Malware Scanner pass): body copy and hints #374151, labels #1f2937. */
        .dd-app .dd-hint, .dd-app .description, .dd-app .dd-stat-lbl, .dd-app .dd-empty, .dd-app .dd-list thead th { color:#374151; }
        .dd-app .dd-th { color:#1f2937; }
        .dd-app .dd-tab { color:#374151; }
        .dd-app .dd-tab.is-active { color:#4338ca; }
        .dd-app .screen-reader-text { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(1px,1px,1px,1px); word-wrap:normal; }
    ';
}

/* ----------------------------- small helpers ---------------------------- */

/** Info icon with the long explanation in a hover box (DESIGN.md 21.6). */
function devdcoun_tip($text)
{
    echo '<span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">' . esc_html($text) . '</span></span>';
}

/**
 * One on/off setting row (DESIGN.md 25): label cell on the left, then the checkbox with its one word, the
 * one-line hint with the info icon under it. Saved by the Save Settings footer; absent from the POST = off.
 */
function devdcoun_setting_row($name, $label, $hint, $tip, $checked, $word = null)
{
    if ($word === null) {
        $word = __('Enable', 'devdome-country-blocker');
    }
    ?>
    <tr>
        <th scope="row"><?php echo esc_html($label); ?></th>
        <td>
            <label class="dd-opt"><input type="checkbox" class="dd-check" id="dc-sw-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((int) $checked, 1); ?>> <?php echo esc_html($word); ?></label>
            <?php if ('' !== $hint) : ?>
                <p class="dd-hint"><?php echo esc_html($hint); ?><?php if ('' !== $tip) { echo ' '; devdcoun_tip($tip); } ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/** Sticky save footer = DESIGN.md 16 + 16.1, inside the form so the button submits it. */
function devdcoun_save_footer($name, $saved)
{
    ?>
    <footer class="dd-footer" style="margin:2rem 0 0;">
        <div class="dd-footer-inner">
            <div class="dd-footer-actions">
                <button type="submit" name="<?php echo esc_attr($name); ?>" value="1" class="dd-btn-primary" style="gap:8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    <?php esc_html_e('Save Settings', 'devdome-country-blocker'); ?>
                </button>
                <?php if ($saved) : ?><span class="dd-saved" style="color:#059669;font-weight:600;font-size:13px;"><?php esc_html_e('Saved.', 'devdome-country-blocker'); ?></span><?php endif; ?>
                <?php /* translators: %d is the number of settings changed but not yet saved. */ ?>
                <span class="dd-pending" hidden style="color:#b45309;font-weight:600;font-size:13px;" data-one="<?php esc_attr_e('1 change not saved', 'devdome-country-blocker'); ?>" data-many="<?php esc_attr_e('%d changes not saved', 'devdome-country-blocker'); ?>"></span>
            </div>
        </div>
    </footer>
    <?php
}

/* ----------------------------- handlers (self-POST, PRG) ---------------- */

/** Settings save: capability + nonce + guard window, validated input, PROVED write, PRG (DESIGN.md 24 / 24.5). */
function devdcoun_handle_save()
{
    if (empty($_POST['devdcoun_settings_save'])) {
        return;
    }
    if (!current_user_can(devdcoun_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-country-blocker'));
    }
    check_admin_referer('devdcoun_settings', '_dcsn');
    devdcoun_db_guard_begin();
    $lists = array('block_countries' => array(), 'allow_countries' => array());
    foreach (array('block_countries' => 'cb_block_countries', 'allow_countries' => 'cb_allow_countries') as $key => $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $lists[$key] = array_filter(explode(',', sanitize_text_field(wp_unslash($_POST[$field]))));
        }
    }
    $allow = array();
    if (isset($_POST['cb_ip_allowlist']) && is_string($_POST['cb_ip_allowlist'])) {
        $allow = preg_split('/[\r\n]+/', sanitize_textarea_field(wp_unslash($_POST['cb_ip_allowlist'])));
    }
    $proxies = array();
    if (isset($_POST['cb_trusted_proxies']) && is_string($_POST['cb_trusted_proxies'])) {
        $proxies = preg_split('/[\r\n]+/', sanitize_textarea_field(wp_unslash($_POST['cb_trusted_proxies'])));
    }
    $input = array(
        'mode'           => isset($_POST['cb_mode']) && is_string($_POST['cb_mode']) ? sanitize_key(wp_unslash($_POST['cb_mode'])) : 'off',
        'block_countries' => $lists['block_countries'],
        'allow_countries' => $lists['allow_countries'],
        'action'         => isset($_POST['cb_action']) && is_string($_POST['cb_action']) ? sanitize_key(wp_unslash($_POST['cb_action'])) : '403',
        'message'        => isset($_POST['cb_message']) && is_string($_POST['cb_message']) ? sanitize_textarea_field(wp_unslash($_POST['cb_message'])) : '',
        'redirect_url'   => isset($_POST['cb_redirect_url']) && is_string($_POST['cb_redirect_url']) ? esc_url_raw(wp_unslash($_POST['cb_redirect_url'])) : '',
        'skip_logged_in' => !empty($_POST['cb_skip_logged_in']),
        'allow_search'   => !empty($_POST['cb_allow_search']),
        'trust_proxy'    => !empty($_POST['cb_trust_proxy']),
        'test_mode'      => !empty($_POST['cb_test_mode']),
        'protect_login'  => !empty($_POST['cb_protect_login']),
        'protect_xmlrpc' => !empty($_POST['cb_protect_xmlrpc']),
        'ip_allowlist'   => is_array($allow) ? $allow : array(),
        'trusted_proxies' => is_array($proxies) ? $proxies : array(),
    );
    $clean = devdcoun_sanitize_settings($input);
    // Allow-only with no country would let everyone in while the screen says the opposite: refused, nothing saved.
    if ('allow' === $clean['mode'] && empty($clean['allow_countries'])) {
        devdcoun_finish(array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'settings', 'cb_error' => 'emptyallow'));
    }
    // Login blocking that would shut out the person saving it is refused: their own country, judged with the
    // settings as they would be stored. Test mode blocks nobody, so it may be saved.
    if (devdcoun_would_lock_out($clean)) {
        devdcoun_finish(array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'settings', 'cb_error' => 'lockout'));
    }
    $saved = devdcoun_save_settings($input);
    if ($saved) {
        devdcoun_refresh_summary();
    }
    $done = array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'settings', 'cb_saved' => '1');
    // What the validation did not keep is said on the screen, never dropped silently.
    $dropped = 0;
    foreach (array('ip_allowlist', 'trusted_proxies') as $list_key) {
        $lines    = array_filter(array_map('trim', $input[$list_key]), 'strlen');
        $dropped += count($lines) - count(array_filter($lines, 'devdcoun_valid_allowlist_entry'));
    }
    if ($dropped > 0) {
        $done['cb_dropped'] = $dropped;
    }
    if ('' !== trim((string) $input['redirect_url']) && '' === $clean['redirect_url']) {
        $done['cb_url'] = '1';
    }
    devdcoun_finish($saved ? $done : array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'settings', 'cb_error' => 'save'));
}
add_action('admin_init', 'devdcoun_handle_save');

/** Database download / update (PRG). The result travels as flags; the Settings tab shows it once. */
function devdcoun_handle_db_build()
{
    if (empty($_POST['devdcoun_db_build'])) {
        return;
    }
    if (!current_user_can(devdcoun_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-country-blocker'));
    }
    check_admin_referer('devdcoun_db_build', '_dcdn');
    devdcoun_db_guard_begin();
    list($ok, $msg) = devdcoun_db_build();
    devdcoun_finish(array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'settings', 'cb_db' => $ok ? 'ok' : 'fail', 'cb_why' => substr((string) $msg, 0, 200)));
}
add_action('admin_init', 'devdcoun_handle_db_build');

/** Clear Statistics: typed-word CLEAR (DESIGN.md 23), the server checks the word too. */
function devdcoun_handle_clear_stats()
{
    if (empty($_POST['devdcoun_clear_stats'])) {
        return;
    }
    if (!current_user_can(devdcoun_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-country-blocker'));
    }
    check_admin_referer('devdcoun_clear_stats', '_dccn');
    devdcoun_db_guard_begin();
    $word = isset($_POST['cb_word']) && is_string($_POST['cb_word']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['cb_word'])))) : '';
    if ('CLEAR' !== $word) {
        devdcoun_finish(array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_error' => 'confirm'));
    }
    $n = devdcoun_clear_statistics();
    if (false === $n) {
        devdcoun_finish(array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_error' => !empty($GLOBALS['devdcoun_clear_partial']) ? 'clearpart' : 'clear'));
    }
    $fresh = devdcoun_refresh_summary();
    // Rows are gone either way; a failed recompute shows the refresh banner next to the cleared one.
    devdcoun_finish($fresh
        ? array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_cleared' => (int) $n)
        : array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_cleared' => (int) $n, 'cb_error' => 'refresh'));
}
add_action('admin_init', 'devdcoun_handle_clear_stats');

/** Refresh Now: recompute the Overview numbers on demand (PRG). */
function devdcoun_handle_refresh()
{
    if (empty($_POST['devdcoun_refresh'])) {
        return;
    }
    if (!current_user_can(devdcoun_capability())) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-country-blocker'));
    }
    check_admin_referer('devdcoun_refresh', '_dcrn');
    devdcoun_db_guard_begin();
    $ok = devdcoun_refresh_summary();
    devdcoun_finish($ok
        ? array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_refreshed' => '1')
        : array('page' => DEVDCOUN_PAGE, 'cb_tab' => 'overview', 'cb_error' => 'refresh'));
}
add_action('admin_init', 'devdcoun_handle_refresh');

/* ----------------------------- page shell ------------------------------- */

function devdcoun_tabs()
{
    return array(
        'overview' => array(__('Overview', 'devdome-country-blocker'), 'dashicons-dashboard'),
        'settings' => array(__('Settings', 'devdome-country-blocker'), 'dashicons-admin-generic'),
    );
}

function devdcoun_render_page()
{
    $tabs = devdcoun_tabs();
    $tab  = isset($_GET['cb_tab']) && is_string($_GET['cb_tab']) ? sanitize_key(wp_unslash($_GET['cb_tab'])) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only initial-tab selector, allowlisted below.
    if ('dashboard' === $tab) {
        $tab = 'overview'; // the pre-release build called the first tab Dashboard
    }
    if (!isset($tabs[$tab])) {
        $tab = 'overview';
    }
    // The page is built inside a guard window (DESIGN.md 24): a query that failed while it was built gets a red
    // banner, printed after the page and lifted to its top by dc-admin.js, instead of a screen that quietly shows defaults.
    devdcoun_db_guard_begin();
    $s   = devdcoun_settings();
    $sum = devdcoun_summary();
    // The tiles read a cached summary (daily cron). Opening the screen refreshes it when it is older than five minutes,
    // so a reload is enough to see a block that just happened.
    if (time() - (int) $sum['updated_at'] > 5 * MINUTE_IN_SECONDS && devdcoun_refresh_summary()) {
        $sum = devdcoun_summary();
    }
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own PRG redirects.
    $err = isset($_GET['cb_error']) && is_string($_GET['cb_error']) ? sanitize_key(wp_unslash($_GET['cb_error'])) : '';
    $why = isset($_GET['cb_why']) && is_string($_GET['cb_why']) ? sanitize_text_field(wp_unslash($_GET['cb_why'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $err_text = '';
    if ('db' === $err) {
        $err_text = '' !== $why ? $why : __('A database query failed during this action. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.', 'devdome-country-blocker');
    } elseif ('save' === $err) {
        $err_text = __('The settings could not be saved: the database did not keep them. Reload the page and check the current state before trying again; if it keeps happening, check the database with your host.', 'devdome-country-blocker');
    } elseif ('emptyallow' === $err) {
        $err_text = __('Nothing was saved: allow-only mode needs at least one country. With an empty list every visitor would be let in. Add the countries you serve, then save again.', 'devdome-country-blocker');
    } elseif ('lockout' === $err) {
        $err_text = __('Nothing was saved: with the login page blocked, these settings would lock you out, because your own country would be blocked. Add your address (Overview, Your Connection) to Always Allowed Addresses first, then save again.', 'devdome-country-blocker');
    } elseif ('confirm' === $err) {
        $err_text = __('That action needs its confirmation: type the word in the panel under the button so the request carries it.', 'devdome-country-blocker');
    } elseif ('refresh' === $err) {
        $err_text = __('The numbers could not be refreshed: the database did not answer or did not keep them. Reload the page; if it keeps happening, check the database with your host.', 'devdome-country-blocker');
    } elseif ('clearpart' === $err) {
        $err_text = __('The statistics were only partly cleared: the hit log was emptied, but the daily counters could not be deleted. Press Clear Statistics again; if it keeps happening, check the database with your host.', 'devdome-country-blocker');
    } elseif ('clear' === $err) {
        $err_text = __('The statistics could not be cleared: a delete did not go through. Reload the page and check the current state before trying again.', 'devdome-country-blocker');
    }
    $mode_labels = array('off' => __('Off', 'devdome-country-blocker'), 'block' => __('Block listed countries', 'devdome-country-blocker'), 'allow' => __('Allow listed countries only', 'devdome-country-blocker'));
    $active_mode = $s['mode'] !== 'off' && !empty(devdcoun_active_countries($s));
    $source_ok   = devdcoun_source_available();
    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]">
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3">
                <div class="p-1.5 rounded text-white inline-flex" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><span class="dashicons dashicons-admin-site-alt3"></span></div>
                <h1 class="text-xl font-bold text-gray-800 m-0"><?php esc_html_e('Country Blocker', 'devdome-country-blocker'); ?></h1>
                <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
                    <?php if ($active_mode && $source_ok && !empty($s['test_mode'])) : ?>
                        <span class="dd-pill dd-pill-warn"><span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('Test Mode: Nobody Is Blocked', 'devdome-country-blocker'); ?></span>
                    <?php elseif ($active_mode && $source_ok) : ?>
                        <span class="dd-pill dd-pill-ok"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span> <?php echo esc_html($mode_labels[$s['mode']]); ?></span>
                    <?php elseif ($active_mode) : ?>
                        <span class="dd-pill dd-pill-warn"><span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e('No country source', 'devdome-country-blocker'); ?></span>
                    <?php else : ?>
                        <span class="dd-pill"><?php esc_html_e('Blocking off', 'devdome-country-blocker'); ?></span>
                    <?php endif; ?>
                    <a class="dc-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-country-blocker&v=' . DEVDCOUN_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Report a bug', 'devdome-country-blocker'); ?>" aria-label="<?php esc_attr_e('Report a bug', 'devdome-country-blocker'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                    </a>
                </div>
            </div>
            <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist" aria-label="<?php esc_attr_e('Country Blocker sections', 'devdome-country-blocker'); ?>">
                <?php foreach ($tabs as $slug => $meta) : $active = ($tab === $slug); ?>
                    <a class="dd-tab <?php echo esc_attr($active ? 'is-active' : ''); ?>" href="#<?php echo esc_attr($slug); ?>" role="tab" id="dc-tab-<?php echo esc_attr($slug); ?>" aria-controls="dc-panel-<?php echo esc_attr($slug); ?>" aria-selected="<?php echo esc_attr($active ? 'true' : 'false'); ?>" tabindex="<?php echo esc_attr($active ? '0' : '-1'); ?>" data-dd-tab="<?php echo esc_attr($slug); ?>"><span class="dashicons <?php echo esc_attr($meta[1]); ?>" aria-hidden="true"></span> <?php echo esc_html($meta[0]); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ('' !== $err_text) : ?>
            <div class="dd-banner" data-dc-flash="1" style="margin:16px 24px 0;border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?php echo esc_html($err_text); ?><?php if (function_exists('devdcorev1_error_report_button')) { echo wp_kses(devdcorev1_error_report_button('devdome-country-blocker', DEVDCOUN_VERSION, ('db' === $err ? 'Database error during a Country Blocker action: ' : 'Country Blocker action did not land: ') . $err_text, 'Country Blocker ' . $tab), devdcoun_report_kses()); } ?></div>
        <?php endif; ?>
        <main>
            <?php foreach (array('overview' => 'devdcoun_render_overview_tab', 'settings' => 'devdcoun_render_settings_tab') as $slug => $renderer) : $active = ($tab === $slug); ?>
                <div class="dd-tabpanel<?php echo esc_attr($active ? ' is-active' : ''); ?>" id="dc-panel-<?php echo esc_attr($slug); ?>" role="tabpanel" aria-labelledby="dc-tab-<?php echo esc_attr($slug); ?>" tabindex="0" data-dd-panel="<?php echo esc_attr($slug); ?>"<?php if (!$active) : ?> hidden<?php endif; ?>>
                    <?php call_user_func($renderer, $s, $sum); ?>
                </div>
            <?php endforeach; ?>
        </main>
        <?php if (devdcoun_db_ready()) : ?>
            <?php // DB-IP Lite is CC BY 4.0: a web application must link back to DB-IP.com on pages that display or use its results. ?>
            <p class="dc-attrib max-w-5xl px-6 pb-6"><a href="https://db-ip.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e('IP Geolocation by DB-IP', 'devdome-country-blocker'); ?></a></p>
        <?php endif; ?>
    </div>
    <?php
    if (devdcoun_db_guard_failed()) :
        $banner_text = __('A database query failed while this page was built, so what it shows may be incomplete or stale. Reload the page; if it keeps happening, check the database with your host.', 'devdome-country-blocker');
        $report_btn  = function_exists('devdcorev1_error_report_button') ? devdcorev1_error_report_button('devdome-country-blocker', DEVDCOUN_VERSION, 'Country Blocker page built over a failed database query: ' . devdcoun_redact(devdcoun_db_guard_error()), 'Country Blocker page') : '';
        ?>
        <div class="dd-banner" id="devdcoun-guard-banner" style="margin:16px 24px 0;border-color:#fecaca;background:#fef2f2;color:#991b1b;"><?php echo esc_html($banner_text); ?><?php echo wp_kses($report_btn, devdcoun_report_kses()); ?></div>
    <?php endif; ?>
    <?php
    devdcoun_db_guard_end();
}

/** Tags the shared core's Report this error button is made of (wp_kses allowlist). */
function devdcoun_report_kses()
{
    return array(
        'span'   => array('class' => true),
        'button' => array('type' => true, 'class' => true, 'data-ddc-report' => true),
        'svg'    => array('viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true),
        'path'   => array('d' => true),
    );
}

/* ----------------------------- overview tab ----------------------------- */

function devdcoun_render_overview_tab($s, $sum)
{
    $names = devdcoun_countries();
    $top   = devdcoun_top_countries(10);
    $hits  = devdcoun_recent_hits(200);
    $cleared = isset($_GET['cb_cleared']) && is_string($_GET['cb_cleared']) ? absint(wp_unslash($_GET['cb_cleared'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG flag
    $mode_labels = array('off' => __('Off', 'devdome-country-blocker'), 'block' => __('Block listed countries', 'devdome-country-blocker'), 'allow' => __('Allow listed countries only', 'devdome-country-blocker'));
    $max = 0;
    foreach ((array) $top as $r) {
        $max = max($max, (int) $r['n']);
    }
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php if ($cleared !== null) : ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;">
                <?php
                /* translators: %d is the number of removed statistics rows. */
                printf(esc_html(_n('Statistics cleared: %d row removed.', 'Statistics cleared: %d rows removed.', $cleared, 'devdome-country-blocker')), (int) $cleared);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['cb_refreshed'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only PRG flag ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;" role="status"><?php esc_html_e('Numbers refreshed.', 'devdome-country-blocker'); ?></div>
        <?php endif; ?>

        <?php if (!devdcoun_source_available()) : ?>
            <div class="dd-banner" style="border-color:#fecaca;background:#fef2f2;color:#b91c1c;margin-bottom:16px;">
                <strong><?php esc_html_e('No country source: blocking is inactive.', 'devdome-country-blocker'); ?></strong><br>
                <?php esc_html_e('Download the local IP database in Settings, or put the site behind Cloudflare. Until then every visitor counts as unknown, and unknown is always allowed.', 'devdome-country-blocker'); ?>
            </div>
        <?php endif; ?>

        <div class="dd-stats dc-stats-4">
            <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html(number_format_i18n((int) $sum['blocked_today'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Blocked Today', 'devdome-country-blocker'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html(number_format_i18n((int) $sum['blocked_7d'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Blocked 7 Days', 'devdome-country-blocker'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo esc_html(number_format_i18n((int) $sum['blocked_30d'])); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Blocked 30 Days', 'devdome-country-blocker'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num" style="color:<?php echo esc_attr($s['mode'] === 'off' ? '#6b7280' : '#10b981'); ?>;"><?php echo (int) count(devdcoun_active_countries($s)); ?></div><div class="dd-stat-lbl"><?php esc_html_e('Countries Listed', 'devdome-country-blocker'); ?></div><div class="dd-stat-sub"><?php echo esc_html(isset($mode_labels[$s['mode']]) ? $mode_labels[$s['mode']] : $s['mode']); ?></div></div>
        </div>
        <form method="post" class="dc-refresh">
            <input type="hidden" name="devdcoun_refresh" value="1">
            <?php wp_nonce_field('devdcoun_refresh', '_dcrn'); ?>
            <span class="dd-hint"><?php
            if (!empty($sum['updated_at'])) {
                /* translators: %s is a time span like "3 minutes". */
                echo esc_html(sprintf(__('Numbers updated %s ago.', 'devdome-country-blocker'), human_time_diff((int) $sum['updated_at'], time())));
            } else {
                esc_html_e('Numbers not calculated yet.', 'devdome-country-blocker');
            }
            ?></span>
            <button type="submit" class="dd-btn dd-btn-sm"><?php esc_html_e('Refresh Now', 'devdome-country-blocker'); ?></button>
        </form>

        <?php
        // What the plugin sees for the owner's own request, and what a guest from there would get: the setup check.
        list($my_cc, $my_src) = devdcoun_country();
        $my_ip   = devdcoun_visitor_ip();
        $my_peer = devdcoun_client_ip();
        $my_meta = devdcoun_db_meta();
        $my_list = devdcoun_active_countries($s);
        if ('off' === $s['mode'] || empty($my_list)) {
            $my_verdict = __('Blocking is off, so every visitor is allowed.', 'devdome-country-blocker');
        } elseif ('' !== $my_ip && !empty($s['ip_allowlist']) && devdcoun_ip_in_list($my_ip, $s['ip_allowlist'])) {
            $my_verdict = __('Your address is on the Always Allowed Addresses list, so you are never blocked.', 'devdome-country-blocker');
        } elseif ('' === $my_cc) {
            $my_verdict = __('Your country could not be determined. A visitor like that is always allowed.', 'devdome-country-blocker');
        } elseif (('block' === $s['mode']) === in_array($my_cc, $my_list, true)) {
            $my_verdict = __('A visitor from your country who is not logged in is blocked.', 'devdome-country-blocker');
        } else {
            $my_verdict = __('A visitor from your country is allowed.', 'devdome-country-blocker');
        }
        ?>
        <div class="dd-sec-head"><span class="dashicons dashicons-location dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Your Connection', 'devdome-country-blocker'); ?></h2></div>
        <div class="dd-card" style="margin-bottom:32px;">
            <div class="dc-tablewrap"><table class="form-table">
                <tr><th scope="row"><?php esc_html_e('Your Country', 'devdome-country-blocker'); ?></th><td><?php echo esc_html('' !== $my_cc ? ((isset($names[$my_cc]) ? $names[$my_cc] : $my_cc) . ' (' . $my_cc . ')') : __('Unknown', 'devdome-country-blocker')); ?><p class="dd-hint"><?php echo esc_html($my_verdict); ?></p></td></tr>
                <tr><th scope="row"><?php esc_html_e('Detected By', 'devdome-country-blocker'); ?></th><td><?php echo esc_html(devdcoun_source_label($my_src)); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('IP Database', 'devdome-country-blocker'); ?></th><td><?php
                if (devdcoun_db_ready()) {
                    echo esc_html(sprintf(
                        /* translators: 1: dataset month like 2026-09, 2: date of the last update. */
                        __('DB-IP %1$s, updated %2$s', 'devdome-country-blocker'),
                        isset($my_meta['month']) ? $my_meta['month'] : '?',
                        !empty($my_meta['built_at']) ? date_i18n(get_option('date_format'), (int) $my_meta['built_at']) : '?'
                    ));
                } else {
                    esc_html_e('Not installed', 'devdome-country-blocker');
                }
                ?><p class="dd-hint"><?php esc_html_e('Refreshed automatically once a month.', 'devdome-country-blocker'); ?></p></td></tr>
                <tr><th scope="row"><?php esc_html_e('Your Address', 'devdome-country-blocker'); ?></th><td><code><?php echo esc_html('' !== $my_ip ? $my_ip : __('Unknown', 'devdome-country-blocker')); ?></code><p class="dd-hint"><?php esc_html_e('Shown to you only, never stored. This is the address to put on the Always Allowed Addresses list.', 'devdome-country-blocker'); ?></p>
                    <?php if ($my_ip === $my_peer && devdcoun_ip_is_private($my_peer)) : ?>
                        <p class="dd-hint" style="color:#b45309;font-weight:600;"><?php esc_html_e('This is a private address, so your site sits behind a proxy or load balancer and every visitor looks like this one. Enter that proxy under Trusted Proxies in Settings, or countries cannot be told apart.', 'devdome-country-blocker'); ?></p>
                    <?php elseif ($my_ip !== $my_peer) : ?>
                        <?php /* translators: %s is the address of the proxy or CDN edge the request came through. */ ?>
                        <p class="dd-hint"><?php echo esc_html(sprintf(__('Your request came through %s (a trusted proxy or Cloudflare).', 'devdome-country-blocker'), $my_peer)); ?></p>
                    <?php endif; ?></td></tr>
            </table></div>
        </div>

        <div class="dd-sec-head"><span class="dashicons dashicons-chart-bar dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Top Blocked Countries (30 Days)', 'devdome-country-blocker'); ?></h2></div>
        <div class="dd-card" style="margin-bottom:32px;">
            <?php if (null === $top) : ?>
                <div class="dd-empty"><?php esc_html_e('The counters could not be read from the database. Reload the page; if it keeps happening, check the database with your host.', 'devdome-country-blocker'); ?></div>
            <?php elseif (empty($top)) : ?>
                <div class="dd-empty"><?php esc_html_e('No blocked visits recorded yet.', 'devdome-country-blocker'); ?></div>
            <?php else : ?>
                <?php foreach ($top as $r) : $cc = (string) $r['country']; ?>
                    <div class="dc-bar-row">
                        <span class="dc-bar-name"><?php echo esc_html((isset($names[$cc]) ? $names[$cc] : $cc) . ' (' . $cc . ')'); ?></span>
                        <span class="dc-bar-wrap"><span class="dc-bar" style="width:<?php echo esc_attr($max > 0 ? max(2, round((int) $r['n'] / $max * 100)) : 0); ?>%"></span></span>
                        <span class="dc-bar-num"><?php echo esc_html(number_format_i18n((int) $r['n'])); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="dd-sec-head"><span class="dashicons dashicons-list-view dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Recent Blocked Hits', 'devdome-country-blocker'); ?></h2></div>
        <p class="dc-intro"><?php esc_html_e('The last 200 blocked requests (in test mode: the requests that would have been blocked). Addresses are anonymized before they are stored (IPv4 keeps the first three numbers, IPv6 the first 48 bits).', 'devdome-country-blocker'); ?></p>
        <?php if (null === $hits) : ?>
            <div class="dd-card" style="margin-bottom:32px;"><div class="dd-empty"><?php esc_html_e('The log could not be read from the database. Reload the page; if it keeps happening, check the database with your host.', 'devdome-country-blocker'); ?></div></div>
        <?php elseif (empty($hits)) : ?>
            <div class="dd-card" style="margin-bottom:32px;"><div class="dd-empty"><?php esc_html_e('Nothing blocked yet. Hits appear here as soon as the blocker acts.', 'devdome-country-blocker'); ?></div></div>
        <?php else : ?>
            <div class="dd-card dc-list-card" style="margin-bottom:32px;">
                <div class="dd-list-wrap"><table class="dd-table dd-list" id="dc-hits-table" data-dc-paged="20">
                    <caption class="screen-reader-text"><?php esc_html_e('Recent blocked hits with time, country, result, anonymized address, path and user agent', 'devdome-country-blocker'); ?></caption>
                    <thead><tr>
                        <th scope="col" class="dd-th"><?php esc_html_e('Time', 'devdome-country-blocker'); ?></th>
                        <th scope="col" class="dd-th"><?php esc_html_e('Country', 'devdome-country-blocker'); ?></th>
                        <th scope="col" class="dd-th"><?php esc_html_e('Result', 'devdome-country-blocker'); ?></th>
                        <th scope="col" class="dd-th"><?php esc_html_e('Address (Anonymized)', 'devdome-country-blocker'); ?></th>
                        <th scope="col" class="dd-th"><?php esc_html_e('Path', 'devdome-country-blocker'); ?></th>
                        <th scope="col" class="dd-th"><?php esc_html_e('User Agent', 'devdome-country-blocker'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($hits as $r) : $cc = (string) $r['country']; ?>
                        <tr>
                            <td class="dd-td" style="white-space:nowrap;"><?php echo esc_html(mysql2date('M j, H:i', $r['created_at'])); ?></td>
                            <td class="dd-td" style="white-space:nowrap;"><?php echo esc_html((isset($names[$cc]) ? $names[$cc] : $cc)); ?></td>
                            <td class="dd-td" style="white-space:nowrap;"><?php echo esc_html(!empty($r['test']) ? __('Test', 'devdome-country-blocker') : __('Blocked', 'devdome-country-blocker')); ?></td>
                            <td class="dd-td" style="white-space:nowrap;"><code><?php echo esc_html($r['ip_display']); ?></code></td>
                            <td class="dd-td dc-path"><?php echo esc_html($r['path']); ?></td>
                            <td class="dd-td dc-path"><?php echo esc_html($r['ua']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="dc-pagination"></div>
            </div>
        <?php endif; ?>

        <div class="dd-sec-head"><span class="dashicons dashicons-trash dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Clear Statistics', 'devdome-country-blocker'); ?></h2></div>
        <div class="dd-card">
            <p class="dc-intro"><?php esc_html_e('Removes every daily counter and every logged hit from this site. Your settings and the downloaded database are not touched.', 'devdome-country-blocker'); ?></p>
            <?php // DESIGN.md 23: destructive = type the word. dc-admin.js drives form[data-dc-typed]. ?>
            <form method="post" style="margin:0;" data-dc-typed="CLEAR">
                <input type="hidden" name="devdcoun_clear_stats" value="1">
                <?php wp_nonce_field('devdcoun_clear_stats', '_dccn'); ?>
                <button type="button" class="dd-btn dd-btn-danger" data-dc-typed-open><?php esc_html_e('Clear Statistics', 'devdome-country-blocker'); ?></button>
                <div class="dc-typed-confirm" hidden>
                    <p class="dc-typed-warn"><?php esc_html_e('This permanently deletes every counter and every logged hit on this site. It cannot be undone.', 'devdome-country-blocker'); ?></p>
                    <p class="dc-typed-ask"><?php echo wp_kses(__('Type <strong>CLEAR</strong> to confirm.', 'devdome-country-blocker'), array('strong' => array())); ?></p>
                    <input type="text" class="dd-input dc-typed-input" autocomplete="off" placeholder="CLEAR" aria-label="<?php esc_attr_e('Type CLEAR to confirm', 'devdome-country-blocker'); ?>">
                    <div class="dc-typed-btns">
                        <button type="submit" class="dd-btn dd-btn-danger" disabled><?php esc_html_e('Clear Statistics', 'devdome-country-blocker'); ?></button>
                        <button type="button" class="dd-btn" data-dc-typed-cancel><?php esc_html_e('Cancel', 'devdome-country-blocker'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
}

/* ----------------------------- settings tab ----------------------------- */

function devdcoun_render_settings_tab($s, $sum)
{
    $meta  = devdcoun_db_meta();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own PRG redirects.
    $saved = isset($_GET['cb_saved']);
    $db    = isset($_GET['cb_db']) && is_string($_GET['cb_db']) ? sanitize_key(wp_unslash($_GET['cb_db'])) : '';
    $why   = isset($_GET['cb_why']) && is_string($_GET['cb_why']) ? sanitize_text_field(wp_unslash($_GET['cb_why'])) : '';
    $dropped = isset($_GET['cb_dropped']) && is_string($_GET['cb_dropped']) ? absint(wp_unslash($_GET['cb_dropped'])) : 0;
    $bad_url = isset($_GET['cb_url']);
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
        <?php if ($dropped > 0) : ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;" role="status">
                <?php
                /* translators: %d is the number of address lines that were not kept. */
                printf(esc_html(_n('%d line in Always Allowed Addresses or Trusted Proxies was not a valid address or range and was not saved.', '%d lines in Always Allowed Addresses or Trusted Proxies were not valid addresses or ranges and were not saved.', $dropped, 'devdome-country-blocker')), (int) $dropped);
                ?>
            </div>
        <?php endif; ?>
        <?php if ($bad_url) : ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#fde68a;background:#fffbeb;color:#92400e;margin-bottom:16px;" role="status"><?php esc_html_e('The redirect URL was not saved: it must be a full http or https address on another website. Until you enter one, blocked visitors get the 403 page.', 'devdome-country-blocker'); ?></div>
        <?php endif; ?>
        <?php if ('ok' === $db) : ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#a7f3d0;background:#ecfdf5;color:#065f46;margin-bottom:16px;" role="status"><?php echo esc_html('' !== $why ? $why : __('Database ready.', 'devdome-country-blocker')); ?></div>
        <?php elseif ('fail' === $db) : ?>
            <div class="dd-banner" data-dc-flash="1" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;margin-bottom:16px;" role="alert"><?php echo esc_html('' !== $why ? $why : __('The database could not be built.', 'devdome-country-blocker')); ?><?php if (function_exists('devdcorev1_error_report_button')) { echo wp_kses(devdcorev1_error_report_button('devdome-country-blocker', DEVDCOUN_VERSION, 'Database download failed: ' . $why, 'Country Blocker settings'), devdcoun_report_kses()); } ?></div>
        <?php endif; ?>

        <?php // Owner 2026-09-15: the database looks after itself (monthly refresh). The section shows only when it needs the owner: not installed yet, or the last download failed. ?>
        <?php if (!devdcoun_db_ready() || !empty($meta['last_error'])) : ?>
        <div class="dd-sec-head"><span class="dashicons dashicons-database dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Local IP Database', 'devdome-country-blocker'); ?></h2></div>
        <div class="dd-card" style="margin-bottom:32px;">
            <p class="dc-intro">
                <?php if (devdcoun_db_ready()) : ?>
                    <?php echo esc_html(sprintf(
                        /* translators: %s is the month of the installed dataset, for example 2026-09. */
                        __('The monthly update of the IP database did not go through. The installed copy from %s keeps working until the next attempt.', 'devdome-country-blocker'),
                        isset($meta['month']) ? $meta['month'] : '?'
                    )); ?>
                <?php else : ?>
                    <?php esc_html_e('Not installed. Needed when the site is not behind Cloudflare: one download of about 10 MB from db-ip.com, stored in your uploads folder and refreshed monthly. It covers IPv4 and IPv6 visitors.', 'devdome-country-blocker'); ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($meta['last_error'])) : ?>
                <p class="dd-hint" style="color:#b91c1c;margin:0 0 12px;"><?php
                /* translators: %s is the error text of the last database download attempt. */
                echo esc_html(sprintf(__('Last attempt failed: %s', 'devdome-country-blocker'), (string) $meta['last_error'])); ?><?php if (function_exists('devdcorev1_error_report_button')) { echo wp_kses(devdcorev1_error_report_button('devdome-country-blocker', DEVDCOUN_VERSION, 'Database download failed: ' . (string) $meta['last_error'], 'Country Blocker settings'), devdcoun_report_kses()); } ?></p>
            <?php endif; ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="devdcoun_db_build" value="1">
                <?php wp_nonce_field('devdcoun_db_build', '_dcdn'); ?>
                <button type="submit" class="dd-btn <?php echo esc_attr(devdcoun_db_ready() ? '' : 'dd-btn-primary'); ?>"><span class="dashicons dashicons-download"></span> <?php echo esc_html(devdcoun_db_ready() ? __('Update Database Now', 'devdome-country-blocker') : __('Download Database', 'devdome-country-blocker')); ?></button>
            </form>
        </div>
        <?php endif; ?>

        <form method="post" id="dc-settings-form">
            <input type="hidden" name="cb_tab" value="settings">
            <?php wp_nonce_field('devdcoun_settings', '_dcsn'); ?>

            <div class="dd-sec-head"><span class="dashicons dashicons-admin-site-alt3 dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Blocking', 'devdome-country-blocker'); ?></h2></div>
            <p class="dc-intro"><?php esc_html_e('Blocks the page views that reach WordPress. Pages served by a full-page cache or a CDN, admin-ajax.php and the REST API are not blocked.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('A full-page cache or CDN answers before WordPress runs, so a blocked visitor can still get a cached page: exclude the site from that cache for the countries you block, or add the same country rule at the cache, CDN or firewall. Background requests (admin-ajax.php, the REST API, for example a shop checkout call from a page that is already open) are left alone on purpose, so nothing on your site breaks.', 'devdome-country-blocker')); ?></p>
            <?php if (is_multisite()) : ?>
                <p class="dc-intro"><?php esc_html_e('On a multisite network every site has its own settings, statistics and country lists: set up each site separately.', 'devdome-country-blocker'); ?></p>
            <?php endif; ?>
            <div class="dd-card">
                <div class="dc-tablewrap"><table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Mode', 'devdome-country-blocker'); ?></th>
                        <td>
                            <div class="dd-opts dd-opts-col" role="radiogroup" aria-label="<?php esc_attr_e('Blocking mode', 'devdome-country-blocker'); ?>">
                                <label class="dd-opt"><input type="radio" class="dd-radio" name="cb_mode" value="off" <?php checked($s['mode'], 'off'); ?>> <?php esc_html_e('Off', 'devdome-country-blocker'); ?></label>
                                <div>
                                    <label class="dd-opt"><input type="radio" class="dd-radio" name="cb_mode" value="block" <?php checked($s['mode'], 'block'); ?>> <?php esc_html_e('Block only listed countries, allow everyone else', 'devdome-country-blocker'); ?></label>
                                    <div class="dc-opt-sub" data-dc-mode-sub="block"<?php if ('block' !== $s['mode']) : ?> style="display:none;"<?php endif; ?>>
                                        <p class="dd-hint"><?php esc_html_e('Visitors from the countries you list are blocked. An unknown country is always allowed.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('The plugin fails open on purpose: without a country there is no basis to block, and a lookup gap must never take your site offline for real visitors.', 'devdome-country-blocker')); ?></p>
                                    </div>
                                </div>
                                <div>
                                    <label class="dd-opt"><input type="radio" class="dd-radio" name="cb_mode" value="allow" <?php checked($s['mode'], 'allow'); ?>> <?php esc_html_e('Allow only listed countries, block everyone else', 'devdome-country-blocker'); ?></label>
                                    <div class="dc-opt-sub" data-dc-mode-sub="allow"<?php if ('allow' !== $s['mode']) : ?> style="display:none;"<?php endif; ?>>
                                        <p class="dd-hint"><?php esc_html_e('Only visitors from the countries you list get in. An unknown country is always allowed.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('This is the strict mode: everything that is not on your list is blocked. The plugin still fails open for an unknown country: without a country there is no basis to block, and a lookup gap must never take your site offline for real visitors.', 'devdome-country-blocker')); ?></p>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php // DESIGN.md 28: the Redirect Manager country selection 1:1. Block and allow mode keep their own list; the row shows only for those modes. ?>
                    <tr id="dc-row-countries"<?php if ('off' === $s['mode']) : ?> style="display:none;"<?php endif; ?>>
                        <th scope="row"><?php esc_html_e('Select Countries', 'devdome-country-blocker'); ?></th>
                        <td>
                            <div class="dc-cc-search">
                                <input type="text" class="dd-input" id="dc-country-search" placeholder="<?php esc_attr_e('Search countries...', 'devdome-country-blocker'); ?>" autocomplete="off" aria-label="<?php esc_attr_e('Search countries', 'devdome-country-blocker'); ?>">
                                <div class="dd-dd-panel" id="dc-country-list" role="listbox" style="display:none;"></div>
                            </div>
                            <div id="dc-selected-countries"></div>
                            <div class="dd-removeall" id="dc-countries-removeall" style="display:none;">
                                <button type="button" class="dd-removeall-btn" data-act="ask"><?php esc_html_e('Remove All', 'devdome-country-blocker'); ?></button>
                                <span class="dd-removeall-confirm" data-act="confirm" style="display:none;"><?php esc_html_e('Remove all?', 'devdome-country-blocker'); ?> <a class="yes" data-act="yes"><?php esc_html_e('Yes', 'devdome-country-blocker'); ?></a> / <a class="no" data-act="no"><?php esc_html_e('No', 'devdome-country-blocker'); ?></a></span>
                            </div>
                            <input type="hidden" id="dc-block-countries" name="cb_block_countries" value="<?php echo esc_attr(implode(',', $s['block_countries'])); ?>">
                            <input type="hidden" id="dc-allow-countries" name="cb_allow_countries" value="<?php echo esc_attr(implode(',', $s['allow_countries'])); ?>">
                            <p class="dd-hint"><?php esc_html_e('Type to search, click to add. Block mode and allow mode each keep their own list.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('Countries are ISO 3166-1 codes, matched exactly as the source reports them: Cloudflare and DB-IP use the same codes. Switching the mode shows that mode\'s own list, so a block list never turns into an allow list by accident.', 'devdome-country-blocker')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Blocked Visitors', 'devdome-country-blocker'); ?></th>
                        <td>
                            <div class="dd-opts dd-opts-col" role="radiogroup" aria-label="<?php esc_attr_e('Block action', 'devdome-country-blocker'); ?>">
                                <div>
                                    <label class="dd-opt"><input type="radio" class="dd-radio" name="cb_action" value="403" <?php checked($s['action'], '403'); ?>> <?php esc_html_e('Get a 403 page with your message', 'devdome-country-blocker'); ?></label>
                                    <div class="dc-opt-sub" data-dc-action-sub="403"<?php if ('403' !== $s['action']) : ?> style="display:none;"<?php endif; ?>>
                                        <textarea class="dd-textarea" name="cb_message" rows="2" aria-label="<?php esc_attr_e('Block message', 'devdome-country-blocker'); ?>"><?php echo esc_textarea($s['message']); ?></textarea>
                                        <p class="dd-hint"><?php esc_html_e('The 403 page sends a noindex header.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('The 403 page is a plain unbranded page with your text in the middle of the screen, so search engines never index a block page as your site.', 'devdome-country-blocker')); ?></p>
                                    </div>
                                </div>
                                <div>
                                    <label class="dd-opt"><input type="radio" class="dd-radio" name="cb_action" value="redirect" <?php checked($s['action'], 'redirect'); ?>> <?php esc_html_e('Get redirected to this URL', 'devdome-country-blocker'); ?></label>
                                    <div class="dc-opt-sub" data-dc-action-sub="redirect"<?php if ('redirect' !== $s['action']) : ?> style="display:none;"<?php endif; ?>>
                                        <input type="url" class="dd-input" name="cb_redirect_url" value="<?php echo esc_attr($s['redirect_url']); ?>" placeholder="https://example.com" aria-label="<?php esc_attr_e('Redirect URL', 'devdome-country-blocker'); ?>">
                                        <p class="dd-hint"><?php esc_html_e('A redirect to this site itself is ignored.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('A redirect to your own host would loop straight back into the blocker, so it is refused and the 403 page is shown instead.', 'devdome-country-blocker')); ?></p>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php devdcoun_setting_row('cb_test_mode', __('Test Mode', 'devdome-country-blocker'), __('Block nobody, only log who would be blocked.', 'devdome-country-blocker'), __('Try a country list before it turns anyone away: every visitor goes through, and the requests that would have been blocked appear in Recent Blocked Hits with the result Test. They are not counted as blocked. Turn it off to start blocking.', 'devdome-country-blocker'), (int) $s['test_mode']); ?>
                    <?php devdcoun_setting_row('cb_protect_login', __('Login Page', 'devdome-country-blocker'), __('Also block these countries on the login page.', 'devdome-country-blocker'), __('Stops login attempts from the blocked countries. Always Allowed Addresses still get in, and a save that would lock you out yourself is refused. If you ever get locked out while travelling, add define(\'DEVDCOUN_DISABLE\', true); to wp-config.php to switch all blocking off.', 'devdome-country-blocker'), (int) $s['protect_login'], __('Block', 'devdome-country-blocker')); ?>
                    <?php devdcoun_setting_row('cb_protect_xmlrpc', __('XML-RPC', 'devdome-country-blocker'), __('Also block these countries on xmlrpc.php.', 'devdome-country-blocker'), __('xmlrpc.php is an old remote-publishing door that login attacks like to use. Leave this off when a service in a blocked country (an app, Jetpack) must reach it, or put that service on Always Allowed Addresses.', 'devdome-country-blocker'), (int) $s['protect_xmlrpc'], __('Block', 'devdome-country-blocker')); ?>
                </table></div>
            </div>

            <div class="dd-sec-head" style="margin-top:32px;"><span class="dashicons dashicons-shield dd-ico" aria-hidden="true"></span><h2 class="dd-h2"><?php esc_html_e('Safety', 'devdome-country-blocker'); ?></h2></div>
            <div class="dd-card">
                <div class="dc-tablewrap"><table class="form-table">
                    <?php devdcoun_setting_row('cb_skip_logged_in', __('Logged-In Users', 'devdome-country-blocker'), __('Never block a logged-in user (recommended).', 'devdome-country-blocker'), __('wp-admin is never blocked, and the login page only when you turn on Login Page above. This switch exempts logged-in users from the front-end block, for example your own editors abroad.', 'devdome-country-blocker'), (int) $s['skip_logged_in'], __('Never block', 'devdome-country-blocker')); ?>
                    <?php devdcoun_setting_row('cb_allow_search', __('Search Engines', 'devdome-country-blocker'), __('Never block Google, Bing, DuckDuckGo and Apple crawlers or the DevDome health monitor (recommended).', 'devdome-country-blocker'), __('Crawlers and the DevDome site health checks come from data centres all over the world; blocking them removes your pages from search and makes the monitor report your site as down. The bypass matches the user agent, which a determined bot can fake, so it only ever lets a request through, never blocks one.', 'devdome-country-blocker'), (int) $s['allow_search'], __('Never block', 'devdome-country-blocker')); ?>
                    <?php devdcoun_setting_row('cb_trust_proxy', __('Proxy Headers', 'devdome-country-blocker'), __('Trust the CloudFront and Vercel country headers.', 'devdome-country-blocker'), __('Only turn this on when the site really runs behind Amazon CloudFront or Vercel: off those platforms anyone can send the header and pick their own country. Cloudflare needs no setting, its header is verified against the published Cloudflare address ranges automatically.', 'devdome-country-blocker'), (int) $s['trust_proxy'], __('Trust', 'devdome-country-blocker')); ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Trusted Proxies', 'devdome-country-blocker'); ?></th>
                        <td>
                            <textarea class="dd-textarea" name="cb_trusted_proxies" rows="2" placeholder="10.0.0.5&#10;172.16.0.0/12" aria-label="<?php esc_attr_e('Trusted proxies', 'devdome-country-blocker'); ?>"><?php echo esc_textarea(implode("\n", $s['trusted_proxies'])); ?></textarea>
                            <p class="dd-hint"><?php esc_html_e('Behind your own proxy or load balancer only: its address, one per line.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('Behind Nginx, HAProxy, Varnish or a cloud load balancer every request connects from the proxy, so every visitor would get the proxy\'s country. With the proxy listed here the visitor\'s address is read from the X-Forwarded-For header, and only when the request really connects from a listed proxy, so nobody can fake an address. Cloudflare needs no entry. Overview, Your Connection shows whether you need this.', 'devdome-country-blocker')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Always Allowed Addresses', 'devdome-country-blocker'); ?></th>
                        <td>
                            <textarea class="dd-textarea" name="cb_ip_allowlist" rows="3" placeholder="203.0.113.42&#10;198.51.100.0/24" aria-label="<?php esc_attr_e('Always allowed addresses', 'devdome-country-blocker'); ?>"><?php echo esc_textarea(implode("\n", $s['ip_allowlist'])); ?></textarea>
                            <p class="dd-hint"><?php esc_html_e('One per line: an IPv4 or IPv6 address, or a range like 198.51.100.0/24 or 2001:db8::/32.', 'devdome-country-blocker'); ?> <?php devdcoun_tip(__('Your office, your monitoring service, a partner: these addresses are never blocked whatever their country. Lines that are not a valid address or range are dropped on save.', 'devdome-country-blocker')); ?></p>
                        </td>
                    </tr>
                </table></div>
            </div>

            <?php devdcoun_save_footer('devdcoun_settings_save', $saved); ?>
        </form>

    </div>
    <?php
}
