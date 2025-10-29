<?php

/**
 * REST webhook handler for INFast events.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers REST routes that receive INFast webhook events.
 */
class Genius_Infast_Webhooks {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin identifier.
	 */
	public function __construct( $plugin_name ) {
		$this->plugin_name = $plugin_name;
	}

	/**
	 * Register REST routes used for webhooks.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'genius-infast/v1',
			'/customer-deleted',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Validate Authorization header when provided.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$expected_token = trim( (string) get_option( 'genius_infast_webhook_token', '' ) );

		if ( '' === $expected_token ) {
			// $this->log( 'permissions_check: aucun jeton configure, autorisation implicite.' );
			return true;
		}

		$header = $request->get_header( 'authorization' );

		if ( empty( $header ) ) {
			return new WP_Error(
				'genius_infast_webhook_unauthorized',
				__( 'Le header Authorization est manquant.', 'genius_infast' ),
				array( 'status' => 401 )
			);
		}

		if ( ! preg_match( '/Bearer\\s+(.*)$/i', $header, $matches ) ) {
			return new WP_Error(
				'genius_infast_webhook_invalid_header',
				__( 'Le header Authorization ne contient pas de jeton Bearer.', 'genius_infast' ),
				array( 'status' => 401 )
			);
		}

		$provided_token = trim( (string) $matches[1] );

		if ( ! hash_equals( $expected_token, $provided_token ) ) {
			return new WP_Error(
				'genius_infast_webhook_forbidden',
				__( 'Jeton de webhook invalide.', 'genius_infast' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle webhook payloads.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			$raw_body = $request->get_body();
			if ( $raw_body ) {
				$decoded = json_decode( $raw_body, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$payload = $decoded;
				}
			}
		}

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'genius_infast_webhook_invalid_payload',
				__( 'Le corps de la requête webhook est invalide ou vide.', 'genius_infast' ),
				array( 'status' => 400 )
			);
		}

		$event_id = $this->extract_event_id( $payload );

		if ( '' === $event_id ) {
			return new WP_Error(
				'genius_infast_webhook_missing_event',
				__( 'Impossible de déterminer le type d’événement du webhook.', 'genius_infast' ),
				array( 'status' => 400 )
			);
		}

		if ( 'customer.deleted' !== $event_id ) {
			$this->log( 'handle_webhook: evenement ignore (' . $event_id . ').' );
			return rest_ensure_response(
				array(
					'handled' => false,
					'event'   => $event_id,
				)
			);
		}

		$customer_id = $this->extract_customer_id( $payload );

		if ( '' === $customer_id ) {
			return new WP_Error(
				'genius_infast_webhook_missing_customer',
				__( 'Le webhook customer.deleted ne contient pas d’identifiant client.', 'genius_infast' ),
				array( 'status' => 400 )
			);
		}

		$cleared = $this->clear_customer_reference( $customer_id );

		return rest_ensure_response(
			array(
				'handled'    => true,
				'event'      => $event_id,
				'customerId' => $customer_id,
				'cleared'    => $cleared,
			)
		);
	}

	/**
	 * Extract event identifier from payload.
	 *
	 * @param array $payload Payload array.
	 * @return string
	 */
	private function extract_event_id( array $payload ) {
		if ( ! empty( $payload['event']['eventId'] ) ) {
			return sanitize_text_field( (string) $payload['event']['eventId'] );
		}

		if ( ! empty( $payload['event']['event'] ) ) {
			return sanitize_text_field( (string) $payload['event']['event'] );
		}

		if ( ! empty( $payload['eventId'] ) ) {
			return sanitize_text_field( (string) $payload['eventId'] );
		}

		if ( ! empty( $payload['event'] ) && is_string( $payload['event'] ) ) {
			return sanitize_text_field( (string) $payload['event'] );
		}

		return '';
	}

	/**
	 * Extract customer identifier from payload.
	 *
	 * @param array $payload Payload array.
	 * @return string
	 */
	private function extract_customer_id( array $payload ) {
		if ( ! empty( $payload['event']['data']['customerId'] ) ) {
			return sanitize_text_field( (string) $payload['event']['data']['customerId'] );
		}

		if ( ! empty( $payload['event']['data']['customer']['id'] ) ) {
			return sanitize_text_field( (string) $payload['event']['data']['customer']['id'] );
		}

		if ( ! empty( $payload['event']['customerId'] ) ) {
			return sanitize_text_field( (string) $payload['event']['customerId'] );
		}

		if ( ! empty( $payload['customerId'] ) ) {
			return sanitize_text_field( (string) $payload['customerId'] );
		}

		return '';
	}

	/**
	 * Remove customer meta from associated WordPress users.
	 *
	 * @param string $customer_id INFast customer identifier.
	 * @return int Number of users updated.
	 */
	private function clear_customer_reference( $customer_id ) {
		$user_ids = get_users(
			array(
				'meta_key'   => '_genius_infast_customer_id',
				'meta_value' => $customer_id,
				'fields'     => 'ids',
			)
		);

		$count = 0;

		if ( ! empty( $user_ids ) && is_array( $user_ids ) ) {
			foreach ( $user_ids as $user_id ) {
				if ( delete_user_meta( $user_id, '_genius_infast_customer_id' ) ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
