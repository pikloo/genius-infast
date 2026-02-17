<?php

/**
 * High level facade exposing INFast services.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides access to service classes while preserving backwards compatibility.
 */
class Genius_Infast_API {

	/**
	 * HTTP client.
	 *
	 * @var Genius_Infast_Client
	 */
	private $client;

	/**
	 * Customer service.
	 *
	 * @var Genius_Infast_Service_Customers
	 */
	public $customers;

	/**
	 * Document service.
	 *
	 * @var Genius_Infast_Service_Documents
	 */
	public $documents;

	/**
	 * Item service.
	 *
	 * @var Genius_Infast_Service_Items
	 */
	public $items;

	/**
	 * Constructor.
	 *
	 * @param string $client_id     OAuth client identifier.
	 * @param string $client_secret OAuth client secret.
	 */
	public function __construct( $client_id, $client_secret ) {
		$this->client    = new Genius_Infast_Client( $client_id, $client_secret );
		$this->customers = new Genius_Infast_Service_Customers( $this->client );
		$this->documents = new Genius_Infast_Service_Documents( $this->client );
		$this->items     = new Genius_Infast_Service_Items( $this->client );
	}

	/**
	 * Retrieve the underlying HTTP client.
	 *
	 * @return Genius_Infast_Client
	 */
	public function get_client() {
		return $this->client;
	}

	/**
	 * Proxy for backwards compatibility: request portal information.
	 *
	 * @return array|WP_Error
	 */
	public function get_portal() {
		return $this->client->get_portal();
	}

	/**
	 * Proxy for backwards compatibility.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		return $this->client->get_access_token();
	}

	/**
	 * Proxy for backwards compatibility.
	 *
	 * @return void
	 */
	public function clear_token_cache() {
		$this->client->clear_token_cache();
	}

	/**
	 * Proxy request helper for legacy usage.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Endpoint path.
	 * @param array  $args   Additional arguments.
	 * @param bool   $retry  Retry flag.
	 * @return array|WP_Error
	 */
	public function request( $method, $path, array $args = array(), $retry = true ) {
		return $this->client->request( $method, $path, $args, $retry );
	}

	/**
	 * Legacy helper: find customer by email.
	 *
	 * @param string $email Email to search.
	 * @return array|WP_Error|null
	 */
	public function find_customer_by_email( $email ) {
		return $this->customers->find_by_email( $email );
	}

	/**
	 * Legacy helper: create customer.
	 *
	 * @param array $payload Payload data.
	 * @return array|WP_Error
	 */
	public function create_customer( array $payload ) {
		return $this->customers->create( $payload );
	}

	/**
	 * Legacy helper: create document.
	 *
	 * @param array $payload Payload data.
	 * @return array|WP_Error
	 */
	public function create_document( array $payload ) {
		return $this->documents->create( $payload );
	}

	/**
	 * Legacy helper: add payment to document.
	 *
	 * @param string $document_id Document identifier.
	 * @param array  $payload     Payload data.
	 * @return array|WP_Error
	 */
	public function add_payment_on_document( $document_id, array $payload = array() ) {
		return $this->documents->add_payment( $document_id, $payload );
	}

	/**
	 * Legacy helper: send document email.
	 *
	 * @param string $document_id Document identifier.
	 * @param array  $payload     Payload data.
	 * @return array|WP_Error
	 */
	public function send_document_email( $document_id, array $payload = array() ) {
		return $this->documents->send_email( $document_id, $payload );
	}

	/**
	 * Legacy helper: export document PDF.
	 *
	 * @param string $document_id Document identifier.
	 * @return array|WP_Error
	 */
	public function export_document_pdf( $document_id ) {
		return $this->documents->export_pdf( $document_id );
	}

	/**
	 * Test authentication by calling /me.
	 *
	 * @return array|WP_Error
	 */
	public function get_current_user() {
		return $this->customers->get_current_user();
	}

	/**
	 * Legacy helper: create item.
	 *
	 * @param array $payload Payload data.
	 * @return array|WP_Error
	 */
	public function create_item( array $payload ) {
		return $this->items->create( $payload );
	}

	/**
	 * Legacy helper: update item.
	 *
	 * @param string $item_id Item identifier.
	 * @param array  $payload Payload data.
	 * @return array|WP_Error
	 */
	public function update_item( $item_id, array $payload ) {
		return $this->items->update( $item_id, $payload );
	}

	/**
	 * Legacy helper: delete item.
	 *
	 * @param string $item_id Item identifier.
	 * @return array|WP_Error
	 */
	public function delete_item( $item_id ) {
		return $this->items->delete( $item_id );
	}

	/**
	 * Legacy helper: find item by reference.
	 *
	 * @param string $reference Reference value.
	 * @return array|WP_Error
	 */
	public function find_item_by_reference( $reference ) {
		return $this->items->find_by_reference( $reference );
	}
}
