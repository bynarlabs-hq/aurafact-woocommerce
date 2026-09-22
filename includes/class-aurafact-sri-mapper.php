<?php
/**
 * Mapeador de porcentajes WC a códigos SRI.
 *
 * Mantiene un cache (transient de WordPress) de los parámetros SRI obtenidos
 * del backend Aurafact para traducir tasas de WooCommerce (0%, 5%, 12%, 13%,
 * 15%) a códigos SRI (0, 8, 2, 2, 4).
 *
 * @package AurafactWooCommerce
 * @subpackage Tax
 */

namespace Aurafact\WooCommerce;

/**
 * Mapea porcentajes de WooCommerce a códigos SRI Ecuador.
 *
 * Estrategia de mapeo (en orden):
 *   1. Match exacto: el % WC está dentro de la lista de porcentajes del parámetro SRI.
 *   2. Match aproximado: tolerancia de 0.01% (cubre redondeos de WC).
 *   3. Fallback: código "4" (15%) si existe en el cache; null en caso contrario.
 *
 * @author Aurafact Team
 * @version 1.2.0
 */
class SriMapper {

	/**
	 * Clave del transient donde se cachean los parámetros SRI.
	 */
	const CACHE_KEY = 'aurafact_sri_params';

	/**
	 * TTL del cache en segundos. 1 hora es suficiente: los parámetros SRI cambian
	 * rara vez (solo cuando SRI publica una nueva tarifa).
	 */
	const CACHE_TTL = 3600;

	/**
	 * Tolerancia para match aproximado de porcentajes (%).
	 * Cubre errores de redondeo de WC (ej: 14.9999 vs 15.0).
	 */
	const MATCH_TOLERANCE = 0.01;

	/**
	 * Código de tarifa SRI por defecto (15% — tarifa general Ecuador).
	 */
	const DEFAULT_TARIFA_CODE = '4';

	/**
	 * Código de tarifa SRI para 0% (productos exentos).
	 */
	const ZERO_TARIFA_CODE = '0';

	/**
	 * Obtiene los parámetros SRI desde el cache o el backend.
	 *
	 * @return array Lista de parámetros SRI (cada uno con keys: codigo, codigoAuxiliar,
	 *               tipo, porcentajes, descripcion, vigente, estado). Vacío si falla.
	 */
	public static function get_parameters() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$settings = AdminSettings::get_instance();
		$api_key  = $settings->get_api_key();
		$base_url = $settings->get_api_base_url();

		if ( empty( $api_key ) ) {
			$settings->log( 'SriMapper: API Key vacía, no se pueden obtener parámetros SRI.' );
			return array();
		}

		$url = untrailingslashit( $base_url ) . '/v1/sri-parameters?tipo=IVA';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'AurafactWooCommerce/' . AURAFACT_WC_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$settings->log( 'SriMapper: Error HTTP al consultar parámetros SRI: ' . $response->get_error_message() );
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$settings->log( sprintf( 'SriMapper: Backend respondió HTTP %d', $status_code ) );
			return array();
		}

		$params = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

		set_transient( self::CACHE_KEY, $params, self::CACHE_TTL );

		return $params;
	}

	/**
	 * Mapea un porcentaje WC a un código de tarifa SRI.
	 *
	 * @param float $wc_tax_rate Porcentaje WC (ej: 15.0, 12.0, 0.0, 13.0).
	 *
	 * @return array|null Array con keys 'codigo' (string), 'codigoAuxiliar' (string|null),
	 *                   'porcentaje' (float), 'descripcion' (string|null).
	 *                   O null si no hay match ni fallback.
	 */
	public static function map_percentage_to_sri( $wc_tax_rate ) {
		$wc_tax_rate = (float) $wc_tax_rate;
		$params      = self::get_parameters();

		if ( empty( $params ) ) {
			return null;
		}

		// 1. Match exacto.
		foreach ( $params as $p ) {
			$porcentajes = isset( $p['porcentajes'] ) && is_array( $p['porcentajes'] ) ? $p['porcentajes'] : array();
			foreach ( $porcentajes as $pct ) {
				if ( abs( (float) $pct - $wc_tax_rate ) < PHP_FLOAT_EPSILON ) {
					return self::build_mapping_result( $p, (float) $pct );
				}
			}
		}

		// 2. Match aproximado (tolerancia configurable).
		foreach ( $params as $p ) {
			$porcentajes = isset( $p['porcentajes'] ) && is_array( $p['porcentajes'] ) ? $p['porcentajes'] : array();
			foreach ( $porcentajes as $pct ) {
				if ( abs( (float) $pct - $wc_tax_rate ) < self::MATCH_TOLERANCE ) {
					return self::build_mapping_result( $p, (float) $pct );
				}
			}
		}

		// 3. Fallback: código "4" (15%) — el más común en Ecuador.
		foreach ( $params as $p ) {
			if ( isset( $p['codigoAuxiliar'] ) && self::DEFAULT_TARIFA_CODE === (string) $p['codigoAuxiliar'] ) {
				return self::build_mapping_result( $p, 15.0 );
			}
		}

		// 4. Último fallback: 0% (exento) si no hay 15%.
		foreach ( $params as $p ) {
			if ( isset( $p['codigoAuxiliar'] ) && self::ZERO_TARIFA_CODE === (string) $p['codigoAuxiliar'] ) {
				return self::build_mapping_result( $p, 0.0 );
			}
		}

		return null;
	}

	/**
	 * Construye el resultado de mapeo a partir de un parámetro SRI.
	 *
	 * @param array $p         Parámetro SRI del cache.
	 * @param float $porcentaje Porcentaje matched.
	 *
	 * @return array Mapeo listo para usar.
	 */
	private static function build_mapping_result( $p, $porcentaje ) {
		return array(
			'codigo'        => isset( $p['codigo'] ) ? (string) $p['codigo'] : '2',
			'codigoAuxiliar' => isset( $p['codigoAuxiliar'] ) ? (string) $p['codigoAuxiliar'] : self::DEFAULT_TARIFA_CODE,
			'porcentaje'    => $porcentaje,
			'descripcion'   => isset( $p['descripcion'] ) ? (string) $p['descripcion'] : null,
		);
	}

	/**
	 * Invalida el cache de parámetros SRI.
	 *
	 * Útil cuando el admin cambia configuración o cuando se desea forzar
	 * una recarga en el siguiente request.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Determina si un porcentaje WC tiene un mapeo SRI válido (sin emitir nada).
	 *
	 * @param float $wc_tax_rate Porcentaje WC.
	 *
	 * @return bool True si hay mapeo o fallback aplicable.
	 */
	public static function has_mapping( $wc_tax_rate ) {
		return null !== self::map_percentage_to_sri( $wc_tax_rate );
	}
}