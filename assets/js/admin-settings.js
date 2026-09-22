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

		var $clearCacheButton = $('#aurafact-wc-clear-sri-cache');
		var $clearCacheResult = $('#aurafact-wc-clear-cache-result');

		if ($clearCacheButton.length) {
			$clearCacheButton.on('click', function (e) {
				e.preventDefault();

				$clearCacheButton.prop('disabled', true);
				$clearCacheResult.html(
					'<span style="color: #666;">' +
						aurafactWcAdmin.i18n.clearing +
						'</span>'
				);

				$.ajax({
					url: aurafactWcAdmin.ajaxUrl,
					type: 'POST',
					data: {
						action: 'aurafact_wc_clear_sri_cache',
						nonce: aurafactWcAdmin.clearCacheNonce,
					},
					success: function (response) {
						if (response.success) {
							$clearCacheResult.html(
								'<span style="color: #46b450; font-weight: bold;">✓ ' +
									aurafactWcAdmin.i18n.cacheCleared +
									'</span>'
							);
						} else {
							$clearCacheResult.html(
								'<span style="color: #dc3232; font-weight: bold;">✗ ' +
									(response.data && response.data.message ? response.data.message : aurafactWcAdmin.i18n.cacheError) +
									'</span>'
							);
						}
					},
					error: function () {
						$clearCacheResult.html(
							'<span style="color: #dc3232; font-weight: bold;">✗ ' +
								aurafactWcAdmin.i18n.cacheError +
								'</span>'
						);
					},
					complete: function () {
						$clearCacheButton.prop('disabled', false);
					},
				});
			});
		}
	}

	$(document).ready(init);
})(jQuery);