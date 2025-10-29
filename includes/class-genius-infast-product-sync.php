<?php

/**
 * Handle WooCommerce product synchronisation with INFast items.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

defined('ABSPATH') || exit;

/**
 * Synchronises WooCommerce products with INFast items.
 */
class Genius_Infast_Product_Sync
{

	/**
	 * Meta key storing INFast item identifier.
	 */
	const META_ITEM_ID = '_genius_infast_item_id';

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin identifier.
	 * @param string $version     Plugin version.
	 */
	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
	}

	/**
	 * Handle product save events.
	 *
	 * @param int     $post_id Post identifier.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function handle_product_save($post_id, $post, $update)
	{
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}

		if ('product' !== $post->post_type) {
			return;
		}

		$product = wc_get_product($post_id);

		if (!$product instanceof WC_Product || $product->is_type('variation')) {
			return;
		}

		if ('publish' === $product->get_status()) {
			$result = $this->sync_product($product);
			if (is_wp_error($result)) {
				$this->log_error('Product save synchronisation error', array('product_id' => $product->get_id(), 'error' => $result->get_error_message()));
			}
		} else {
			$result = $this->delete_product($product);
			if (is_wp_error($result)) {
				$this->log_error('Product deletion synchronisation error', array('product_id' => $product->get_id(), 'error' => $result->get_error_message()));
			}
		}
	}

	/**
	 * Handle product status transition.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function handle_transition_status($new_status, $old_status, $post)
	{
		if ('product' !== $post->post_type) {
			return;
		}

		$product = wc_get_product($post->ID);

		if (!$product instanceof WC_Product || $product->is_type('variation')) {
			return;
		}

		if ('publish' !== $old_status && 'publish' === $new_status) {
			$result = $this->sync_product($product);
			if (is_wp_error($result)) {
				$this->log_error('Product status synchronisation error', array('product_id' => $product->get_id(), 'error' => $result->get_error_message()));
			}
			return;
		}

		if ('publish' === $old_status && 'publish' !== $new_status) {
			$result = $this->delete_product($product);
			if (is_wp_error($result)) {
				$this->log_error('Product unsync error', array('product_id' => $product->get_id(), 'error' => $result->get_error_message()));
			}
		}
	}

	/**
	 * Handle product deletion.
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	public function handle_delete_post($post_id)
	{
		$product = wc_get_product($post_id);

		if (!$product instanceof WC_Product || $product->is_type('variation')) {
			return;
		}

		$result = $this->delete_product($product);
		if (is_wp_error($result)) {
			$this->log_error('Product delete hook error', array('product_id' => $product->get_id(), 'error' => $result->get_error_message()));
		}
	}

	/**
	 * Synchronise a single product.
	 *
	 * @param int|WC_Product $product Product identifier or object.
	 * @return true|WP_Error
	 */
	public function sync_product($product)
	{
		if (!$product instanceof WC_Product) {
			$product = wc_get_product($product);
		}


		if (!$product instanceof WC_Product || $product->is_type('variation')) {
			return new WP_Error('genius_infast_invalid_product', __('Produit invalide pour la synchronisation.', 'genius_infast'));
		}

		if ('publish' !== $product->get_status()) {
			return $this->delete_product($product);
		}

		$api = $this->get_api_client();
		if (is_wp_error($api)) {
			return $api;
		}

		$payload = $this->build_item_payload($product);
		if (is_wp_error($payload)) {
			return $payload;
		}

		$this->log_info('Synchronisation du produit avec INFast', array('product_id' => $product->get_id()));

		$item_id = $product->get_meta(self::META_ITEM_ID, true);

		if (!$item_id) {
			// Attempt to match by reference (SKU).
			$reference = isset($payload['reference']) ? $payload['reference'] : '';
			if ($reference) {
				$existing = $api->find_item_by_reference($reference);
				if (!is_wp_error($existing) && !empty($existing['data'][0]['id'])) {
					$item_id = $existing['data'][0]['id'];
				}
			}
		}

		if ($item_id) {
			$response = $api->update_item($item_id, $payload);
			if (is_wp_error($response)) {
				$status = $response->get_error_data($response->get_error_code());
				$status = is_array($status) && isset($status['status']) ? (int) $status['status'] : 0;
				if (404 === $status) {
					$response = $api->create_item($payload);
					if (!is_wp_error($response) && !empty($response['data']['id'])) {
						$item_id = $response['data']['id'];
					}
				}
			}
		} else {
			$response = $api->create_item($payload);
			if (!is_wp_error($response) && !empty($response['data']['id'])) {
				$item_id = $response['data']['id'];
			}
		}

		if (is_wp_error($response)) {
			$this->log_error('Product synchronisation failed', array('product_id' => $product->get_id(), 'error' => $response->get_error_message()));
			return $response;
		}

		if (empty($item_id)) {
			return new WP_Error('genius_infast_missing_item_id', __('INFast n a pas retourne d identifiant article.', 'genius_infast'));
		}

		$product->update_meta_data(self::META_ITEM_ID, $item_id);
		$product->save_meta_data();

		return true;
	}

	/**
	 * Delete product on INFast.
	 *
	 * @param int|WC_Product $product Product identifier or object.
	 * @return true|WP_Error
	 */
	public function delete_product($product)
	{
		if (!$product instanceof WC_Product) {
			$product = wc_get_product($product);
		}

		if (!$product instanceof WC_Product) {
			return true;
		}

		$item_id = $product->get_meta(self::META_ITEM_ID, true);

		if (!$item_id) {
			return true;
		}

		$api = $this->get_api_client();
		if (is_wp_error($api)) {
			return $api;
		}

		$response = $api->delete_item($item_id);

		if (is_wp_error($response)) {
			$status = $response->get_error_data($response->get_error_code());
			$status = is_array($status) && isset($status['status']) ? (int) $status['status'] : 0;
			if (404 !== $status) {
				$this->log_error('Product deletion failed', array('product_id' => $product->get_id(), 'error' => $response->get_error_message()));
				return $response;
			}
		}

		$product->delete_meta_data(self::META_ITEM_ID);
		$product->save_meta_data();

		return true;
	}

	/**
	 * Synchronise all WooCommerce products.
	 *
	 * @return string|WP_Error Success message or error.
	 */
	public function sync_all_products()
	{
		if (!function_exists('wc_get_products')) {
			return new WP_Error('genius_infast_missing_woocommerce', __('WooCommerce doit etre actif pour synchroniser les produits.', 'genius_infast'));
		}

		$api = $this->get_api_client();
		if (is_wp_error($api)) {
			return $api;
		}
		unset($api);

		$published_products = wc_get_products(
			array(
				'status' => 'publish',
				'limit' => -1,
				'return' => 'ids',
			)
		);

		$synced = 0;
		$errors = array();

		foreach ($published_products as $product_id) {
			$result = $this->sync_product($product_id);
			if (is_wp_error($result)) {
				$errors[] = $product_id . ': ' . $result->get_error_message();
			} else {
				$synced++;
			}
		}

		$unpublished_with_meta = get_posts(
			array(
				'post_type' => 'product',
				'post_status' => array('draft', 'pending', 'private', 'trash'),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => self::META_ITEM_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$deleted = 0;
		foreach ($unpublished_with_meta as $product_id) {
			$result = $this->delete_product($product_id);
			if (is_wp_error($result)) {
				$errors[] = $product_id . ': ' . $result->get_error_message();
			} else {
				$deleted++;
			}
		}

		if ($errors) {
			$this->log_error('Bulk product synchronisation completed with errors', array('issues' => $errors));
		}

		$message = sprintf(
			/* translators: 1: number of synced products, 2: number of deleted items */
			__('Synchronisation des produits terminee. %1$d produits mis a jour, %2$d produits supprimés.', 'genius_infast'),
			(int) $synced,
			(int) $deleted
		);

		if ($errors) {
			$message .= ' ' . __('Certains produits n\'ont pas pu être synchronisés. Consultez les journaux pour plus de details.', 'genius_infast');
		}

		return $message;
	}

	/**
	 * Remove all stored INFast item identifiers from WooCommerce products.
	 *
	 * @return string|WP_Error Summary message or error.
	 */
	public function unlink_all_products()
	{
		$linked_products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_ITEM_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $linked_products ) ) {
			return __( 'Aucun produit WooCommerce n\'est actuellement lié à INFast.', 'genius_infast' );
		}

		$api = $this->get_api_client();
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$deleted_remote = 0;
		$unlinked_local = 0;
		$errors         = array();

		foreach ( $linked_products as $product_id ) {
			$product   = wc_get_product( $product_id );
			$item_id   = $product instanceof WC_Product ? $product->get_meta( self::META_ITEM_ID, true ) : '';

			if ( $item_id ) {
				$response = $api->delete_item( $item_id );
				if ( is_wp_error( $response ) ) {
					$status = $response->get_error_data( $response->get_error_code() );
					$status = is_array( $status ) && isset( $status['status'] ) ? (int) $status['status'] : 0;

					if ( 404 === $status ) {
						// Already removed on INFast, treat as success.
						$deleted_remote++;
					} else {
						$errors[] = sprintf( '#%d: %s', $product_id, $response->get_error_message() );
						$this->log_error(
							'Product unlink remote deletion failed',
							array(
								'product_id' => $product_id,
								'item_id'    => $item_id,
								'error'      => $response->get_error_message(),
							)
						);
					}
				} else {
					$deleted_remote++;
				}
			}

			if ( $product instanceof WC_Product ) {
				$product->delete_meta_data( self::META_ITEM_ID );
				$product->save_meta_data();
			} else {
				delete_post_meta( $product_id, self::META_ITEM_ID );
			}

			$unlinked_local++;
		}

		if ( $errors ) {
			return new WP_Error(
				'genius_infast_unlink_errors',
				sprintf(
					__( 'Des erreurs sont survenues lors de la suppression sur INFast. %1$s produits dissociés localement, %2$s articles supprimés sur INFast.', 'genius_infast' ),
					$unlinked_local,
					$deleted_remote
				),
				array( 'details' => $errors )
			);
		}

		return sprintf(
			__( '%1$s produits dissociés. %2$s articles ont été supprimés sur INFast.', 'genius_infast' ),
			$unlinked_local,
			$deleted_remote
		);
	}

	/**
	 * Build INFast item payload from product data.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array|WP_Error
	 */
	private function build_item_payload(WC_Product $product)
	{
		$price_excl = $this->get_product_price_excluding_tax($product);

		if ($price_excl <= 0) {
			$raw_price = (float) $product->get_price();
			if ($raw_price > 0) {
				$price_excl = $raw_price;
			} else {
				$price_excl = 0.0;
			}
		}

		$payload = array(
			'name' => $product->get_name(),
			'price' => $price_excl,
			'vat' => $this->get_product_vat_rate($product),
			'reference' => $this->get_product_reference($product),
			'type' => $product->is_virtual() ? 'SERVICE' : 'PRODUCT',
			'metadata' => 'INTERNAL_DB_ID=' . $product->get_id(),
		);

		if ('yes' !== get_option('genius_infast_skip_description', 'no')) {
			$description = $product->get_description();
			if (empty($description)) {
				$description = $product->get_short_description();
			}
			if ($description) {
				$payload['description'] = wp_strip_all_tags($description);
				$payload['description'] = trim(substr($payload['description'], 0, 8196));
			}
		}

		$buy_price = (float) $product->get_meta('_purchase_price', true);
		if ($buy_price > 0) {
			$payload['buyingPrice'] = function_exists('wc_format_decimal') ? wc_format_decimal($buy_price) : round($buy_price, 2);
		}

		$unit = $product->get_meta('_unit', true);
		if (empty($unit)) {
			$unit = $product->get_attribute('unit');
		}
		if ($unit) {
			$payload['unit'] = sanitize_text_field($unit);
		}

		return $payload;
	}

	/**
	 * Compute product reference for INFast.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function get_product_reference(WC_Product $product)
	{
		$sku = $product->get_sku();
		if ($sku) {
			return $this->sanitize_reference($sku);
		}

		return $this->sanitize_reference($product->get_id());
	}

	/**
	 * Sanitize a reference string for INFast.
	 *
	 * @param string $reference Raw reference.
	 * @return string
	 */
	private function sanitize_reference($reference)
	{
		$reference = preg_replace('/[^A-Za-z0-9-_]/', '', (string) $reference);
		return substr($reference, 0, 24);
	}

	/**
	 * Retrieve product price excluding tax.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	private function get_product_price_excluding_tax(WC_Product $product)
	{
		if (function_exists('wc_get_price_excluding_tax')) {
			return round((float) wc_get_price_excluding_tax($product), 2);
		}

		return round((float) $product->get_regular_price(), 2);
	}

	/**
	 * Estimate product VAT rate.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	private function get_product_vat_rate(WC_Product $product)
	{
		if (!function_exists('wc_get_price_including_tax') || !function_exists('wc_get_price_excluding_tax')) {
			return 0.0;
		}

		$excl = wc_get_price_excluding_tax($product);
		$incl = wc_get_price_including_tax($product);

		if ($excl <= 0) {
			return 0.0;
		}

		$vat = ($incl - $excl) / $excl * 100;

		return max(0.0, round($vat, 2));
	}

	/**
	 * Retrieve API client.
	 *
	 * @return Genius_Infast_API|WP_Error
	 */
	private function get_api_client()
	{
		$client_id = get_option('genius_infast_client_id', '');
		$client_secret = get_option('genius_infast_client_secret', '');

		if (empty($client_id) || empty($client_secret)) {
			return new WP_Error('genius_infast_missing_credentials', __('Les identifiants INFast sont necessaires pour synchroniser les produits.', 'genius_infast'));
		}

		return new Genius_Infast_API($client_id, $client_secret);
	}

	/**
	 * Log errors when possible.
	 *
	 * @param string $message Message content.
	 * @param array  $context Contextual data.
	 * @return void
	 */
	private function log_error($message, array $context = array())
	{
		if (!$this->logger) {
			return;
		}

		$context['source'] = 'genius-infast';
		$this->logger->error($message, $context);
	}

	/**
	 * Log informational message when available.
	 *
	 * @param string $message Message content.
	 * @param array  $context Contextual data.
	 * @return void
	 */
	private function log_info($message, array $context = array())
	{
		if (!$this->logger) {
			return;
		}

		$context['source'] = 'genius-infast';
		$this->logger->info($message, $context);
	}
}
