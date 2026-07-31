/**
 * Scripts administrativos para Aurafact WooCommerce.
 *
 * Gestiona la prueba de conexión AJAX y la verificación de conectividad.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
(function ($) {
	'use strict';

	/**
	 * Inicializa los eventos de la pantalla de configuración.
	 */
	function init() {
		var $testButton = $('#aurafact-wc-test-connection');
		var $resultSpan = $('#aurafact-wc-test-result');

		if (!$testButton.length) {
			return;
		}

		$testButton.on('click', function (e) {
			e.preventDefault();

			$testButton.prop('disabled', true);
			$resultSpan
				.html(
					'<span style="color: #666;">' +
						aurafactWcAdmin.i18n.testing +
						'</span>'
				);

			$.ajax({
				url: aurafactWcAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'aurafact_wc_test_connection',
					nonce: aurafactWcAdmin.nonce,
				},
				success: function (response) {
					if (response.success) {
						$resultSpan.html(
							'<span style="color: #46b450; font-weight: bold;">✓ ' +
								response.data.message +
								'</span>'
						);
						$('#aurafact-wc-connectivity-status').html(
							'<span style="color: #46b450; font-weight: bold;">✓ ' +
								aurafactWcAdmin.i18n.success +
								'</span>'
						);
					} else {
						$resultSpan.html(
							'<span style="color: #dc3232; font-weight: bold;">✗ ' +
								response.data.message +
								'</span>'
						);
						$('#aurafact-wc-connectivity-status').html(
							'<span style="color: #dc3232; font-weight: bold;">✗ ' +
								aurafactWcAdmin.i18n.error +
								'</span>'
						);
					}
				},
				error: function () {
					$resultSpan.html(
						'<span style="color: #dc3232; font-weight: bold;">✗ ' +
							aurafactWcAdmin.i18n.error +
							'</span>'
					);
				},
				complete: function () {
					$testButton.prop('disabled', false);
				},
			});
		});
	}

	$(document).ready(init);
})(jQuery);