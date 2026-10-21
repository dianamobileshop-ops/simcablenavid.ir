/**
 * Shop pages: attribute filters and variation chips.
 */
(function () {
	'use strict';

	function trigger(el) {
		if (window.jQuery) {
			window.jQuery(el).trigger('change');
		} else {
			el.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	// ---- Category filters: checkboxes become one comma list per attribute (what WooCommerce reads).
	function initFilters() {
		var form = document.querySelector('.navid-filters form');
		if (!form) {
			return;
		}
		form.addEventListener('submit', function () {
			Array.prototype.forEach.call(form.querySelectorAll('[data-navid-attr]'), function (box) {
				var name = box.getAttribute('data-navid-attr');
				var values = [];
				Array.prototype.forEach.call(box.querySelectorAll('input[type=checkbox]:checked'), function (cb) {
					values.push(cb.value);
				});
				if (!values.length) {
					return;
				}
				[['filter_' + name, values.join(',')], ['query_type_' + name, 'or']].forEach(function (pair) {
					var input = document.createElement('input');
					input.type = 'hidden';
					input.name = pair[0];
					input.value = pair[1];
					form.appendChild(input);
				});
			});
		});
	}

	// ---- Variation selects as chips (sizes etc.). The select stays in the page, hidden.
	function renderChips(select) {
		var holder = select.parentNode.querySelector('.navid-var-chips');
		if (!holder) {
			holder = document.createElement('div');
			holder.className = 'navid-var-chips';
			select.parentNode.insertBefore(holder, select);
			select.classList.add('navid-var-hidden');
		}
		holder.textContent = '';
		Array.prototype.forEach.call(select.options, function (opt) {
			if (!opt.value) {
				return;
			}
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'navid-var-chip' + (opt.value === select.value ? ' is-on' : '');
			chip.textContent = opt.textContent;
			chip.addEventListener('click', function () {
				select.value = (select.value === opt.value) ? '' : opt.value;
				trigger(select);
				renderChips(select);
			});
			holder.appendChild(chip);
		});
	}

	function initVariations() {
		var forms = document.querySelectorAll('form.variations_form');
		Array.prototype.forEach.call(forms, function (form) {
			var selects = form.querySelectorAll('table.variations select');
			var refresh = function () {
				Array.prototype.forEach.call(selects, renderChips);
			};
			refresh();
			if (window.jQuery) {
				window.jQuery(form).on('woocommerce_update_variation_values reset_data found_variation', function () {
					window.setTimeout(refresh, 0);
				});
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initFilters();
		initVariations();
	});

	// ---- Add to cart: quantity stepper, smooth AJAX add with a "flying" product, sticky bar on phones.
	var T = window.NAVIDSHOP || {};

	function toast(text, bad) {
		var old = document.querySelector('.navid-toast');
		if (old) { old.remove(); }
		var box = document.createElement('div');
		box.className = 'navid-toast' + (bad ? ' is-bad' : '');
		var span = document.createElement('span');
		span.textContent = text;
		box.appendChild(span);
		if (!bad && T.cartUrl) {
			var a = document.createElement('a');
			a.href = T.cartUrl;
			a.textContent = T.view;
			box.appendChild(a);
		}
		document.body.appendChild(box);
		window.requestAnimationFrame(function () { box.classList.add('is-in'); });
		window.setTimeout(function () {
			box.classList.remove('is-in');
			window.setTimeout(function () { box.remove(); }, 300);
		}, 3500);
	}

	function visible(el) {
		return el && el.offsetParent !== null && el.getBoundingClientRect().width > 0;
	}

	function cartTarget() {
		var list = document.querySelectorAll('.navid-mcart, .navid-cart, .navid-tab-cart');
		for (var i = 0; i < list.length; i++) {
			if (visible(list[i])) { return list[i]; }
		}
		return null;
	}

	function fly(img) {
		var target = cartTarget();
		if (!img || !target || !img.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			return;
		}
		var a = img.getBoundingClientRect();
		var b = target.getBoundingClientRect();
		var clone = img.cloneNode(false);
		clone.removeAttribute('srcset');
		clone.removeAttribute('sizes');
		clone.className = 'navid-fly';
		clone.style.cssText = 'position:fixed;z-index:9999;pointer-events:none;border-radius:12px;object-fit:contain;'
			+ 'left:' + a.left + 'px;top:' + a.top + 'px;width:' + a.width + 'px;height:' + a.height + 'px;';
		document.body.appendChild(clone);
		var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
		var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
		var anim = clone.animate([
			{ transform: 'translate(0,0) scale(1)', opacity: 1 },
			{ transform: 'translate(' + (dx * 0.5) + 'px,' + (dy * 0.5 - 60) + 'px) scale(.45)', opacity: .9, offset: 0.55 },
			{ transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.08)', opacity: .2 }
		], { duration: 850, easing: 'cubic-bezier(.4,0,.2,1)' });
		anim.onfinish = function () {
			clone.remove();
			target.classList.add('navid-bump');
			window.setTimeout(function () { target.classList.remove('navid-bump'); }, 500);
		};
	}

	function refreshFragments() {
		if (!T.fragments) { return; }
		fetch(T.fragments, { method: 'POST', credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.fragments) { return; }
				Object.keys(data.fragments).forEach(function (sel) {
					Array.prototype.forEach.call(document.querySelectorAll(sel), function (el) {
						el.outerHTML = data.fragments[sel];
					});
				});
			})
			.catch(function () {});
	}

	function initStepper(form) {
		var input = form.querySelector('.quantity input.qty');
		if (!input || input.type === 'hidden' || input.closest('.navid-qty')) { return; }
		var wrap = document.createElement('span');
		wrap.className = 'navid-qty';
		input.parentNode.insertBefore(wrap, input);
		var minus = document.createElement('button');
		var plus = document.createElement('button');
		minus.type = plus.type = 'button';
		minus.textContent = '−';
		plus.textContent = '+';
		minus.setAttribute('aria-label', 'کمتر');
		plus.setAttribute('aria-label', 'بیشتر');
		wrap.appendChild(plus);
		wrap.appendChild(input);
		wrap.appendChild(minus);
		function step(dir) {
			var st = parseFloat(input.step) || 1;
			var min = input.min !== '' ? parseFloat(input.min) : 0;
			var max = input.max !== '' ? parseFloat(input.max) : Infinity;
			var val = parseFloat(input.value) || min;
			val = Math.min(max, Math.max(min, val + dir * st));
			input.value = String(Math.round(val * 1000) / 1000);
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}
		minus.addEventListener('click', function () { step(-1); });
		plus.addEventListener('click', function () { step(1); });
	}

	function initCart() {
		var form = document.querySelector('.navid-buybox form.cart');
		if (!form) { return; }
		var btn = form.querySelector('.single_add_to_cart_button');
		if (!btn) { return; }
		initStepper(form);
		// Variable products build their quantity box later; try again when it appears.
		if (window.jQuery) {
			window.jQuery(form).on('show_variation', function () { initStepper(form); });
		}

		var label = btn.textContent;
		var busy = false;

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy || btn.classList.contains('disabled') || btn.disabled) { return; }
			var addId = (btn.name === 'add-to-cart' && btn.value) ? btn.value : '';
			var hidden = form.querySelector('input[name="add-to-cart"]');
			if (!addId && hidden) { addId = hidden.value; }
			var fd = new FormData(form);
			if (addId) { fd.set('add-to-cart', addId); }
			busy = true;
			btn.classList.add('is-loading');
			btn.textContent = T.adding || '…';
			var img = document.querySelector('.navid-product-gallery .woocommerce-product-gallery__image img, .navid-product-gallery img');

			fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.text().then(function (t) { return { r: r, t: t }; }); })
				.then(function (res) {
					var doc = new DOMParser().parseFromString(res.t, 'text/html');
					var err = doc.querySelector('.woocommerce-error');
					if (err) {
						toast(err.textContent.trim().replace(/\s+/g, ' '), true);
						return;
					}
					if (res.r.redirected && res.r.url && res.r.url.split('#')[0] !== window.location.href.split('#')[0]) {
						window.location.href = res.r.url;
						return;
					}
					fly(img);
					refreshFragments();
					btn.classList.add('is-done');
					btn.textContent = '✓ ' + (T.added || '');
					toast(T.added || 'OK', false);
					window.setTimeout(function () { btn.classList.remove('is-done'); btn.textContent = label; }, 2200);
				})
				.catch(function () { toast(T.error || 'Error', true); })
				.then(function () {
					busy = false;
					btn.classList.remove('is-loading');
					if (btn.textContent === (T.adding || '…')) { btn.textContent = label; }
				});
		});

		// Sticky purchase bar on phones, shown while the real button is out of view.
		if (window.matchMedia('(max-width: 768px)').matches && 'IntersectionObserver' in window) {
			var bar = document.createElement('div');
			bar.className = 'navid-stickybar';
			var priceEl = document.createElement('span');
			priceEl.className = 'navid-stickybar-price';
			var go = document.createElement('button');
			go.type = 'button';
			go.textContent = T.addLabel || label;
			bar.appendChild(priceEl);
			bar.appendChild(go);
			document.body.appendChild(bar);
			var priceSrc = document.querySelector('.navid-buybox-price');
			function readPrice() { priceEl.textContent = priceSrc ? priceSrc.textContent.trim().replace(/\s+/g, ' ').slice(0, 40) : ''; }
			go.addEventListener('click', function () {
				if (btn.classList.contains('disabled') || btn.disabled) {
					form.scrollIntoView({ behavior: 'smooth', block: 'center' });
				} else {
					btn.click();
				}
			});
			new IntersectionObserver(function (entries) {
				readPrice();
				bar.classList.toggle('is-in', !entries[0].isIntersecting && entries[0].boundingClientRect.top < 0);
			}).observe(btn);
		}
	}

	document.addEventListener('DOMContentLoaded', initCart);
})();
