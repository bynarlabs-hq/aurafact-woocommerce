<?php
/**
 * Campos fiscales ecuatorianos y validación en el checkout de WooCommerce.
 *
 * Inyecta los campos de identificación fiscal (tipo documento, número, razón social)
 * en el checkout, implementa validación de módulo 10/11 y persiste los datos
 * en los metadatos de la orden.
 *
 * Compatible con WC clásico (shortcode + filtros legacy) y WC Blocks
 * (API moderna `woocommerce_register_additional_checkout_field()`).
 *
 * @package AurafactWooCommerce
 * @subpackage Checkout
 */

namespace Aurafact\WooCommerce;

/**
 * Gestión de campos fiscales en el checkout.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @author Aurafact Team
 * @version 1.1.0
 */
class Checkout {

    /**
     * Instancia única del singleton.
     *
     * @var Checkout|null
     */
    private static $instance = null;

    /**
     * Key del campo tipo de documento.
     */
    const FIELD_DOC_TYPE = 'doc_type';

    /**
     * Key del campo número de documento.
     */
    const FIELD_DOC_NUMBER = 'doc_number';

    /**
     * Key del campo razón social.
     */
    const FIELD_BUSINESS_NAME = 'business_name';

    /**
     * Catálogo de tipos de identificación SRI Ecuador.
     */
    const DOC_TYPES = array(
        array( 'value' => '04', 'label' => 'RUC' ),
        array( 'value' => '05', 'label' => 'Cédula' ),
        array( 'value' => '06', 'label' => 'Pasaporte' ),
        array( 'value' => '07', 'label' => 'Consumidor Final' ),
    );

    /**
     * Obtiene la instancia única del singleton.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
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
     * Detecta automáticamente si WC Blocks API está disponible y elige el camino.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
     *
     * @return void
     */
    public function init() {
        if ( Compat::use_blocks_api() ) {
            add_action( 'woocommerce_init', array( $this, 'register_blocks_fields' ) );
            Compat::register_validator( array( $this, 'validate_fiscal_fields' ) );
        } else {
            // El registro legacy se hace vía Compat::register_field que internamente usa
            // add_filter('woocommerce_billing_fields', ...). El hook se ejecuta durante
            // el render del checkout, no aquí.
            add_action( 'woocommerce_init', array( $this, 'register_legacy_fields' ) );
        }

        // Persistir metadatos al crear la orden (común a ambos paths).
        add_action( 'woocommerce_checkout_order_created', array( $this, 'save_fiscal_meta_legacy' ), 10, 2 );

        // Mostrar metadatos en el detalle de la orden en admin.
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_admin_order_meta' ), 10, 1 );

        // Encolar JS de validación (solo legacy; WC Blocks hace validación nativa).
        if ( ! Compat::use_blocks_api() ) {
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
        }
    }

    /**
     * Registra los campos vía API moderna de WC Blocks.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return void
     */
    public function register_blocks_fields() {
        $hidden_schema = CountryFilter::get_blocks_hidden_schema();

        // 1. Tipo de identificación.
        Compat::register_field(
            self::FIELD_DOC_TYPE,
            array(
                'label'    => __( 'Tipo de identificación', 'aurafact-woocommerce' ),
                'location' => 'address',
                'type'     => 'select',
                'options'  => self::get_doc_type_options_for_blocks(),
                'required' => true,
                'hidden'   => $hidden_schema,
            )
        );

        // 2. Número de identificación.
        Compat::register_field(
            self::FIELD_DOC_NUMBER,
            array(
                'label'    => __( 'Número de identificación', 'aurafact-woocommerce' ),
                'location' => 'address',
                'type'     => 'text',
                'required' => true,
                'hidden'   => $hidden_schema,
            )
        );

        // 3. Razón social (opcional, solo requerida condicionalmente en validación).
        Compat::register_field(
            self::FIELD_BUSINESS_NAME,
            array(
                'label'    => __( 'Razón social', 'aurafact-woocommerce' ),
                'location' => 'address',
                'type'     => 'text',
                'required' => false,
                'hidden'   => $hidden_schema,
            )
        );
    }

    /**
     * Registra los campos vía filtros legacy (para WC < 8.9 o shortcode clásico).
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return void
     */
    public function register_legacy_fields() {
        Compat::register_field(
            self::FIELD_DOC_TYPE,
            array(
                'label'    => __( 'Tipo de identificación', 'aurafact-woocommerce' ),
                'type'     => 'select',
                'options'  => self::get_doc_type_options_for_blocks(),
                'required' => true,
                'priority' => 35,
            )
        );

        Compat::register_field(
            self::FIELD_DOC_NUMBER,
            array(
                'label'    => __( 'Número de identificación', 'aurafact-woocommerce' ),
                'type'     => 'text',
                'required' => true,
                'priority' => 36,
            )
        );

        Compat::register_field(
            self::FIELD_BUSINESS_NAME,
            array(
                'label'    => __( 'Razón social', 'aurafact-woocommerce' ),
                'type'     => 'text',
                'required' => false,
                'priority' => 37,
            )
        );

        // Validación legacy.
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_legacy' ) );
    }

    /**
     * Devuelve las opciones de tipo de documento en formato WC Blocks.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @return array Array de ['value' => '', 'label' => 'Selecciona...', ...].
     */
    private static function get_doc_type_options_for_blocks() {
        $options = array(
            array(
                'value' => '',
                'label' => __( 'Selecciona un tipo', 'aurafact-woocommerce' ),
            ),
        );
        foreach ( self::DOC_TYPES as $doc_type ) {
            $options[] = $doc_type;
        }
        return $options;
    }

    /**
     * Validación unificada de campos fiscales (compat con ambos paths).
     *
     * En WC Blocks API: recibe ($errors, $fields, $group).
     * En legacy: no recibe args; lee de $_POST.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param mixed ...$args Argumentos variables según el hook.
     *
     * @return void
     */
    public function validate_fiscal_fields( ...$args ) {
        if ( Compat::use_blocks_api() ) {
            // WC Blocks: $errors, $fields, $group.
            list( $errors, $fields, $group ) = $args;
            $doc_type   = isset( $fields[ Compat::FIELD_PREFIX . self::FIELD_DOC_TYPE ] ) ? sanitize_text_field( $fields[ Compat::FIELD_PREFIX . self::FIELD_DOC_TYPE ] ) : '';
            $doc_number = isset( $fields[ Compat::FIELD_PREFIX . self::FIELD_DOC_NUMBER ] ) ? sanitize_text_field( $fields[ Compat::FIELD_PREFIX . self::FIELD_DOC_NUMBER ] ) : '';
            $business_name = isset( $fields[ Compat::FIELD_PREFIX . self::FIELD_BUSINESS_NAME ] ) ? sanitize_text_field( $fields[ Compat::FIELD_PREFIX . self::FIELD_BUSINESS_NAME ] ) : '';

            $this->run_validation( $errors, $doc_type, $doc_number, $business_name, 'woocommerce' === $errors->get_error_code() ? false : true );
            return;
        }

        // Legacy: no-op (validate_legacy se encarga).
    }

    /**
     * Validación legacy (lee de $_POST directamente).
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
     *
     * @return void
     */
    public function validate_legacy() {
        $doc_type      = isset( $_POST[ 'billing_' . self::FIELD_DOC_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'billing_' . self::FIELD_DOC_TYPE ] ) ) : '';
        $doc_number    = isset( $_POST[ 'billing_' . self::FIELD_DOC_NUMBER ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'billing_' . self::FIELD_DOC_NUMBER ] ) ) : '';
        $business_name = isset( $_POST[ 'billing_' . self::FIELD_BUSINESS_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'billing_' . self::FIELD_BUSINESS_NAME ] ) ) : '';

        $errors = new \WP_Error();
        $this->run_validation( $errors, $doc_type, $doc_number, $business_name, false );

        // Si hay errores, agregarlos como notices de WC.
        $error_codes = $errors->get_error_codes();
        if ( ! empty( $error_codes ) ) {
            foreach ( $error_codes as $code ) {
                wc_add_notice( $errors->get_error_message( $code ), 'error' );
            }
        }
    }

    /**
     * Lógica de validación común a ambos paths.
     *
     * @author Aurafact Team
     * @version 1.1.0
     *
     * @param \WP_Error $errors        Objeto de errores.
     * @param string    $doc_type      Tipo de documento.
     * @param string    $doc_number    Número de documento.
     * @param string    $business_name Razón social.
     * @param bool      $use_wp_error  Si true, agrega errores directamente al objeto WP_Error (WC Blocks).
     *
     * @return void
     */
    private function run_validation( $errors, $doc_type, $doc_number, $business_name, $use_wp_error = false ) {
        $add_error = function ( $code, $message ) use ( $errors, $use_wp_error ) {
            if ( $use_wp_error && $errors instanceof \WP_Error ) {
                $errors->add( $code, $message );
            } else {
                wc_add_notice( $message, 'error' );
            }
        };

        // Validar que el tipo esté seleccionado.
        if ( empty( $doc_type ) ) {
            $add_error( 'aurafact_doc_type_required', __( 'Selecciona un tipo de identificación.', 'aurafact-woocommerce' ) );
            return;
        }

        // Validar según tipo.
        switch ( $doc_type ) {
            case '05': // Cédula.
                $this->validate_cedula( $doc_number, $add_error );
                break;

            case '04': // RUC.
                $this->validate_ruc( $doc_number, $add_error );
                if ( empty( $business_name ) ) {
                    $add_error( 'aurafact_business_name_required', __( 'La razón social es obligatoria para RUC.', 'aurafact-woocommerce' ) );
                }
                break;

            case '06': // Pasaporte.
                if ( strlen( $doc_number ) < 5 ) {
                    $add_error( 'aurafact_passport_min_length', __( 'El pasaporte debe tener al menos 5 caracteres.', 'aurafact-woocommerce' ) );
                }
                break;

            case '07': // Consumidor Final.
                if ( '9999999999999' !== $doc_number ) {
                    $add_error( 'aurafact_cf_must_be_999', __( 'Consumidor Final debe usar el número 9999999999999.', 'aurafact-woocommerce' ) );
                }
                break;

            default:
                $add_error( 'aurafact_invalid_doc_type', __( 'Tipo de identificación no válido.', 'aurafact-woocommerce' ) );
                break;
        }
    }

    /**
     * Valida una cédula ecuatoriana.
     *
     * Modos disponibles (configurados en admin):
     * - `format_only` (default): solo valida formato (10 dígitos + provincia válida).
     * - `algorithm`: aplica módulo 10 (puede rechazar cédulas emitidas antes del 2000).
     * - `disabled`: no valida nada.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @author Aurafact Team
     * @version 1.1.5
     *
     * @param string   $doc_number Número de cédula.
     * @param callable $add_error  Callback para agregar errores.
     *
     * @return void
     */
    private function validate_cedula( $doc_number, $add_error ) {
        $mode = get_option( 'aurafact_wc_cedula_validation_mode', 'format_only' );

        // Validación de formato (siempre se ejecuta).
        if ( 10 !== strlen( $doc_number ) || ! ctype_digit( $doc_number ) ) {
            $add_error( 'aurafact_invalid_cedula_length', __( 'La cédula debe tener 10 dígitos numéricos.', 'aurafact-woocommerce' ) );
            return;
        }

        $provincia = (int) substr( $doc_number, 0, 2 );
        if ( $provincia < 1 || $provincia > 24 ) {
            $add_error( 'aurafact_invalid_cedula_province', __( 'El código de provincia de la cédula no es válido (debe ser 01-24).', 'aurafact-woocommerce' ) );
            return;
        }

        if ( 'disabled' === $mode ) {
            return;
        }

        if ( 'format_only' === $mode ) {
            // Modo recomendado: solo formato.
            return;
        }

        // mode === 'algorithm': aplicar módulo 10.
        // ADVERTENCIA: este algoritmo puede rechazar cédulas emitidas antes del 2000
        // o con errores de transcripción del SRI.
        $coeficientes = array( 2, 1, 2, 1, 2, 1, 2, 1, 2 );
        $suma         = 0;

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
            $add_error( 'aurafact_invalid_cedula', __( 'La cédula no pasa la validación algorítmica. Si es una cédula real, cambia "Validación de Cédula" a "Solo formato" en la configuración.', 'aurafact-woocommerce' ) );
        }
    }

    /**
     * Valida un RUC ecuatoriano.
     *
     * Modos disponibles (configurados en admin):
     * - `format_only` (default): solo valida formato (13 dígitos + provincia válida).
     *   No rechaza RUCs reales de sociedades públicas/privadas.
     * - `algorithm`: aplica módulo 11 (puede rechazar RUCs válidos de otros tipos).
     * - `disabled`: no valida nada.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @author Aurafact Team
     * @version 1.1.4
     *
     * @param string   $doc_number Número de RUC.
     * @param callable $add_error  Callback para agregar errores.
     *
     * @return void
     */
    private function validate_ruc( $doc_number, $add_error ) {
        $mode = get_option( 'aurafact_wc_ruc_validation_mode', 'format_only' );

        // Validación de formato (siempre se ejecuta).
        if ( 13 !== strlen( $doc_number ) || ! ctype_digit( $doc_number ) ) {
            $add_error( 'aurafact_invalid_ruc_length', __( 'El RUC debe tener 13 dígitos numéricos.', 'aurafact-woocommerce' ) );
            return;
        }

        $provincia = (int) substr( $doc_number, 0, 2 );
        if ( $provincia < 1 || $provincia > 24 ) {
            $add_error( 'aurafact_invalid_ruc_province', __( 'El código de provincia del RUC no es válido (debe ser 01-24).', 'aurafact-woocommerce' ) );
            return;
        }

        if ( 'disabled' === $mode ) {
            return;
        }

        if ( 'format_only' === $mode ) {
            // Modo recomendado: solo formato. No rechazamos RUCs por algoritmo.
            return;
        }

        // mode === 'algorithm': aplicar módulo 11.
        // ADVERTENCIA: este algoritmo solo es válido para RUCs de persona natural
        // (3er dígito 0-5 + sufijo 001). Rechazará RUCs válidos de sociedades privadas
        // (3er dígito 9) y entidades públicas (3er dígito 6).
        $sufijo = substr( $doc_number, -3 );
        if ( '001' !== $sufijo ) {
            $add_error( 'aurafact_invalid_ruc_suffix', __( 'El RUC no es válido (los últimos 3 dígitos deben ser 001 para persona natural).', 'aurafact-woocommerce' ) );
            return;
        }

        $coeficientes = array( 4, 3, 2, 7, 6, 5, 4, 3, 2 );
        $suma         = 0;

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
            $add_error( 'aurafact_invalid_ruc', __( 'El RUC no pasa la validación algorítmica. Si es un RUC real de sociedad, cambia "Validación de RUC" a "Solo formato" en la configuración.', 'aurafact-woocommerce' ) );
        }
    }

    /**
     * Persiste los metadatos fiscales en la orden (legacy).
     *
     * Para WC Blocks API, el guardado es automático. Este método es respaldo.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
     *
     * @param \WC_Order $order  Objeto de la orden.
     * @param array     $data   Datos del checkout.
     *
     * @return void
     */
    public function save_fiscal_meta_legacy( $order, $data ) {
        $values = array();

        if ( isset( $data[ 'billing_' . self::FIELD_DOC_TYPE ] ) ) {
            $values[ self::FIELD_DOC_TYPE ] = sanitize_text_field( $data[ 'billing_' . self::FIELD_DOC_TYPE ] );
        }

        if ( isset( $data[ 'billing_' . self::FIELD_DOC_NUMBER ] ) ) {
            $values[ self::FIELD_DOC_NUMBER ] = sanitize_text_field( $data[ 'billing_' . self::FIELD_DOC_NUMBER ] );
        }

        if ( isset( $data[ 'billing_' . self::FIELD_BUSINESS_NAME ] ) ) {
            $values[ self::FIELD_BUSINESS_NAME ] = sanitize_text_field( $data[ 'billing_' . self::FIELD_BUSINESS_NAME ] );
        }

        if ( ! empty( $values ) ) {
            Compat::save_fields( $order, $values );
        }
    }

    /**
     * Muestra los metadatos fiscales en el detalle de la orden en admin.
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
     *
     * @param \WC_Order $order Objeto de la orden.
     *
     * @return void
     */
    public function display_admin_order_meta( $order ) {
        $doc_type      = Compat::get_field_value( $order, self::FIELD_DOC_TYPE );
        $doc_number    = Compat::get_field_value( $order, self::FIELD_DOC_NUMBER );
        $business_name = Compat::get_field_value( $order, self::FIELD_BUSINESS_NAME );

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
     * Encola el script de validación en el checkout (solo legacy).
     *
     * @author Fabian Silva <fabian.silva@consulti.ec>
     * @version 1.1.0
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
