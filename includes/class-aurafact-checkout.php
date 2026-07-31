<?php
/**
 * Campos fiscales ecuatorianos y validación en el checkout de WooCommerce.
 *
 * Inyecta los campos de identificación fiscal (tipo documento, número, razón social)
 * en el checkout, implementa validación de módulo 10/11 y persiste los datos
 * en los metadatos de la orden.
 *
 * @package AurafactWooCommerce
 * @subpackage Checkout
 */

namespace Aurafact\WooCommerce;

/**
 * Gestión de campos fiscales en el checkout.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class Checkout {

	/**
	 * Instancia única del singleton.
	 *
	 * @var Checkout|null
	 */
	private static $instance = null;

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return Checkout Instancia única.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado para singleton.
	 */
	private function __construct() {
	}

	/**
	 * Inicializa los hooks del checkout.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function init() {
		// Campos de facturación adicionales.
		add_filter( 'woocommerce_billing_fields', array( $this, 'add_fiscal_fields' ), 20, 1 );

		// Validación en servidor antes de crear la orden.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_fiscal_fields' ) );

		// Persistir metadatos al crear la orden.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_fiscal_meta' ), 10, 2 );

		// Mostrar metadatos en el detalle de la orden en admin.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_meta' ), 10, 1 );

		// Encolar JS de validación en checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
	}

	/**
	 * Agrega los campos fiscales ecuatorianos al checkout.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param array $fields Arreglo de campos de facturación existentes.
	 *
	 * @return array Arreglo de campos modificado.
	 */
	public function add_fiscal_fields( $fields ) {
		$fields['billing_doc_type'] = array(
			'label'       => __( 'Tipo de identificación', 'aurafact-woocommerce' ),
			'required'    => true,
			'type'        => 'select',
			'class'       => array( 'form-row-wide', 'aurafact-doc-type' ),
			'priority'    => 35,
			'options'     => array(
				''  => __( 'Selecciona un tipo', 'aurafact-woocommerce' ),
				'04' => __( 'RUC', 'aurafact-woocommerce' ),
				'05' => __( 'Cédula', 'aurafact-woocommerce' ),
				'06' => __( 'Pasaporte', 'aurafact-woocommerce' ),
				'07' => __( 'Consumidor Final', 'aurafact-woocommerce' ),
			),
		);

		$fields['billing_doc_number'] = array(
			'label'       => __( 'Número de identificación', 'aurafact-woocommerce' ),
			'required'    => true,
			'type'        => 'text',
			'class'       => array( 'form-row-wide', 'aurafact-doc-number' ),
			'priority'    => 36,
			'maxlength'   => 13,
		);

		$fields['billing_business_name'] = array(
			'label'       => __( 'Razón social', 'aurafact-woocommerce' ),
			'required'    => false,
			'type'        => 'text',
			'class'       => array( 'form-row-wide', 'aurafact-business-name' ),
			'priority'    => 37,
		);

		return $fields;
	}

	/**
	 * Valida los campos fiscales en el servidor antes de crear la orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function validate_fiscal_fields() {
		$doc_type   = isset( $_POST['billing_doc_type'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_doc_type'] ) ) : '';
		$doc_number = isset( $_POST['billing_doc_number'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_doc_number'] ) ) : '';
		$business_name = isset( $_POST['billing_business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_business_name'] ) ) : '';

		// Validar que el tipo de documento esté seleccionado.
		if ( empty( $doc_type ) ) {
			wc_add_notice(
				__( 'Selecciona un tipo de identificación.', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Validar según el tipo de documento.
		switch ( $doc_type ) {
			case '05': // Cédula.
				$this->validate_cedula( $doc_number );
				break;

			case '04': // RUC.
				$this->validate_ruc( $doc_number );
				if ( empty( $business_name ) ) {
					wc_add_notice(
						__( 'La razón social es obligatoria para RUC.', 'aurafact-woocommerce' ),
						'error'
					);
				}
				break;

			case '06': // Pasaporte.
				if ( strlen( $doc_number ) < 5 ) {
					wc_add_notice(
						__( 'El pasaporte debe tener al menos 5 caracteres.', 'aurafact-woocommerce' ),
						'error'
					);
				}
				break;

			case '07': // Consumidor Final.
				if ( '9999999999999' !== $doc_number ) {
					wc_add_notice(
						__( 'Consumidor Final debe usar el número 9999999999999.', 'aurafact-woocommerce' ),
						'error'
					);
				}
				break;

			default:
				wc_add_notice(
					__( 'Tipo de identificación no válido.', 'aurafact-woocommerce' ),
					'error'
				);
				break;
		}
	}

	/**
	 * Valida una cédula ecuatoriana (módulo 10).
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $doc_number Número de cédula a validar.
	 *
	 * @return void
	 */
	private function validate_cedula( $doc_number ) {
		if ( 10 !== strlen( $doc_number ) || ! ctype_digit( $doc_number ) ) {
			wc_add_notice(
				__( 'El número de cédula debe tener 10 dígitos numéricos.', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Verificar provincia (primeros 2 dígitos entre 1 y 24).
		$provincia = (int) substr( $doc_number, 0, 2 );
		if ( $provincia < 1 || $provincia > 24 ) {
			wc_add_notice(
				__( 'El número de cédula no es válido (código de provincia incorrecto).', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Algoritmo de módulo 10.
		$coeficientes = array( 2, 1, 2, 1, 2, 1, 2, 1, 2 );
		$suma = 0;

		for ( $i = 0; $i < 9; $i++ ) {
			$producto = (int) $doc_number[ $i ] * $coeficientes[ $i ];
			if ( $producto >= 10 ) {
				$producto -= 9;
			}
			$suma += $producto;
		}

		$digito_verificador = (int) $doc_number[9];
		$digito_calculado   = ( 10 - ( $suma % 10 ) ) % 10;

		if ( $digito_verificador !== $digito_calculado ) {
			wc_add_notice(
				__( 'El número de cédula ingresado no es válido.', 'aurafact-woocommerce' ),
				'error'
			);
		}
	}

	/**
	 * Valida un RUC ecuatoriano (módulo 11).
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $doc_number Número de RUC a validar.
	 *
	 * @return void
	 */
	private function validate_ruc( $doc_number ) {
		if ( 13 !== strlen( $doc_number ) || ! ctype_digit( $doc_number ) ) {
			wc_add_notice(
				__( 'El RUC ingresado no es válido. Debe tener 13 dígitos.', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Verificar provincia (primeros 2 dígitos entre 1 y 24).
		$provincia = (int) substr( $doc_number, 0, 2 );
		if ( $provincia < 1 || $provincia > 24 ) {
			wc_add_notice(
				__( 'El RUC no es válido (código de provincia incorrecto).', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Los últimos 3 dígitos deben ser 001 para RUC de persona natural.
		$sufijo = substr( $doc_number, -3 );
		if ( '001' !== $sufijo ) {
			wc_add_notice(
				__( 'El RUC no es válido (los últimos 3 dígitos deben ser 001).', 'aurafact-woocommerce' ),
				'error'
			);
			return;
		}

		// Algoritmo de módulo 11.
		$coeficientes = array( 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$suma = 0;

		for ( $i = 0; $i < 9; $i++ ) {
			$suma += (int) $doc_number[ $i ] * $coeficientes[ $i ];
		}

		$digito_verificador = (int) $doc_number[9];
		$residuo            = $suma % 11;
		$digito_calculado   = 11 - $residuo;

		if ( 11 === $digito_calculado ) {
			$digito_calculado = 0;
		}

		if ( $digito_verificador !== $digito_calculado ) {
			wc_add_notice(
				__( 'El RUC ingresado no es válido.', 'aurafact-woocommerce' ),
				'error'
			);
		}
	}

	/**
	 * Guarda los metadatos fiscales al crear la orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order  Objeto de la orden.
	 * @param array     $data   Datos del checkout.
	 *
	 * @return void
	 */
	public function save_fiscal_meta( $order, $data ) {
		if ( isset( $data['billing_doc_type'] ) ) {
			$order->update_meta_data( '_billing_doc_type', sanitize_text_field( $data['billing_doc_type'] ) );
		}

		if ( isset( $data['billing_doc_number'] ) ) {
			$order->update_meta_data( '_billing_doc_number', sanitize_text_field( $data['billing_doc_number'] ) );
		}

		if ( isset( $data['billing_business_name'] ) ) {
			$order->update_meta_data( '_billing_business_name', sanitize_text_field( $data['billing_business_name'] ) );
		}
	}

	/**
	 * Muestra los metadatos fiscales en el detalle de la orden en admin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return void
	 */
	public function display_admin_order_meta( $order ) {
		$doc_type   = $order->get_meta( '_billing_doc_type' );
		$doc_number = $order->get_meta( '_billing_doc_number' );
		$business_name = $order->get_meta( '_billing_business_name' );

		if ( ! $doc_type && ! $doc_number && ! $business_name ) {
			return;
		}

		$doc_labels = array(
			'04' => __( 'RUC', 'aurafact-woocommerce' ),
			'05' => __( 'Cédula', 'aurafact-woocommerce' ),
			'06' => __( 'Pasaporte', 'aurafact-woocommerce' ),
			'07' => __( 'Consumidor Final', 'aurafact-woocommerce' ),
		);

		$label = isset( $doc_labels[ $doc_type ] ) ? $doc_labels[ $doc_type ] : $doc_type;
		?>
		<div class="aurafact-fiscal-data" style="padding: 12px 0; border-top: 1px solid #ddd; margin-top: 12px;">
			<h4><?php esc_html_e( 'Datos fiscales (Aurafact)', 'aurafact-woocommerce' ); ?></h4>
			<p>
				<strong><?php esc_html_e( 'Tipo:', 'aurafact-woocommerce' ); ?></strong>
				<?php echo esc_html( $label ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Número:', 'aurafact-woocommerce' ); ?></strong>
				<?php echo esc_html( $doc_number ); ?>
			</p>
			<?php if ( ! empty( $business_name ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Razón social:', 'aurafact-woocommerce' ); ?></strong>
				<?php echo esc_html( $business_name ); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Encola el script de validación en el checkout.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function enqueue_checkout_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'aurafact-wc-checkout',
			AURAFACT_WC_PLUGIN_URL . 'assets/js/aurafact-checkout.js',
			array( 'jquery' ),
			AURAFACT_WC_VERSION,
			true
		);

		wp_localize_script(
			'aurafact-wc-checkout',
			'aurafactWcCheckout',
			array(
				'i18n' => array(
					'selectType'            => __( 'Selecciona un tipo de identificación', 'aurafact-woocommerce' ),
					'invalidCedula'         => __( 'El número de cédula ingresado no es válido.', 'aurafact-woocommerce' ),
					'invalidRuc'            => __( 'El RUC ingresado no es válido.', 'aurafact-woocommerce' ),
					'invalidRucLength'      => __( 'El RUC ingresado no es válido. Debe tener 13 dígitos.', 'aurafact-woocommerce' ),
					'invalidCedulaLength'   => __( 'La cédula debe tener 10 dígitos.', 'aurafact-woocommerce' ),
					'passportMinLength'     => __( 'El pasaporte debe tener al menos 5 caracteres.', 'aurafact-woocommerce' ),
					'cfMustBe9999999999999' => __( 'Consumidor Final debe usar el número 9999999999999.', 'aurafact-woocommerce' ),
					'businessNameRequired'  => __( 'La razón social es obligatoria para RUC.', 'aurafact-woocommerce' ),
					'businessNameLabel'     => __( 'Razón social', 'aurafact-woocommerce' ),
				),
			)
		);
	}
}