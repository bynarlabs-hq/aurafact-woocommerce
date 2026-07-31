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
	const ENDPOINT_INVOICES   = '/api/v1/invoices';
	const ENDPOINT_DOCUMENTS  = '/api/v1/documents';
	const ENDPOINT_HEALTH     = '/api/v1/health';

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
	 * @param string $path    Ruta del endpoint (ej: /api/v1/invoices).
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
	 * y lo envía a POST /api/v1/invoices.
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
	 * @return array Payload para POST /api/v1/invoices.
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

		// Secuencial: usar timestamp como placeholder (el plugin real usaría
		// la secuencia configurada en Aurafact, pero por ahora generamos uno).
		$secuencial = str_pad( (string) ( $order->get_id() % 999999999 ), 9, '0', STR_PAD_LEFT );

		$payload = array(
			'establecimiento' => '001',
			'puntoEmision'    => '002',
			'secuencial'      => $secuencial,
			'fechaEmision'    => gmdate( 'Y-m-d' ),
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
		$doc_type   = $order->get_meta( '_billing_doc_type' );
		$doc_number = $order->get_meta( '_billing_doc_number' );
		$business_name = $order->get_meta( '_billing_business_name' );

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
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return array Arreglo de ItemInfo.
	 */
	private function build_items( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			$codigo_principal = $item->get_sku();
			if ( empty( $codigo_principal ) && $product ) {
				$codigo_principal = (string) $product->get_id();
			}
			if ( empty( $codigo_principal ) ) {
				$codigo_principal = 'PROD-' . $item->get_id();
			}

			$taxes = $this->get_item_tax_info( $item, $order );

			$items[] = array(
				'codigoPrincipal' => $codigo_principal,
				'codigoAuxiliar'  => null,
				'descripcion'     => $item->get_name(),
				'cantidad'        => (string) $item->get_quantity(),
				'precioUnitario'  => wc_format_decimal( $item->get_subtotal() / $item->get_quantity(), 2 ),
				'descuento'       => wc_format_decimal( $item->get_subtotal() - $item->get_total(), 2 ),
				'codigoImpuesto'  => $taxes['codigoImpuesto'],
				'codigoTarifa'    => $taxes['codigoTarifa'],
			);
		}

		// Agregar shipping como item adicional.
		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			$shipping_taxes = $this->get_shipping_tax_info( $order );

			$items[] = array(
				'codigoPrincipal' => 'SHIPPING',
				'codigoAuxiliar'  => null,
				'descripcion'     => sprintf(
					/* translators: %s: Método de envío */
					__( 'Gastos de envío - %s', 'aurafact-woocommerce' ),
					$order->get_shipping_method()
				),
				'cantidad'        => '1',
				'precioUnitario'  => wc_format_decimal( $shipping_total, 2 ),
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
	 * Mapea las tasas de WooCommerce a los códigos de Aurafact (SRI).
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order_Item_Product $item  Item de la orden.
	 * @param \WC_Order              $order Orden.
	 *
	 * @return array Con keys codigoImpuesto y codigoTarifa.
	 */
	private function get_item_tax_info( $item, $order ) {
		$taxes = $item->get_taxes();

		// Por defecto: IVA 15% (código más común en Ecuador).
		$default = array(
			'codigoImpuesto' => '2',
			'codigoTarifa'   => '4',
		);

		// Si no hay taxes, determinar según configuración de WooCommerce.
		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return $default;
		}

		$tax_total = array_sum( array_map( 'floatval', $taxes['total'] ) );

		// Si no hay impuesto, verificar si es 0%, exento o no objeto.
		if ( $tax_total <= 0 ) {
			$product = $item->get_product();
			if ( $product ) {
				$tax_class = $product->get_tax_class();
				$tax_rates = \WC_Tax::get_rates_for_tax_class( $tax_class );

				foreach ( $tax_rates as $rate ) {
					if ( isset( $rate->rate ) ) {
						$rate_percent = (float) $rate->rate;
						if ( $rate_percent <= 0 ) {
							return array(
								'codigoImpuesto' => '2',
								'codigoTarifa'   => '0', // IVA 0%.
							);
						}
					}
				}
			}

			// Fallback: exento.
			return array(
				'codigoImpuesto' => '2',
				'codigoTarifa'   => '7', // Exento.
			);
		}

		// Determinar tarifa basada en el subtotal vs impuesto.
		$subtotal = (float) $item->get_subtotal();
		if ( $subtotal > 0 ) {
			$effective_rate = ( $tax_total / $subtotal ) * 100;

			if ( $effective_rate < 1 ) {
				return array(
					'codigoImpuesto' => '2',
					'codigoTarifa'   => '0', // IVA 0%.
				);
			}
		}

		return $default; // IVA 15%.
	}

	/**
	 * Obtiene la información de impuestos del envío.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Orden.
	 *
	 * @return array Con keys codigoImpuesto y codigoTarifa.
	 */
	private function get_shipping_tax_info( $order ) {
		$shipping_tax = (float) $order->get_shipping_tax();

		if ( $shipping_tax <= 0 ) {
			return array(
				'codigoImpuesto' => '2',
				'codigoTarifa'   => '0', // IVA 0%.
			);
		}

		return array(
			'codigoImpuesto' => '2',
			'codigoTarifa'   => '4', // IVA 15%.
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
}