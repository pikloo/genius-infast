<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/public
 * @author     Your Name <email@example.com>
 */
class Genius_Infast_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register plugin shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		add_shortcode( 'genius_infast_document_link', array( $this, 'render_document_link_shortcode' ) );
	}

	/**
	 * Render document PDF download link shortcode.
	 *
	 * Usage:
	 * [genius_infast_document_link order_id="123"]
	 *
	 * Attributes:
	 * - order_id: WooCommerce order ID containing _genius_infast_document_id meta.
	 * - label: Link text (default: Télécharger la facture).
	 * - format: link|url (default: link).
	 * - class: CSS classes when format=link.
	 * - target: HTML target when format=link (default: _blank).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_document_link_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'order_id' => '',
				'label'    => __( 'Telecharger la facture', 'genius_infast' ),
				'format'   => 'link',
				'class'    => 'genius-infast-document-link',
				'target'   => '_blank',
			),
			$atts,
			'genius_infast_document_link'
		);

		$order_id = absint( $atts['order_id'] );
		if ( $order_id <= 0 ) {
			return '';
		}

		$url = $this->build_document_download_url( $order_id );
		if ( '' === $url ) {
			return '';
		}

		$format = strtolower( sanitize_key( $atts['format'] ) );
		if ( 'url' === $format ) {
			return esc_url( $url );
		}

		$label  = wp_strip_all_tags( (string) $atts['label'] );
		$class  = sanitize_html_class( (string) $atts['class'] );
		$target = sanitize_text_field( (string) $atts['target'] );

		return sprintf(
			'<a href="%1$s" class="%2$s" target="%3$s" rel="noopener noreferrer">%4$s</a>',
			esc_url( $url ),
			esc_attr( $class ),
			esc_attr( $target ),
			esc_html( $label )
		);
	}

	/**
	 * Handle PDF download by proxying INFast API endpoint.
	 *
	 * @return void
	 */
	public function handle_document_pdf_download() {
		$order_id  = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		if ( $order_id <= 0 ) {
			wp_die( esc_html__( 'Commande invalide.', 'genius_infast' ), '', array( 'response' => 400 ) );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'genius_infast' ), '', array( 'response' => 404 ) );
		}

		if ( ! $this->can_access_order_document( $order, $order_key ) ) {
			wp_die( esc_html__( 'Acces refuse.', 'genius_infast' ), '', array( 'response' => 403 ) );
		}

		$document_id = sanitize_text_field( (string) $order->get_meta( '_genius_infast_document_id', true ) );
		if ( '' === $document_id ) {
			wp_die( esc_html__( 'Aucun document INFast pour cette commande.', 'genius_infast' ), '', array( 'response' => 404 ) );
		}

		$client_id     = get_option( 'genius_infast_client_id', '' );
		$client_secret = get_option( 'genius_infast_client_secret', '' );

		if ( '' === $client_id || '' === $client_secret ) {
			wp_die( esc_html__( 'Identifiants INFast manquants.', 'genius_infast' ), '', array( 'response' => 500 ) );
		}

		$api      = new Genius_Infast_API( $client_id, $client_secret );
		$response = $api->export_document_pdf( $document_id );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ), '', array( 'response' => 502 ) );
		}

		$pdf_b64 = '';
		if ( isset( $response['pdfB64'] ) && is_string( $response['pdfB64'] ) ) {
			$pdf_b64 = $response['pdfB64'];
		} elseif ( isset( $response['data']['pdfB64'] ) && is_string( $response['data']['pdfB64'] ) ) {
			$pdf_b64 = $response['data']['pdfB64'];
		}

		if ( '' === $pdf_b64 ) {
			wp_die( esc_html__( 'Reponse PDF INFast invalide.', 'genius_infast' ), '', array( 'response' => 502 ) );
		}

		$pdf_binary = base64_decode( $pdf_b64, true );
		if ( false === $pdf_binary ) {
			wp_die( esc_html__( 'Impossible de decoder le PDF INFast.', 'genius_infast' ), '', array( 'response' => 502 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = 'facture-' . sanitize_file_name( $document_id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf_binary ) );

		echo $pdf_binary;
		exit;
	}

	/**
	 * Build admin-post URL used to download document PDF.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	private function build_document_download_url( $order_id ) {
		if ( $order_id <= 0 ) {
			return '';
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$document_id = sanitize_text_field( (string) $order->get_meta( '_genius_infast_document_id', true ) );
		if ( '' === $document_id ) {
			return '';
		}

		$args = array(
			'action'   => 'genius_infast_document_pdf',
			'order_id' => $order_id,
		);

		$order_key = (string) $order->get_order_key();
		if ( '' !== $order_key ) {
			$args['order_key'] = $order_key;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Check whether current request can access the order document.
	 *
	 * @param WC_Order $order     WooCommerce order.
	 * @param string   $order_key Optional order key from query.
	 * @return bool
	 */
	private function can_access_order_document( WC_Order $order, $order_key ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( is_user_logged_in() && (int) get_current_user_id() === (int) $order->get_user_id() ) {
			return true;
		}

		$expected_key = (string) $order->get_order_key();
		if ( '' !== $expected_key && hash_equals( $expected_key, (string) $order_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Genius_Infast_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Genius_Infast_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/genius-infast-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Genius_Infast_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Genius_Infast_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/genius-infast-public.js', array( 'jquery' ), $this->version, false );

	}

}
