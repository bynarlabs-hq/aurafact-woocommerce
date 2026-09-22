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
			return array(
				'ran'     => false,
				'created' => array(),
				'skipped' => array(),
				'failed'  => array(),
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

		AdminSettings::get_instance()->log(
			sprintf(
				'TaxSetup: creado=%s, skipped=%s, failed=%s',
				wp_json_encode( $result['created'] ),
				wp_json_encode( $result['skipped'] ),
				wp_json_encode( $result['failed'] )
			)
		);

		return $result;
	}

	/**
	 * Hook de activación: corre el auto-setup en admin_init la primera vez
	 * que se visita el admin, si el setting está activo y no se ejecutó antes.
	 *
	 * @return void
	 */
	public static function on_admin_init() {
		self::maybe_setup();
	}

	/**
	 * Devuelve las clases de impuestos actualmente registradas en WC.
	 *
	 * @return array Lista de nombres de clases.
	 */
	private static function get_existing_tax_classes() {
		if ( ! function_exists( '\\WC_Tax::get_tax_classes' ) ) {
			return array();
		}
		$classes = \WC_Tax::get_tax_classes();
		// Siempre incluye la clase estándar vacía ('').
		$classes[] = '';
		return array_unique( $classes );
	}

	/**
	 * Verifica si WC y la clase WC_Tax están disponibles.
	 *
	 * @return bool
	 */
	private static function is_wc_available() {
		return class_exists( '\\WC_Tax' ) && function_exists( '\\WC_Tax::create_tax_class' );
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