<?php
/**
 * Capa de compatibilidad entre WC clásico (shortcode + filtros legacy) y WC Blocks.
 *
 * Esta clase abstrae las diferencias entre el filtro tradicional `woocommerce_billing_fields`
 * (usado en WC < 8.9 y temas clásicos) y la API moderna
 * `woocommerce_register_additional_checkout_field()` (WC >= 8.9 + block themes).
 *
 * Permite que el resto del plugin use una interfaz unificada sin importar la versión
 * de WooCommerce o el tipo de tema (block o clásico).
 *
 * @package AurafactWooCommerce
 * @subpackage Core
 */

namespace Aurafact\WooCommerce;

/**
 * Abstracción de compatibilidad entre versiones de WooCommerce y tipos de tema.
 *
 * @author Aurafact Team
 * @version 1.1.0
 */
class Compat {

    /**
     * Versión mínima de WC que soporta `woocommerce_register_additional_checkout_field()`.
     */
    const MIN_WC_VERSION_BLOCKS = '8.9.0';

    /**
     * Versión mínima de WC que soporta conditional fields con JSON Schema.
     */
    const MIN_WC_VERSION_CONDITIONAL = '9.9.0';

    /**
     * Prefijo usado para todas las keys de campos del plugin.
     */
    const FIELD_PREFIX = 'aurafact-woocommerce/';

    /**
     * Determina si la API moderna de WC Blocks está disponible.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return bool true si WC >= 8.9 y la función existe.
     */
    public static function use_blocks_api() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return false;
        }
        if ( ! defined( 'WC_VERSION' ) ) {
            return false;
        }
        return version_compare( WC_VERSION, self::MIN_WC_VERSION_BLOCKS, '>=' );
    }

    /**
     * Determina si los conditional fields (JSON Schema) están soportados.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return bool true si WC >= 9.9.
     */
    public static function supports_conditional_fields() {
        if ( ! self::use_blocks_api() ) {
            return false;
        }
        return version_compare( WC_VERSION, self::MIN_WC_VERSION_CONDITIONAL, '>=' );
    }

    /**
     * Registra un campo adicional en el checkout.
     *
     * Wrapper unificado: usa la API moderna si está disponible, o el filtro legacy como fallback.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param string $key       Identificador único del campo (sin prefijo). Ej: 'doc_type'.
     * @param array  $config    Configuración del campo (label, type, options, required, validate, sanitize).
     *
     * @return void
     */
    public static function register_field( $key, $config ) {
        if ( self::use_blocks_api() ) {
            self::register_blocks_field( $key, $config );
        } else {
            self::register_legacy_field( $key, $config );
        }
    }

    /**
     * Registra un campo usando la API moderna de WC Blocks.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param string $key    Identificador único.
     * @param array  $config Configuración del campo.
     *
     * @return void
     */
    private static function register_blocks_field( $key, $config ) {
        $blocks_config = array(
            'id'       => self::FIELD_PREFIX . $key,
            'label'    => $config['label'],
            'location' => isset( $config['location'] ) ? $config['location'] : 'address',
            'type'     => isset( $config['type'] ) ? $config['type'] : 'text',
            'required' => isset( $config['required'] ) ? $config['required'] : false,
        );

        if ( isset( $config['options'] ) ) {
            $blocks_config['options'] = $config['options'];
        }

        if ( isset( $config['sanitize_callback'] ) ) {
            $blocks_config['sanitize_callback'] = $config['sanitize_callback'];
        }

        if ( isset( $config['validate_callback'] ) ) {
            $blocks_config['validate_callback'] = $config['validate_callback'];
        }

        // Conditional fields: ocultar según schema (WC >= 9.9).
        if ( self::supports_conditional_fields() && isset( $config['hidden'] ) ) {
            $blocks_config['hidden'] = $config['hidden'];
        }

        woocommerce_register_additional_checkout_field( $blocks_config );
    }

    /**
     * Registra un campo usando el filtro legacy de WC.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param string $key    Identificador único.
     * @param array  $config Configuración del campo.
     *
     * @return void
     */
    private static function register_legacy_field( $key, $config ) {
        add_filter(
            'woocommerce_billing_fields',
            function ( $fields ) use ( $key, $config ) {
                $fields[ 'billing_' . $key ] = array(
                    'label'    => $config['label'],
                    'required' => isset( $config['required'] ) ? $config['required'] : false,
                    'type'     => isset( $config['type'] ) ? $config['type'] : 'text',
                    'class'    => array( 'form-row-wide', 'aurafact-' . str_replace( '_', '-', $key ) ),
                    'priority' => isset( $config['priority'] ) ? $config['priority'] : 35,
                );

                if ( isset( $config['options'] ) ) {
                    $legacy_options = array( '' => __( 'Selecciona una opción', 'aurafact-woocommerce' ) );
                    foreach ( $config['options'] as $option ) {
                        $legacy_options[ $option['value'] ] = $option['label'];
                    }
                    $fields[ 'billing_' . $key ]['options'] = $legacy_options;
                }

                return $fields;
            },
            20,
            1
        );
    }

    /**
     * Lee el valor de un campo fiscal de una orden, abstrayendo legacy vs blocks vs HPOS.
     *
     * Estrategia de lectura (orden de prioridad):
     * 1. `_additional_checkout_fields[<prefix><key>]` (WC Blocks API >= 8.9 sin HPOS)
     * 2. `_additional_checkout_fields[<key>]` (sin prefijo)
     * 3. `_wc_billing/<prefix><key>` (HPOS activo)
     * 4. `_wc_shipping/<prefix><key>` (HPOS activo, billing = shipping)
     * 5. `_billing_<key>` (legacy pre-HPOS)
     *
     * @author Aurafact Team
     * @version 1.1.3
     *
     * @param \WC_Order $order    Objeto de la orden.
     * @param string    $key      Key del campo (sin prefijo). Ej: 'doc_type'.
     * @param mixed     $default  Valor por defecto si no se encuentra.
     *
     * @return mixed Valor del campo o el default.
     */
    public static function get_field_value( $order, $key, $default = '' ) {
        if ( ! $order ) {
            return $default;
        }

        $prefixed_key = self::FIELD_PREFIX . $key;

        // 1. WC Blocks API (sin HPOS): meta agrupado.
        $all = $order->get_meta( '_additional_checkout_fields', true );
        if ( is_array( $all ) ) {
            if ( isset( $all[ $prefixed_key ] ) && '' !== $all[ $prefixed_key ] ) {
                return $all[ $prefixed_key ];
            }
            if ( isset( $all[ $key ] ) && '' !== $all[ $key ] ) {
                return $all[ $key ];
            }
        }

        // 2. HPOS activo: WC prefija los metas con `_wc_billing/` o `_wc_shipping/`.
        $hpos_value = $order->get_meta( '_wc_billing/' . $prefixed_key, true );
        if ( ! empty( $hpos_value ) ) {
            return $hpos_value;
        }

        $hpos_shipping = $order->get_meta( '_wc_shipping/' . $prefixed_key, true );
        if ( ! empty( $hpos_shipping ) ) {
            return $hpos_shipping;
        }

        // 3. Fallback legacy pre-HPOS.
        $legacy_value = $order->get_meta( '_billing_' . $key, true );
        if ( ! empty( $legacy_value ) ) {
            return $legacy_value;
        }

        return $default;
    }

    /**
     * Persiste los campos en una orden. Llamar una vez con todos los campos.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param \WC_Order $order  Objeto de la orden.
     * @param array     $values  Array asociativo [key => valor].
     *
     * @return void
     */
    public static function save_fields( $order, array $values ) {
        if ( ! $order ) {
            return;
        }

        if ( self::use_blocks_api() ) {
            // WC Blocks API persiste automáticamente al hacer checkout_update_order_meta.
            // Pero como respaldo, también guardamos en formato legacy por si algún plugin lee los metas.
            $all = $order->get_meta( '_additional_checkout_fields', true );
            if ( ! is_array( $all ) ) {
                $all = array();
            }
            foreach ( $values as $key => $value ) {
                $all[ self::FIELD_PREFIX . $key ] = $value;
                // Compatibilidad: también guardar en meta legacy con prefijo _billing_.
                $order->update_meta_data( '_billing_' . $key, $value );
            }
            $order->update_meta_data( '_additional_checkout_fields', $all );
        } else {
            foreach ( $values as $key => $value ) {
                $order->update_meta_data( '_billing_' . $key, $value );
            }
        }
    }

    /**
     * Hook de validación unificado. Registra en el hook correcto según la versión de WC.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param callable $callback Función que recibe ($errors, $fields, $group).
     *
     * @return void
     */
    public static function register_validator( $callback ) {
        if ( self::use_blocks_api() ) {
            add_action( 'woocommerce_blocks_validate_location_address_fields', $callback, 10, 3 );
        } else {
            add_action( 'woocommerce_checkout_process', $callback );
        }
    }
}
