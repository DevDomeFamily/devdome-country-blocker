<?php
/**
 * One-time "Report a bug" hint (owner request, 29 Sep 2026).
 *
 * The first time a user opens ANY DevDome plugin screen, a small bubble points at the bug button in the header
 * ("Found an issue? Report it here."). The bubble's own script marks it seen per user the moment it is shown (a
 * nonce-checked admin-ajax call: the page view itself, a GET, writes nothing), so it shows once across the whole
 * suite, never once per plugin. Dismiss is client-side only (any click or Escape).
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Print the hint on a DevDome screen for a user who has not seen it yet. Hooked on admin_enqueue_scripts. */
function devdcorev1_bug_hint_enqueue()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no action taken on the value.
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $hub  = defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools';
    if ($page !== $hub && strpos($page, 'devdome-') !== 0) {
        return;
    }
    $uid = get_current_user_id();
    if (!$uid || get_user_meta($uid, 'devdcorev1_bug_hint_seen', true)) {
        return;
    }

    $ver = defined('DEVDCOREV1_VERSION') ? DEVDCOREV1_VERSION : '1.0';
    wp_register_script('devdcorev1-bug-hint', false, array(), $ver, true);
    wp_enqueue_script('devdcorev1-bug-hint');
    wp_add_inline_script('devdcorev1-bug-hint', devdcorev1_bug_hint_js());
}
add_action('admin_enqueue_scripts', 'devdcorev1_bug_hint_enqueue');

/** admin-ajax: the bubble was shown to this user, never show it again (nonce + signed-in user). */
function devdcorev1_bug_hint_seen()
{
    check_ajax_referer('devdcorev1_bug_hint', 'nonce');
    $uid = get_current_user_id();
    if (!$uid || !current_user_can('read') || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error('Forbidden.', 403);
    }
    update_user_meta($uid, 'devdcorev1_bug_hint_seen', 1);
    wp_send_json_success();
}
add_action('wp_ajax_devdcorev1_bug_hint_seen', 'devdcorev1_bug_hint_seen');

/** The bubble: finds the header bug button (also inside a same-origin iframe, Affiliate Manager) and sits under it. */
function devdcorev1_bug_hint_js()
{
    $text = wp_json_encode('Found an issue? Report it here.');
    $ajax = wp_json_encode(admin_url('admin-ajax.php'));
    $nonce = wp_json_encode(wp_create_nonce('devdcorev1_bug_hint'));
    $css  = wp_json_encode(
        '.devdcorev1-bug-hint{position:absolute;z-index:99999;display:flex;align-items:center;gap:10px;max-width:280px;padding:9px 10px 9px 13px;'
        . 'background:#1f2937;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;border-radius:8px;'
        . 'box-shadow:0 8px 24px rgba(0,0,0,.22)}'
        . '.devdcorev1-bug-hint:before{content:"";position:absolute;top:-6px;right:13px;border:6px solid transparent;border-top:0;border-bottom-color:#1f2937}'
        . '.devdcorev1-bug-hint button{background:none;border:0;padding:0;margin:0;color:#cbd5e1;font-size:18px;line-height:1;cursor:pointer}'
        . '.devdcorev1-bug-hint button:hover{color:#fff}'
    );
    return '(function(){var tries=0,t=setInterval(function(){'
        . 'var doc=document,b=doc.querySelector(\'a[href*="devdome.com/report-bug"]\');'
        . 'if(!b){var fs=document.querySelectorAll("iframe");for(var i=0;i<fs.length&&!b;i++){try{var d=fs[i].contentDocument;if(d&&d.body){b=d.querySelector(\'a[href*="devdome.com/report-bug"]\');if(b){doc=d;}}}catch(e){}}}'
        . 'if(!b){if(++tries>40){clearInterval(t);}return;}'
        . 'clearInterval(t);'
        . 'var s=doc.createElement("style");s.textContent=' . $css . ';doc.head.appendChild(s);'
        . 'var el=doc.createElement("div");el.className="devdcorev1-bug-hint";el.setAttribute("role","status");'
        . 'var txt=doc.createElement("span");txt.textContent=' . $text . ';el.appendChild(txt);'
        . 'var x=doc.createElement("button");x.type="button";x.setAttribute("aria-label","Dismiss");x.innerHTML="&times;";el.appendChild(x);'
        . 'doc.body.appendChild(el);'
        . 'try{var fd=new FormData();fd.append("action","devdcorev1_bug_hint_seen");fd.append("nonce",' . $nonce . ');fetch(' . $ajax . ',{method:"POST",credentials:"same-origin",body:fd});}catch(e){}'
        . 'var w=doc.defaultView;function place(){var r=b.getBoundingClientRect();el.style.top=(r.bottom+w.scrollY+10)+"px";el.style.left=Math.max(8,r.right+w.scrollX-el.offsetWidth)+"px";}'
        . 'place();w.addEventListener("resize",place);'
        . 'function off(){if(el.parentNode){el.parentNode.removeChild(el);}doc.removeEventListener("click",off,true);doc.removeEventListener("keydown",key,true);w.removeEventListener("resize",place);}'
        . 'function key(e){if(e.key==="Escape"){off();}}'
        . 'setTimeout(function(){doc.addEventListener("click",off,true);},0);doc.addEventListener("keydown",key,true);'
        . '},250);})();';
}
