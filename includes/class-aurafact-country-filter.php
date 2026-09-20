<?php
/**
 * Filtro de cobertura geográfica para emisión de facturas.
 *
 * Determina si los campos fiscales deben mostrarse en el checkout según:
 * - Configuración del admin (solo Ecuador / todos los países)
 * - País de facturación seleccionado por el cliente
 *
 * @package AurafactWooCommerce
 * @subpackage Core
 */

namespace Aurafact\WooCommerce;

/**
 * Lógica de filtro por país para emisión de facturas electrónicas.
 *
 * @author Aurafact Team
 * @version 1.1.0
 */
class CountryFilter {

    /**
     * Option key en wp_options.
     */
    const OPTION_NAME = 'aurafact_wc_country_restriction';

    /**
     * Valor: solo Ecuador (default, recomendado).
     */
    const MODE_EC_ONLY = 'EC_ONLY';

    /**
     * Valor: todos los países (el merchant decide cuándo facturar).
     */
    const MODE_ALL = 'ALL';

    /**
     * País por defecto: Ecuador.
     */
    const DEFAULT_COUNTRY = 'EC';

    /**
     * Obtiene la configuración actual.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return string Una de las constantes MODE_*.
     */
    public static function get_mode() {
        return get_option( self::OPTION_NAME, self::MODE_EC_ONLY );
    }

    /**
     * Determina si los campos fiscales deben mostrarse para un país dado.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param string $country Código ISO del país (ej: 'EC', 'CO'). Vacío = aún no seleccionado.
     *
     * @return bool true si los campos deben mostrarse.
     */
    public static function should_show_fields( $country ) {
        $mode = self::get_mode();

        if ( self::MODE_ALL === $mode ) {
            return true;
        }

        // MODE_EC_ONLY: solo si el país es Ecuador.
        return strtoupper( (string) $country ) === self::DEFAULT_COUNTRY;
    }

    /**
     * Determina si una orden debe emitir factura según su país.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param \WC_Order $order Orden de WooCommerce.
     *
     * @return bool true si debe emitir factura.
     */
    public static function should_emit_for_order( $order ) {
        if ( ! $order ) {
            return false;
        }

        $country = $order->get_billing_country();
        return self::should_show_fields( $country );
    }

    /**
     * Schema JSON para ocultar campos en WC Blocks cuando no aplica.
     *
     * Compatible con WC >= 9.9. Si la versión no soporta JSON Schema, devuelve array vacío.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return array Schema para la propiedad 'hidden' de woocommerce_register_additional_checkout_field.
     */
    public static function get_blocks_hidden_schema() {
        if ( ! Compat::supports_conditional_fields() ) {
            return array();
        }

        // Si modo = ALL, nunca ocultar.
        if ( self::MODE_ALL === self::get_mode() ) {
            return array();
        }

        // MODE_EC_ONLY: ocultar si el país de billing no es Ecuador.
        return array(
            'customer' => array(
                'properties' => array(
                    'address' => array(
                        'properties' => array(
                            'country' => array(
                                'not' => array(
                                    'enum' => array( self::DEFAULT_COUNTRY ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Devuelve la lista de países permitidos para facturación.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return array Lista de códigos ISO.
     */
    public static function get_allowed_countries() {
        $mode = self::get_mode();

        if ( self::MODE_ALL === $mode ) {
            // Todos los países disponibles en WooCommerce.
            $countries = WC()->countries ? WC()->countries->get_allowed_countries() : array();
            return is_array( $countries ) ? array_keys( $countries ) : array();
        }

        return array( self::DEFAULT_COUNTRY );
    }
}
