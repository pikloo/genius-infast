<?php

/**
 * Low level HTTP client handling INFast authentication and requests.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles OAuth authentication and HTTP requests against the INFast API.
 */
class Genius_Infast_Client {

	/**
	 * Base URL for INFast API.
	 */
	const BASE_URL = 'https://api.infast.fr/api/v2';

	/**
	 * Client identifier provided by INFast.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Client secret provided by INFast.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * Optional logger instance when WooCommerce is available.
	 *
	 * @var WC_Logger|null
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param string $client_id     OAuth client identifier.
	 * @param string $client_secret OAuth client secret.
	 */
	public function __construct( $client_id, $client_secret ) {
		$this->client_id     = trim( (string) $client_id );
		$this->client_secret = trim( (string) $client_secret );
		$this->logger        = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
	}

	/**
	 * Retrieve an access token (cached when possible).
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
			return new WP_Error( 'genius_infast_missing_credentials', __( 'Les identifiants INFast sont manquants.', 'genius_infast' ) );
		}

		$cache_key = $this->get_token_cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && ! empty( $cached['token'] ) && ! empty( $cached['expires_at'] ) && $cached['expires_at'] > time() + 30 ) {
			return $cached['token'];
		}

		$response = wp_remote_post(
			self::BASE_URL . '/oauth2/token',
			array(
				'timeout'     => 30,
				'httpversion' => '1.1',
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'        => array(
					'grant_type' => 'client_credentials',
					'scope'      => 'write',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			$message = ! empty( $data['error_description'] ) ? $data['error_description'] : __( 'Impossible d obtenir un jeton d acces INFast.', 'genius_infast' );
			$this->clear_token_cache();

			$this->log_error(
				$message,
				array(
					'status' => $code,
					'body'   => $body,
				)
			);

			return new WP_Error(
				'genius_infast_token_error',
				$message,
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		$expires_in = ! empty( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;
		$payload    = array(
			'token'      => $data['access_token'],
			'expires_at' => time() + max( 60, $expires_in ),
		);

		$cache_ttl = max( 60, $expires_in - 60 );

		set_transient( $cache_key, $payload, $cache_ttl );

		return $payload['token'];
	}

	/**
	 * Clear cached access token.
	 *
	 * @return void
	 */
	public function clear_token_cache() {
		delete_transient( $this->get_token_cache_key() );
	}

	/**
	 * Perform a request against the API.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Endpoint path.
	 * @param array  $args   Additional arguments (headers/body/query).
	 * @param bool   $retry  Whether the call can retry once on 401.
	 * @return array|WP_Error
	 */
	public function request( $method, $path, array $args = array(), $retry = true ) {
		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = trailingslashit( self::BASE_URL ) . ltrim( $path, '/' );

		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		);

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}

		$request_args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => 30,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ( isset( $args['body'] ) ) {
			if ( null === $args['body'] ) {
				$request_args['body'] = '';
			} elseif ( is_string( $args['body'] ) ) {
				$request_args['body'] = $args['body'];
			} else {
				$request_args['body']                    = wp_json_encode( $args['body'] );
				$request_args['headers']['Content-Type'] = 'application/json';
			}
		}

		if ( isset( $args['query'] ) && is_array( $args['query'] ) && ! empty( $args['query'] ) ) {
			$query = array();

			foreach ( $args['query'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$query[ $key ] = $value;
					continue;
				}

				if ( null === $value ) {
					continue;
				}

				$query[ $key ] = (string) $value;
			}

			if ( ! empty( $query ) ) {
				$query_string = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); // RFC 3986 preserves + as %2B.

				if ( '' !== $query_string ) {
					$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query_string;
				}
			}
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code && $retry ) {
			$this->clear_token_cache();
			return $this->request( $method, $path, $args, false );
		}

		if ( '' === $body ) {
			return array();
		}

		$data = json_decode( $body, true );

		if ( null === $data && '' !== $body ) {
			return new WP_Error( 'genius_infast_json_error', __( 'Reponse inattendue du service INFast.', 'genius_infast' ), array( 'status' => $code, 'body' => $body ) );
		}

		if ( $code >= 400 ) {
			$message = isset( $data['error'] ) ? $data['error'] : __( 'Erreur de l API INFast.', 'genius_infast' );
			$this->log_error( $message, array( 'status' => $code, 'details' => isset( $data['details'] ) ? $data['details'] : null ) );

			return new WP_Error(
				'genius_infast_api_error',
				$message,
				array(
					'status'  => $code,
					'details' => isset( $data['details'] ) ? $data['details'] : null,
				)
			);
		}

		return $data;
	}

	/**
	 * Retrieve current portal information.
	 *
	 * @return array|WP_Error
	 */
	public function get_portal() {
		return $this->request( 'GET', 'portal' );
	}

	/**
	 * Create a cache key for access token storage.
	 *
	 * @return string
	 */
	private function get_token_cache_key() {
		return 'genius_infast_token_' . md5( $this->client_id );
	}

	/**
	 * Log API errors when a logger is available.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Contextual data.
	 * @return void
	 */
	private function log_error( $message, array $context = array() ) {
		if ( ! $this->logger ) {
			return;
		}

		$context['source'] = 'genius-infast';
		$this->logger->error( $message, $context );
	}
}
