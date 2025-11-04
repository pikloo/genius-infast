<?php

/**
 * WooCommerce integration layer for INFast automation.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

defined('ABSPATH') || exit;

/**
 * Handles WooCommerce order synchronisation with INFast.
 */
class Genius_Infast_WooCommerce
{

	/**
	 * Plugin slug.
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
	 * Handle a WooCommerce order when payment is completed.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return void
	 */
	public function handle_payment_complete($order_id)
	{
		$this->synchronise_order($order_id);
	}

	/**
	 * Fallback handler when order reaches the completed status.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return void
	 */
	public function handle_order_completed($order_id)
	{
		$this->synchronise_order($order_id);
	}

	/**
	 * Handle any status change.
	 *
	 * @param int        $order_id   Order ID.
	 * @param string     $old_status Old status.
	 * @param string     $new_status New status.
	 * @param WC_Order   $order      Order object.
	 * @return void
	 */
	public function handle_order_status_changed($order_id, $old_status = '', $new_status = '', $order = null)
	{
		unset($old_status, $new_status, $order);
		$this->synchronise_order($order_id);
	}

	/**
	 * Perform the synchronisation workflow.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return void
	 */
	private function synchronise_order($order_id)
	{
		if (!function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);

		if (!$order instanceof WC_Order) {
			return;
		}

		$statuses = $this->get_trigger_statuses();
		$status = 'wc-' . $order->get_status();

		if (!in_array($status, $statuses, true)) {
			return;
		}

		// Avoid concurrent executions.
		if ('yes' === $order->get_meta('_genius_infast_syncing', true)) {
			return;
		}

		$order->update_meta_data('_genius_infast_syncing', 'yes');
		$order->save();

		try {
			$this->process_order($order);
		} catch (Exception $exception) {
			$this->log_error($exception->getMessage(), array('order_id' => $order->get_id()));
			$order->add_order_note(sprintf(__('Erreur INFast : %s', 'genius_infast'), $exception->getMessage()));
		}

		$order->delete_meta_data('_genius_infast_syncing');
		$order->save();
	}

	/**
	 * Core workflow for a single order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 *
	 * @throws Exception When an unrecoverable error occurs.
	 */
	private function process_order(WC_Order $order)
	{
		$credentials = $this->get_credentials();

		if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
			throw new Exception(__('Les identifiants INFast ne sont pas configures.', 'genius_infast'));
		}

		$api = new Genius_Infast_API($credentials['client_id'], $credentials['client_secret']);

		$customer_id = $this->get_or_create_customer($order, $api);
		if (is_wp_error($customer_id)) {
			throw new Exception($customer_id->get_error_message());
		}

		$document_id = $this->get_or_create_document($order, $api, $customer_id);
		if (is_wp_error($document_id)) {
			throw new Exception($document_id->get_error_message());
		}

		$payment_result = $this->ensure_payment_recorded($order, $api, $document_id);
		if (is_wp_error($payment_result)) {
			throw new Exception($payment_result->get_error_message());
		}

		if ($this->should_send_email()) {
			$email_result = $this->maybe_send_document_email($order, $api, $document_id);
			if (is_wp_error($email_result)) {
				$this->log_error($email_result->get_error_message(), array('order_id' => $order->get_id(), 'document_id' => $document_id));
				$order->add_order_note(sprintf(__('Impossible d envoyer automatiquement l e-mail INFast : %s', 'genius_infast'), $email_result->get_error_message()));
			}
		}
	}

	/**
	 * Ensure the customer exists on INFast.
	 *
	 * @param WC_Order          $order WooCommerce order.
	 * @param Genius_Infast_API $api   API client.
	 * @return string|WP_Error
	 */
	private function get_or_create_customer(WC_Order $order, Genius_Infast_API $api)
	{
		$existing_id = $order->get_meta('_genius_infast_customer_id', true);
		if ($existing_id) {
			return $existing_id;
		}

		$user_id = $order->get_user_id();
		if ($user_id) {
			$user_customer_id = get_user_meta($user_id, '_genius_infast_customer_id', true);
			if ($user_customer_id) {
				$order->update_meta_data('_genius_infast_customer_id', $user_customer_id);
				$order->save();
				return $user_customer_id;
			}
		}

		$email = $order->get_billing_email();

		if ($email) {
			$response = $api->find_customer_by_email($email);

			if (is_wp_error($response)) {
				if ($this->is_not_found_wp_error($response)) {
					$response = null;
				} else {
					return $response;
				}
			}

			if (is_array($response) && !empty($response['id'])) {
				$customer_id = $response['id'];
				$this->persist_customer_reference($order, $customer_id);
				$order->add_order_note(__('Client INFast existant trouvé via l’e-mail.', 'genius_infast'));
				return $customer_id;
			}
		}

		$payload = $this->build_customer_payload($order);

		$response = $api->create_customer($payload);

		if (is_wp_error($response)) {
			return $response;
		}

		if (empty($response['data']['id'])) {
			return new WP_Error('genius_infast_missing_customer_id', __('La reponse INFast ne contient pas d identifiant client.', 'genius_infast'));
		}

		$customer_id = $response['data']['id'];
		$this->persist_customer_reference($order, $customer_id);
		$order->add_order_note(__('Client cree sur INFast.', 'genius_infast'));

		return $customer_id;
	}

	/**
	 * Create or reuse document for order.
	 *
	 * @param WC_Order          $order       WooCommerce order.
	 * @param Genius_Infast_API $api         API client.
	 * @param string            $customer_id INFast customer identifier.
	 * @return string|WP_Error
	 */
	private function get_or_create_document(WC_Order $order, Genius_Infast_API $api, $customer_id)
	{
		$document_id = $order->get_meta('_genius_infast_document_id', true);

		if ($document_id) {
			return $document_id;
		}

		$payload = $this->build_document_payload($order, $customer_id);

		$response = $api->create_document($payload);

		if (is_wp_error($response)) {
			return $response;
		}

		if (empty($response['data']['id'])) {
			return new WP_Error('genius_infast_missing_document_id', __('La reponse INFast ne contient pas d identifiant de document.', 'genius_infast'));
		}

		$document_id = $response['data']['id'];
		$order->update_meta_data('_genius_infast_document_id', $document_id);
		$order->save();
		$order->add_order_note(sprintf(__('Facture %s creee sur INFast.', 'genius_infast'), $document_id));

		return $document_id;
	}

	/**
	 * Ensure payment is recorded on INFast.
	 *
	 * @param WC_Order          $order       WooCommerce order.
	 * @param Genius_Infast_API $api         API client.
	 * @param string            $document_id INFast document identifier.
	 * @return true|WP_Error
	 */
	private function ensure_payment_recorded(WC_Order $order, Genius_Infast_API $api, $document_id)
	{
		$transaction_id = $order->get_meta('_genius_infast_transaction_id', true);

		if ($transaction_id) {
			return true;
		}

		$total = (float) $order->get_total();

		// Avoid adding payments with zero amount.
		if ($total <= 0) {
			return true;
		}

		$info = sprintf(__('Commande WooCommerce no%s', 'genius_infast'), $order->get_order_number());
		if ($order->get_payment_method_title()) {
			$info .= ' - ' . $order->get_payment_method_title();
		}

		$payment_payload = array(
			'method' => $this->map_transaction_method($order->get_payment_method()),
			'amount' => $this->format_amount($total),
			'info' => $info,
		);

		$payment_response = $api->add_payment_on_document($document_id, $payment_payload);

		if (is_wp_error($payment_response)) {
			return $payment_response;
		}

		if (empty($payment_response['data']['id'])) {
			return new WP_Error('genius_infast_missing_transaction_id', __('La reponse INFast ne contient pas d identifiant de paiement.', 'genius_infast'));
		}

		$order->update_meta_data('_genius_infast_transaction_id', $payment_response['data']['id']);
		$order->save();
		$order->add_order_note(__('Paiement enregistre sur INFast.', 'genius_infast'));

		return true;
	}

	/**
	 * Send the document by email if not already done.
	 *
	 * @param WC_Order          $order       WooCommerce order.
	 * @param Genius_Infast_API $api         API client.
	 * @param string            $document_id INFast document identifier.
	 * @return true|WP_Error
	 */
	private function maybe_send_document_email(WC_Order $order, Genius_Infast_API $api, $document_id)
	{
		$already_sent = (bool) $order->get_meta('_genius_infast_document_email_sent', true);

		if ($already_sent) {
			return true;
		}

		$email_copy = sanitize_email(get_option('genius_infast_email_copy', ''));
		$payload = array();

		if ($email_copy) {
			$payload['cc'] = $email_copy;
		}

		$response = $api->send_document_email($document_id, $payload);

		if (is_wp_error($response)) {
			return $response;
		}

		$order->update_meta_data('_genius_infast_document_email_sent', 'yes');
		$order->save();
		$order->add_order_note(__('Facture envoyee via INFast.', 'genius_infast'));

		return true;
	}

	/**
	 * Prepare customer payload from WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function build_customer_payload(WC_Order $order)
	{
		$email_raw = $order->get_billing_email();

		$email_clean = sanitize_email(trim((string) $email_raw));

		if (empty($email_clean) || !is_email($email_clean)) {
			throw new Exception(__('Adresse e-mail invalide pour ce client.', 'genius_infast'));
			// $email_clean = 'no-reply+' . $order->get_id() . '@example.invalid';
		}

		// $email_encoded = rawurlencode($email_clean);

		$name = $order->get_billing_company();
		if (empty($name)) {
			$name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		if (empty($name)) {
			$name = $email_clean;
		}
		if (empty($name)) {
			$name = sprintf(__('Client WooCommerce no%d', 'genius_infast'), $order->get_id());
		}

		$street_parts = array_filter([
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
		]);

		$address = array_filter([
			'street' => implode("\n", $street_parts),
			'postalCode' => $order->get_billing_postcode(),
			'city' => $order->get_billing_city(),
			'country' => $this->get_country_label($order->get_billing_country()),
		]);

		return array_filter([
			'name' => $name,
			'email' => $email_raw,
			'mobile' => $order->get_billing_phone(),
			'address' => $address,
		]);
	}


	/**
	 * Prepare document payload for INFast invoice creation.
	 *
	 * @param WC_Order $order       WooCommerce order.
	 * @param string   $customer_id INFast customer identifier.
	 * @return array
	 */
	private function build_document_payload(WC_Order $order, $customer_id)
	{
		$order_timestamp = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : current_time('timestamp', true);
		$emit_date = gmdate('c', $order_timestamp);

		$lines = array();

		foreach ($order->get_items(array('line_item')) as $item) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			$quantity = (float) max(1, $item->get_quantity());
			$total = (float) $item->get_total();
			$total_tax = (float) $item->get_total_tax();
			$unit_price = $quantity > 0 ? $total / $quantity : 0;
			$sku = $product ? $product->get_sku() : '';
			$product_id = $item->get_product_id();
			$reference = $sku ? $sku : ($product_id ? (string) $product_id : 'ITEM-' . $item->get_id());
			$vat = $this->calculate_vat_rate($total, $total_tax);
			$item_payload = array(
				'lineType' => 'ITEM',
				'name' => $item->get_name(),
				'reference' => $reference,
				'price' => $this->format_amount($unit_price),
				'quantity' => $this->format_quantity($quantity),
				'vat' => $this->format_amount($vat),
				'type' => ($product && $product->is_virtual()) ? 'SERVICE' : 'PRODUCT',
			);

			$lines[] = $item_payload;
		}

		$shipping_total = (float) $order->get_shipping_total();
		$shipping_tax = (float) $order->get_shipping_tax();

		if ($shipping_total || $shipping_tax) {
			$lines[] = array(
				'lineType' => 'ITEM',
				'name' => $order->get_shipping_method() ? $order->get_shipping_method() : __('Livraison', 'genius_infast'),
				'reference' => 'SHIPPING',
				'price' => $this->format_amount($shipping_total),
				'quantity' => $this->format_quantity(1),
				'vat' => $this->format_amount($this->calculate_vat_rate($shipping_total, $shipping_tax)),
				'type' => 'SERVICE',
			);
		}

		foreach ($order->get_fees() as $fee) {
			$total = (float) $fee->get_total();
			$total_tax = (float) $fee->get_total_tax();

			if (0 === $total && 0 === $total_tax) {
				continue;
			}

			$lines[] = array(
				'lineType' => 'ITEM',
				'name' => $fee->get_name(),
				'reference' => 'FEE-' . $fee->get_id(),
				'price' => $this->format_amount($total),
				'quantity' => $this->format_quantity(1),
				'vat' => $this->format_amount($this->calculate_vat_rate($total, $total_tax)),
				'type' => 'SERVICE',
			);
		}

		if (empty($lines)) {
			throw new Exception(__('Aucune ligne de facture n a pu etre generee pour cette commande.', 'genius_infast'));
		}

		$payment_method = $this->map_payment_method($order->get_payment_method());
		$document_reference = $order->get_order_number();

		$payload = array(
			'type' => 'INVOICE',
			'status' => 'VALIDATED',
			'customerId' => $customer_id,
			'lines' => $lines,
			'referenceInternal' => (string) $document_reference,
			'emitDate' => $emit_date,
			'dueDate' => $emit_date,
			'metadata' => 'INTERNAL_DB_ID=' . $order->get_id(),

		);

		$discount_total = (float) $order->get_discount_total();

		if ($discount_total > 0) {
			$payload['discount'] = array(
				'type' => 'CASH',
				'amount' => $this->format_amount($discount_total),
			);
		}

		if ($payment_method) {
			$payload['paymentMethod'] = $payment_method;
			if ('OTHER' === $payment_method) {
				$payload['paymentMethodInfo'] = $order->get_payment_method_title();
			}
		}

		if ($this->is_option_enabled(get_option('genius_infast_enable_legal_notice', 'no'))) {
			$notice = trim((string) get_option('genius_infast_legal_notice', ''));
			if ($notice) {
				$payload['amountNotice'] = $notice;
			}
		}

		return $payload;
	}

	/**
	 * Persist customer reference on order and user (if available).
	 *
	 * @param WC_Order $order       WooCommerce order.
	 * @param string   $customer_id INFast customer identifier.
	 * @return void
	 */
	private function persist_customer_reference(WC_Order $order, $customer_id)
	{
		$order->update_meta_data('_genius_infast_customer_id', $customer_id);
		$order->save();

		$user_id = $order->get_user_id();
		if ($user_id) {
			update_user_meta($user_id, '_genius_infast_customer_id', $customer_id);
		}
	}

	/**
	 * Retrieve plugin credentials.
	 *
	 * @return array
	 */
	private function get_credentials()
	{
		return array(
			'client_id' => get_option('genius_infast_client_id', ''),
			'client_secret' => get_option('genius_infast_client_secret', ''),
		);
	}

	/**
	 * Whether the invoice should be emailed automatically.
	 *
	 * @return bool
	 */
	private function should_send_email()
	{
		$send = $this->is_option_enabled(get_option('genius_infast_send_email', 'yes'));
		return $send;
	}

	/**
	 * Retrieve statuses that trigger invoice synchronisation.
	 *
	 * @return array
	 */
	private function get_trigger_statuses()
	{
		$statuses = get_option('genius_infast_trigger_statuses', array('wc-completed'));

		if (!is_array($statuses) || empty($statuses)) {
			return array('wc-completed');
		}

		return array_map('sanitize_text_field', $statuses);
	}

	/**
	 * Log an error message if logger is available.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Contextual information.
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
	 * Determine if an option value equates to an enabled state.
	 *
	 * @param mixed $value Option value.
	 * @return bool
	 */
	private function is_option_enabled($value)
	{
		return in_array($value, array('yes', '1', 1, true, 'on'), true);
	}

	/**
	 * Map WooCommerce payment method to INFast document payment method.
	 *
	 * @param string $payment_method WooCommerce payment method slug.
	 * @return string|null
	 */
	private function map_payment_method($payment_method)
	{
		$map = array(
			'cheque' => 'CHECK',
			'bacs' => 'TRANSFER',
			'cod' => 'CASH',
			'stripe' => 'CREDITCARD',
			'stripe_cc' => 'CREDITCARD',
			'stripe_ideal' => 'TRANSFER',
			'paypal' => 'OTHER',
			'ppcp-gateway' => 'OTHER',
			'other' => 'OTHER',
		);

		return isset($map[$payment_method]) ? $map[$payment_method] : 'OTHER';
	}

	/**
	 * Map WooCommerce payment method to INFast transaction method.
	 *
	 * @param string $payment_method WooCommerce payment method slug.
	 * @return string
	 */
	private function map_transaction_method($payment_method)
	{
		$map = array(
			'cheque' => 'CHECK',
			'bacs' => 'TRANSFER',
			'cod' => 'CASH',
			'stripe' => 'CREDITCARD',
			'stripe_cc' => 'CREDITCARD',
			'stripe_ideal' => 'TRANSFER',
			'paypal' => 'OTHER',
			'ppcp-gateway' => 'OTHER',
		);

		return isset($map[$payment_method]) ? $map[$payment_method] : 'OTHER';
	}

	/**
	 * Format numeric amount respecting WooCommerce decimal settings.
	 *
	 * @param float $amount Numeric amount.
	 * @return float
	 */
	private function format_amount($amount)
	{
		$amount = (float) $amount;
		$decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

		if (function_exists('wc_format_decimal')) {
			return (float) wc_format_decimal($amount, $decimals);
		}

		return round($amount, $decimals);
	}

	/**
	 * Format quantity.
	 *
	 * @param float $quantity Quantity value.
	 * @return float
	 */
	private function format_quantity($quantity)
	{
		$quantity = (float) $quantity;

		if (function_exists('wc_format_decimal')) {
			return (float) wc_format_decimal($quantity, 4);
		}

		return round($quantity, 4);
	}

	/**
	 * Calculate VAT rate from totals.
	 *
	 * @param float $total     Amount excluding VAT.
	 * @param float $total_tax Tax amount.
	 * @return float
	 */
	private function calculate_vat_rate($total, $total_tax)
	{
		if ($total <= 0 || $total_tax <= 0) {
			return 0.0;
		}

		return ($total_tax / $total) * 100;
	}

	/**
	 * Retrieve the country label for INFast payload.
	 *
	 * @param string $country_code Country ISO code.
	 * @return string
	 */
	private function get_country_label($country_code)
	{
		if (empty($country_code)) {
			return '';
		}

		if (function_exists('WC') && isset(WC()->countries)) {
			$countries = WC()->countries->get_countries();
			if (isset($countries[$country_code])) {
				return $countries[$country_code];
			}
		}

		return strtoupper($country_code);
	}


	private function is_not_found_wp_error(\WP_Error $err): bool
	{
		$data = $err->get_error_data();
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

		if ($status === 404) {
			return true;
		}

		$msg = $err->get_error_message();
		return (stripos((string) $msg, 'not found') !== false);
	}

}
