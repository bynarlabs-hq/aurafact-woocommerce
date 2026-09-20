<?php
/**
 * Pantalla de configuración del plugin Aurafact WooCommerce.
 *
 * Proporciona la interfaz de administración para configurar la integración
 * con Aurafact, incluyendo API Key, ambiente, evento de emisión y logs.
 *
 * @package AurafactWooCommerce
 * @subpackage Admin
 */

namespace Aurafact\WooCommerce;

/**
 * Administración de la pantalla de configuración.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class AdminSettings {

	/**
	 * Instancia única del singleton.
	 *
	 * @var AdminSettings|null
	 */
	private static $instance = null;

	/**
	 * Nombre de la opción en wp_options.
	 */
	const OPTION_NAME = 'aurafact_wc_settings';

	/**
	 * Capacidad requerida para acceder a la configuración.
	 */
	const REQUIRED_CAPABILITY = 'manage_woocommerce';

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return AdminSettings Instancia única.
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
	 * Inicializa los hooks de administración.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_aurafact', array( $this, 'render_settings_tab' ) );
		add_action( 'woocommerce_update_options_aurafact', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_aurafact_wc_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Agrega la pestaña de Aurafact a los ajustes de WooCommerce.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param array $tabs Arreglo de pestañas existentes.
	 *
	 * @return array Arreglo de pestañas modificado.
	 */
	public function add_settings_tab( $tabs ) {
		$tabs['aurafact'] = __( 'Aurafact', 'aurafact-woocommerce' );

		return $tabs;
	}

	/**
	 * Renderiza la pantalla de configuración.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function render_settings_tab() {
		woocommerce_admin_fields( $this->get_settings_fields() );
		$this->render_connection_test_button();
		$this->render_status_section();
	}

	/**
	 * Define los campos de configuración.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return array Arreglo de campos de configuración.
	 */
	private function get_settings_fields() {
		$settings = array(
			'section_title' => array(
				'name' => __( 'Configuración Aurafact', 'aurafact-woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Configura la integración de tu tienda WooCommerce con Aurafact para facturación electrónica ecuatoriana.', 'aurafact-woocommerce' ),
				'id'   => 'aurafact_wc_section_title',
			),
			'api_key' => array(
				'name'     => __( 'API Key', 'aurafact-woocommerce' ),
				'type'     => 'password',
				'desc'     => __( 'Tu clave de API de Aurafact. Se almacena de forma segura.', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_api_key',
				'desc_tip' => true,
			),
			'environment' => array(
				'name'     => __( 'Ambiente', 'aurafact-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					'sandbox'    => __( 'Sandbox (Pruebas)', 'aurafact-woocommerce' ),
					'production' => __( 'Producción', 'aurafact-woocommerce' ),
				),
				'desc'     => __( 'Selecciona Sandbox para pruebas o Producción para facturación real.', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_environment',
				'desc_tip' => true,
				'default'  => 'sandbox',
			),
			'emission_event' => array(
				'name'     => __( 'Evento de emisión', 'aurafact-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					'completed'     => __( 'Al completar el pedido', 'aurafact-woocommerce' ),
					'processing'    => __( 'Al procesar el pedido', 'aurafact-woocommerce' ),
					'order_created' => __( 'Al crear la orden (recomendado para pagos offline)', 'aurafact-woocommerce' ),
				),
				'desc'     => __( 'Define en qué momento del flujo de pedidos se emitirá la factura. Para pagos offline (transferencia, contra reembolso) usa "Al crear la orden".', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_emission_event',
				'desc_tip' => true,
				'default'  => 'order_created',
			),
			'country_restriction' => array(
				'name'     => __( 'Cobertura de facturación', 'aurafact-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					CountryFilter::MODE_EC_ONLY => __( 'Solo Ecuador', 'aurafact-woocommerce' ),
					CountryFilter::MODE_ALL     => __( 'Todos los países', 'aurafact-woocommerce' ),
				),
				'desc'     => __( 'Solo Ecuador: los campos fiscales solo aparecen si el cliente selecciona Ecuador como país. Todos los países: los campos aparecen siempre.', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_country_restriction',
				'desc_tip' => true,
				'default'  => CountryFilter::MODE_EC_ONLY,
			),
			'ruc_validation_mode' => array(
				'name'     => __( 'Validación de RUC', 'aurafact-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					'format_only' => __( 'Solo formato (recomendado)', 'aurafact-woocommerce' ),
					'algorithm'   => __( 'Algoritmo módulo 11 (puede rechazar RUCs válidos)', 'aurafact-woocommerce' ),
					'disabled'    => __( 'Sin validación (no recomendado)', 'aurafact-woocommerce' ),
				),
				'desc'     => __( 'Solo formato valida 13 dígitos numéricos + provincia. Algoritmo aplica módulo 11 (puede rechazar RUCs de sociedades).', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_ruc_validation_mode',
				'desc_tip' => true,
				'default'  => 'format_only',
			),
			'cedula_validation_mode' => array(
				'name'     => __( 'Validación de Cédula', 'aurafact-woocommerce' ),
				'type'     => 'select',
				'options'  => array(
					'format_only' => __( 'Solo formato (recomendado)', 'aurafact-woocommerce' ),
					'algorithm'   => __( 'Algoritmo módulo 10 (puede rechazar cédulas antiguas)', 'aurafact-woocommerce' ),
					'disabled'    => __( 'Sin validación (no recomendado)', 'aurafact-woocommerce' ),
				),
				'desc'     => __( 'Solo formato valida 10 dígitos numéricos + provincia. Algoritmo aplica módulo 10 (puede rechazar cédulas emitidas antes del 2000).', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_cedula_validation_mode',
				'desc_tip' => true,
				'default'  => 'format_only',
			),
			'debug_mode' => array(
				'name'     => __( 'Depuración', 'aurafact-woocommerce' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Habilitar registro de depuración (log)', 'aurafact-woocommerce' ),
				'id'       => 'aurafact_wc_debug_mode',
				'default'  => 'no',
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id'   => 'aurafact_wc_section_end',
			),
		);

		return $settings;
	}

	/**
	 * Renderiza el botón de prueba de conexión.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	private function render_connection_test_button() {
		$api_key = $this->get_api_key();
		?>
		<div style="margin: 15px 0; padding: 15px; background: #f0f8ff; border-left: 4px solid #2271b1;">
			<h3><?php esc_html_e( 'Prueba de conexión', 'aurafact-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Verifica que tu API Key sea válida y que el plugin pueda comunicarse con Aurafact.', 'aurafact-woocommerce' ); ?></p>
			<button
				type="button"
				id="aurafact-wc-test-connection"
				class="button button-primary"
				<?php echo empty( $api_key ) ? 'disabled' : ''; ?>
			>
				<?php esc_html_e( 'Probar conexión', 'aurafact-woocommerce' ); ?>
			</button>
			<span id="aurafact-wc-test-result" style="margin-left: 10px;"></span>
		</div>
		<?php
	}

	/**
	 * Renderiza la sección de estado del plugin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	private function render_status_section() {
		global $wp_version;
		?>
		<div style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
			<h3><?php esc_html_e( 'Estado del sistema', 'aurafact-woocommerce' ); ?></h3>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Plugin Aurafact', 'aurafact-woocommerce' ); ?></strong></td>
						<td><?php echo esc_html( AURAFACT_WC_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WooCommerce', 'aurafact-woocommerce' ); ?></strong></td>
						<td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WordPress', 'aurafact-woocommerce' ); ?></strong></td>
						<td><?php echo esc_html( $wp_version ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'PHP', 'aurafact-woocommerce' ); ?></strong></td>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Conectividad Aurafact', 'aurafact-woocommerce' ); ?></strong></td>
						<td id="aurafact-wc-connectivity-status">
							<?php esc_html_e( 'Pendiente', 'aurafact-woocommerce' ); ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Guarda los valores de configuración.
	 *
	 * Realiza sanitización y validación de todos los campos antes de persistir.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function save_settings() {
		woocommerce_update_options( $this->get_settings_fields() );

		// Sanitización adicional del API Key.
		if ( isset( $_POST['aurafact_wc_api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$api_key = sanitize_text_field( wp_unslash( $_POST['aurafact_wc_api_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$api_key = trim( $api_key );

			if ( ! empty( $api_key ) && ! preg_match( '/^[a-zA-Z0-9_\-\.]+$/', $api_key ) ) {
				add_action(
					'admin_notices',
					function () {
						echo '<div class="notice notice-error"><p>' .
							esc_html__( 'La API Key contiene caracteres no válidos.', 'aurafact-woocommerce' ) .
							'</p></div>';
					}
				);
				return;
			}

			update_option( 'aurafact_wc_api_key', sanitize_text_field( $api_key ) );
		}
	}

	/**
	 * Obtiene la API Key almacenada.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string API Key desencriptada o cadena vacía si no existe.
	 */
	public function get_api_key() {
		return get_option( 'aurafact_wc_api_key', '' );
	}

	/**
	 * Obtiene el ambiente configurado.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string 'sandbox' o 'production'.
	 */
	public function get_environment() {
		return get_option( 'aurafact_wc_environment', 'sandbox' );
	}

	/**
	 * Obtiene la URL base de la API según el ambiente.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string URL base de la API.
	 */
	public function get_api_base_url() {
		if ( 'production' === $this->get_environment() ) {
			return AURAFACT_WC_API_URL;
		}

		return AURAFACT_WC_API_SANDBOX_URL;
	}

	/**
	 * Obtiene el evento de emisión configurado.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string 'completed' o 'processing'.
	 */
	public function get_emission_event() {
		return get_option( 'aurafact_wc_emission_event', 'completed' );
	}

	/**
	 * Verifica si el modo de depuración está habilitado.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return bool True si el modo depuración está activo.
	 */
	public function is_debug_mode() {
		return 'yes' === get_option( 'aurafact_wc_debug_mode', 'no' );
	}

	/**
	 * Registra un mensaje en el log si el modo depuración está activo.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $message Mensaje a registrar.
	 *
	 * @return void
	 */
	public function log( $message ) {
		if ( ! $this->is_debug_mode() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->debug( $message, array( 'source' => 'aurafact-woocommerce' ) );
		}
	}

	/**
	 * Enqueue assets para la página de administración.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $hook Hook de la página actual.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// Verificar que estemos en la pestaña de Aurafact.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['tab'] ) || 'aurafact' !== $_GET['tab'] ) {
			return;
		}

		wp_enqueue_script(
			'aurafact-wc-admin',
			AURAFACT_WC_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			AURAFACT_WC_VERSION,
			true
		);

		wp_localize_script(
			'aurafact-wc-admin',
			'aurafactWcAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aurafact_wc_test_connection' ),
				'i18n'     => array(
					'testing'   => __( 'Probando conexión...', 'aurafact-woocommerce' ),
					'success'   => __( 'Conexión exitosa', 'aurafact-woocommerce' ),
					'error'     => __( 'Error de conexión', 'aurafact-woocommerce' ),
					'noApiKey'  => __( 'No hay API Key configurada', 'aurafact-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Maneja la solicitud AJAX de prueba de conexión.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		// Verificar nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aurafact_wc_test_connection' ) ) {
			wp_send_json_error( array( 'message' => __( 'Error de seguridad. Recarga la página e intenta de nuevo.', 'aurafact-woocommerce' ) ) );
		}

		// Verificar capacidad.
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para realizar esta acción.', 'aurafact-woocommerce' ) ) );
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Configura una API Key primero.', 'aurafact-woocommerce' ) ) );
		}

		$api_url = $this->get_api_base_url() . '/v1/health';

		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error de conexión con Aurafact: ' . $response->get_error_message() );
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 === $status_code ) {
			$this->log( 'Conexión exitosa con Aurafact (ambiente: ' . $this->get_environment() . ')' );
			wp_send_json_success(
				array(
					'message' => __( 'Conexión exitosa con Aurafact.', 'aurafact-woocommerce' ),
					'data'    => $data,
				)
			);
		} else {
			$error_message = isset( $data['message'] ) ? $data['message'] : sprintf(
				/* translators: %d: Código de estado HTTP */
				__( 'Error HTTP %d', 'aurafact-woocommerce' ),
				$status_code
			);
			$this->log( 'Error de conexión con Aurafact: ' . $error_message );
			wp_send_json_error( array( 'message' => $error_message ) );
		}
	}
}