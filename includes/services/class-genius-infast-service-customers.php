<?php

/**
 * Customer related operations for INFast.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes/services
 */

defined('ABSPATH') || exit;

/**
 * Handles customer endpoints.
 */
class Genius_Infast_Service_Customers
{

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
	public function __construct(Genius_Infast_Client $client)
	{
		$this->client = $client;
	}

	/**
	 * Validate credentials by calling /me.
	 *
	 * @return array|WP_Error
	 */
	public function get_current_user()
	{
		return $this->client->request('GET', 'me');
	}

	/**
	 * Find a customer using its email address.
	 *
	 * @param string $email Email to search.
	 * @return array|WP_Error|null
	 */
	public function find_by_email($email)
	{
		$email = sanitize_email(trim((string) $email));
		if (empty($email) || !is_email($email)) {
			return null;
		}

		$response = $this->client->request(
			'GET',
			'customers',
			array(
				'query' => array(
					'email' => $email,
					'limit' => 1,
				),
			)
		);

		if (is_wp_error($response)) {
			$data   = $response->get_error_data();
			$status = 0;
			if (is_array($data)) {
				if (isset($data['status'])) {
					$status = (int) $data['status'];
				} elseif (isset($data['code'])) {
					$status = (int) $data['code'];
				} elseif (isset($data['response']['code'])) {
					$status = (int) $data['response']['code'];
				}
			}
			if (404 === $status) {
				return null;
			}

			$maybe_message = $response->get_error_message();
			if (stripos((string) $maybe_message, 'not found') !== false) {
				return null;
			}
			return $response;
		}

		if (empty($response['data']) || !is_array($response['data'])) {
			return null;
		}

		return $response['data'][0];
	}

	/**
	 * Create a customer.
	 *
	 * @param array $payload Customer payload.
	 * @return array|WP_Error
	 */
	public function create(array $payload)
	{
		return $this->client->request(
			'POST',
			'customers',
			array(
				'body' => $payload,
			)
		);
	}
}
