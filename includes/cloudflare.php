<?php
/**
 * Push to Cloudflare (25 Oct 2026, owner: "push the country list to Cloudflare, block before cache").
 *
 * The plugin blocks what reaches WordPress; a cached page is served before it runs. With a Cloudflare API token the
 * owner can push the same country rule to the zone's WAF as ONE custom rule ("DevDome Country Blocker"), so the
 * block happens at the edge, before the cache. Nothing is sent until the owner presses Push; Remove deletes that one
 * rule again. Other rules in the zone are never touched: the rule is found by its description and updated in place.
 *
 * Token: a Cloudflare API token with "Zone > Zone WAF > Edit" and "Zone > Zone > Read" on this site's zone. It is
 * stored in the plugin settings and shown masked.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DEVDCOUN_CF_API')) {
    define('DEVDCOUN_CF_API', 'https://api.cloudflare.com/client/v4');
}
define('DEVDCOUN_CF_RULE_DESC', 'DevDome Country Blocker');

/**
 * The WAF expression for the stored settings, '' when there is nothing to push (blocking off or no country).
 * Block mode: visitors from the listed countries. Allow mode: everyone not listed. Always Allowed Addresses are
 * excluded, so the edge lets in exactly who the plugin lets in. Pure; tested in tests/run-tests.php.
 */
function devdcoun_cf_expression(array $s)
{
    if (empty($s['mode']) || 'off' === $s['mode']) {
        return '';
    }
    $list = devdcoun_active_countries($s);
    if (!$list) {
        return '';
    }
    $codes = array();
    foreach ($list as $cc) {
        $cc = strtoupper((string) $cc);
        if (preg_match('/^[A-Z]{2}$/', $cc)) {
            $codes[] = '"' . $cc . '"';
        }
    }
    if (!$codes) {
        return '';
    }
    $expr = 'block' === $s['mode']
        ? '(ip.geoip.country in {' . implode(' ', $codes) . '})'
        : '(not ip.geoip.country in {' . implode(' ', $codes) . '})';
    $allow = array();
    foreach ((array) (isset($s['ip_allowlist']) ? $s['ip_allowlist'] : array()) as $line) {
        $line = trim((string) $line);
        if ('' !== $line && devdcoun_valid_allowlist_entry($line)) {
            $allow[] = $line;
        }
    }
    if ($allow) {
        $expr .= ' and not (ip.src in {' . implode(' ', $allow) . '})';
    }
    return $expr;
}

/** The last four characters of the saved token, for the screen; '' when none is saved. */
function devdcoun_cf_token_hint($token)
{
    $token = (string) $token;
    return strlen($token) >= 8 ? substr($token, -4) : '';
}

/** One API call. Returns array(ok, result|null, message). Never throws; the message is safe to show. */
function devdcoun_cf_request($token, $method, $path, $body = null)
{
    $args = array(
        'method'     => $method,
        'timeout'    => 15,
        'user-agent' => 'DevDome-Country-Blocker/' . DEVDCOUN_VERSION,
        'headers'    => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
    );
    if (null !== $body) {
        $args['body'] = wp_json_encode($body);
    }
    $r = wp_remote_request(DEVDCOUN_CF_API . $path, $args);
    if (is_wp_error($r)) {
        return array(false, null, 'Cloudflare could not be reached: ' . $r->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    $d    = json_decode(wp_remote_retrieve_body($r), true);
    if (is_array($d) && !empty($d['success'])) {
        return array(true, isset($d['result']) ? $d['result'] : null, '');
    }
    $msg = '';
    if (is_array($d) && !empty($d['errors']) && is_array($d['errors'])) {
        $first = reset($d['errors']);
        if (is_array($first) && isset($first['message'])) {
            $msg = (string) $first['message'] . (isset($first['code']) ? ' (code ' . (int) $first['code'] . ')' : '');
        }
    }
    if ('' === $msg) {
        $msg = 'HTTP ' . $code;
    }
    if (401 === $code || 403 === $code) {
        $msg .= '. The token needs the Zone WAF Edit and Zone Read permissions for the zone of this site.';
    }
    return array(false, array('http' => $code, 'body' => $d), $msg);
}

/** The zone of this site: the host, then its parent names (shop.example.com -> example.com). */
function devdcoun_cf_find_zone($token, $host)
{
    $host  = strtolower(trim((string) $host));
    $parts = explode('.', $host);
    while (count($parts) >= 2) {
        $name = implode('.', $parts);
        list($ok, $res, $msg) = devdcoun_cf_request($token, 'GET', '/zones?name=' . rawurlencode($name) . '&status=active&per_page=1');
        if (!$ok) {
            return array('', '', $msg);
        }
        if (is_array($res) && isset($res[0]['id'])) {
            return array((string) $res[0]['id'], $name, '');
        }
        array_shift($parts);
    }
    return array('', '', sprintf('No active Cloudflare zone found for %s with this token.', $host));
}

/**
 * Create or update the one rule. Returns array(ok, message). On success the settings keep the zone, ruleset and rule
 * ids and the pushed expression, so the screen can say whether the edge still matches the saved settings.
 */
function devdcoun_cf_push()
{
    $s     = devdcoun_settings();
    $token = isset($s['cf_token']) ? (string) $s['cf_token'] : '';
    if ('' === $token) {
        return array(false, __('Save a Cloudflare API token first.', 'devdome-country-blocker'));
    }
    $expr = devdcoun_cf_expression($s);
    if ('' === $expr) {
        return array(false, __('Nothing to push: turn country blocking on and choose at least one country, then save.', 'devdome-country-blocker'));
    }
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    list($zone, $zone_name, $msg) = devdcoun_cf_find_zone($token, $host);
    if ('' === $zone) {
        return array(false, $msg);
    }
    $rule = array('action' => 'block', 'expression' => $expr, 'description' => DEVDCOUN_CF_RULE_DESC, 'enabled' => true);
    list($ok, $entry, $msg) = devdcoun_cf_request($token, 'GET', '/zones/' . rawurlencode($zone) . '/rulesets/phases/http_request_firewall_custom/entrypoint');
    $ruleset_id = '';
    $rule_id    = '';
    if ($ok && is_array($entry) && isset($entry['id'])) {
        $ruleset_id = (string) $entry['id'];
        foreach ((array) (isset($entry['rules']) ? $entry['rules'] : array()) as $r) {
            if (is_array($r) && isset($r['description']) && DEVDCOUN_CF_RULE_DESC === $r['description'] && isset($r['id'])) {
                $rule_id = (string) $r['id'];
                break;
            }
        }
    } elseif (!$ok && is_array($entry) && 404 !== (int) $entry['http']) {
        return array(false, $msg); // a 404 only means the zone has no custom rules yet: created below
    }
    if ('' === $ruleset_id) {
        list($ok, $res, $msg) = devdcoun_cf_request($token, 'PUT', '/zones/' . rawurlencode($zone) . '/rulesets/phases/http_request_firewall_custom/entrypoint', array('rules' => array($rule)));
    } elseif ('' !== $rule_id) {
        list($ok, $res, $msg) = devdcoun_cf_request($token, 'PATCH', '/zones/' . rawurlencode($zone) . '/rulesets/' . rawurlencode($ruleset_id) . '/rules/' . rawurlencode($rule_id), $rule);
    } else {
        list($ok, $res, $msg) = devdcoun_cf_request($token, 'POST', '/zones/' . rawurlencode($zone) . '/rulesets/' . rawurlencode($ruleset_id) . '/rules', $rule);
    }
    if (!$ok) {
        devdcoun_cf_remember(array('at' => time(), 'ok' => 0, 'msg' => $msg, 'zone' => $zone_name));
        return array(false, $msg);
    }
    // The answer is the whole ruleset: find our rule's id in it.
    if (is_array($res) && isset($res['id'])) {
        $ruleset_id = (string) $res['id'];
        foreach ((array) (isset($res['rules']) ? $res['rules'] : array()) as $r) {
            if (is_array($r) && isset($r['description']) && DEVDCOUN_CF_RULE_DESC === $r['description'] && isset($r['id'])) {
                $rule_id = (string) $r['id'];
            }
        }
    }
    devdcoun_cf_remember(array('at' => time(), 'ok' => 1, 'msg' => '', 'zone' => $zone_name, 'zone_id' => $zone, 'ruleset_id' => $ruleset_id, 'rule_id' => $rule_id, 'expression' => $expr));
    /* translators: %s is the Cloudflare zone name. */
    return array(true, sprintf(__('Rule pushed to Cloudflare zone %s. It blocks at the edge now, before the cache.', 'devdome-country-blocker'), $zone_name));
}

/** Delete the one rule again. Returns array(ok, message). */
function devdcoun_cf_remove()
{
    $s     = devdcoun_settings();
    $token = isset($s['cf_token']) ? (string) $s['cf_token'] : '';
    $last  = isset($s['cf_last']) && is_array($s['cf_last']) ? $s['cf_last'] : array();
    if ('' === $token) {
        return array(false, __('Save a Cloudflare API token first.', 'devdome-country-blocker'));
    }
    if (empty($last['zone_id']) || empty($last['ruleset_id']) || empty($last['rule_id'])) {
        return array(false, __('No pushed rule is on record for this site.', 'devdome-country-blocker'));
    }
    list($ok, $res, $msg) = devdcoun_cf_request($token, 'DELETE', '/zones/' . rawurlencode($last['zone_id']) . '/rulesets/' . rawurlencode($last['ruleset_id']) . '/rules/' . rawurlencode($last['rule_id']));
    if (!$ok && !(is_array($res) && 404 === (int) $res['http'])) {
        return array(false, $msg);
    }
    devdcoun_cf_remember(array('at' => time(), 'ok' => 1, 'msg' => '', 'zone' => isset($last['zone']) ? $last['zone'] : '', 'removed' => 1));
    return array(true, __('The Cloudflare rule was removed. Blocking continues inside WordPress.', 'devdome-country-blocker'));
}

/** Store the push record inside the settings option (one proved write, the same as every other setting). */
function devdcoun_cf_remember(array $last)
{
    $s            = devdcoun_settings();
    $s['cf_last'] = $last;
    devdcoun_option_write(devdcoun_settings_option(), $s, false);
    devdcoun_settings(true);
}

/** Does the pushed rule still match the saved settings? null = nothing pushed. */
function devdcoun_cf_in_sync(array $s)
{
    $last = isset($s['cf_last']) && is_array($s['cf_last']) ? $s['cf_last'] : array();
    if (empty($last['rule_id']) || !empty($last['removed'])) {
        return null;
    }
    return isset($last['expression']) && devdcoun_cf_expression($s) === (string) $last['expression'];
}
