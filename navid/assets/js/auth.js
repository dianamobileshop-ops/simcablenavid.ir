/**
 * Login page: tabs and the SMS code login (uses the site's existing OTP endpoints).
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var card = document.querySelector('.navid-auth-card');
		if (!card) {
			return;
		}

		var tabs = card.querySelectorAll('[data-navid-tab]');
		var panels = card.querySelectorAll('[data-navid-panel]');
		Array.prototype.forEach.call(tabs, function (tab) {
			tab.addEventListener('click', function () {
				var key = tab.getAttribute('data-navid-tab');
				Array.prototype.forEach.call(tabs, function (t) { t.classList.toggle('is-on', t === tab); });
				Array.prototype.forEach.call(panels, function (p) { p.hidden = p.getAttribute('data-navid-panel') !== key; });
			});
		});

		var A = window.NAVIDAUTH;
		var phoneForm = card.querySelector('.navid-otp-phone');
		var codeForm = card.querySelector('.navid-otp-code');
		if (!A || !phoneForm || !codeForm) {
			return;
		}
		var phone = phoneForm.querySelector('input');
		var code = codeForm.querySelector('input');
		var status = card.querySelector('.navid-auth-status');
		var resend = codeForm.querySelector('.navid-otp-resend');
		var timer = null;

		function say(text, ok) {
			status.textContent = text || '';
			status.classList.toggle('is-ok', !!ok);
			status.classList.toggle('is-bad', !ok && !!text);
		}

		function request(action, data) {
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', A.nonce);
			fd.append('scene', 'login');
			Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
			return fetch(A.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
		}

		function cooldown() {
			var left = A.cooldown || 60;
			window.clearInterval(timer);
			resend.disabled = true;
			resend.querySelector('span').textContent = left;
			timer = window.setInterval(function () {
				left--;
				resend.querySelector('span').textContent = left;
				if (left <= 0) {
					window.clearInterval(timer);
					resend.disabled = false;
				}
			}, 1000);
		}

		function send() {
			return request('npt_send_otp', { phone: phone.value }).then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success) {
					phoneForm.hidden = true;
					codeForm.hidden = false;
					code.value = '';
					code.focus();
					cooldown();
				}
			}).catch(function () { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
		}

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
			request('npt_verify_otp', { phone: phone.value, code: code.value }).then(function (res) {
				say(res.data && res.data.message, res.success);
				if (res.success && res.data && res.data.loggedIn) {
					window.location.href = A.redirect;
				}
			}).catch(function () { say('خطا در ارتباط با سرور. دوباره تلاش کنید.'); });
		});
	});
})();
