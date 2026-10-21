/**
 * Cart page: turn the cart into a proforma without leaving the page.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var card = document.querySelector('[data-navid-pf]');
		if (!card || !window.NAVIDCART) {
			return;
		}
		var form = card.querySelector('.navid-pf-form');
		var status = card.querySelector('.navid-pf-status');
		var done = card.querySelector('.navid-pf-done');
		var btn = card.querySelector('.navid-pf-btn');

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			status.textContent = '';
			status.classList.remove('is-bad');
			var fd = new FormData(form);
			fd.append('action', 'npt_cart_proforma');
			fd.append('nonce', window.NAVIDCART.nonce);
			btn.disabled = true;
			var label = btn.textContent;
			btn.textContent = 'در حال ثبت…';
			fetch(window.NAVIDCART.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) {
						status.textContent = (res && res.data && res.data.message) || 'ثبت پیش‌فاکتور انجام نشد.';
						status.classList.add('is-bad');
						return;
					}
					form.hidden = true;
					card.querySelector('.navid-pf-num').textContent = 'پیش‌فاکتور شمارهٔ ' + res.data.number + ' ثبت شد.';
					card.querySelector('.navid-pf-view').href = res.data.short_url;
					card.querySelector('.navid-pf-checkout').href = res.data.checkout || '#';
					done.hidden = false;
				})
				.catch(function () {
					status.textContent = 'خطا در ارتباط با سرور. دوباره تلاش کنید.';
					status.classList.add('is-bad');
				})
				.then(function () {
					btn.disabled = false;
					btn.textContent = label;
				});
		});
	});
})();
