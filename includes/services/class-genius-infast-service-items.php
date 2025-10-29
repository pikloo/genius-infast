<?php

/**
 * Item related operations for INFast.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes/services
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles item endpoints.
 */
class Genius_Infast_Service_Items {

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
	 * Create an item.
	 *
	 * @param array $payload Item payload.
	 * @return array|WP_Error
	 */
	public function create( array $payload ) {
		return $this->client->request(
			'POST',
			'items',
			array(
				'body' => $payload,
			)
		);
	}

	/**
	 * Update an item.
	 *
	 * @param string $item_id Item identifier.
	 * @param array  $payload Payload to update.
	 * @return array|WP_Error
	 */
	public function update( $item_id, array $payload ) {
		return $this->client->request(
			'PATCH',
			'items/' . rawurlencode( $item_id ),
			array(
				'body' => $payload,
			)
		);
	}

	/**
	 * Delete an item.
	 *
	 * @param string $item_id Item identifier.
	 * @return array|WP_Error
	 */
	public function delete( $item_id ) {
		return $this->client->request(
			'DELETE',
			'items/' . rawurlencode( $item_id )
		);
	}

	/**
	 * Find item by reference.
	 *
	 * @param string $reference Item reference.
	 * @return array|WP_Error
	 */
	public function find_by_reference( $reference ) {
		if ( empty( $reference ) ) {
			return new WP_Error( 'genius_infast_missing_reference', __( 'La reference de l article est requise.', 'genius_infast' ) );
		}

		return $this->client->request(
			'GET',
			'items',
			array(
				'query' => array(
					'reference' => $reference,
					'limit'     => 1,
				),
			)
		);
	}
}
