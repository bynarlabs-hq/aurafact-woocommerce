<?php
/**
 * Manejador de órdenes para emisión automática de facturas.
 *
 * Escucha los cambios de estado de las órdenes de WooCommerce y dispara
 * la emisión de facturas electrónicas a través de la API de Aurafact.
 *
 * @package AurafactWooCommerce
 * @subpackage Orders
 */

namespace Aurafact\WooCommerce;

/**
 * Gestión de emisión de facturas desde órdenes de WooCommerce.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class OrderHandler {

	/**
	 * Instancia única del singleton.
	 *
	 * @var OrderHandler|null
	 */
	private static $instance = null;

	/**
	 * Máximo de reintentos automáticos.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Meta keys usadas para almacenar datos de facturación.
	 */
	const META_DOCUMENT_ID      = '_aurafact_document_id';
	const META_CLAVE_ACCESO     = '_aurafact_clave_acceso';
	const META_PDF_URL          = '_aurafact_pdf_url';
	const META_XML_URL          = '_aurafact_xml_url';
	const META_STATUS           = '_aurafact_status';
	const META_NUMERO_DOCUMENTO = '_aurafact_numero_documento';
	const META_FECHA_EMISION    = '_aurafact_fecha_emision';
	const META_RETRY_COUNT      = '_aurafact_retry_count';

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return OrderHandler Instancia única.
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
	 * Inicializa los hooks del manejador de órdenes.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function init() {
		// Hook dinámico según configuración.
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_status_change' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_status_change' ), 10, 2 );

		// Cron para consultar estado de documentos pendientes.
		add_action( 'aurafact_wc_poll_document_status', array( $this, 'poll_pending_documents' ) );

		// Agregar intervalo de 5 minutos a los schedules de WP.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// Mostrar metadatos de emisión en el admin de la orden.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_emission_meta' ), 10, 1 );

		// Programar cron en init (no en plugins_loaded) para evitar el notice
		// _load_textdomain_just_in_time: wp_schedule_event() dispara el filtro
		// cron_schedules, y WC 7.1.1 carga perezosamente su text domain ahí.
		add_action( 'init', array( $this, 'schedule_poll_cron' ) );
	}

	/**
	 * Registra el cron de polling de documentos en Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function schedule_poll_cron() {
		if ( ! wp_next_scheduled( 'aurafact_wc_poll_document_status' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'aurafact_wc_poll_document_status' );
		}
	}

	/**
	 * Agrega el intervalo de 5 minutos a los schedules de WordPress.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param array $schedules Schedules existentes.
	 *
	 * @return array Schedules modificados.
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			/* translators: Intervalo de 5 minutos */
			'display'  => __( 'Cada 5 minutos', 'aurafact-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Maneja el cambio de estado de la orden.
	 *
	 * Verifica si el nuevo estado coincide con el configurado y dispara
	 * la emisión si corresponde.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param int      $order_id   ID de la orden.
	 * @param \WC_Order $order     Objeto de la orden.
	 *
	 * @return void
	 */
	public function on_order_status_change( $order_id, $order ) {
		$settings = AdminSettings::get_instance();
		$trigger_status = $settings->get_emission_event();

		// Mapear el estado de WooCommerce al evento configurado.
		$current_status = $order->get_status();
		$status_map = array(
			'completed'  => 'completed',
			'processing' => 'processing',
		);

		$expected_status = isset( $status_map[ $trigger_status ] ) ? $status_map[ $trigger_status ] : 'completed';

		if ( $current_status !== $expected_status ) {
			return;
		}

		// Verificar datos fiscales.
		$doc_type   = $order->get_meta( '_billing_doc_type' );
		$doc_number = $order->get_meta( '_billing_doc_number' );

		if ( empty( $doc_type ) || empty( $doc_number ) ) {
			$order->add_order_note(
				__( 'Aurafact: La orden no tiene datos fiscales completos. No se pudo emitir la factura.', 'aurafact-woocommerce' )
			);
			return;
		}

		// Verificar idempotencia.
		if ( $this->is_already_emitted( $order ) ) {
			return;
		}

		$this->emitir( $order_id, $order );
	}

	/**
	 * Verifica si ya se emitió una factura para esta orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return bool True si ya existe una factura autorizada.
	 */
	private function is_already_emitted( $order ) {
		$status = $order->get_meta( self::META_STATUS );

		// Si está autorizado, no re-emitir.
		if ( 'autorizado' === $status ) {
			return true;
		}

		// Si está en_procesamiento, permitir consulta pero no re-emitir.
		if ( 'en_procesamiento' === $status ) {
			return true;
		}

		// Si está rechazado, permitir re-emisión.
		return false;
	}

	/**
	 * Ejecuta la emisión de la factura.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param int       $order_id ID de la orden.
	 * @param \WC_Order $order    Objeto de la orden.
	 *
	 * @return void
	 */
	private function emitir( $order_id, $order ) {
		$api_client = ApiClient::get_instance();
		$result = $api_client->emitir_factura( $order_id );

		if ( $result['success'] ) {
			$data = $result['data'];
			$settings = AdminSettings::get_instance();

			// Guardar metadatos de la emisión.
			if ( isset( $data['id'] ) ) {
				$order->update_meta_data( self::META_DOCUMENT_ID, sanitize_text_field( $data['id'] ) );
			}
			if ( isset( $data['secuencial'] ) ) {
				$order->update_meta_data( self::META_NUMERO_DOCUMENTO, sanitize_text_field( $data['secuencial'] ) );
			}
			if ( isset( $data['claveAcceso'] ) ) {
				$order->update_meta_data( self::META_CLAVE_ACCESO, sanitize_text_field( $data['claveAcceso'] ) );
			}

			$status = isset( $data['estado'] ) ? $data['estado'] : 'en_procesamiento';
			$order->update_meta_data( self::META_STATUS, $status );
			$order->update_meta_data( self::META_FECHA_EMISION, gmdate( 'Y-m-d H:i:s' ) );
			$order->update_meta_data( self::META_RETRY_COUNT, '0' );

			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: %s: Número de documento */
					__( 'Aurafact: Factura emitida correctamente. Documento: %s | Estado: %s', 'aurafact-woocommerce' ),
					isset( $data['secuencial'] ) ? $data['secuencial'] : '—',
					$status
				)
			);

			$settings->log( sprintf(
				'Factura emitida para orden %d: ID=%s, Estado=%s',
				$order_id,
				isset( $data['id'] ) ? $data['id'] : '—',
				$status
			) );

		} else {
			// Manejar error.
			$error_msg = $result['error'];
			$retry_count = (int) $order->get_meta( self::META_RETRY_COUNT );
			$retry_count++;

			$order->update_meta_data( self::META_RETRY_COUNT, (string) $retry_count );
			$order->update_meta_data( self::META_STATUS, 'error' );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: Mensaje de error, 2: Número de reintento */
					__( 'Aurafact: Error al emitir factura: %1$s (Intento %2$d de %3$d)', 'aurafact-woocommerce' ),
					$error_msg,
					$retry_count,
					self::MAX_RETRIES
				)
			);

			// Reintentar automáticamente si no se ha superado el máximo.
			if ( $retry_count < self::MAX_RETRIES ) {
				wp_schedule_single_event(
					time() + 300, // 5 minutos.
					'aurafact_wc_retry_emission',
					array( $order_id )
				);
			}
		}
	}

	/**
	 * Consulta el estado de documentos pendientes (cron).
	 *
	 * Busca órdenes con estado "en_procesamiento" y consulta su estado
	 * actual en la API de Aurafact.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function poll_pending_documents() {
		$orders = wc_get_orders(
			array(
				'limit'       => 50,
				'meta_key'    => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'en_procesamiento', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'return'      => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$api_client = ApiClient::get_instance();
		$settings = AdminSettings::get_instance();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$document_id = $order->get_meta( self::META_DOCUMENT_ID );
			if ( empty( $document_id ) ) {
				continue;
			}

			$result = $api_client->consultar_documento( $document_id );

			if ( ! $result['success'] ) {
				$settings->log( sprintf(
					'Error consultando documento %s para orden %d: %s',
					$document_id,
					$order_id,
					$result['error']
				) );
				continue;
			}

			$data = $result['data'];
			$new_status = isset( $data['estado'] ) ? $data['estado'] : '';

			if ( empty( $new_status ) || 'en_procesamiento' === $new_status ) {
				continue;
			}

			// Actualizar metadatos según el nuevo estado.
			$order->update_meta_data( self::META_STATUS, $new_status );

			if ( 'autorizado' === $new_status ) {
				if ( isset( $data['claveAcceso'] ) ) {
					$order->update_meta_data( self::META_CLAVE_ACCESO, sanitize_text_field( $data['claveAcceso'] ) );
				}
				if ( isset( $data['pdfUrl'] ) ) {
					$order->update_meta_data( self::META_PDF_URL, esc_url_raw( $data['pdfUrl'] ) );
				}
				if ( isset( $data['xmlUrl'] ) ) {
					$order->update_meta_data( self::META_XML_URL, esc_url_raw( $data['xmlUrl'] ) );
				}
				if ( isset( $data['secuencial'] ) ) {
					$order->update_meta_data( self::META_NUMERO_DOCUMENTO, sanitize_text_field( $data['secuencial'] ) );
				}

				$order->add_order_note(
					sprintf(
						/* translators: %s: Clave de acceso SRI */
						__( 'Aurafact: Factura autorizada por el SRI. Clave de acceso: %s', 'aurafact-woocommerce' ),
						isset( $data['claveAcceso'] ) ? $data['claveAcceso'] : '—'
					)
				);

			} elseif ( 'rechazado' === $new_status ) {
				$motivo = isset( $data['motivo'] ) ? $data['motivo'] : __( 'Sin motivo especificado', 'aurafact-woocommerce' );

				$order->add_order_note(
					sprintf(
						/* translators: %s: Motivo del rechazo */
						__( 'Aurafact: Factura rechazada por el SRI. Motivo: %s', 'aurafact-woocommerce' ),
						$motivo
					)
				);
			}

			$order->save();
			$settings->log( sprintf(
				'Documento %s para orden %d actualizado: %s',
				$document_id,
				$order_id,
				$new_status
			) );
		}
	}

	/**
	 * Muestra los metadatos de emisión en el detalle de la orden en admin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return void
	 */
	public function display_emission_meta( $order ) {
		$document_id = $order->get_meta( self::META_DOCUMENT_ID );
		$status      = $order->get_meta( self::META_STATUS );
		$clave       = $order->get_meta( self::META_CLAVE_ACCESO );
		$numero_doc  = $order->get_meta( self::META_NUMERO_DOCUMENTO );
		$pdf_url     = $order->get_meta( self::META_PDF_URL );
		$xml_url     = $order->get_meta( self::META_XML_URL );
		$fecha_emision = $order->get_meta( self::META_FECHA_EMISION );

		if ( ! $document_id && ! $status ) {
			return;
		}

		$status_labels = array(
			'en_procesamiento' => __( 'En procesamiento', 'aurafact-woocommerce' ),
			'autorizado'       => __( 'Autorizado', 'aurafact-woocommerce' ),
			'rechazado'        => __( 'Rechazado', 'aurafact-woocommerce' ),
			'error'            => __( 'Error', 'aurafact-woocommerce' ),
		);
		$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

		?>
		<div class="aurafact-emission-data" style="padding: 12px 0; border-top: 1px solid #ddd; margin-top: 12px; clear: both;">
			<h4><?php esc_html_e( 'Facturación Aurafact', 'aurafact-woocommerce' ); ?></h4>
			<p>
				<strong><?php esc_html_e( 'Estado:', 'aurafact-woocommerce' ); ?></strong>
				<span style="color: <?php echo 'autorizado' === $status ? '#46b450' : ( 'rechazado' === $status ? '#dc3232' : '#666' ); ?>; font-weight: bold;">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</p>
			<?php if ( ! empty( $numero_doc ) ) : ?>
			<p><strong><?php esc_html_e( 'Documento:', 'aurafact-woocommerce' ); ?></strong> <?php echo esc_html( $numero_doc ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $clave ) ) : ?>
			<p><strong><?php esc_html_e( 'Clave de acceso:', 'aurafact-woocommerce' ); ?></strong> <?php echo esc_html( $clave ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $fecha_emision ) ) : ?>
			<p><strong><?php esc_html_e( 'Fecha de emisión:', 'aurafact-woocommerce' ); ?></strong> <?php echo esc_html( $fecha_emision ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $document_id ) ) : ?>
			<p><strong><?php esc_html_e( 'ID interno:', 'aurafact-woocommerce' ); ?></strong> <?php echo esc_html( $document_id ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $pdf_url ) ) : ?>
			<p><a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="button button-small"><?php esc_html_e( 'Ver RIDE (PDF)', 'aurafact-woocommerce' ); ?></a></p>
			<?php endif; ?>
			<?php if ( ! empty( $xml_url ) ) : ?>
			<p><a href="<?php echo esc_url( $xml_url ); ?>" target="_blank" class="button button-small"><?php esc_html_e( 'Descargar XML', 'aurafact-woocommerce' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}
}