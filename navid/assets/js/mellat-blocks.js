/**
 * Mellat in the Checkout block: label and description; payment itself runs on the server.
 */
(function () {
	'use strict';
	var wc = window.wc || {};
	if (!wc.wcBlocksRegistry || !wc.wcSettings || !window.wp || !window.wp.element) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wc.wcSettings.getSetting('navid_mellat_data', {});
	var title = decode(data.title || 'پرداخت آنلاین (بانک ملت)');

	var Label = function () {
		return el('span', { style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			data.icon ? el('img', { src: data.icon, alt: '', width: 32, height: 22 }) : null,
			title
		);
	};
	var Content = function () {
		return el('div', null, decode(data.description || ''));
	};

	wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'navid_mellat',
		label: el(Label),
		content: el(Content),
		edit: el(Content),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: data.supports || ['products'] }
	});
})();
