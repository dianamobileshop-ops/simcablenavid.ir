/**
 * Login card (page and pop-up): tabs, SMS code login, SMS sign-up, and the pop-up itself.
 */
(function () {
	'use strict';

	var A = window.NAVIDAUTH || {};

	function send(action, data, withScene) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', A.nonce);
		if (withScene) { fd.append('scene', withScene); }
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(A.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
	}

	// 403 = the page came from a cache and its token expired: get a fresh one, try once more.
	function post(action, data, withScene) {
		return send(action, data, withScene).then(function (r) {
			if (r.status !== 403) { return r.json(); }
			return fetch(A.ajaxUrl + '?action=navid_nonces', { credentials: 'same-origin' })
				.then(function (n) { return n.json(); })
				.then(function (n) {
					if (!n || !n.success || !n.data.otp) { return r.json(); }
					A.nonce = n.data.otp;
					return send(action, data, withScene).then(function (r2) { return r2.json(); });
				});
		});
	}

	function initCard(card) {
		var tabs = card.querySelectorAll('[data-navid-tab]');
		var panels = card.querySelectorAll('[data-navid-panel]');
		Array.prototype.forEach.call(tabs, function (tab) {
			tab.addEventListener('click', function () {
				var key = tab.getAttribute('data-navid-tab');
				Array.prototype.forEach.call(tabs, function (t) { t.classList.toggle('is-on', t === tab); });
				Array.prototype.forEach.call(panels, function (p) { p.hidden = p.getAttribute('data-navid-panel') !== key; });
			});
		});

		function after() {
			if (A.reload) { window.location.reload(); } else { window.location.href = A.redirect; }
		}

		var steps = card.querySelector('.navid-lg-steps');
		if (!steps) { return; }
		var phoneForm = steps.querySelector('.navid-otp-phone');
		var codeForm = steps.querySelector('.navid-otp-code');
		var nameForm = steps.querySelector('.navid-name-form');
		var done = steps.querySelector('.navid-lg-done');
		var status = steps.querySelector('.navid-auth-status');
		var phone = phoneForm.querySelector('input');
		var otpBox = codeForm.querySelector('.navid-lg-otp');
		var resend = codeForm.querySelector('.navid-otp-resend');
		var ring = codeForm.querySelector('.navid-lg-ring circle');
		var digits = parseInt(card.getAttribute('data-digits'), 10) || 5;
		var timer = null;
		var busy = false;

		function say(text, ok) {
			status.textContent = text || '';
			status.classList.toggle('is-ok', !!ok);
			status.classList.toggle('is-bad', !ok && !!text);
		}

		// One step at a time; the new one slides in.
		function show(name) {
			Array.prototype.forEach.call(steps.querySelectorAll('[data-step-name]'), function (el) {
				var on = el.getAttribute('data-step-name') === name;
				el.hidden = !on;
				el.classList.toggle('is-in', on);
			});
			steps.setAttribute('data-step', name);
			say('');
		}

		function setBusy(form, on) {
			busy = on;
			var btn = form.querySelector('.navid-lg-btn');
			if (btn) { btn.classList.toggle('is-busy', on); btn.disabled = on; }
		}

		function fail() { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); }

		// ---- code boxes
		var boxes = [];
		for (var i = 0; i < digits; i++) {
			var box = document.createElement('input');
			box.type = 'text';
			box.inputMode = 'numeric';
			box.maxLength = 1;
			box.autocomplete = i === 0 ? 'one-time-code' : 'off';
			box.setAttribute('aria-label', 'رقم ' + (i + 1));
			otpBox.appendChild(box);
			boxes.push(box);
		}
		function code() { return boxes.map(function (b) { return b.value; }).join(''); }
		function clearBoxes() { boxes.forEach(function (b) { b.value = ''; b.classList.remove('is-filled'); }); boxes[0].focus(); }
		function fill(text) {
			text = (text || '').replace(/\D+/g, '').slice(0, digits);
			boxes.forEach(function (b, k) { b.value = text.charAt(k); b.classList.toggle('is-filled', !!b.value); });
			var next = Math.min(text.length, digits - 1);
			boxes[next].focus();
			if (text.length === digits) { verify(); }
		}
		boxes.forEach(function (b, k) {
			b.addEventListener('input', function () {
				otpBox.classList.remove('is-shake');
				var v = b.value.replace(/\D+/g, '');
				if (v.length > 1) { fill(v); return; }
				b.value = v;
				b.classList.toggle('is-filled', !!v);
				if (v && k < digits - 1) { boxes[k + 1].focus(); }
				if (code().length === digits) { verify(); }
			});
			b.addEventListener('keydown', function (e) {
				if (e.key === 'Backspace' && !b.value && k > 0) { boxes[k - 1].focus(); boxes[k - 1].value = ''; boxes[k - 1].classList.remove('is-filled'); }
				if (e.key === 'ArrowLeft' && k < digits - 1) { boxes[k + 1].focus(); }
				if (e.key === 'ArrowRight' && k > 0) { boxes[k - 1].focus(); }
			});
			b.addEventListener('paste', function (e) {
				e.preventDefault();
				fill((e.clipboardData || window.clipboardData).getData('text'));
			});
			b.addEventListener('focus', function () { b.select(); });
		});

		function cooldown() {
			var total = A.cooldown || 60;
			var left = total;
			var len = 2 * Math.PI * 15;
			window.clearInterval(timer);
			resend.disabled = true;
			function paint() {
				resend.querySelector('span').textContent = left;
				if (ring) {
					ring.style.strokeDasharray = len;
					ring.style.strokeDashoffset = len * (1 - left / total);
				}
			}
			paint();
			timer = window.setInterval(function () {
				left--;
				paint();
				if (left <= 0) { window.clearInterval(timer); resend.disabled = false; }
			}, 1000);
		}

		// ---- 1. number
		function sendCode() {
			if (busy) { return Promise.resolve(); }
			setBusy(phoneForm, true);
			return post('npt_send_otp', { phone: phone.value }, 'login').then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success) {
					codeForm.querySelector('.navid-lg-num').textContent = phone.value.replace(/\s+/g, '');
					show('code');
					clearBoxes();
					cooldown();
				}
			}).catch(fail).then(function () { setBusy(phoneForm, false); });
		}
		phoneForm.addEventListener('submit', function (e) { e.preventDefault(); sendCode(); });
		resend.addEventListener('click', function () {
			post('npt_send_otp', { phone: phone.value }, 'login').then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success) { clearBoxes(); cooldown(); }
			}).catch(fail);
		});
		codeForm.querySelector('.navid-otp-change').addEventListener('click', function () {
			window.clearInterval(timer);
			show('phone');
			phone.focus();
		});

		// ---- 2. code
		function verify() {
			if (busy || code().length !== digits) { return; }
			setBusy(codeForm, true);
			post('npt_verify_otp', { phone: phone.value, code: code() }, 'login').then(function (res) {
				if (!res.success) {
					say(res.data && res.data.message, false);
					otpBox.classList.remove('is-shake');
					void otpBox.offsetWidth;
					otpBox.classList.add('is-shake');
					clearBoxes();
					return;
				}
				otpBox.classList.add('is-ok');
				window.clearInterval(timer);
				if (res.data && res.data.needsName) {
					// A new customer: the page keeps working, the name is the last step.
					fetch(A.ajaxUrl + '?action=navid_nonces', { credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (n) { A.nameNonce = n && n.success ? n.data.name : ''; })
						.catch(function () {})
						.then(function () { window.setTimeout(function () { show('name'); nameForm.querySelector('input').focus(); }, 350); });
				} else {
					finish();
				}
			}).catch(fail).then(function () { setBusy(codeForm, false); });
		}
		codeForm.addEventListener('submit', function (e) { e.preventDefault(); verify(); });

		// ---- 3. name (new customers only)
		nameForm.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy) { return; }
			setBusy(nameForm, true);
			var fd = new FormData();
			fd.append('action', 'navid_save_name');
			fd.append('nonce', A.nameNonce || '');
			fd.append('name', nameForm.querySelector('input').value);
			fetch(A.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.success) { finish(); } else { say(res.data && res.data.message, false); }
				}).catch(fail).then(function () { setBusy(nameForm, false); });
		});

		function finish() {
			show('done');
			window.setTimeout(after, 1100);
		}
	}

	// ---- Signed-in customer without a number: confirm it with a code
	function initBind(box) {
		var phoneForm = box.querySelector('.navid-bind-phone');
		var codeForm = box.querySelector('.navid-bind-code');
		var status = box.querySelector('.navid-auth-status');
		var say = function (text, ok) {
			status.textContent = text || '';
			status.classList.toggle('is-ok', !!ok);
			status.classList.toggle('is-bad', !ok && !!text);
		};
		var fail = function () { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); };
		phoneForm.addEventListener('submit', function (e) {
			e.preventDefault();
			post('npt_bind_send', { phone: phoneForm.phone.value }).then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success) {
					phoneForm.hidden = true;
					codeForm.hidden = false;
					codeForm.code.focus();
				}
			}).catch(fail);
		});
		codeForm.addEventListener('submit', function (e) {
			e.preventDefault();
			post('npt_bind_done', { phone: phoneForm.phone.value, code: codeForm.code.value }).then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success) { window.setTimeout(function () { window.location.reload(); }, 900); }
			}).catch(fail);
		});
	}

	// ---- Pop-up
	function initModal() {
		var modal = document.getElementById('navid-login-modal');
		if (!modal) { return; }
		A.reload = true;
		var closeBtn = modal.querySelector('.navid-modal-close');

		var lastFocus = null;
		function open() {
			lastFocus = document.activeElement;
			modal.hidden = false;
			document.body.classList.add('navid-modal-open');
			window.requestAnimationFrame(function () { modal.classList.add('is-open'); });
			var first = modal.querySelector('input:not([type=hidden])');
			if (first) { window.setTimeout(function () { first.focus(); }, 120); }
		}
		function close() {
			modal.classList.remove('is-open');
			document.body.classList.remove('navid-modal-open');
			window.setTimeout(function () { modal.hidden = true; }, 200);
			// Keyboard users land back on the button that opened the pop-up.
			if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		}

		// Tab stays inside the pop-up while it is open.
		modal.addEventListener('keydown', function (e) {
			if (e.key !== 'Tab') { return; }
			var items = Array.prototype.filter.call(
				modal.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'),
				function (el) { return el.offsetParent !== null; }
			);
			if (!items.length) { return; }
			var first = items[0];
			var last = items[items.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		});

		var selector = '[data-navid-login], .mqb-otp-trigger, a[href="#mqb-otp"]';
		if (A.trigger) {
			try { document.querySelector(A.trigger); selector += ', ' + A.trigger; } catch (ignore) {}
		}
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest(selector);
			if (trigger) {
				e.preventDefault();
				open();
			}
		});
		closeBtn.addEventListener('click', close);
		modal.querySelector('.navid-modal-backdrop').addEventListener('click', close);
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) { close(); }
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-navid-card]'), initCard);
		Array.prototype.forEach.call(document.querySelectorAll('[data-navid-bind]'), initBind);
		initModal();
	});
})();
