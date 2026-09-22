<?php
/**
 * Configuración automática de clases de impuestos en WooCommerce.
 *
 * Crea las 3 clases de impuestos estándar (Estándar, Tasa cero, Tasa reducida)
 * si el admin activa la opción "Auto-configurar impuestos al activar" en los
 * ajustes del plugin.
 *
 * @package AurafactWooCommerce
 * @subpackage Tax
 */

namespace Aurafact\WooCommerce;

/**
 * Helper para crear las clases de impuestos estándar en WC.
 *
 * Solo crea clases que NO existan. Nunca pisa configuración existente.
 *
 * @author Aurafact Team
 * @version 1.2.0
 */
class TaxSetup {

	/**
	 * Option key del setting "Auto-configurar impuestos".
	 */
	const OPTION_AUTO_SETUP = 'aurafact_wc_auto_setup_taxes';

	/**
	 * Option key del flag de ejecución (para idempotencia).
	 * Marca que el auto-setup ya se ejecutó para no duplicar trabajo.
	 */
	const OPTION_AUTO_SETUP_DONE = 'aurafact_wc_auto_setup_taxes_done';

	/**
	 * Transient donde se guarda el último resultado de la ejecución.
	 * Permite mostrar un admin notice al admin con el resumen de lo creado.
	 * TTL: 1 semana (suficiente para que el admin lo vea).
	 */
	const RESULT_TRANSIENT = 'aurafact_wc_tax_setup_last_result';

	/**
	 * User meta flag para evitar mostrar el mismo notice repetidamente al mismo admin.
	 */
	const RESULT_VIEWED_META = 'aurafact_wc_tax_setup_result_viewed';

	/**
	 * Clases de impuestos estándar a crear.
	 * Claves: nombre interno; Valores: nombre que verá el admin en WC.
	 *
	 * @return array<string,string>
	 */
	public static function get_standard_tax_classes() {
		return array(
			'Estándar'      => 'Estándar',
			'Tasa cero'     => 'Tasa cero',
			'Tasa reducida' => 'Tasa reducida',
		);
	}

	/**
	 * Crea las clases de impuestos estándar si la opción está activa y
	 * el setup aún no se ejecutó.
	 *
	 * @return array Resultado con keys:
	 *               - ran (bool): si se ejecutó
	 *               - created (array): nombres de clases creadas
	 *               - skipped (array): nombres que ya existían
	 *               - failed (array): nombres que fallaron al crear
	 */
	public static function maybe_setup() {
		if ( 'yes' !== get_option( self::OPTION_AUTO_SETUP, 'no' ) ) {
			return array(
				'ran'     => false,
				'created' => array(),
				'skipped' => array(),
				'failed'  => array(),
			);
		}

		// Idempotencia: si ya se ejecutó, no correr de nuevo.
		if ( get_option( self::OPTION_AUTO_SETUP_DONE ) ) {
			return array(
				'ran'     => false,
				'created' => array(),
				'skipped' => array_keys( self::get_standard_tax_classes() ),
				'failed'  => array(),
			);
		}

		if ( ! self::is_wc_available() ) {
			// Log diagnóstico: WC_Tax no está disponible en este momento.
			$diagnostic = sprintf(
				'TaxSetup omitido: WC_Tax::create_tax_class no está disponible. class_exists(WC_Tax)=%s, method_exists=%s',
				class_exists( '\WC_Tax' ) ? 'true' : 'false',
				method_exists( '\WC_Tax', 'create_tax_class' ) ? 'true' : 'false'
			);
			error_log( '[aurafact-woocommerce] ' . $diagnostic ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return array(
				'ran'     => false,
				'created' => array(),
				'skipped' => array(),
				'failed'  => array_keys( self::get_standard_tax_classes() ),
				'error'   => 'wc_tax_unavailable',
			);
		}

		$existing = self::get_existing_tax_classes();
		$result   = array(
			'ran'     => true,
			'created' => array(),
			'skipped' => array(),
			'failed'  => array(),
		);

		foreach ( self::get_standard_tax_classes() as $name ) {
			if ( in_array( $name, $existing, true ) ) {
				$result['skipped'][] = $name;
				continue;
			}

			$created = \WC_Tax::create_tax_class( array( 'name' => $name ) );
			if ( $created && ! is_wp_error( $created ) ) {
				$result['created'][] = $name;
			} else {
				$result['failed'][] = $name;
			}
		}

		update_option( self::OPTION_AUTO_SETUP_DONE, '1' );

		self::store_result( $result );

		$message = sprintf(
			'TaxSetup ejecutado. creadas=%s, ya_existían=%s, fallidas=%s',
			empty( $result['created'] ) ? '0' : implode( ', ', $result['created'] ),
			empty( $result['skipped'] ) ? '0' : implode( ', ', $result['skipped'] ),
			empty( $result['failed'] ) ? '0' : implode( ', ', $result['failed'] )
		);

		// Log siempre (independiente del modo debug) porque es un evento puntual
		// que el admin necesita poder verificar. WP_DEBUG_LOG no es requerido.
		error_log( '[aurafact-woocommerce] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		AdminSettings::get_instance()->log( $message );

		return $result;
	}

	/**
	 * Guarda el resultado en transient + limpia el flag "visto" para todos los admins.
	 *
	 * @param array $result Resultado devuelto por {@see maybe_setup()}.
	 *
	 * @return void
	 */
	private static function store_result( $result ) {
		set_transient( self::RESULT_TRANSIENT, $result, WEEK_IN_SECONDS );

		// Limpiar flag "visto" para que el nuevo resultado se muestre de nuevo.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->usermeta,
			array( 'meta_key' => self::RESULT_VIEWED_META ),
			array( '%s' )
		);
	}

	/**
	 * Hook de admin_notices: muestra al admin el resultado del último auto-setup.
	 *
	 * @return void
	 */
	public static function maybe_render_notice() {
		// Solo en admin, solo para usuarios con permisos de WC.
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$result = get_transient( self::RESULT_TRANSIENT );
		if ( false === $result || ! is_array( $result ) || empty( $result['ran'] ) ) {
			return;
		}

		// Idempotencia: si este admin ya vio el notice, no mostrarlo de nuevo.
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, self::RESULT_VIEWED_META, true ) ) {
			return;
		}

		$created = isset( $result['created'] ) ? $result['created'] : array();
		$skipped = isset( $result['skipped'] ) ? $result['skipped'] : array();
		$failed  = isset( $result['failed'] ) ? $result['failed'] : array();

		$has_failure = ! empty( $failed );
		$class       = $has_failure ? 'notice-warning' : 'notice-success';
		$title       = __( 'Aurafact: Auto-configuración de impuestos', 'aurafact-woocommerce' );

		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible aurafact-tax-setup-notice" data-user-id="<?php echo esc_attr( (string) $user_id ); ?>">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<?php if ( !empty( $created ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: lista de clases creadas */
						esc_html__( 'Clases creadas: %s', 'aurafact-woocommerce' ),
						'<code>' . esc_html( implode( ', ', $created ) ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( !empty( $skipped ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: lista de clases que ya existían */
						esc_html__( 'Ya existían: %s', 'aurafact-woocommerce' ),
						'<code>' . esc_html( implode( ', ', $skipped ) ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( !empty( $failed ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: lista de clases que no se pudieron crear */
						esc_html__( 'No se pudieron crear: %s. Revisa los logs.', 'aurafact-woocommerce' ),
						'<code>' . esc_html( implode( ', ', $failed ) ) . '</code>'
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=tools' ) ); ?>">
					<?php esc_html_e( 'Verificar en WooCommerce → Ajustes → Impuesto', 'aurafact-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php

		// Marcar como visto por el usuario actual para no spamear.
		update_user_meta( $user_id, self::RESULT_VIEWED_META, time() );
	}

	/**
	 * Devuelve el último resultado guardado (para debug o admin).
	 *
	 * @return array|false Resultado o false si no hay.
	 */
	public static function get_last_result() {
		return get_transient( self::RESULT_TRANSIENT );
	}

	/**
	 * Borra el resultado almacenado (útil para testing / re-ejecución).
	 *
	 * @return void
	 */
	public static function clear_result() {
		delete_transient( self::RESULT_TRANSIENT );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->usermeta,
			array( 'meta_key' => self::RESULT_VIEWED_META ),
			array( '%s' )
		);
	}

	/**
	 * Hook de activación: corre el auto-setup en admin_init la primera vez
	 * que se visita el admin, si el setting está activo y no se ejecutó antes.
	 *
	 * Envuelto en try/catch para que cualquier excepción no rompa el admin.
	 *
	 * @return void
	 */
	public static function on_admin_init() {
		try {
			self::maybe_setup();
		} catch ( \Throwable $e ) {
			error_log( '[aurafact-woocommerce] TaxSetup on_admin_init error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Ejecuta el setup forzando la creación incluso si ya se ejecutó antes.
	 *
	 * Usado por el botón AJAX "Ejecutar auto-setup ahora" del admin.
	 *
	 * @return array Resultado con created/skipped/failed.
	 */
	public static function force_run() {
		delete_option( self::OPTION_AUTO_SETUP_DONE );
		delete_transient( self::RESULT_TRANSIENT );

		$result = self::maybe_setup();

		// Si maybe_setup no hizo nada porque el setting estaba off, ejecutar de todas formas.
		if ( empty( $result['ran'] ) ) {
			// Activar setting y forzar.
			update_option( self::OPTION_AUTO_SETUP, 'yes' );
			delete_option( self::OPTION_AUTO_SETUP_DONE );
			$result = self::maybe_setup();
		}

		return $result;
	}

	/**
	 * Devuelve las clases de impuestos actualmente registradas en WC.
	 *
	 * @return array Lista de nombres de clases.
	 */
	private static function get_existing_tax_classes() {
		if ( ! class_exists( '\WC_Tax' ) || ! method_exists( '\WC_Tax', 'get_tax_classes' ) ) {
			return array();
		}
		$classes = \WC_Tax::get_tax_classes();
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}
		// Siempre incluye la clase estándar vacía ('').
		$classes[] = '';
		return array_values( array_unique( $classes ) );
	}

	/**
	 * Verifica si WC y la clase WC_Tax están disponibles.
	 *
	 * IMPORTANTE: `function_exists()` solo revisa funciones globales. `WC_Tax::create_tax_class`
	 * es un método de la clase WC_Tax, no una función, por lo que hay que usar
	 * `method_exists()` para detectarlo correctamente.
	 *
	 * @return bool
	 */
	private static function is_wc_available() {
		return class_exists( '\WC_Tax' ) && method_exists( '\WC_Tax', 'create_tax_class' );
	}

	/**
	 * Limpia el flag de ejecución (testing / re-ejecución manual).
	 *
	 * @return void
	 */
	public static function reset_execution_flag() {
		delete_option( self::OPTION_AUTO_SETUP_DONE );
	}

	/**
	 * Indica si el auto-setup está activo en la configuración.
	 *
	 * @return bool
	 */
	public static function is_auto_setup_enabled() {
		return 'yes' === get_option( self::OPTION_AUTO_SETUP, 'no' );
	}
}