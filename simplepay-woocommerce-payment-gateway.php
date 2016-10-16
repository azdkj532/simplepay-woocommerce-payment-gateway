<?php
/*
	Plugin Name: Jdways WooCommerce Payment Gateway
	Plugin URI: http://bosun.me/simplepay-woocommerce-payment-gateway
	Description: Simplepay Woocommerce Payment Gateway allows you to accept local and International payment via Verve Card, MasterCard, Visa Card & eTranzact.
	Version: 1.2.0
	Author: Tunbosun Ayinla
	Author URI: http://bosun.me/
	License:		   GPL-2.0+
 	License URI:	   http://www.gnu.org/licenses/gpl-2.0.txt
 	GitHub Plugin URI: https://github.com/tubiz/simplepay-woocommerce-payment-gateway
*/

if ( ! defined( 'ABSPATH' ) )
	exit;

add_action('plugins_loaded', 'tbz_wc_simplepay_init', 0);

function tbz_wc_simplepay_init() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

	/**
 	 * Gateway class
 	 */
	class WC_Jcard_Gateway extends WC_Payment_Gateway {

		public function __construct(){

			$this->id 					= 'jcard_gateway';
			$this->icon 				= '';
			$this->has_fields 			= false;
			$this->order_button_text	= 'Make Payment';
			$this->method_title	 	    = 'Jcard';
			$this->method_description  	= 'Pay through Jcard';

			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title 				= $this->get_option( 'title' );
			$this->description 			= $this->get_option( 'description' );
			$this->testmode				= $this->get_option( 'testmode' );

			// Jdway information
			$this->service_code			= $this->get_option( 'service_code' );
			$this->sign_key				= $this->get_option( 'sign_key' );
			$this->testurl 				= $this->get_option('testurl');
			$this->liveurl 				= $this->get_option('liveurl');
			$this->checkout_url         = WC()->api_request_url('WC_Jcard_Gateway');

			//Actions
			add_action( 'woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_' . $this->id, array( $this, 'check_jdway_response' ) );

			// Check if the gateway can be used
		}

		/**
		 * Admin Panel Options
		 **/
		public function admin_options(){
			echo '<h3>Jdways Payment settings</h3>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
		}

		/**
		 * Initialise Gateway Settings Form Fields
		**/
		function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title' 		=> 'Enable/Disable',
					'type' 			=> 'checkbox',
					'label' 		=> 'Enable SimplePay Payment Gateway',
					'description' 	=> 'Enable or disable the gateway.',
					'desc_tip'		=> true,
					'default' 		=> 'yes'
				),
				'title' => array(
					'title' 		=> 'Title',
					'type' 			=> 'text',
					'description' 	=> 'This controls the title which the user sees during checkout.',
					'desc_tip'		=> false,
					'default' 		=> 'Jcard'
				),
				'description' => array(
					'title' 		=> 'Description',
					'type' 			=> 'textarea',
					'description' 	=> 'This controls the description which the user sees during checkout.',
					'default' 		=> 'Payment Methods Accepted: MasterCard, VisaCard, Verve Card & eTranzact'
				),
				'liveurl' => array(
					'title' 		=> 'LiveUrl',
					'type' 			=> 'text',
					'description' 	=> '',
					'default' 		=> 'http://www.gamecard.com.tw/Payment/Choice.asp'
				),
				'testurl' => array(
					'title' 		=> 'Sand Box',
					'type' 			=> 'text',
					'description' 	=> '',
					'default' 		=> 'http://60.199.176.121/Payment/Choice.asp'
				),
				'service_code' => array(
					'title' 		=> 'ServiceCode',
					'type' 			=> 'text',
					'description' 	=> 'This controls the ServiceCode which the server uses during payment.',
					'desc_tip'		=> false,
					'default' 		=> ''
				),
				'sign_key' => array(
					'title' 		=> 'SignKey',
					'type' 			=> 'text',
					'description' 	=> '',
					'desc_tip		'=> false,
					'default' 		=> ''
				),
				'testing' => array(
					'title'	   		=> 'Gateway Testing',
					'type'			=> 'title',
					'description' 	=> '',
				),
				'testmode' => array(
					'title'	   		=> 'Test Mode',
					'type'			=> 'checkbox',
					'label'	   		=> 'Enable Test Mode',
					'default'	 	=> 'no',
					'description' 	=> 'Test mode enables you to test payments before going live. <br />If you ready to start receving payment on your site, kindly uncheck this.',
				)
			);
		}

		/**
		 * Get SimplePay Args for passing to SimplePay
		**/
		function get_jdway_args( $order ) {

			$order_id 		= $order->id;
			$order_total	= $order->get_total();
			$user_id		= $order->get_user_id();

			// $return_url	 	= esc_url( $this->get_return_url( $order ) );
			$return_url	 	= esc_url( $this->checkout_url );
			$cancel_url 	= esc_url( $order->get_cancel_order_url() );

			$memo			= '';
			$sign_code		= md5($order_id . $this->sign_key);

			// Jdway Args
			$payment_args = array(
				'ServiceCode' 	=> $this->service_code,
				'OrderID'		=> $order_id,
				'ReturnURL'		=> esc_url_raw($return_url),
				'UserID'		=> $user_id,
				'Memo'			=> $memo,
				'Product'		=> '',
				'SignCode'		=> $sign_code,
				'PayType'		=> 'F',
                'Money'			=> $order_total,
				'PayTypeFor'	=> 'summit',
			);

			$jdway_args = apply_filters( 'woocommerce_simplepay_args', $payment_args );
			return $jdway_args;
		}

		/*
		 * Generate payment request url
		 */
		function get_redirect_url( $order_id, $sandbox = false ) {
			$order 			= wc_get_order( $order_id );

			$jdway_args = $this->get_jdway_args( $order );
			$payment_args = http_build_query( $jdway_args, '', '&' );

			if ($sandbox) {
				return $this->testurl .'?'. $payment_args;
			} else {
				return $this->liveurl .'?'. $payment_args;
			}
		}

		/**
		 * Process the payment and return the result
		**/
		function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$sandbox = 'yes' === $this->testmode;
			return array(
				'result' => 'success',
				'redirect'	=> $order->get_checkout_payment_url( true ),
			);
		}

		/**
		 * Output for the order received page.
		**/
        function receipt_page( $order_id ) {
			$order = wc_get_order( $order_id );
			echo '<p>Thank you - your order is now pending payment. You will be automatically redirected to the gateway to make payment.';

			if ( 'yes' == $this->testmode ) {
				$payment_url = $this->testurl;
			} else {
				$payment_url = $this->liveurl;
			}

			$jdway_args = $this->get_jdway_args( $order );
			$jdway_form_array = array();

			foreach ($jdway_args as $key => $value) {
				$jdway_form_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
			}

			$form = '<form action="' . esc_url( $payment_url ) . '" method="post" id="jdway_payment_form" target="_top">
						' . implode( '', $jdway_form_array ) . '
						<div class="payment_buttons">
							<input type="submit" class="button alt" id="submit_jdway_payment_form" value="確認" />
							<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">Cancel order &amp; restore cart</a>
						</div>
					</form>';
			echo $form;
			return $form;
		}

		/**
		 * Verify a successful Payment!
		**/
		function check_jdway_response( $posted ) {
			if ($_POST['Flag'] == '1') {
				$order_id = $_POST['OrderID'];
				$order = wc_get_order( $order_id );

				$transaction_id = $_POST['TransactionID'];
				$service_code = $this->service_code;
				$money = $order->get_total();

				// check sign code

				$sign_code = md5('' . $order_id .'&'. $this->sign_key . '_' . $transaction_id . '&' . $service_code . '_' .$money);
				$return_sign_code = strtolower($_POST['SignCode']);
				if ($sign_code != $return_sign_code) {
					die ('signed code error');
				}

				// check money is correct
				if ($_POST['Money'] != $money) {
					die ('Wrong amount of payment (' .$_POST['Money']. ' != ' .$money. ')' );
				}
				wc_redirect( $this->get_return_url($order) );

			} else {
				die('Fail to pay the bill');
			}
		}

	}

	/**
 	* Add SimplePay Gateway to WC
 	**/
	function wc_add_jdways_gateway($methods) {
		$methods[] = 'WC_Jcard_Gateway';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'wc_add_jdways_gateway' );


	/**
	* Add Settings link to the plugin entry in the plugins menu for WC 2.1 and above
	**/
	add_filter('plugin_action_links', 'plugin_action_links', 10, 2);

	function plugin_action_links($links, $file) {
		static $this_plugin;

		if (!$this_plugin) {
			$this_plugin = plugin_basename(__FILE__);
		}

		if ($file == $this_plugin) {
			$settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_jcard_gateway">Settings</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	/**
 	* Display the testmode notice
 	**/
	function wc_testmode_notice(){
		$settings = get_option( 'woocommerce_jcard_gateway_settings' );

		$test_mode = $settings['testmode'];

		if ( 'yes' == $test_mode ) {
		?>
			<div class="update-nag">
				SimplePay testmode is still enabled. Click <a href="<?php echo get_bloginfo('wpurl') ?>/wp-admin/admin.php?page=wc-settings&tab=checkout&section=WC_Jcard_Gateway">here</a> to disable it when you want to start accepting live payment on your site.
			</div>
		<?php
		}
	}
	add_action( 'admin_notices', 'wc_testmode_notice' );
}
