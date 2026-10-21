/**
 * Banner slider: slide (scroll-snap), cards and fade modes, arrows, dots, progress bar,
 * autoplay that pauses on hover/focus, keyboard arrows. No libraries.
 */
(function () {
	'use strict';

	function init(root) {
		var track = root.querySelector('.navid-slider-track');
		var slides = root.querySelectorAll('.navid-slide');
		var dots = root.querySelectorAll('.navid-dot');
		var prev = root.querySelector('.navid-arrow--prev');
		var next = root.querySelector('.navid-arrow--next');
		var bar = root.querySelector('.navid-progress span');
		var mode = root.getAttribute('data-mode') || 'slide';
		var speed = parseInt(root.getAttribute('data-speed'), 10) || 5000;
		var autoplay = root.getAttribute('data-autoplay') === '1' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var n = slides.length;
		if (n < 2) {
			return;
		}
		var index = 0;
		var timer = null;
		var paused = false;

		function mark(i) {
			index = i;
			Array.prototype.forEach.call(slides, function (el, k) {
				el.classList.toggle('is-on', k === i);
			});
			Array.prototype.forEach.call(dots, function (el, k) {
				el.classList.toggle('is-on', k === i);
			});
		}

		function restartBar() {
			if (!bar) {
				return;
			}
			bar.style.animation = 'none';
			void bar.offsetWidth;
			bar.style.animation = autoplay && !paused ? 'navid-progress ' + speed + 'ms linear forwards' : 'none';
		}

		function go(i) {
			i = (i + n) % n;
			mark(i);
			if (mode !== 'fade') {
				// Scroll the track only: scrollIntoView() also scrolled the page, so autoplay
				// kept pulling a visitor who had scrolled down back to the slider.
				var t = track.getBoundingClientRect();
				var s = slides[i].getBoundingClientRect();
				track.scrollBy({ left: (s.left + s.width / 2) - (t.left + t.width / 2), behavior: 'smooth' });
			}
			restartBar();
		}

		function start() {
			stop();
			if (autoplay && !paused) {
				timer = window.setInterval(function () { go(index + 1); }, speed);
				restartBar();
			}
		}

		function stop() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		Array.prototype.forEach.call(dots, function (dot, i) {
			dot.addEventListener('click', function () { go(i); start(); });
		});
		// RTL: the arrow on the right goes back, the one on the left goes forward.
		if (prev) { prev.addEventListener('click', function () { go(index - 1); start(); }); }
		if (next) { next.addEventListener('click', function () { go(index + 1); start(); }); }

		// Swiping scrolls the track itself; the dots follow whichever slide is in view.
		if (mode !== 'fade' && 'IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						var k = Array.prototype.indexOf.call(slides, entry.target);
						if (k !== index) {
							mark(k);
							restartBar();
						}
					}
				});
			}, { root: track, threshold: 0.6 });
			Array.prototype.forEach.call(slides, function (s) { io.observe(s); });
		} else if (mode === 'fade') {
			var startX = null;
			root.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
			root.addEventListener('touchend', function (e) {
				if (startX === null) { return; }
				var dx = e.changedTouches[0].clientX - startX;
				startX = null;
				if (Math.abs(dx) > 40) { go(dx > 0 ? index + 1 : index - 1); start(); }
			});
		}

		root.addEventListener('mouseenter', function () { paused = true; stop(); restartBar(); });
		root.addEventListener('mouseleave', function () { paused = false; start(); });
		root.addEventListener('focusin', function () { paused = true; stop(); });
		root.addEventListener('focusout', function () { paused = false; start(); });
		root.setAttribute('tabindex', '0');
		root.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { go(index + 1); start(); }
			if (e.key === 'ArrowRight') { go(index - 1); start(); }
		});
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) { stop(); } else { start(); }
		});

		start();
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-navid-slider]'), init);
	});
})();
