/**
 * Mobile menu toggle, sub-menu accordions and the category panel.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var toggle = document.querySelector('[data-navid-menu-toggle]');
		var nav = document.getElementById('navid-nav');
		if (toggle && nav) {
			// Phones: the menu is a drawer that slides in from the right.
			var backdrop = document.createElement('div');
			backdrop.className = 'navid-nav-backdrop';
			document.body.appendChild(backdrop);
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'navid-nav-close';
			closeBtn.setAttribute('aria-label', 'بستن منو');
			closeBtn.textContent = '×';
			nav.insertBefore(closeBtn, nav.firstChild);

			var setOpen = function (open) {
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				nav.classList.toggle('is-open', open);
				backdrop.classList.toggle('is-on', open);
				document.body.classList.toggle('navid-nav-open', open);
			};
			toggle.addEventListener('click', function () {
				setOpen(toggle.getAttribute('aria-expanded') !== 'true');
			});
			closeBtn.addEventListener('click', function () { setOpen(false); });
			backdrop.addEventListener('click', function () { setOpen(false); });
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { setOpen(false); }
			});
			window.matchMedia('(min-width: 769px)').addEventListener('change', function (e) {
				if (e.matches) { setOpen(false); }
			});
		}

		// Sub-menus are closed until opened: hover on desktop, a button on phones.
		Array.prototype.forEach.call(document.querySelectorAll('.navid-menu .menu-item-has-children'), function (li) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'navid-submenu-toggle';
			btn.setAttribute('aria-label', 'باز و بسته کردن زیرمنو');
			btn.textContent = '▾';
			btn.addEventListener('click', function () {
				li.classList.toggle('is-open');
			});
			var sub = li.querySelector(':scope > .sub-menu');
			if (sub) {
				li.insertBefore(btn, sub);
			}
		});

		// Search in the mobile tab bar opens and focuses the header search field.
		var searchTab = document.querySelector('[data-navid-search]');
		var searchInput = document.getElementById('navid-s');
		if (searchTab && searchInput) {
			searchTab.addEventListener('click', function (e) {
				e.preventDefault();
				document.body.classList.toggle('navid-search-open');
				window.scrollTo({ top: 0, behavior: 'smooth' });
				searchInput.focus();
			});
		}

		// Back to top (footer).
		Array.prototype.forEach.call(document.querySelectorAll('[data-navid-totop]'), function (btn) {
			btn.addEventListener('click', function () {
				var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
			});
		});

		var cats = document.querySelector('.navid-catmenu');
		if (cats) {
			document.addEventListener('click', function (e) {
				if (!cats.contains(e.target)) {
					cats.removeAttribute('open');
				}
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					cats.removeAttribute('open');
				}
			});
		}
	});

	// ---- Live product search: suggestions while typing.
	document.addEventListener('DOMContentLoaded', function () {
		var input = document.getElementById('navid-s');
		var box = input && input.parentNode.querySelector('.navid-live');
		if (!input || !box || !window.NAVID) {
			return;
		}
		var timer = null;
		var last = '';

		function hide() {
			box.hidden = true;
			box.textContent = '';
			// Typing the same text again must ask the server again.
			last = '';
		}

		function show(items, q) {
			box.textContent = '';
			if (!items.length) {
				var empty = document.createElement('div');
				empty.className = 'navid-live-empty';
				empty.textContent = 'محصولی پیدا نشد';
				box.appendChild(empty);
			}
			items.forEach(function (p) {
				var a = document.createElement('a');
				a.href = p.url;
				a.className = 'navid-live-item';
				var img = document.createElement('img');
				img.src = p.image;
				img.alt = '';
				img.loading = 'lazy';
				var name = document.createElement('span');
				name.className = 'navid-live-name';
				name.textContent = p.name;
				var price = document.createElement('span');
				price.className = 'navid-live-price';
				price.textContent = p.price;
				a.appendChild(img);
				a.appendChild(name);
				a.appendChild(price);
				box.appendChild(a);
			});
			var all = document.createElement('a');
			all.className = 'navid-live-all';
			all.href = input.form.action + '?post_type=product&s=' + encodeURIComponent(q);
			all.textContent = 'مشاهده همهٔ نتایج';
			box.appendChild(all);
			box.hidden = false;
		}

		input.addEventListener('input', function () {
			var q = input.value.trim();
			window.clearTimeout(timer);
			if (q.length < 2) {
				hide();
				return;
			}
			timer = window.setTimeout(function () {
				if (q === last) {
					return;
				}
				last = q;
				fetch(window.NAVID.ajaxUrl + '?action=navid_live_search&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (input.value.trim() === q && res && res.success) {
							show(res.data, q);
						}
					})
					.catch(hide);
			}, 250);
		});

		document.addEventListener('click', function (e) {
			if (!box.contains(e.target) && e.target !== input) {
				hide();
			}
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				hide();
			}
		});
	});
})();

/* Footer: sections fold on phones and stay open on desktop. */
(function () {
	var mq = window.matchMedia('(max-width: 768px)');
	function sync() {
		Array.prototype.forEach.call(document.querySelectorAll('.navid-fold'), function (d) {
			d.open = !mq.matches;
		});
	}
	sync();
	if (mq.addEventListener) {
		mq.addEventListener('change', sync);
	}
})();
