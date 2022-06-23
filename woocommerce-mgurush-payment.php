<?php
/*
 * Plugin Name: mGurush Payment Gateway
 * Description: mGurush Payment Gateway
 * Author: Chol Paul
 * Version: 0.1
 */
 
add_action('woocommerce_order_refunded', function ($orderId) {
	$order = wc_get_order($orderId);
	$transactionId = get_post_meta($orderId, 'mgurush_transaction_id', true);
	$refNumber = get_post_meta($orderId, 'ref_number', true);
	$refundRefNumber = uniqid();
	$mgurush = WC()->payment_gateways->payment_gateways()['mgurush'];
	if ($mgurush->settings['production'] == 'Yes') {
		$subdomain  = 'app';
	} else {
		$subdomain  = 'uat';
	}
	$data = json_encode([
		'partnerCode' => (string) $mgurush->settings['partner_code'],
		'amount' => (string) $order->get_total(),
		'currency' => $order->get_currency(),
		'txnRefNumber' => (string) $transactionId,
		'orderId' => $orderId,
		'refundTxnRefNumber' => $refNumber
	]);
	$raw = hash_hmac('sha256', $data, $mgurush->settings['secret'], true);
	$hmac = base64_encode($raw);
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, 'https://' . $subdomain . '.mgurush.com/irh/ecomTxn/refund');
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_POST, 1);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	curl_setopt($curl, CURLOPT_HTTPHEADER, [
		'Content-Type: application/json',
		'Access_key: ' . $mgurush->settings['access_key'],
		'Hmac: ' . $hmac
	]);
	$response = curl_exec($curl);
	curl_close($curl);
	$order->add_order_note($response);
	$order->save();
});

add_action('woocommerce_api_mgurush', function () {
    global $wpdb;
	$dispatch = sanitize_text_field($_GET['dispatch']);
	switch ($dispatch) {
		case 'payment_notification.validate':
			$refNumber = sanitize_text_field($_GET['txn_ref']);
			$orderId = $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . $wpdb->prefix . 'postmeta WHERE meta_key = %s AND meta_value = %s', 'ref_number', $refNumber));
			$order = wc_get_order($orderId);
			$mgurush = WC()->payment_gateways->payment_gateways()['mgurush'];
			wp_send_json([
				'existence' => true,
				'body_response' => [
					'amount' => $order->get_total(),
					'merchant_name' => $mgurush->settings['merchant_name'],
					'merchant_code' => (int) $mgurush->settings['partner_code'],
					'currency' => $order->get_currency(),
					'txn_ref' => $refNumber,
					'order_id' => $orderId
				]
			]);		
			break;
		case 'payment_notification.process':
			$refNumber = sanitize_text_field($_GET['txn_ref']);
			$transactionId = sanitize_text_field($_GET['trans_id']);
			$orderId = $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . $wpdb->prefix . 'postmeta WHERE meta_key = %s AND meta_value = %s', 'ref_number', $refNumber));
			update_post_meta($orderId, 'mgurush_transaction_id', $transactionId);
			$order = wc_get_order($orderId);
			$order->payment_complete();
			$order->reduce_order_stock();
			$thankYouUrl = wc_get_checkout_url() . '/order-received/' . $orderId . '/?key=' . $order->get_order_key();
			echo 'errorCode=200&status=Payment success&callback=' . urlencode($thankYouUrl);
			break;
		case 'payment_notification.fail':
			$refNumber = sanitize_text_field($_GET['txn_ref']);
			$orderId = $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . $wpdb->prefix . 'postmeta WHERE meta_key = %s AND meta_value = %s', 'ref_number', $refNumber));
			update_post_meta($orderId, 'mgurush_transaction_id', $transactionId);
			$order = wc_get_order($orderId);
			$order->update_status('failed');
			$order->save();
			$thankYouUrl = wc_get_checkout_url() . '/order-received/' . $orderId . '/?key=' . $order->get_order_key();
			echo 'errorCode=400&status=Payment failed&callback=' . urlencode($thankYouUrl);
			break;
	}
	exit;
});

add_filter('woocommerce_payment_gateways', function ($gateways) {
	$gateways[] = 'WC_mGurush_Gateway';
	return $gateways;
});

add_action('plugins_loaded', function () {
	class WC_mGurush_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'mgurush';
			$this->icon = plugin_dir_url(__FILE__) . 'icon.png';
			$this->has_fields = false;
			$this->supports = ['products'];
			$this->method_title = 'mGurush';
			$this->method_description = 'mGurush payment method';
			$this->init_form_fields();
			$this->init_settings();
			$this->title = 'mGurush';
			$this->description = 'mGurush payment method';
			$this->enabled = $this->get_option('enabled');
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		}
		
		public function init_form_fields() {
			$this->form_fields = [
				'enabled' => [
					'title' => 'Enable',
					'label' => 'Enable mGurush gateway',
					'type' => 'checkbox'
				],
				'access_key' => [
					'title' => 'Access key',
					'type' => 'text',
				],
				'secret' => [
					'title' => 'Secret',
					'type' => 'text',
				],
				'production' => [
					'title' => 'Production',
					'type' => 'select',
					'options' => ['No' => 'No', 'Yes' => 'Yes']
				],
				'partner_code' => [
					'title' => 'Partner code',
					'type' => 'text',
				],
				'merchant_name' => [
					'title' => 'Merchant name',
					'type' => 'text',
				]
			];
		}
		
		public function process_payment($orderId) {
			global $woocommerce;
			$woocommerce->cart->empty_cart();
			$partnerCode = $this->get_option('partner_code');
			$refNumber = uniqid();
			update_post_meta($orderId, 'ref_number', $refNumber);
			$mgurush = WC()->payment_gateways->payment_gateways()['mgurush'];
			if ($mgurush->settings['production'] == 'Yes') {
				$subdomain  = 'app';
			} else {
				$subdomain  = 'uat';
			}
			return [
				'result' => 'success',
				'redirect' => 'https://' . $subdomain . '.mgurush.com/mGurush_eCom/txnPin/txnPin.html?txnRefNumber=' . $refNumber . '&partnerCode=' . $partnerCode . '&order_id=' . $orderId
			];
		}
	}
});