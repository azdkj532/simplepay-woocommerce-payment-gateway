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

			//Actions
			add_action( 'woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_jcard_gateway', array( $this, 'check_simplepay_response' ) );

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

			$return_url	 	= esc_url( $this->get_return_url( $order ) );
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
			echo '<p>Thank you - your order is now pending payment. You will be automatically redirected to the gateway to make payment.</p>';

            if ( 'yes' == $this->testmode ) {
                $payment_url = $this->testurl;
            } else {
                $payment_url = $this->liveurl;
            }

            $jdway_args = $this->get_jdway_args( $order );
            $jdway_form_array = array();

            foreach ($jdway_args as $key => $value) {
                $simplepay_form_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
            }

            return '<form action="' . esc_url( $payment_url ) . '" method="post" id="jdway_payment_form" target="_top">
                        ' . implode( '', $jdway_form_array ) . '
                        <div class="payment_buttons">
                            <input type="submit" class="button alt" id="submit_jdway_payment_form" value="確認" />
                            <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">Cancel order &amp; restore cart</a>
                        </div>
                    </form>';
		}

		/**
		 * Verify a successful Payment!
		**/
		function check_simplepay_response( $posted ) {

			if( isset( $_POST['transaction_id'] ) ) {

				$transaction_id = $_POST['transaction_id'];

				$order_id 		= $_POST['customid'];
				$order_id 		= (int) $order_id;

				$order 			= wc_get_order( $order_id );
				$order_total	= $order->get_total();

				$amount_paid	= $_POST['total'];
				$response_code 	= $_POST['SP_TRANSACTION_ERROR_CODE'];
				$response_desc  = $_POST['SP_TRANSACTION_ERROR'];

				do_action('tbz_wc_simplepay_after_payment', $_POST);

				if( 'SP0000' == $response_code ) {

					// check if the amount paid is equal to the order amount.
					if( $amount_paid < $order_total ) {

						//Update the order status
						$order->update_status('on-hold', '');

						//Error Note
						$message = 'Thank you for shopping with us.<br />Your payment transaction was successful, but the amount paid is not the same as the total order amount.<br />Your order is currently on-hold.<br />Kindly contact us for more information regarding your order and payment status.';
						$message_type = 'notice';

						//Add Customer Order Note
						$order->add_order_note($message.'<br />Simplepay Transaction ID: '.$transaction_id, 1);

						//Add Admin Order Note
						$order->add_order_note('Look into this order. <br />This order is currently on hold.<br />Reason: Amount paid is less than the total order amount.<br />Amount Paid was &#8358; '.$amount_paid.' while the total order amount is &#8358; '.$order_total.'<br />Simplepay Transaction ID: '.$transaction_id);

						// Reduce stock levels
						$order->reduce_order_stock();

						// Empty cart
						WC()->cart->empty_cart();
					}
					else
					{

						if( $order->status == 'processing' ){
							$order->add_order_note('Payment Via Simplepay Payment Gateway<br />Transaction ID: '.$transaction_id);

							//Add customer order note
		 					$order->add_order_note('Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Transaction ID: '.$transaction_id, 1);

							// Reduce stock levels
							$order->reduce_order_stock();

							// Empty cart
							WC()->cart->empty_cart();

							$message = 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.';
							$message_type = 'success';
						}
						else {

							if( $order->has_downloadable_item() ){

								//Update order status
								$order->update_status( 'completed', 'Payment received, your order is now complete.' );

								//Add admin order note
								$order->add_order_note('Payment Via Simplepay Payment Gateway<br />Transaction ID: '.$transaction_id);

								//Add customer order note
			 					$order->add_order_note('Payment Received.<br />Your order is now complete.<br />Transaction ID: '.$transaction_id, 1);

								$message = 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is now complete.';
								$message_type = 'success';

							}
							else {

								//Update order status
								$order->update_status( 'processing', 'Payment received, your order is currently being processed.' );

								//Add admin order noote
								$order->add_order_note('Payment Via Simplepay Payment Gateway<br />Transaction ID: '.$transaction_id);

								//Add customer order note
			 					$order->add_order_note('Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Transaction ID: '.$transaction_id, 1);

								$message = 'Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.';
								$message_type = 'success';
							}

							// Reduce stock levels
							$order->reduce_order_stock();

							// Empty cart
							WC()->cart->empty_cart();
						}
					}

					$simplepay_message = array(
						'message'	=> $message,
						'message_type' => $message_type
					);

					if ( version_compare( WOOCOMMERCE_VERSION, "2.2" ) >= 0 ) {
						add_post_meta( $order_id, '_transaction_id', $transaction_id, true );
					}

					update_post_meta( $order_id, '_tbz_simplepay_message', $simplepay_message );

					die( 'IPN Processed OK. Payment Successfully' );
				}

				else
				{
					$message = 	'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.';
					$message_type = 'error';

					//Add Customer Order Note
				   	$order->add_order_note($message.'<br />Transaction ID: '.$transaction_id, 1);

					//Add Admin Order Note
				  	$order->add_order_note($message.'<br />Simplepay Transaction ID: '.$transaction_id);


					//Update the order status
					$order->update_status('failed', 'Payment failed');

					$simplepay_message = array(
						'message'	  	=> $message,
						'message_type' 	=> $message_type
					);

					update_post_meta( $order_id, '_tbz_simplepay_message', $simplepay_message );

					die( 'IPN Processed OK. Payment Failed' );
				}
			}
			else{
				$order_id 		= $_POST['customid'];

				$message 		= 'Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.';
				$message_type 	= 'error';

				$simplepay_message = array(
					'message'		=> $message,
					'message_type' 	=> $message_type
				);

				update_post_meta( $order_id, '_tbz_simplepay_message', $simplepay_message );

				die( 'IPN Processed OK' );
			}
		}

	}

	function tbz_wc_simplepay_success_message(){

		if( function_exists( 'is_order_received_page' )){

			$order_id 		= absint( get_query_var( 'order-received' ) );
			$order 			= new WC_Order( $order_id );
			$payment_method = $order->payment_method;

			if( is_order_received_page() &&  ( 'jcard_gateway' == $payment_method ) ){
				$simplepay_message 	= get_post_meta( $order_id, '_tbz_simplepay_message', true );

				if( isset( $simplepay_message ) && ! empty( $simplepay_message ) ){
					if( ! empty( $simplepay_message['message'] ) ){
						$message 		= $simplepay_message['message'];
					}
					if( ! empty( $simplepay_message['message_type'] ) ){
						$message_type 	= $simplepay_message['message_type'];
					}

					delete_post_meta( $order_id, '_tbz_simplepay_message' );

					if( ! wc_has_notice ($message, $message_type ) ){
						wc_add_notice( $message, $message_type );
					}
				}
			}
		}
	}
	add_action( 'wp', 'tbz_wc_simplepay_success_message' );

	/**
 	* Add SimplePay Gateway to WC
 	**/
	function tbz_wc_add_simplepay_gateway($methods) {
		$methods[] = 'WC_Jcard_Gateway';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'tbz_wc_add_simplepay_gateway' );


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
