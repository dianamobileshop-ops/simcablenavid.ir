(function ($) {
	'use strict';

	function bindEditing($wrap) {
		$wrap.off('click.npt', '.npt-edit-btn').on('click.npt', '.npt-edit-btn', function () {
			var $row = $(this).closest('.npt-row');
			$row.addClass('npt-editing');
			$row.find('.npt-price-input').show().focus();
			$row.find('.npt-edit-btn').hide();
			$row.find('.npt-save-btn, .npt-cancel-btn').show();
		});

		$wrap.off('click.npt', '.npt-cancel-btn').on('click.npt', '.npt-cancel-btn', function () {
			var $row = $(this).closest('.npt-row');
			cancelEdit($row);
		});

		$wrap.off('click.npt', '.npt-save-btn').on('click.npt', '.npt-save-btn', function () {
			var $row = $(this).closest('.npt-row');
			saveEdit($row);
		});

		$wrap.off('keydown.npt', '.npt-price-input').on('keydown.npt', '.npt-price-input', function (e) {
			var $row = $(this).closest('.npt-row');
			if (e.key === 'Enter') {
				e.preventDefault();
				saveEdit($row);
			} else if (e.key === 'Escape') {
				cancelEdit($row);
			}
		});
	}

	function cancelEdit($row) {
		$row.removeClass('npt-editing');
		$row.find('.npt-price-input').hide();
		$row.find('.npt-save-btn, .npt-cancel-btn').hide();
		$row.find('.npt-edit-btn').show();
	}

	function saveEdit($row) {
		var productId = $row.data('product-id');
		var $input = $row.find('.npt-price-input');
		var newPrice = $input.val();

		if (newPrice === '' || isNaN(newPrice) || Number(newPrice) < 0) {
			alert(NPT.i18n.invalid);
			return;
		}

		// The input shows the configured display unit (Rial = ×10); the
		// server always stores Toman, so convert back before sending.
		var factor = Number(NPT.currencyFactor) || 1;
		var storedPrice = String(Number(newPrice) / factor);

		$row.addClass('npt-saving');

		$.post(NPT.ajaxUrl, {
			action: 'npt_update_price',
			nonce: NPT.nonce,
			product_id: productId,
			price: storedPrice
		}).done(function (res) {
			$row.removeClass('npt-saving');
			if (res.success) {
				// Server always returns the bare number (Persian digits, no
				// currency word); the mobile row layout appends "تومان"
				// itself since it has no column header to carry that label.
				var priceText = res.data.price_display;
				// Desktop and mobile render the same product as two separate
				// rows; update every twin (display + edit input) so a stale
				// value can never be re-saved from the other layout.
				var $wrap = $row.closest('.npt-wrap');
				$wrap.find('.npt-row[data-product-id="' + productId + '"]').each(function () {
					var $twin = $(this);
					var text = $twin.hasClass('npt-mobile-row') ? priceText + ' ' + NPT.i18n.currency : priceText;
					$twin.find('.npt-price-display').text(text);
					$twin.find('.npt-price-input').val(newPrice);
				});
				if (res.data && res.data.date_display) {
					$wrap.find('.npt-update-badge strong').text(res.data.date_display);
				}
				cancelEdit($row);
			} else {
				alert((res.data && res.data.message) || NPT.i18n.error);
			}
		}).fail(function () {
			$row.removeClass('npt-saving');
			alert(NPT.i18n.error);
		});
	}

	function updatePdfLink($wrap, category) {
		var cat = encodeURIComponent(category || '');
		var $btn = $wrap.find('.npt-pdf-btn');
		if ($btn.length) {
			// No nonce here on purpose: this is a public, read-only download and
			// the link is rendered into HTML that page-caching plugins cache for
			// hours, so a short-lived nonce would go stale before the cache does.
			// The endpoint is protected server-side with a per-IP rate limit instead.
			$btn.attr('href', NPT.ajaxUrl +
				'?action=npt_download_pdf' +
				'&category=' + cat);
		}
		var $view = $wrap.find('.npt-view-btn');
		if ($view.length) {
			$view.attr('href', NPT.ajaxUrl +
				'?action=npt_online_view' +
				'&category=' + cat);
		}
	}

	function bindFilter($wrap) {
		$wrap.off('change.npt', '.npt-cat-filter').on('change.npt', '.npt-cat-filter', function () {
			var category = $(this).val();
			var $holder = $wrap.find('.npt-table-holder');
			var $loading = $wrap.find('.npt-loading');

			updatePdfLink($wrap, category);
			$loading.show();

			$.post(NPT.ajaxUrl, {
				action: 'npt_filter',
				nonce: NPT.nonce,
				category: category
			}).done(function (res) {
				$loading.hide();
				if (res.success) {
					$holder.html(res.data.html);
					bindEditing($wrap);
				}
			}).fail(function () {
				$loading.hide();
			});
		});
	}

	$(function () {
		$('.npt-wrap').each(function () {
			var $wrap = $(this);
			bindFilter($wrap);
			bindEditing($wrap);
		});
	});
})(jQuery);
