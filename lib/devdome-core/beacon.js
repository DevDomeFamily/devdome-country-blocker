/**
 * devdome-core — shared behavioral beacon (one per DevDome suite, vendored).
 * Proves a client actually ran JS, kept a first-party cookie, and interacted
 * (scroll / mouse / touch / form), and reports navigator.webdriver. Fires once per
 * pageview via sendBeacon to the core REST endpoint, which re-broadcasts it to any
 * listening plugin (bot-protection, etc.) via the `devdcorev1_beacon` action.
 * No data leaves the site.
 */
(function () {
  var C = window.DEVDCOREV1_BEACON;
  if (!C || !C.url) { return; }

  function getSid() {
    var m = document.cookie.match(/(?:^|;\s*)ddc_sid=([a-f0-9]+)/);
    if (m) { return m[1]; }
    var s = '';
    if (window.crypto && crypto.getRandomValues) {
      var a = crypto.getRandomValues(new Uint8Array(16));
      for (var i = 0; i < a.length; i++) { s += ('0' + a[i].toString(16)).slice(-2); }
    } else {
      s = (Date.now().toString(16) + Math.random().toString(16).slice(2)).slice(0, 32);
    }
    document.cookie = 'ddc_sid=' + s + ';path=/;max-age=1800;SameSite=Lax';
    return s;
  }

  var sid = getSid();
  var cookieOk = document.cookie.indexOf('ddc_sid=') !== -1 ? 1 : 0;
  var sig = { s: 0, m: 0, t: 0, f: 0 };
  var mark = function (k) { return function () { sig[k] = 1; }; };

  window.addEventListener('scroll', mark('s'), { passive: true, once: true });
  window.addEventListener('mousemove', mark('m'), { passive: true, once: true });
  window.addEventListener('touchstart', mark('t'), { passive: true, once: true });
  document.addEventListener('focusin', function (e) {
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) { sig.f = 1; }
  }, { passive: true });

  var sent = false;
  function send() {
    if (sent) { return; }
    sent = true;
    var body = new URLSearchParams({
      sid: sid, c: cookieOk, s: sig.s, m: sig.m, t: sig.t, f: sig.f,
      wd: (navigator.webdriver ? 1 : 0), path: (location.pathname + location.search) || '/'
    });
    try {
      if (navigator.sendBeacon) { navigator.sendBeacon(C.url, body); }
      else {
        fetch(C.url, {
          method: 'POST', body: body, keepalive: true,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
      }
    } catch (e) {}
  }

  // Send once interaction has had a chance to register, and again on the way out.
  setTimeout(send, 4000);
  window.addEventListener('pagehide', send, { once: true });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { send(); }
  });
})();
