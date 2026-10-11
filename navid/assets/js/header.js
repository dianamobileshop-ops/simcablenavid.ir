/**
 * Mobile menu toggle.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var toggle = document.querySelector('[data-navid-menu-toggle]');
		var nav = document.getElementById('navid-nav');
		if (!toggle || !nav) {
			return;
		}
		toggle.addEventListener('click', function () {
			var open = toggle.getAttribute('aria-expanded') === 'true';
			toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
			nav.classList.toggle('is-open', !open);
		});
	});
})();
