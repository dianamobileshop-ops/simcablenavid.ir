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
})();
