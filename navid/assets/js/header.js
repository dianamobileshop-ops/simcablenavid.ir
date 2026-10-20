/**
 * Mobile menu toggle, sub-menu accordions and the category panel.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var toggle = document.querySelector('[data-navid-menu-toggle]');
		var nav = document.getElementById('navid-nav');
		if (toggle && nav) {
			toggle.addEventListener('click', function () {
				var open = toggle.getAttribute('aria-expanded') === 'true';
				toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
				nav.classList.toggle('is-open', !open);
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
})();
