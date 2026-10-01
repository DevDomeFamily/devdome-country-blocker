/**
 * Country Blocker screen behaviour (enqueued file, no inline scripts).
 * 1. WAI-ARIA tabs with hash deep-links (DESIGN.md 3).
 * 2. Flash banners gone on the next action or tab switch, URL flags stripped (DESIGN.md 22.3).
 * 3. Typed-word confirmation for Clear statistics (DESIGN.md 23).
 * 4. Pending-changes counter next to Save (DESIGN.md 16.1).
 * 5. Paged list controller for the recent hits (DESIGN.md 19 + 21.8).
 * 6. Country selection (DESIGN.md 28, the Redirect Manager picker 1:1): search, suggestions, selected rows, Remove All;
 *    block mode and allow mode keep their own list. The Blocked Visitors options show only their own field.
 */
(function () {
	'use strict';

	var app = document.querySelector('.dd-app');
	// DESIGN.md 24: the "a database query failed" banner is printed after the page; it belongs at its top.
	var guardBanner = document.getElementById('devdcoun-guard-banner');
	if (app && guardBanner) { app.insertBefore(guardBanner, app.firstChild); }
	if (!app) { return; }

	/* ------------------------------ tabs + flash ------------------------------ */

	var tabs = Array.prototype.slice.call(app.querySelectorAll('.dd-tab[data-dd-tab]'));
	var panels = Array.prototype.slice.call(app.querySelectorAll('.dd-tabpanel[data-dd-panel]'));

	function clearFlash() {
		Array.prototype.slice.call(document.querySelectorAll('.dd-banner[data-dc-flash], .dd-footer .dd-saved')).forEach(function (b) { b.parentNode.removeChild(b); });
	}
	(function stripFlags() {
		if (!(window.history && history.replaceState) || !window.URLSearchParams) { return; }
		var url = new URL(location.href), changed = false;
		Array.from(url.searchParams.keys()).forEach(function (k) {
			if (k.indexOf('cb_') === 0 && k !== 'cb_tab') { url.searchParams.delete(k); changed = true; }
		});
		if (changed) { history.replaceState(null, '', url.pathname + url.search + url.hash); }
	})();
	document.addEventListener('submit', function () { clearFlash(); }, true);

	function show(name, moveFocus) {
		if ('dashboard' === name) { name = 'overview'; }
		var known = panels.some(function (p) { return p.getAttribute('data-dd-panel') === name; });
		if (!known) { return; }
		var current = null;
		panels.forEach(function (p) { if (p.classList.contains('is-active')) { current = p.getAttribute('data-dd-panel'); } });
		if (current !== null && current !== name) { clearFlash(); }
		panels.forEach(function (p) {
			var on = p.getAttribute('data-dd-panel') === name;
			p.classList.toggle('is-active', on);
			if (on) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', 'hidden'); }
		});
		tabs.forEach(function (t) {
			var on = t.getAttribute('data-dd-tab') === name;
			t.classList.toggle('is-active', on);
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.setAttribute('tabindex', on ? '0' : '-1');
			if (on && moveFocus) { t.focus(); }
		});
	}
	tabs.forEach(function (t, idx) {
		t.addEventListener('click', function (e) {
			e.preventDefault();
			var name = t.getAttribute('data-dd-tab');
			show(name, false);
			t.blur();
			if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
		});
		t.addEventListener('keydown', function (e) {
			var target = null;
			if ('ArrowRight' === e.key || 'ArrowDown' === e.key) { target = tabs[(idx + 1) % tabs.length]; }
			else if ('ArrowLeft' === e.key || 'ArrowUp' === e.key) { target = tabs[(idx - 1 + tabs.length) % tabs.length]; }
			else if ('Home' === e.key) { target = tabs[0]; }
			else if ('End' === e.key) { target = tabs[tabs.length - 1]; }
			else if (' ' === e.key || 'Enter' === e.key) { target = t; }
			if (!target) { return; }
			e.preventDefault();
			var name = target.getAttribute('data-dd-tab');
			show(name, true);
			if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
		});
	});
	window.addEventListener('hashchange', function () {
		var h = (location.hash || '').replace('#', '');
		if (h) { show(h, false); }
	});
	var hash = (location.hash || '').replace('#', '');
	if (hash) { show(hash, false); }

	/* ------------------------------ info boxes stay on the screen ------------------------------ */

	// The hover box is centred on its icon; near an edge it is nudged back inside the window (any width, phones too).
	Array.prototype.forEach.call(app.querySelectorAll('.dd-tip'), function (tip) {
		var box = tip.querySelector('.dd-tip-box');
		if (!box) { return; }
		function place() {
			box.style.marginLeft = '';
			var r = box.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			if (r.right > vw - 8) { box.style.marginLeft = (vw - 8 - r.right) + 'px'; }
			else if (r.left < 8) { box.style.marginLeft = (8 - r.left) + 'px'; }
		}
		tip.addEventListener('mouseenter', place);
		tip.addEventListener('focusin', place);
	});

	/* ------------------------------ typed-word confirmation ------------------------------ */

	Array.prototype.slice.call(app.querySelectorAll('form[data-dc-typed]')).forEach(function (form) {
		var word = String(form.getAttribute('data-dc-typed') || '').toUpperCase();
		var open = form.querySelector('[data-dc-typed-open]');
		var panel = form.querySelector('.dc-typed-confirm');
		var input = form.querySelector('.dc-typed-input');
		var submit = form.querySelector('button[type="submit"]');
		var cancel = form.querySelector('[data-dc-typed-cancel]');
		if (!word || !open || !panel || !input || !submit) { return; }
		function ok() { return input.value.trim().toUpperCase() === word; }
		function reset() { panel.setAttribute('hidden', 'hidden'); open.style.display = ''; input.value = ''; submit.disabled = true; }
		open.addEventListener('click', function () {
			clearFlash();
			panel.removeAttribute('hidden'); open.style.display = 'none';
			input.value = ''; submit.disabled = true;
			setTimeout(function () { input.focus(); }, 0);
		});
		input.addEventListener('input', function () { submit.disabled = !ok(); });
		if (cancel) { cancel.addEventListener('click', function () { reset(); open.focus(); }); }
		form.addEventListener('submit', function (e) {
			if (!ok()) { e.preventDefault(); return; }
			var h = form.querySelector('input[name="cb_word"]');
			if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'cb_word'; form.appendChild(h); }
			h.value = input.value.trim();
			submit.disabled = true;
		});
		form.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hasAttribute('hidden')) { e.preventDefault(); reset(); open.focus(); } });
	});

	/* ------------------------------ paged lists ------------------------------ */

	Array.prototype.forEach.call(app.querySelectorAll('table[data-dc-paged]'), function (table) {
		var per = parseInt(table.getAttribute('data-dc-paged'), 10) || 20;
		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
		var wrap = table.closest('.dd-list-wrap');
		var pager = wrap && wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('dc-pagination') ? wrap.nextElementSibling : null;
		var state = { page: 1, per: per };
		function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
		function render() {
			var per = state.per;
			var pages = Math.max(1, Math.ceil(rows.length / per));
			if (state.page > pages) { state.page = pages; }
			var onPage = rows.slice((state.page - 1) * per, state.page * per);
			rows.forEach(function (tr) { tr.hidden = onPage.indexOf(tr) === -1; });
			if (!pager) { return; }
			pager.textContent = '';
			if (!rows.length) { return; }
			pager.appendChild(el('span', 'dc-pg-count', ((state.page - 1) * per + 1) + '-' + Math.min(rows.length, state.page * per) + ' of ' + rows.length));
			var right = el('div', 'dc-pg-right');
			var perWrap = el('div', 'dc-pg-per');
			perWrap.appendChild(el('span', '', 'Show per page:'));
			var jump = el('div', 'page-jump-wrap');
			var jb = el('button', 'page-btn page-jump-btn', per + ' ▾');
			jb.type = 'button';
			jb.setAttribute('aria-haspopup', 'true');
			jb.setAttribute('aria-expanded', 'false');
			var menu = el('div', 'page-jump-menu hidden');
			[10, 20, 50, 100, 200].forEach(function (n) {
				var it = el('button', 'pjm-item' + (n === per ? ' active' : ''), String(n));
				it.type = 'button';
				it.addEventListener('click', function () { menu.classList.add('hidden'); state.per = n; state.page = 1; render(); });
				menu.appendChild(it);
			});
			jb.addEventListener('click', function (e) {
				e.stopPropagation();
				var open = menu.classList.contains('hidden');
				menu.classList.toggle('hidden', !open);
				jb.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			document.addEventListener('click', function () { menu.classList.add('hidden'); jb.setAttribute('aria-expanded', 'false'); });
			jump.appendChild(jb);
			jump.appendChild(menu);
			perWrap.appendChild(jump);
			right.appendChild(perWrap);
			var btns = el('div', 'dc-pg-btns');
			var mk = function (label, target, disabled, active) {
				var b = el('button', 'page-btn' + (active ? ' active' : ''), label);
				b.type = 'button';
				b.disabled = !!disabled;
				if (!disabled && !active) { b.addEventListener('click', function () { state.page = target; render(); }); }
				return b;
			};
			btns.appendChild(mk('‹', state.page - 1, state.page <= 1));
			var prev = 0;
			for (var p = 1; p <= pages; p++) {
				if (p !== 1 && p !== pages && Math.abs(p - state.page) > 1) { continue; }
				if (p - prev > 1) { btns.appendChild(el('span', 'page-gap', '…')); }
				btns.appendChild(mk(String(p), p, false, p === state.page));
				prev = p;
			}
			btns.appendChild(mk('›', state.page + 1, state.page >= pages));
			right.appendChild(btns);
			pager.appendChild(right);
		}
		render();
	});

	/* ------------------------------ pending-changes counter ------------------------------ */

	(function () {
		var forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (f) { return f.querySelector('.dd-footer .dd-pending'); });
		forms.forEach(function (form) {
			var slot = form.querySelector('.dd-footer .dd-pending');
			var saved = form.querySelector('.dd-footer .dd-saved');
			var submitting = false;
			function fields() {
				return Array.prototype.slice.call(form.elements).filter(function (el) {
					return el.name && !/^(submit|button)$/.test(el.type) && el.name.indexOf('_dcsn') !== 0 && el.name !== '_wp_http_referer' && el.name !== 'cb_tab';
				});
			}
			function value(el) {
				if (el.type === 'checkbox' || el.type === 'radio') { return el.checked ? '1' : '0'; }
				return String(el.value);
			}
			// A radio group is ONE field: its key is the name alone and its value the checked option, so a mode change
			// counts once, not twice (one radio off, one on).
			function key(el, i) { return el.type === 'radio' ? el.name : el.name + '#' + i; }
			function groupValue(el) {
				if (el.type !== 'radio') { return value(el); }
				var on = form.querySelector('input[type="radio"][name="' + el.name + '"]:checked');
				return on ? String(on.value) : '';
			}
			var initial = {};
			fields().forEach(function (el, i) { initial[key(el, i)] = groupValue(el); });
			function count() {
				var n = 0, seen = {};
				fields().forEach(function (el, i) { var k = key(el, i); if (seen[k]) { return; } seen[k] = true; if (initial[k] !== undefined && initial[k] !== groupValue(el)) { n++; } });
				return n;
			}
			function paint() {
				var n = count();
				if (n > 0) {
					slot.textContent = n === 1 ? slot.getAttribute('data-one') : slot.getAttribute('data-many').replace('%d', String(n));
					slot.hidden = false;
					if (saved) { saved.hidden = true; }
				} else {
					slot.hidden = true;
					slot.textContent = '';
				}
			}
			form.addEventListener('change', paint);
			form.addEventListener('input', paint);
			form.addEventListener('dc:changed', paint); // the country picker writes a hidden field; it fires this
			form.addEventListener('submit', function () { submitting = true; });
			window.addEventListener('beforeunload', function (e) {
				if (!submitting && count() > 0) { e.preventDefault(); e.returnValue = ''; }
			});
		});
	})();

	/* ------------------------------ country selection (DESIGN.md 28) ------------------------------ */

	(function () {
		var searchInput = document.getElementById('dc-country-search');
		var countryList = document.getElementById('dc-country-list');
		var selectedList = document.getElementById('dc-selected-countries');
		var storeBlock = document.getElementById('dc-block-countries');
		var storeAllow = document.getElementById('dc-allow-countries');
		var removeAll = document.getElementById('dc-countries-removeall');
		var row = document.getElementById('dc-row-countries');
		if (!searchInput || !countryList || !selectedList || !storeBlock || !storeAllow || typeof window.DevdCoun === 'undefined') { return; }
		var all = window.DevdCoun.countries || {};
		var counts = window.DevdCoun.counts || {};
		var i18n = window.DevdCoun.i18n || {};
		var byName = Object.keys(all).sort(function (a, b) { return String(all[a]).localeCompare(String(all[b])); });
		function mode() { var m = document.querySelector('input[name="cb_mode"]:checked'); return m ? m.value : 'off'; }
		// Each mode keeps its own separate list; the picker works on the active mode's store.
		function activeStore() { return mode() === 'allow' ? storeAllow : storeBlock; }
		function getCodes() {
			return (activeStore().value || '').split(',').map(function (c) { return c.trim().toUpperCase(); }).filter(function (c) { return !!all[c]; });
		}
		function setCodes(codes) {
			activeStore().value = codes.join(',');
			if (storeBlock.form) { storeBlock.form.dispatchEvent(new Event('dc:changed')); } // the pending-changes counter hears hidden fields this way
		}
		function nameOf(code) { return all[code] || code; }
		function renderSelected() {
			selectedList.textContent = '';
			var codes = getCodes();
			if (removeAll) {
				removeAll.style.display = codes.length ? '' : 'none';
				var ab = removeAll.querySelector('[data-act="ask"]'), cb = removeAll.querySelector('[data-act="confirm"]');
				if (ab) { ab.style.display = ''; }
				if (cb) { cb.style.display = 'none'; }
			}
			if (!codes.length) { return; }
			var wrap = document.createElement('div'); wrap.className = 'dd-sel-group';
			var head = document.createElement('div'); head.className = 'dd-sel-head'; head.textContent = String(i18n.selected || 'Selected Countries (%d)').replace('%d', String(codes.length));
			wrap.appendChild(head);
			codes.forEach(function (code) {
				var r = document.createElement('div'); r.className = 'dd-sel-row';
				var info = document.createElement('span'); info.textContent = nameOf(code) + ' (' + code + ')'; info.style.minWidth = '0';
				var actions = document.createElement('span'); actions.style.display = 'flex'; actions.style.alignItems = 'center'; actions.style.gap = '12px'; actions.style.flexShrink = '0';
				var hits = document.createElement('span'); hits.className = 'dd-sel-hits'; hits.appendChild(document.createTextNode((i18n.blocked || 'Blocked 30 Days:') + ' '));
				var num = document.createElement('span'); num.className = 'dd-sel-hits-num'; num.textContent = String(counts[code] || 0); hits.appendChild(num);
				var x = document.createElement('span'); x.className = 'dd-sel-x'; x.title = i18n.remove || 'Remove'; x.textContent = i18n.remove || 'Remove';
				x.setAttribute('role', 'button'); x.setAttribute('tabindex', '0');
				var drop = function () { setCodes(getCodes().filter(function (c) { return c !== code; })); renderSelected(); renderResults(); };
				x.addEventListener('click', drop);
				x.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); drop(); } });
				actions.appendChild(hits); actions.appendChild(x);
				r.appendChild(info); r.appendChild(actions);
				wrap.appendChild(r);
			});
			selectedList.appendChild(wrap);
		}
		function addCode(code) {
			var codes = getCodes();
			if (codes.indexOf(code) !== -1) { return; }
			codes.push(code); setCodes(codes); renderSelected();
		}
		function renderResults() {
			var q = (searchInput.value || '').trim().toLowerCase();
			countryList.textContent = '';
			if (q === '') { countryList.style.display = 'none'; return; }
			var codes = getCodes(), shown = 0;
			byName.forEach(function (code) {
				if (shown >= 50) { return; }
				if (String(all[code]).toLowerCase().indexOf(q) === -1 && code.toLowerCase().indexOf(q) === -1) { return; }
				if (codes.indexOf(code) !== -1) { return; } // already selected
				shown++;
				var opt = document.createElement('div'); opt.className = 'dd-geo-opt'; opt.setAttribute('role', 'option');
				var label = document.createElement('span'); label.textContent = nameOf(code) + ' (' + code + ')';
				var add = document.createElement('span'); add.className = 'dd-geo-add'; add.textContent = i18n.add || '+ Add';
				opt.appendChild(label); opt.appendChild(add);
				opt.addEventListener('click', function () { addCode(code); searchInput.value = ''; renderResults(); searchInput.focus(); });
				countryList.appendChild(opt);
			});
			if (!shown) {
				var empty = document.createElement('div'); empty.className = 'dd-geo-empty'; empty.textContent = i18n.noMatches || 'No matches';
				countryList.appendChild(empty);
			}
			countryList.style.display = 'block';
		}
		searchInput.addEventListener('input', renderResults);
		searchInput.addEventListener('focus', renderResults);
		searchInput.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { countryList.style.display = 'none'; }
			if (e.key === 'Enter') { e.preventDefault(); var first = countryList.querySelector('.dd-geo-opt'); if (first) { first.click(); } }
		});
		document.addEventListener('click', function (e) { if (!searchInput.parentNode.contains(e.target)) { countryList.style.display = 'none'; } });
		// Switching the mode swaps the picker to that mode's own list; Off hides the row.
		function syncMode() {
			if (row) { row.style.display = mode() === 'off' ? 'none' : ''; }
			// Each mode shows its own hint, only while it is the chosen one.
			Array.prototype.forEach.call(document.querySelectorAll('[data-dc-mode-sub]'), function (sub) {
				sub.style.display = sub.getAttribute('data-dc-mode-sub') === mode() ? '' : 'none';
			});
			searchInput.value = '';
			countryList.style.display = 'none';
			renderSelected();
		}
		Array.prototype.forEach.call(document.querySelectorAll('input[name="cb_mode"]'), function (r) { r.addEventListener('change', syncMode); });
		if (removeAll) {
			removeAll.addEventListener('click', function (e) {
				var a = e.target.getAttribute('data-act');
				var ab = removeAll.querySelector('[data-act="ask"]'), cb = removeAll.querySelector('[data-act="confirm"]');
				if (a === 'ask') { ab.style.display = 'none'; cb.style.display = ''; }
				else if (a === 'no') { ab.style.display = ''; cb.style.display = 'none'; }
				else if (a === 'yes') { setCodes([]); renderSelected(); renderResults(); }
			});
		}
		syncMode();
	})();

	/* ------------------------------ Blocked Visitors: each option shows only its own field ------------------------------ */

	(function () {
		var radios = document.querySelectorAll('input[name="cb_action"]');
		if (!radios.length) { return; }
		function sync() {
			var on = document.querySelector('input[name="cb_action"]:checked');
			var v = on ? on.value : '403';
			Array.prototype.forEach.call(document.querySelectorAll('[data-dc-action-sub]'), function (sub) {
				sub.style.display = sub.getAttribute('data-dc-action-sub') === v ? '' : 'none';
			});
		}
		Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', sync); });
		sync();
	})();
})();
