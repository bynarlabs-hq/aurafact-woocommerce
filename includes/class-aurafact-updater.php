<?php
/**
 * Sistema de actualizaciones automáticas para Aurafact WooCommerce.
 *
 * Integra el plugin con GitHub Releases para buscar, notificar y aplicar
 * actualizaciones directamente desde el panel de administración de WordPress.
 *
 * @package AurafactWooCommerce
 * @subpackage Updater
 */

namespace Aurafact\WooCommerce;

/**
 * Gestor de actualizaciones automáticas vía GitHub Releases.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class Updater {

	/**
	 * Instancia única del singleton.
	 *
	 * @var Updater|null
	 */
	private static $instance = null;

	/**
	 * URL del repositorio en GitHub.
	 */
	const GITHUB_REPO_URL = 'https://github.com/bynarlabs-hq/aurafact-woocommerce';

	/**
	 * API de GitHub para releases.
	 */
	const GITHUB_API_URL = 'https://api.github.com/repos/bynarlabs-hq/aurafact-woocommerce/releases';

	/**
	 * Intervalo de verificación en segundos (12 horas).
	 */
	const CHECK_INTERVAL = 43200;

	/**
	 * Transient key para almacenar la información de la última verificación.
	 */
	const TRANSIENT_KEY = 'aurafact_wc_update_info';

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return Updater Instancia única.
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
	 * Inicializa los hooks del actualizador.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function init() {
		// Hook para verificación periódica programada.
		add_action( 'aurafact_wc_check_updates', array( $this, 'check_for_updates' ) );

		// Filtrar la información de actualización en el panel de plugins.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update_transient' ) );

		// Agregar información del plugin en la fila del panel de plugins.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

		// Agregar enlace de "detalles del plugin" personalizado.
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Verifica si hay una nueva versión disponible en GitHub Releases.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return object|false Objeto con datos de la release o false si no hay actualización.
	 */
	public function check_for_updates() {
		$response = wp_remote_get(
			self::GITHUB_API_URL . '/latest',
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'Aurafact-WooCommerce/' . AURAFACT_WC_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! isset( $release->tag_name ) ) {
			return false;
		}

		// La tag se espera en formato "v1.0.0" o "1.0.0".
		$latest_version = ltrim( $release->tag_name, 'v' );

		// Comparar versiones.
		if ( version_compare( AURAFACT_WC_VERSION, $latest_version, '>=' ) ) {
			// Ya estamos en la última versión.
			set_site_transient( self::TRANSIENT_KEY, 'up_to_date', self::CHECK_INTERVAL );
			return false;
		}

		$update_info = array(
			'version'       => $latest_version,
			'new_version'   => $latest_version,
			'package'       => $this->get_download_url( $release ),
			'url'           => self::GITHUB_REPO_URL,
			'slug'          => 'aurafact-woocommerce',
			'plugin'        => AURAFACT_WC_PLUGIN_BASENAME,
			'requires'      => '7.4',
			'tested'        => '', // Se actualiza dinámicamente.
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => $this->get_plugin_description(),
				'changelog'   => isset( $release->body ) ? wp_kses_post( $release->body ) : '',
			),
			'banners'       => array(
				'low'  => AURAFACT_WC_PLUGIN_URL . 'assets/banner-772x250.png',
				'high' => AURAFACT_WC_PLUGIN_URL . 'assets/banner-1544x500.png',
			),
			'icons'         => array(
				'1x' => AURAFACT_WC_PLUGIN_URL . 'assets/icon-128x128.png',
			),
		);

		set_site_transient( self::TRANSIENT_KEY, $update_info, self::CHECK_INTERVAL );

		return (object) $update_info;
	}

	/**
	 * Obtiene la URL de descarga del zip desde la release.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param object $release Objeto de la release de GitHub.
	 *
	 * @return string URL de descarga del zip.
	 */
	private function get_download_url( $release ) {
		// Buscar un asset .zip en la release.
		if ( isset( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && '.zip' === substr( $asset->name, -4 ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback: descargar el source zip de la tag.
		if ( isset( $release->zipball_url ) ) {
			return $release->zipball_url;
		}

		return '';
	}

	/**
	 * Filtra el transient de actualización de plugins para incluir Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param object $transient Transient de actualización de plugins.
	 *
	 * @return object Transient modificado.
	 */
	public function check_for_update_transient( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$update_info = $this->get_stored_update_info();

		if ( ! $update_info || ! isset( $update_info['new_version'] ) ) {
			return $transient;
		}

		if ( version_compare( AURAFACT_WC_VERSION, $update_info['new_version'], '<' ) ) {
			$transient->response[ AURAFACT_WC_PLUGIN_BASENAME ] = (object) $update_info;
		} else {
			// Marcar como que no hay actualización (para evitar re-verificación constante).
			if ( ! isset( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ AURAFACT_WC_PLUGIN_BASENAME ] = (object) $update_info;
		}

		return $transient;
	}

	/**
	 * Proporciona información detallada del plugin para la ventana modal
	 * de "Ver detalles" en el panel de plugins.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param object|false $result  Resultado por defecto.
	 * @param string       $action  Tipo de acción solicitada.
	 * @param object       $args    Argumentos de la consulta.
	 *
	 * @return object|false Información del plugin o false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'aurafact-woocommerce' !== $args->slug ) {
			return $result;
		}

		$update_info = $this->get_stored_update_info();

		if ( ! $update_info ) {
			return $result;
		}

		$info = array(
			'name'          => 'Aurafact WooCommerce',
			'slug'          => 'aurafact-woocommerce',
			'version'       => isset( $update_info['new_version'] ) ? $update_info['new_version'] : AURAFACT_WC_VERSION,
			'author'        => '<a href="https://aurafact.com">Aurafact</a>',
			'author_profile' => 'https://aurafact.com',
			'contributors'  => array( 'aurafact' => 'Aurafact' ),
			'homepage'      => self::GITHUB_REPO_URL,
			'requires'      => '7.4',
			'requires_php'  => '7.4',
			'tested'        => '',
			'downloaded'    => 0,
			'added'         => '2026-07-01',
			'last_updated'  => isset( $update_info['version'] ) ? gmdate( 'Y-m-d' ) : '',
			'sections'      => isset( $update_info['sections'] ) ? $update_info['sections'] : array(),
			'banners'       => isset( $update_info['banners'] ) ? $update_info['banners'] : array(),
			'icons'         => isset( $update_info['icons'] ) ? $update_info['icons'] : array(),
			'download_link' => isset( $update_info['package'] ) ? $update_info['package'] : '',
		);

		return (object) $info;
	}

	/**
	 * Obtiene la información de actualización almacenada en transient.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return array|false Arreglo con datos de actualización o false.
	 */
	private function get_stored_update_info() {
		$data = get_site_transient( self::TRANSIENT_KEY );

		if ( ! $data || 'up_to_date' === $data ) {
			return false;
		}

		return $data;
	}

	/**
	 * Obtiene la descripción del plugin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string Descripción del plugin.
	 */
	private function get_plugin_description() {
		return __(
			'Aurafact WooCommerce integra tu tienda WooCommerce con Aurafact para emitir facturación electrónica ecuatoriana compatible con el SRI. Los comprobantes se generan automáticamente al completar un pedido y quedan disponibles para descarga por parte del cliente.',
			'aurafact-woocommerce'
		);
	}

	/**
	 * Agrega metadatos adicionales en la fila del plugin en el panel de administración.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param array  $links Arreglo de enlaces existentes.
	 * @param string $file  Nombre del archivo del plugin.
	 *
	 * @return array Arreglo de enlaces modificado.
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( AURAFACT_WC_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		$extra_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=aurafact' ) ) . '">' .
				esc_html__( 'Configuración', 'aurafact-woocommerce' ) .
			'</a>',
			'<a href="' . esc_url( self::GITHUB_REPO_URL ) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__( 'Repositorio', 'aurafact-woocommerce' ) .
			'</a>',
			'<a href="' . esc_url( 'https://aurafact.com/docs/woocommerce' ) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__( 'Documentación', 'aurafact-woocommerce' ) .
			'</a>',
		);

		return array_merge( $links, $extra_links );
	}
}