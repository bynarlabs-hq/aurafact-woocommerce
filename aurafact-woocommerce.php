<?php
/**
 * Aurafact WooCommerce
 *
 * @package           AurafactWooCommerce
 * @author            Aurafact
 * @license           GPL v3 or later
 *
 * @wordpress-plugin
 * Plugin Name:       Aurafact WooCommerce
 * Plugin URI:        https://aurafact.com/woocommerce
 * Description:       Facturación electrónica ecuatoriana para WooCommerce. Conecta tu tienda con Aurafact y cumple con el SRI.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Aurafact
 * Author URI:        https://aurafact.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       aurafact-woocommerce
 * Domain Path:       /languages
 */

/**
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constantes de definición
// ---------------------------------------------------------------------------

define( 'AURAFACT_WC_VERSION', '1.0.0' );
define( 'AURAFACT_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AURAFACT_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AURAFACT_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AURAFACT_WC_API_URL', 'https://api.aurafact.com' );
define( 'AURAFACT_WC_API_SANDBOX_URL', 'https://api.sandbox.aurafact.com' );
define( 'AURAFACT_WC_DB_OPTION_PREFIX', 'aurafact_wc_' );

// ---------------------------------------------------------------------------
// Hooks de activación y desactivación
// ---------------------------------------------------------------------------

/**
 * Activación del plugin.
 *
 * Verifica que WooCommerce esté instalado y activo antes de activar el plugin.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 *
 * @return void
 * @throws \Exception Si WooCommerce no está activo.
 */
function aurafact_wc_activate() {
	// Verificar que WooCommerce esté activo.
	if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
		$is_network_active = false;
		if ( is_multisite() ) {
			$network_plugins  = get_site_option( 'active_sitewide_plugins' );
			$is_network_active = isset( $network_plugins['woocommerce/woocommerce.php'] );
		}

		if ( ! $is_network_active ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Aurafact WooCommerce requiere WooCommerce instalado y activo.', 'aurafact-woocommerce' ),
				esc_html__( 'Error de activación', 'aurafact-woocommerce' ),
				array( 'back_link' => true )
			);
		}
	}

	// Establecer versión del plugin en las opciones.
	update_option( 'aurafact_wc_version', AURAFACT_WC_VERSION );

	// Programar evento de verificación de actualizaciones si no existe.
	if ( ! wp_next_scheduled( 'aurafact_wc_check_updates' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'aurafact_wc_check_updates' );
	}
}

/**
 * Desactivación del plugin.
 *
 * Limpia eventos programados y opciones temporales.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 *
 * @return void
 */
function aurafact_wc_deactivate() {
	// Limpiar evento programado de actualizaciones.
	$timestamp = wp_next_scheduled( 'aurafact_wc_check_updates' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'aurafact_wc_check_updates' );
	}

	// Limpiar el flag de rewrite rules.
	delete_option( 'aurafact_wc_rewrite_rules_flushed' );
}

register_activation_hook( __FILE__, 'aurafact_wc_activate' );
register_deactivation_hook( __FILE__, 'aurafact_wc_deactivate' );

// ---------------------------------------------------------------------------
// Autoloading básico de clases
// ---------------------------------------------------------------------------

/**
 * Autoloader para las clases del plugin.
 *
 * Busca clases en el directorio includes/ siguiendo la convención
 * class-{nombre}.php.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 *
 * @param string $class Nombre completo de la clase con namespace.
 *
 * @return void
 */
function aurafact_wc_autoload( $class ) {
	// Solo procesar clases del namespace Aurafact\WooCommerce.
	$prefix = 'Aurafact\\WooCommerce\\';
	$len    = strlen( $prefix );

	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );

	// Convertir nombre de clase a formato de archivo: ClassName -> class-class-name.php.
	$file_name = 'class-' . str_replace( '_', '-', strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $relative_class ) ) ) . '.php';

	$file = AURAFACT_WC_PLUGIN_DIR . 'includes/' . $file_name;

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'aurafact_wc_autoload' );

// ---------------------------------------------------------------------------
// Inicialización del plugin
// ---------------------------------------------------------------------------

/**
 * Inicializa el plugin una vez que todos los plugins están cargados.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 *
 * @return void
 */
function aurafact_wc_init() {
	// Cargar traducciones.
	load_plugin_textdomain(
		'aurafact-woocommerce',
		false,
		dirname( AURAFACT_WC_PLUGIN_BASENAME ) . '/languages'
	);

	// Inicializar clases principales.
	if ( is_admin() ) {
		$admin_settings = \Aurafact\WooCommerce\AdminSettings::get_instance();
		$admin_settings->init();

		$updater = \Aurafact\WooCommerce\Updater::get_instance();
		$updater->init();
	}

	// Inicializar checkout (frontend y admin).
	$checkout = \Aurafact\WooCommerce\Checkout::get_instance();
	$checkout->init();

	// Inicializar manejador de órdenes (emisión automática).
	$order_handler = \Aurafact\WooCommerce\OrderHandler::get_instance();
	$order_handler->init();
}

add_action( 'plugins_loaded', 'aurafact_wc_init' );