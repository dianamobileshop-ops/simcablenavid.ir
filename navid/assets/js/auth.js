/**
 * Login card (page and pop-up): tabs, SMS code login, SMS sign-up, and the pop-up itself.
 */
(function () {
	'use strict';

	var A = window.NAVIDAUTH || {};

	function post(action, data, withScene) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', A.nonce);
		if (withScene) { fd.append('scene', withScene); }
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(A.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	function initCard(card) {
		var tabs = card.querySelectorAll('[data-navid-tab]');
		var panels = card.querySelectorAll('[data-navid-panel]');
		Array.prototype.forEach.call(tabs, function (tab) {
			tab.addEventListener('click', function () {
				var key = tab.getAttribute('data-navid-tab');
				Array.prototype.forEach.call(tabs, function (t) { t.classList.toggle('is-on', t === tab); });
				Array.prototype.forEach.call(panels, function (p) { p.hidden = p.getAttribute('data-navid-panel') !== key; });
				if (key === 'register') { loadCaptcha(); }
			});
		});

		function after() {
			if (A.reload) { window.location.reload(); } else { window.location.href = A.redirect; }
		}

		// ---- SMS login
		var phoneForm = card.querySelector('.navid-otp-phone');
		var codeForm = card.querySelector('.navid-otp-code');
		var status = card.querySelector('.navid-auth-status:not(.navid-reg-status)');
		if (phoneForm && codeForm) {
			var phone = phoneForm.querySelector('input');
			var code = codeForm.querySelector('input');
			var resend = codeForm.querySelector('.navid-otp-resend');
			var timer = null;

			var say = function (text, ok) {
				status.textContent = text || '';
				status.classList.toggle('is-ok', !!ok);
				status.classList.toggle('is-bad', !ok && !!text);
			};
			var cooldown = function () {
				var left = A.cooldown || 60;
				window.clearInterval(timer);
				resend.disabled = true;
				resend.querySelector('span').textContent = left;
				timer = window.setInterval(function () {
					left--;
					resend.querySelector('span').textContent = left;
					if (left <= 0) { window.clearInterval(timer); resend.disabled = false; }
				}, 1000);
			};
			var send = function () {
				return post('npt_send_otp', { phone: phone.value }, 'login').then(function (res) {
					say(res.data && res.data.message, res.success);
					if (res.success) {
						phoneForm.hidden = true;
						codeForm.hidden = false;
						code.value = '';
						code.focus();
						cooldown();
					}
				}).catch(function () { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
			};
			phoneForm.addEventListener('submit', function (e) { e.preventDefault(); send(); });
			resend.addEventListener('click', send);
			codeForm.querySelector('.navid-otp-change').addEventListener('click', function () {
				codeForm.hidden = true;
				phoneForm.hidden = false;
				say('');
				phone.focus();
			});
			codeForm.addEventListener('submit', function (e) {
				e.preventDefault();
				post('npt_verify_otp', { phone: phone.value, code: code.value }, 'login').then(function (res) {
					say(res.data && res.data.message, res.success);
					if (res.success && res.data && res.data.loggedIn) { after(); }
				}).catch(function () { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
			});
		}

		// ---- SMS sign-up
		var regForm = card.querySelector('.navid-reg-form');
		var regCode = card.querySelector('.navid-reg-code');
		var regStatus = card.querySelector('.navid-reg-status');
		var capLoaded = false;
		function loadCaptcha() {
			if (!regForm) { return; }
			post('npt_captcha', {}).then(function (res) {
				if (res && res.success) {
					regForm.querySelector('.navid-cap-q').textContent = res.data.q;
					regForm.querySelector('[name=captcha_id]').value = res.data.id;
					capLoaded = true;
				}
			}).catch(function () {});
		}
		if (regForm && regCode) {
			var rsay = function (text, ok) {
				regStatus.textContent = text || '';
				regStatus.classList.toggle('is-ok', !!ok);
				regStatus.classList.toggle('is-bad', !ok && !!text);
			};
			regForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var d = {};
				['name', 'phone', 'captcha_id', 'captcha_answer', 'npt_website'].forEach(function (k) {
					d[k] = regForm.querySelector('[name=' + k + ']').value;
				});
				post('npt_register_send', d, 'register').then(function (res) {
					rsay(res.data && res.data.message, res.success);
					if (res.success) {
						regForm.hidden = true;
						regCode.hidden = false;
						regCode.querySelector('input').focus();
					} else {
						loadCaptcha();
					}
				}).catch(function () { rsay('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
			});
			regCode.addEventListener('submit', function (e) {
				e.preventDefault();
				post('npt_register_done', {
					name: regForm.querySelector('[name=name]').value,
					phone: regForm.querySelector('[name=phone]').value,
					code: regCode.querySelector('input').value,
					npt_website: ''
				}, 'register').then(function (res) {
					rsay(res.data && res.data.message, res.success);
					if (res.success) { after(); }
				}).catch(function () { rsay('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
			});
		}
	}

	// ---- Pop-up
	function initModal() {
		var modal = document.getElementById('navid-login-modal');
		if (!modal) { return; }
		A.reload = true;
		var closeBtn = modal.querySelector('.navid-modal-close');

		function open() {
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
		}

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('[data-navid-login]');
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
		initModal();
	});
})();
