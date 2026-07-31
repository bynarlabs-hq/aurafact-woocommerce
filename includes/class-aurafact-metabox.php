<?php
/**
 * Metabox, Thank You Page, Emails y Shortcode para facturación Aurafact.
 *
 * Proporciona la interfaz visual de la factura electrónica en el admin,
 * en la página de agradecimiento, en los emails de confirmación y
 * mediante shortcode para el cliente final.
 *
 * @package AurafactWooCommerce
 * @subpackage Admin
 */

namespace Aurafact\WooCommerce;

/**
 * Gestión de metabox, thank-you, emails y shortcode.
 *
 * @author Fabian Silva <fabian.silva@consulti.ec>
 * @version 1.0
 */
class Metabox {

	/**
	 * Instancia única del singleton.
	 *
	 * @var Metabox|null
	 */
	private static $instance = null;

	/**
	 * Obtiene la instancia única del singleton.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return Metabox Instancia única.
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
	 * Inicializa los hooks del metabox.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function init() {
		// Metabox en admin.
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'wp_ajax_aurafact_wc_retry_emission', array( $this, 'ajax_retry_emission' ) );

		// Thank You Page.
		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_links' ), 20, 1 );

		// Emails de confirmación.
		add_action( 'woocommerce_email_order_details', array( $this, 'render_email_links' ), 20, 4 );

		// Shortcode.
		add_shortcode( 'aurafact_factura', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Registra el metabox en la pantalla de edición de órdenes.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function register_metabox() {
		add_meta_box(
			'aurafact_wc_metabox',
			__( 'Factura Electrónica Aurafact', 'aurafact-woocommerce' ),
			array( $this, 'render_metabox' ),
			'shop_order',
			'side',
			'high'
		);
	}

	/**
	 * Renderiza el contenido del metabox en el admin.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WP_Post $post Objeto del post (orden).
	 *
	 * @return void
	 */
	public function render_metabox( $post ) {
		$order = wc_get_order( $post->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Orden no encontrada.', 'aurafact-woocommerce' ) . '</p>';
			return;
		}

		$document_id  = $order->get_meta( OrderHandler::META_DOCUMENT_ID );
		$status       = $order->get_meta( OrderHandler::META_STATUS );
		$clave        = $order->get_meta( OrderHandler::META_CLAVE_ACCESO );
		$numero_doc   = $order->get_meta( OrderHandler::META_NUMERO_DOCUMENTO );
		$pdf_url      = $order->get_meta( OrderHandler::META_PDF_URL );
		$xml_url      = $order->get_meta( OrderHandler::META_XML_URL );
		$retry_count  = (int) $order->get_meta( OrderHandler::META_RETRY_COUNT );

		$status_labels = array(
			'en_procesamiento' => __( 'En procesamiento', 'aurafact-woocommerce' ),
			'autorizado'       => __( 'Autorizado', 'aurafact-woocommerce' ),
			'rechazado'        => __( 'Rechazado', 'aurafact-woocommerce' ),
			'error'            => __( 'Error', 'aurafact-woocommerce' ),
		);

		$status_colors = array(
			'en_procesamiento' => '#f0ad4e',
			'autorizado'       => '#46b450',
			'rechazado'        => '#dc3232',
			'error'            => '#dc3232',
		);

		$status_icons = array(
			'en_procesamiento' => '⏳',
			'autorizado'       => '✅',
			'rechazado'        => '❌',
			'error'            => '⚠️',
		);

		wp_nonce_field( 'aurafact_wc_metabox', 'aurafact_wc_metabox_nonce' );
		?>
		<div class="aurafact-metabox">
			<?php if ( empty( $document_id ) && empty( $status ) ) : ?>
				<p style="color: #666;">
					<?php esc_html_e( 'Esta orden no tiene factura electrónica asociada.', 'aurafact-woocommerce' ); ?>
				</p>
				<p>
					<button type="button" class="button button-primary aurafact-retry-btn"
							data-order-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Emitir factura ahora', 'aurafact-woocommerce' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p>
					<span style="font-size: 1.4em;">
						<?php echo isset( $status_icons[ $status ] ) ? esc_html( $status_icons[ $status ] ) : '🔖'; ?>
					</span>
					<strong style="color: <?php echo isset( $status_colors[ $status ] ) ? esc_attr( $status_colors[ $status ] ) : '#666'; ?>;">
						<?php echo isset( $status_labels[ $status ] ) ? esc_html( $status_labels[ $status ] ) : esc_html( $status ); ?>
					</strong>
				</p>

				<?php if ( ! empty( $numero_doc ) ) : ?>
					<p><strong><?php esc_html_e( 'Número:', 'aurafact-woocommerce' ); ?></strong><br>
					<?php echo esc_html( $numero_doc ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $clave ) ) : ?>
					<p><strong><?php esc_html_e( 'Clave de Acceso:', 'aurafact-woocommerce' ); ?></strong><br>
					<code style="font-size: 0.75em; word-break: break-all;"><?php echo esc_html( $clave ); ?></code></p>
				<?php endif; ?>

				<?php if ( ! empty( $pdf_url ) ) : ?>
					<p><a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="button button-small">
						<?php esc_html_e( '📄 Descargar RIDE (PDF)', 'aurafact-woocommerce' ); ?>
					</a></p>
				<?php endif; ?>

				<?php if ( ! empty( $xml_url ) ) : ?>
					<p><a href="<?php echo esc_url( $xml_url ); ?>" target="_blank" class="button button-small">
						<?php esc_html_e( '📎 Descargar XML', 'aurafact-woocommerce' ); ?>
					</a></p>
				<?php endif; ?>

				<?php if ( 'rechazado' === $status || 'error' === $status ) : ?>
					<hr style="margin: 10px 0;">
					<p>
						<button type="button" class="button aurafact-retry-btn"
								data-order-id="<?php echo esc_attr( $post->ID ); ?>">
							<?php esc_html_e( '🔄 Reintentar facturación', 'aurafact-woocommerce' ); ?>
						</button>
					</p>
					<p style="color: #dc3232; font-size: 0.85em;">
						<strong><?php esc_html_e( 'Último error:', 'aurafact-woocommerce' ); ?></strong><br>
						<?php echo esc_html( $this->get_order_error_summary( $order ) ); ?>
					</p>
					<?php if ( $retry_count > 0 ) : ?>
						<p style="color: #666; font-size: 0.8em;">
							<?php
							printf(
								/* translators: %d: Número de reintentos */
								esc_html__( 'Reintentos: %d', 'aurafact-woocommerce' ),
								$retry_count
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( 'en_procesamiento' === $status ) : ?>
					<p style="color: #666; font-size: 0.85em;">
						<?php esc_html_e( 'La factura está siendo procesada. El estado se actualizará automáticamente.', 'aurafact-woocommerce' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('.aurafact-retry-btn').on('click', function (e) {
					e.preventDefault();
					var btn = $(this);
					var orderId = btn.data('order-id');
					btn.prop('disabled', true).text('<?php echo esc_js( __( 'Emitiendo...', 'aurafact-woocommerce' ) ); ?>');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'aurafact_wc_retry_emission',
							order_id: orderId,
							nonce: $('#aurafact_wc_metabox_nonce').val(),
						},
						success: function (response) {
							if (response.success) {
								location.reload();
							} else {
								alert(response.data.message);
								btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reintentar facturación', 'aurafact-woocommerce' ) ); ?>');
							}
						},
						error: function () {
							alert('<?php echo esc_js( __( 'Error de conexión. Intenta de nuevo.', 'aurafact-woocommerce' ) ); ?>');
							btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reintentar facturación', 'aurafact-woocommerce' ) ); ?>');
						},
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Obtiene un resumen del último error de la orden.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order Objeto de la orden.
	 *
	 * @return string Resumen del error.
	 */
	private function get_order_error_summary( $order ) {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 5,
			)
		);

		foreach ( $notes as $note ) {
			if ( strpos( $note->content, 'Aurafact: Error' ) !== false ) {
				return wp_trim_words( $note->content, 20, '...' );
			}
		}

		return __( 'Error desconocido. Revisa las notas de la orden.', 'aurafact-woocommerce' );
	}

	/**
	 * Maneja la solicitud AJAX de reintento de emisión.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @return void
	 */
	public function ajax_retry_emission() {
		// Verificar nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aurafact_wc_metabox' ) ) {
			wp_send_json_error( array( 'message' => __( 'Error de seguridad. Recarga la página.', 'aurafact-woocommerce' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para realizar esta acción.', 'aurafact-woocommerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Orden no encontrada.', 'aurafact-woocommerce' ) ) );
		}

		// Resetear metadatos de error y llamar a la emisión.
		$order->update_meta_data( OrderHandler::META_STATUS, '' );
		$order->update_meta_data( OrderHandler::META_RETRY_COUNT, '0' );
		$order->save();

		// Disparar emisión.
		$order_handler = OrderHandler::get_instance();
		$order_handler->on_order_status_change( $order_id, $order );

		// Verificar resultado.
		$new_status = $order->get_meta( OrderHandler::META_STATUS );

		if ( 'error' !== $new_status && ! empty( $new_status ) ) {
			wp_send_json_success( array( 'message' => __( 'Facturación iniciada correctamente.', 'aurafact-woocommerce' ) ) );
		} else {
			$error_msg = __( 'No se pudo emitir la factura. Revisa las notas de la orden.', 'aurafact-woocommerce' );
			wp_send_json_error( array( 'message' => $error_msg ) );
		}
	}

	/**
	 * Renderiza enlaces de descarga en la página de agradecimiento.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param int $order_id ID de la orden.
	 *
	 * @return void
	 */
	public function render_thankyou_links( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status  = $order->get_meta( OrderHandler::META_STATUS );
		$pdf_url = $order->get_meta( OrderHandler::META_PDF_URL );
		$xml_url = $order->get_meta( OrderHandler::META_XML_URL );

		if ( 'autorizado' !== $status || ( empty( $pdf_url ) && empty( $xml_url ) ) ) {
			return;
		}
		?>
		<section class="aurafact-thankyou" style="margin: 20px 0; padding: 20px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px;">
			<h3><?php esc_html_e( 'Factura Electrónica', 'aurafact-woocommerce' ); ?></h3>
			<p><?php esc_html_e( 'Tu factura electrónica ya está disponible. Puedes descargarla desde los siguientes enlaces:', 'aurafact-woocommerce' ); ?></p>
			<p>
				<?php if ( ! empty( $pdf_url ) ) : ?>
					<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="button" style="margin-right: 8px;">
						<?php esc_html_e( 'Descargar Factura Electrónica (PDF)', 'aurafact-woocommerce' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $xml_url ) ) : ?>
					<a href="<?php echo esc_url( $xml_url ); ?>" target="_blank" class="button">
						<?php esc_html_e( 'Descargar XML', 'aurafact-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Renderiza enlaces de descarga en los emails de WooCommerce.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param \WC_Order $order         Objeto de la orden.
	 * @param bool      $sent_to_admin Si el email es para el admin.
	 * @param bool      $plain_text    Si es texto plano.
	 * @param \WC_Email $email         Objeto del email.
	 *
	 * @return void
	 */
	public function render_email_links( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $order ) {
			return;
		}

		// Solo en emails al cliente (no al admin).
		if ( $sent_to_admin ) {
			return;
		}

		// Solo en emails de orden completada o procesando.
		$allowed_emails = array( 'customer_completed_order', 'customer_processing_order' );
		if ( ! in_array( $email->id, $allowed_emails, true ) ) {
			return;
		}

		$status  = $order->get_meta( OrderHandler::META_STATUS );
		$pdf_url = $order->get_meta( OrderHandler::META_PDF_URL );
		$xml_url = $order->get_meta( OrderHandler::META_XML_URL );

		if ( 'autorizado' !== $status || ( empty( $pdf_url ) && empty( $xml_url ) ) ) {
			return;
		}

		if ( $plain_text ) {
			// Versión texto plano.
			echo "\n\n" . esc_html__( 'FACTURA ELECTRÓNICA', 'aurafact-woocommerce' ) . "\n";
			echo esc_html__( 'Tu factura electrónica ya está disponible:', 'aurafact-woocommerce' ) . "\n";
			if ( ! empty( $pdf_url ) ) {
				echo esc_html__( 'PDF:', 'aurafact-woocommerce' ) . ' ' . esc_url( $pdf_url ) . "\n";
			}
			if ( ! empty( $xml_url ) ) {
				echo esc_html__( 'XML:', 'aurafact-woocommerce' ) . ' ' . esc_url( $xml_url ) . "\n";
			}
			echo "\n";
		} else {
			// Versión HTML.
			?>
			<div style="margin: 20px 0; padding: 20px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'Factura Electrónica', 'aurafact-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Tu factura electrónica ya está disponible. Descárgala aquí:', 'aurafact-woocommerce' ); ?></p>
				<p>
					<?php if ( ! empty( $pdf_url ) ) : ?>
						<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" style="display: inline-block; padding: 8px 16px; margin-right: 8px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px;">
							<?php esc_html_e( 'Descargar PDF', 'aurafact-woocommerce' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $xml_url ) ) : ?>
						<a href="<?php echo esc_url( $xml_url ); ?>" target="_blank" style="display: inline-block; padding: 8px 16px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px;">
							<?php esc_html_e( 'Descargar XML', 'aurafact-woocommerce' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Renderiza el shortcode [aurafact_factura].
	 *
	 * Muestra los comprobantes de la última orden del usuario logueado.
	 *
	 * @author Fabian Silva <fabian.silva@consulti.ec>
	 * @version 1.0
	 *
	 * @param array  $atts    Atributos del shortcode.
	 * @param string $content Contenido envuelto.
	 *
	 * @return string HTML del shortcode.
	 */
	public function render_shortcode( $atts, $content = '' ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Debes iniciar sesión para ver tus facturas.', 'aurafact-woocommerce' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'order_id' => 0,
				'limit'    => 1,
			),
			$atts,
			'aurafact_factura'
		);

		$user_id = get_current_user_id();

		if ( ! empty( $atts['order_id'] ) ) {
			$order = wc_get_order( intval( $atts['order_id'] ) );
			if ( ! $order || (int) $order->get_user_id() !== $user_id ) {
				return '<p>' . esc_html__( 'Orden no encontrada o no tienes permisos para verla.', 'aurafact-woocommerce' ) . '</p>';
			}
			$orders = array( $order );
		} else {
			$orders = wc_get_orders(
				array(
					'customer' => $user_id,
					'limit'    => intval( $atts['limit'] ),
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);
		}

		if ( empty( $orders ) ) {
			return '<p>' . esc_html__( 'No tienes órdenes con factura electrónica.', 'aurafact-woocommerce' ) . '</p>';
		}

		ob_start();
		foreach ( $orders as $order ) {
			$status     = $order->get_meta( OrderHandler::META_STATUS );
			$pdf_url    = $order->get_meta( OrderHandler::META_PDF_URL );
			$xml_url    = $order->get_meta( OrderHandler::META_XML_URL );
			$numero_doc = $order->get_meta( OrderHandler::META_NUMERO_DOCUMENTO );
			$clave      = $order->get_meta( OrderHandler::META_CLAVE_ACCESO );

			if ( 'autorizado' !== $status ) {
				continue;
			}
			?>
			<div class="aurafact-shortcode-invoice" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
				<h4>
					<?php
					printf(
						/* translators: %s: Número de orden */
						esc_html__( 'Factura - Orden #%s', 'aurafact-woocommerce' ),
						esc_html( $order->get_order_number() )
					);
					?>
				</h4>
				<?php if ( ! empty( $numero_doc ) ) : ?>
					<p><strong><?php esc_html_e( 'Documento:', 'aurafact-woocommerce' ); ?></strong> <?php echo esc_html( $numero_doc ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $clave ) ) : ?>
					<p><strong><?php esc_html_e( 'Clave de acceso:', 'aurafact-woocommerce' ); ?></strong><br>
					<code style="font-size: 0.85em;"><?php echo esc_html( $clave ); ?></code></p>
				<?php endif; ?>
				<p>
					<?php if ( ! empty( $pdf_url ) ) : ?>
						<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" class="button" style="margin-right: 8px;">
							<?php esc_html_e( 'Descargar PDF', 'aurafact-woocommerce' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $xml_url ) ) : ?>
						<a href="<?php echo esc_url( $xml_url ); ?>" target="_blank" class="button">
							<?php esc_html_e( 'Descargar XML', 'aurafact-woocommerce' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
			<?php
		}
		return ob_get_clean();
	}
}