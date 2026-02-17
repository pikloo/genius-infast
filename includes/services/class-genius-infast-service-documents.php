<?php

/**
 * Document related operations for INFast.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes/services
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles document endpoints.
 */
class Genius_Infast_Service_Documents {

	/**
	 * HTTP client.
	 *
	 * @var Genius_Infast_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Genius_Infast_Client $client HTTP client.
	 */
	public function __construct( Genius_Infast_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Create a document.
	 *
	 * @param array $payload Document payload.
	 * @return array|WP_Error
	 */
	public function create( array $payload ) {
		return $this->client->request(
			'POST',
			'documents',
			array(
				'body' => $payload,
			)
		);
	}

	/**
	 * Add a payment to a document.
	 *
	 * @param string $document_id Document identifier.
	 * @param array  $payload     Payment payload.
	 * @return array|WP_Error
	 */
	public function add_payment( $document_id, array $payload = array() ) {
		return $this->client->request(
			'POST',
			'documents/' . rawurlencode( $document_id ) . '/payment',
			array(
				'body' => $payload,
			)
		);
	}

	/**
	 * Send document by email.
	 *
	 * @param string $document_id Document identifier.
	 * @param array  $payload     Email payload.
	 * @return array|WP_Error
	 */
	public function send_email( $document_id, array $payload = array() ) {
		return $this->client->request(
			'POST',
			'documents/' . rawurlencode( $document_id ) . '/messages',
			array(
				'body' => empty( $payload ) ? new stdClass() : $payload,
			)
		);
	}

	/**
	 * Export a document as PDF.
	 *
	 * @param string $document_id Document identifier.
	 * @return array|WP_Error
	 */
	public function export_pdf( $document_id ) {
		return $this->client->request(
			'GET',
			'documents/' . rawurlencode( $document_id ) . '/pdf'
		);
	}

}
