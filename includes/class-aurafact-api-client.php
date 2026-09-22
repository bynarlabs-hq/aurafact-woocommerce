<?php
/**
 * Cliente HTTP para la API pública de Aurafact.
 *
 * Implementa la comunicación con los endpoints de facturación electrónica:
 * emisión de facturas, consulta de documentos y verificación de conexión.
 *
 * @package AurafactWooCommerce
 * @subpackage API
 */

namespace Aurafact\WooCommerce;

/**
 * Cliente HTTP para la API REST de Aurafact.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class ApiClient {

	/**
	 * Instancia única del singleton.
	 *
	 * @var ApiClient|null
	 */
	private static $instance = null;

	/**
	 * Último error ocurrido.
	 *
	 * @var string|null
	 */
	private $last_error = null;

	/**
	 * Endpoints de la API.
	 */
	const ENDPOINT_INVOICES   = '/v1/invoices';
	const ENDPOINT_DOCUMENTS  = '/v1/documents';
	const ENDPOINT_HEALTH     = '/v1/health';

	/**
	 * Versión mínima del contrato de la API que el plugin espera.
	 * Se valida contra la cabecera X-Aurafact-API-Version de la respuesta.
	 */
	const MIN_API_VERSION = '1.0.0';

	/**
	 * Timeout de conexión en segundos.
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Mapeo de gateways de pago WooCommerce a códigos SRI.
	 */
	const PAYMENT_GATEWAY_MAP = array(
		'codigo'  => '01',
		'plazo'   => '0',
		'unidadTiempo' => 'dias',
	);

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return ApiClient Instancia única.
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
	 * Obtiene la configuración del plugin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return AdminSettings
	 */
	private function get_settings() {
		return AdminSettings::get_instance();
	}

	/**
	 * Realiza una petición HTTP a la API de Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $method  Método HTTP (GET, POST).
	 * @param string $path    Ruta del endpoint (ej: /v1/invoices).
	 * @param array  $body    Cuerpo de la petición (para POST).
	 *
	 * @return array Respuesta con keys: success, data, error.
	 */
	private function request( $method, $path, $body = array() ) {
		$this->last_error = null;

		$settings = $this->get_settings();
		$api_key  = $settings->get_api_key();
		$base_url = $settings->get_api_base_url();

		if ( empty( $api_key ) ) {
			$this->last_error = __( 'API Key no configurada.', 'aurafact-woocommerce' );
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $this->last_error,
			);
		}

		$url = untrailingslashit( $base_url ) . $path;

		$args = array(
			'method'    => $method,
			'timeout'   => self::REQUEST_TIMEOUT,
			'headers'   => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'AurafactWooCommerce/' . AURAFACT_WC_VERSION,
			),
		);

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$settings->log( sprintf( 'API %s %s', $method, $url ) );

		$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			$settings->log( 'API Error: ' . $this->last_error );
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $this->last_error,
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		$settings->log( sprintf( 'API Response %d: %s', $status_code, substr( $response_body, 0, 500 ) ) );

		// Validar versión del contrato de la API contra X-Aurafact-API-Version.
		$api_version = wp_remote_retrieve_header( $response, 'x-aurafact-api-version' );
		if ( ! empty( $api_version ) ) {
			$this->validar_version_api( $api_version );
		}

		if ( $status_code >= 200 && $status_code < 300 ) {
			return array(
				'success' => true,
				'data'    => $data,
				'error'   => null,
			);
		}

		// Manejo de errores HTTP.
		$error_message = '';
		if ( isset( $data['message'] ) ) {
			$error_message = $data['message'];
		} elseif ( isset( $data['error'] ) ) {
			$error_message = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
		} else {
			$error_message = sprintf(
				/* translators: %d: Código de estado HTTP */
				__( 'Error HTTP %d', 'aurafact-woocommerce' ),
				$status_code
			);
		}

		$this->last_error = $error_message;

		return array(
			'success' => false,
			'data'    => $data,
			'error'   => $error_message,
		);
	}

	/**
	 * Emite una factura electrónica a partir de una orden de WooCommerce.
	 *
	 * Construye el payload completo según el contrato PublicInvoiceRequest
	 * y lo envía a POST /v1/invoices.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param int $order_id ID de la orden de WooCommerce.
	 *
	 * @return array Respuesta con keys: success, data, error.
	 */
	public function emitir_factura( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->last_error = sprintf(
				/* translators: %d: ID de orden */
				__( 'Orden %d no encontrada.', 'aurafact-woocommerce' ),
				$order_id
			);
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $this->last_error,
			);
		}

		$payload = $this->build_invoice_payload( $order );

		$settings = $this->get_settings();
		$settings->log( sprintf(
			'Enviando factura para orden %d: %s',
			$order_id,
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		) );

		return $this->request( 'POST', self::ENDPOINT_INVOICES, $payload );
	}

	/**
	 * Construye el payload de facturación a partir de una orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden de WooCommerce.
	 *
	 * @return array Payload para POST /v1/invoices.
	 */
	public function build_invoice_payload( $order ) {
		$settings = $this->get_settings();

		// Cliente.
		$cliente = $this->build_cliente_info( $order );

		// Items (productos) + shipping.
		$items = $this->build_items( $order );

		// Aplicar descuentos proporcionales si hay cupones.
		$items = $this->apply_coupon_discounts( $order, $items );

		// Formas de pago.
		$formas_pago = $this->build_formas_pago( $order );

		// Información adicional.
		$info_adicional = array(
			'Email'       => $order->get_billing_email(),
			'Vendedor'    => 'WooCommerce',
			'NumeroOrden' => (string) $order->get_id(),
		);

		$payload = array(
			'tipoDocumento'   => '01',
			'establecimiento' => '001',
			'puntoEmision'    => '002',
			'cliente'         => $cliente,
			'items'           => $items,
			'formasPago'      => $formas_pago,
			'infoAdicional'   => $info_adicional,
		);

		return $payload;
	}

	/**
	 * Construye ClienteInfo a partir de la orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return array ClienteInfo.
	 */
	private function build_cliente_info( $order ) {
		$doc_type      = Compat::get_field_value( $order, 'doc_type' );
		$doc_number    = Compat::get_field_value( $order, 'doc_number' );
		$business_name = Compat::get_field_value( $order, 'business_name' );

		// Razón social: preferir el metadato fiscal, o el nombre completo.
		$razon_social = ! empty( $business_name )
			? $business_name
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		// Dirección completa.
		$direccion = trim( implode( ', ', array_filter( array(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
		) ) ) );

		return array(
			'tipoIdentificacion' => ! empty( $doc_type ) ? $doc_type : '07',
			'identificacion'     => ! empty( $doc_number ) ? $doc_number : '9999999999999',
			'razonSocial'        => $razon_social,
			'direccion'          => $direccion,
			'email'              => $order->get_billing_email(),
			'telefono'           => $order->get_billing_phone(),
		);
	}

	/**
	 * Construye el arreglo de items desde la orden.
	 *
	 * Incluye productos y gastos de envío.
	 *
	 * Soporta "precios con IVA incluido" (woocommerce_prices_include_tax = yes).
	 * Cuando los precios incluyen IVA, el backend espera base imponible
	 * (sin IVA), por lo que se descuenta el IVA del subtotal antes de enviarlo.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.2.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return array Arreglo de ItemInfo.
	 */
	private function build_items( $order ) {
		$items = array();

		$prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) === 'yes';

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			// get_sku() es del producto, no del item.
			$codigo_principal = $product ? $product->get_sku() : '';
			if ( empty( $codigo_principal ) && $product ) {
				$codigo_principal = (string) $product->get_id();
			}
			if ( empty( $codigo_principal ) ) {
				$codigo_principal = 'PROD-' . $item->get_id();
			}

			$taxes    = $this->get_item_tax_info( $item, $order );
			$quantity = max( 1, (float) $item->get_quantity() );

			// Precio unitario: si los precios incluyen impuesto, hay que extraer
			// la base imponible para que el backend calcule correctamente.
			$subtotal = (float) $item->get_subtotal();
			if ( $prices_include_tax && isset( $taxes['porcentaje'] ) && $taxes['porcentaje'] > 0 ) {
				$tasa            = $taxes['porcentaje'] / 100;
				$base_imponible  = $subtotal / ( 1 + $tasa );
				$precio_unitario = $base_imponible / $quantity;
			} else {
				$precio_unitario = $subtotal / $quantity;
			}

			$items[] = array(
				'codigoPrincipal' => $codigo_principal,
				'codigoAuxiliar'  => null,
				'descripcion'     => $item->get_name(),
				'cantidad'        => (string) $item->get_quantity(),
				'precioUnitario'  => wc_format_decimal( $precio_unitario, 2 ),
				'descuento'       => wc_format_decimal( $item->get_subtotal() - $item->get_total(), 2 ),
				'codigoImpuesto'  => $taxes['codigoImpuesto'],
				'codigoTarifa'    => $taxes['codigoTarifa'],
			);
		}

		// Agregar shipping como item adicional.
		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$shipping_taxes   = $this->get_shipping_tax_info( $order );
			$shipping_unit    = $shipping_total;

			if ( $prices_include_tax && isset( $shipping_taxes['porcentaje'] ) && $shipping_taxes['porcentaje'] > 0 ) {
				$tasa_shipping    = $shipping_taxes['porcentaje'] / 100;
				$shipping_unit    = $shipping_total / ( 1 + $tasa_shipping );
			}

			$items[] = array(
				'codigoPrincipal' => 'SHIPPING',
				'codigoAuxiliar'  => null,
				'descripcion'     => sprintf(
					/* translators: %s: Método de envío */
					__( 'Gastos de envío - %s', 'aurafact-woocommerce' ),
					$order->get_shipping_method()
				),
				'cantidad'        => '1',
				'precioUnitario'  => wc_format_decimal( $shipping_unit, 2 ),
				'descuento'       => '0.00',
				'codigoImpuesto'  => $shipping_taxes['codigoImpuesto'],
				'codigoTarifa'    => $shipping_taxes['codigoTarifa'],
			);
		}

		return $items;
	}

	/**
	 * Obtiene la información de impuestos de un item.
	 *
	 * Mapea las tasas de WooCommerce a los códigos de Aurafact (SRI) consultando
	 * el catálogo del backend vía {@see SriMapper}. Si la tarifa efectiva no
	 * aparece en el catálogo, intenta primero el porcentaje del tax rate de WC
	 * y luego cae al default (15%).
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @author Aurafact Team
	 * @version 1.2.0
	 *
	 * @param \WC_Order_Item_Product $item  Item de la orden.
	 * @param \WC_Order              $order Orden.
	 *
	 * @return array Con keys codigoImpuesto, codigoTarifa, porcentaje (para
	 *               manejo de "precios con IVA incluido").
	 */
	private function get_item_tax_info( $item, $order ) {
		$taxes = $item->get_taxes();

		$effective_rate = $this->compute_effective_tax_rate( $item, $taxes );

		// Si no se pudo calcular el %, intentar leer la tarifa declarada en WC.
		if ( null === $effective_rate ) {
			$effective_rate = $this->get_product_tax_rate( $item );
		}

		// Si aún no hay % (producto sin clase), default 15% (tarifa general Ecuador).
		if ( null === $effective_rate ) {
			$effective_rate = 15.0;
		}

		// Mapeo a través del SriMapper.
		$mapping = SriMapper::map_percentage_to_sri( $effective_rate );

		if ( null === $mapping ) {
			// No hay mapeo válido: notificar al admin vía order note y bloquear emisión.
			$order->add_order_note(
				sprintf(
					/* translators: %s: Porcentaje de impuesto sin mapeo SRI */
					__( 'Aurafact: No se encontró código SRI para %.2f%%. Configura las clases de impuestos en WC o contacta a soporte.', 'aurafact-woocommerce' ),
					$effective_rate
				)
			);
			throw new \Exception(
				sprintf(
					'Aurafact: No SRI code for %.2f%% tax rate',
					$effective_rate
				)
			);
		}

		return array(
			'codigoImpuesto' => $mapping['codigo'],
			'codigoTarifa'   => $mapping['codigoAuxiliar'],
			'porcentaje'     => isset( $mapping['porcentaje'] ) ? (float) $mapping['porcentaje'] : 0.0,
		);
	}

	/**
	 * Calcula la tasa efectiva de impuesto a partir de los totales del item.
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param array                  $taxes Resultado de {@see \WC_Order_Item::get_taxes()}.
	 *
	 * @return float|null Tasa efectiva (%). Null si no se puede calcular.
	 */
	private function compute_effective_tax_rate( $item, $taxes ) {
		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return null;
		}

		$tax_total = array_sum( array_map( 'floatval', $taxes['total'] ) );
		if ( $tax_total <= 0 ) {
			return 0.0;
		}

		$subtotal = (float) $item->get_subtotal();
		if ( $subtotal <= 0 ) {
			return null;
		}

		return ( $tax_total / $subtotal ) * 100;
	}

	/**
	 * Obtiene la tasa declarada en la configuración de WC para el producto del item.
	 *
	 * Útil cuando el item no tiene taxes aplicados (ej: aún no procesado) pero
	 * la configuración de WC tiene una tarifa para la clase del producto.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 *
	 * @return float|null Tasa (%). Null si el producto no tiene clase.
	 */
	private function get_product_tax_rate( $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return null;
		}

		$tax_class = $product->get_tax_class();
		$tax_rates = \WC_Tax::get_rates_for_tax_class( $tax_class );

		foreach ( $tax_rates as $rate ) {
			if ( isset( $rate->rate ) ) {
				return (float) $rate->rate;
			}
		}

		return null;
	}

	/**
	 * Obtiene la información de impuestos del envío.
	 *
	 * Usa el mismo flujo que los items: calcula el % efectivo y mapea a SRI.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @author Aurafact Team
	 * @version 1.2.0
	 *
	 * @param \WC_Order $order Orden.
	 *
	 * @return array Con keys codigoImpuesto, codigoTarifa, porcentaje.
	 */
	private function get_shipping_tax_info( $order ) {
		$shipping_tax  = (float) $order->get_shipping_tax();
		$shipping_total = (float) $order->get_shipping_total();

		if ( $shipping_tax <= 0 || $shipping_total <= 0 ) {
			return array(
				'codigoImpuesto' => '2',
				'codigoTarifa'   => '0',
				'porcentaje'     => 0.0,
			);
		}

		$effective_rate = ( $shipping_tax / $shipping_total ) * 100;

		$mapping = SriMapper::map_percentage_to_sri( $effective_rate );
		if ( null === $mapping ) {
			return array(
				'codigoImpuesto' => '2',
				'codigoTarifa'   => '4',
				'porcentaje'     => 15.0,
			);
		}

		return array(
			'codigoImpuesto' => $mapping['codigo'],
			'codigoTarifa'   => $mapping['codigoAuxiliar'],
			'porcentaje'     => isset( $mapping['porcentaje'] ) ? (float) $mapping['porcentaje'] : 15.0,
		);
	}

	/**
	 * Aplica descuentos por cupón proporcionalmente entre los items.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Orden.
	 * @param array     $items Arreglo de items actual (sin shipping).
	 *
	 * @return array Items con descuentos actualizados.
	 */
	private function apply_coupon_discounts( $order, $items ) {
		$coupon_discount = (float) $order->get_discount_total();

		if ( $coupon_discount <= 0 ) {
			return $items;
		}

		// Calcular subtotal total de items (sin shipping).
		$subtotal_total = 0;
		$item_subtotals = array();
		foreach ( $order->get_items() as $item ) {
			$subtotal = (float) $item->get_subtotal();
			$item_subtotals[ $item->get_id() ] = $subtotal;
			$subtotal_total += $subtotal;
		}

		if ( $subtotal_total <= 0 ) {
			return $items;
		}

		// Distribuir descuento proporcionalmente.
		$item_index = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! isset( $items[ $item_index ] ) ) {
				break;
			}

			$peso = $item_subtotals[ $item->get_id() ] / $subtotal_total;
			$descuento_proporcional = round( $coupon_discount * $peso, 2 );

			// Sumar al descuento existente del item.
			$descuento_existente = (float) $items[ $item_index ]['descuento'];
			$items[ $item_index ]['descuento'] = wc_format_decimal(
				$descuento_existente + $descuento_proporcional,
				2
			);

			$item_index++;
		}

		return $items;
	}

	/**
	 * Construye el arreglo de formas de pago.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Orden.
	 *
	 * @return array Arreglo de FormaPagoInfo.
	 */
	private function build_formas_pago( $order ) {
		$payment_method = $order->get_payment_method();

		// Mapeo de métodos de pago comunes a códigos SRI.
		$payment_codes = array(
			'codigo' => '01', // 01 = Sin utilización del sistema financiero.
		);

		// Mapear algunos gateways conocidos.
		$gateway_map = array(
			'stripe'               => '19',
			'paypal'               => '19',
			'ppcp'                 => '19',
			'woocommerce_payments' => '19',
			'bacs'                 => '01',
			'cod'                  => '01',
			'cheque'               => '02',
			'transfer'             => '16',
			'bank_transfer'        => '16',
			'mercado_pago'         => '19',
		);

		if ( isset( $gateway_map[ $payment_method ] ) ) {
			$payment_codes['codigo'] = $gateway_map[ $payment_method ];
		}

		return array(
			array(
				'codigo'       => $payment_codes['codigo'],
				'total'        => wc_format_decimal( $order->get_total(), 2 ),
				'plazo'        => '0',
				'unidadTiempo' => 'dias',
			),
		);
	}

	/**
	 * Consulta el estado de un documento en Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param string $document_id UUID del documento devuelto por la emisión.
	 *
	 * @return array Respuesta con keys: success, data, error.
	 */
	public function consultar_documento( $document_id ) {
		if ( empty( $document_id ) ) {
			$this->last_error = __( 'ID de documento no proporcionado.', 'aurafact-woocommerce' );
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $this->last_error,
			);
		}

		return $this->request( 'GET', self::ENDPOINT_DOCUMENTS . '/' . urlencode( $document_id ) );
	}

	/**
	 * Prueba la conexión con la API de Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return array Respuesta con keys: success, data, error.
	 */
	public function probar_conexion() {
		return $this->request( 'GET', self::ENDPOINT_HEALTH );
	}

	/**
	 * Retorna el último mensaje de error.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return string|null Último error o null si no hay.
	 */
	public function get_ultimo_error() {
		return $this->last_error;
	}

	/**
	 * Valida la versión del contrato de la API reportada por el backend.
	 *
	 * @author Aurafact Team
	 * @version 1.1.1
	 *
	 * @param string $api_version Versión reportada por X-Aurafact-API-Version.
	 *
	 * @return void
	 */
	private function validar_version_api( $api_version ) {
		if ( version_compare( $api_version, self::MIN_API_VERSION, '<' ) ) {
			$this->last_error = sprintf(
				/* translators: 1: versión reportada, 2: versión mínima requerida */
				__( 'Versión de API incompatible: el servidor reporta %1$s pero el plugin requiere %2$s o superior.', 'aurafact-woocommerce' ),
				$api_version,
				self::MIN_API_VERSION
			);
		}
	}
}