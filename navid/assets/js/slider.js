/**
 * Banner slider: auto-advance, dots, swipe. No libraries.
 */
(function () {
	'use strict';

	var DELAY = 5000;

	function init(root) {
		var slides = root.querySelectorAll('.navid-slide');
		var dots = root.querySelectorAll('.navid-dot');
		if (slides.length < 2) {
			return;
		}
		var index = 0;
		var timer = null;

		function show(next) {
			index = (next + slides.length) % slides.length;
			Array.prototype.forEach.call(slides, function (el, i) {
				el.classList.toggle('is-on', i === index);
			});
			Array.prototype.forEach.call(dots, function (el, i) {
				el.classList.toggle('is-on', i === index);
			});
		}

		function start() {
			stop();
			timer = window.setInterval(function () { show(index + 1); }, DELAY);
		}

		function stop() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		Array.prototype.forEach.call(dots, function (dot, i) {
			dot.addEventListener('click', function () {
				show(i);
				start();
			});
		});

		var startX = null;
		root.addEventListener('touchstart', function (e) {
			startX = e.touches[0].clientX;
			stop();
		}, { passive: true });
		root.addEventListener('touchend', function (e) {
			if (startX === null) {
				return;
			}
			var dx = e.changedTouches[0].clientX - startX;
			startX = null;
			if (Math.abs(dx) > 40) {
				// Right-to-left layout: swiping left goes to the previous slide.
				show(dx > 0 ? index + 1 : index - 1);
			}
			start();
		});

		root.addEventListener('mouseenter', stop);
		root.addEventListener('mouseleave', start);
		start();
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-navid-slider]'), init);
	});
})();
