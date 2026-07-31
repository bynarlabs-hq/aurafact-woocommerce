/**
 * Validación fiscal ecuatoriana en el checkout de WooCommerce.
 *
 * Valida en tiempo real los campos de identificación fiscal (cédula, RUC,
 * pasaporte, consumidor final) y maneja la visibilidad condicional de la
 * razón social.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
(function ($) {
	'use strict';

	var AurafactCheckout = {
		/**
		 * Inicializa los eventos del checkout.
		 */
		init: function () {
			this.bindEvents();
		},

		/**
		 * Registra los eventos del checkout.
		 */
		bindEvents: function () {
			var self = this;

			// Al actualizar el checkout (por cambio de campos).
			$(document.body).on('updated_checkout', function () {
				self.setupFields();
			});

			// Delegación de eventos para campos dinámicos.
			$(document.body).on(
				'change',
				'#billing_doc_type',
				function () {
					self.onDocTypeChange();
				}
			);

			$(document.body).on(
				'input',
				'#billing_doc_number',
				function () {
					self.onDocNumberInput();
				}
			);
		},

		/**
		 * Configura los campos después de una actualización del checkout.
		 */
		setupFields: function () {
			var docType = $('#billing_doc_type').val();
			this.toggleBusinessName(docType);

			// Si ya hay un valor, ejecutar validación.
			var docNumber = $('#billing_doc_number').val();
			if (docType && docNumber) {
				this.validateField(docType, docNumber);
			}
		},

		/**
		 * Maneja el cambio de tipo de documento.
		 */
		onDocTypeChange: function () {
			var docType = $('#billing_doc_type').val();
			var $docNumber = $('#billing_doc_number');

			// Limpiar y resetear validación.
			$docNumber.val('');
			this.clearError($docNumber);
			this.toggleBusinessName(docType);

			// Configurar placeholder y maxlength según tipo.
			switch (docType) {
				case '04': // RUC.
					$docNumber.attr('maxlength', 13);
					$docNumber.attr(
						'placeholder',
						'13 dígitos (ej. 1234567890001)'
					);
					break;
				case '05': // Cédula.
					$docNumber.attr('maxlength', 10);
					$docNumber.attr(
						'placeholder',
						'10 dígitos (ej. 1234567890)'
					);
					break;
				case '06': // Pasaporte.
					$docNumber.attr('maxlength', 20);
					$docNumber.attr('placeholder', '');
					break;
				case '07': // Consumidor Final.
					$docNumber.attr('maxlength', 13);
					$docNumber.val('9999999999999');
					this.clearError($docNumber);
					break;
				default:
					$docNumber.attr('maxlength', 13);
					$docNumber.attr('placeholder', '');
					break;
			}
		},

		/**
		 * Maneja la entrada en el campo de número de documento.
		 */
		onDocNumberInput: function () {
			var docType = $('#billing_doc_type').val();
			var docNumber = $('#billing_doc_number').val();

			if (!docType) {
				return;
			}

			this.validateField(docType, docNumber);
		},

		/**
		 * Valida un campo según el tipo de documento.
		 *
		 * @param {string} docType   Tipo de documento (04, 05, 06, 07).
		 * @param {string} docNumber Número de documento a validar.
		 */
		validateField: function (docType, docNumber) {
			var $field = $('#billing_doc_number');

			switch (docType) {
				case '05': // Cédula.
					this.validateCedula(docNumber, $field);
					break;
				case '04': // RUC.
					this.validateRuc(docNumber, $field);
					break;
				case '06': // Pasaporte.
					if (docNumber.length < 5) {
						this.showError(
							$field,
							aurafactWcCheckout.i18n.passportMinLength
						);
					} else {
						this.clearError($field);
					}
					break;
				case '07': // Consumidor Final.
					if (docNumber !== '9999999999999') {
						this.showError(
							$field,
							aurafactWcCheckout.i18n.cfMustBe9999999999999
						);
					} else {
						this.clearError($field);
					}
					break;
			}
		},

		/**
		 * Valida una cédula ecuatoriana (módulo 10).
		 *
		 * @param {string}   cedula Número de cédula.
		 * @param {jQuery} $field  Campo a marcar.
		 */
		validateCedula: function (cedula, $field) {
			if (!/^\d{10}$/.test(cedula)) {
				this.showError(
					$field,
					aurafactWcCheckout.i18n.invalidCedulaLength
				);
				return;
			}

			// Verificar provincia.
			var provincia = parseInt(cedula.substring(0, 2), 10);
			if (provincia < 1 || provincia > 24) {
				this.showError(
					$field,
					aurafactWcCheckout.i18n.invalidCedula
				);
				return;
			}

			// Algoritmo módulo 10.
			var coeficientes = [2, 1, 2, 1, 2, 1, 2, 1, 2];
			var suma = 0;

			for (var i = 0; i < 9; i++) {
				var producto =
					parseInt(cedula.charAt(i), 10) * coeficientes[i];
				if (producto >= 10) {
					producto -= 9;
				}
				suma += producto;
			}

			var digitoVerificador = parseInt(cedula.charAt(9), 10);
			var digitoCalculado = (10 - (suma % 10)) % 10;

			if (digitoVerificador === digitoCalculado) {
				this.clearError($field);
			} else {
				this.showError(
					$field,
					aurafactWcCheckout.i18n.invalidCedula
				);
			}
		},

		/**
		 * Valida un RUC ecuatoriano (módulo 11).
		 *
		 * @param {string}   ruc   Número de RUC.
		 * @param {jQuery} $field Campo a marcar.
		 */
		validateRuc: function (ruc, $field) {
			if (!/^\d{13}$/.test(ruc)) {
				this.showError(
					$field,
					aurafactWcCheckout.i18n.invalidRucLength
				);
				return;
			}

			// Verificar provincia.
			var provincia = parseInt(ruc.substring(0, 2), 10);
			if (provincia < 1 || provincia > 24) {
				this.showError($field, aurafactWcCheckout.i18n.invalidRuc);
				return;
			}

			// Últimos 3 dígitos deben ser 001.
			if (ruc.substring(-3) !== '001') {
				this.showError($field, aurafactWcCheckout.i18n.invalidRuc);
				return;
			}

			// Algoritmo módulo 11.
			var coeficientes = [4, 3, 2, 7, 6, 5, 4, 3, 2];
			var suma = 0;

			for (var i = 0; i < 9; i++) {
				suma += parseInt(ruc.charAt(i), 10) * coeficientes[i];
			}

			var digitoVerificador = parseInt(ruc.charAt(9), 10);
			var residuo = suma % 11;
			var digitoCalculado = 11 - residuo;

			if (digitoCalculado === 11) {
				digitoCalculado = 0;
			}

			if (digitoVerificador === digitoCalculado) {
				this.clearError($field);
			} else {
				this.showError($field, aurafactWcCheckout.i18n.invalidRuc);
			}
		},

		/**
		 * Muestra u oculta el campo de razón social según el tipo.
		 *
		 * @param {string} docType Tipo de documento seleccionado.
		 */
		toggleBusinessName: function (docType) {
			var $row = $('.aurafact-business-name');

			if (docType === '04') {
				$row.show();
				$row.find('input').prop('required', true);
				$row.find('label').text(
					aurafactWcCheckout.i18n.businessNameLabel + ' *'
				);
			} else {
				$row.hide();
				$row.find('input').prop('required', false).val('');
			}
		},

		/**
		 * Muestra un error en un campo.
		 *
		 * @param {jQuery} $field Campo a marcar.
		 * @param {string}  msg    Mensaje de error.
		 */
		showError: function ($field, msg) {
			var $container = $field.closest('.form-row');
			$container.addClass('woocommerce-invalid');
			$container.removeClass('woocommerce-validated');

			var $error = $container.find('.aurafact-field-error');
			if (!$error.length) {
				$error = $(
					'<span class="aurafact-field-error" style="color: #a00; font-size: 0.9em;"></span>'
				);
				$container.append($error);
			}
			$error.text(msg);
		},

		/**
		 * Limpia el error de un campo.
		 *
		 * @param {jQuery} $field Campo a limpiar.
		 */
		clearError: function ($field) {
			var $container = $field.closest('.form-row');
			$container.removeClass('woocommerce-invalid');
			$container.addClass('woocommerce-validated');
			$container.find('.aurafact-field-error').remove();
		},
	};

	$(document).ready(function () {
		AurafactCheckout.init();
	});
})(jQuery);