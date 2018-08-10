<?php
//namespace PayWithCointopay;

/*
Plugin Name: Cointopay Gateway for Easy Digital Downloads
Description: Cointopay payment gateway for Easy Digital Downloads
Version: 1.0
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;
define("EDD_COINTOPAYGATEWAY_DIR", dirname(__FILE__));
//add_action('init', array('EDD_Cointopay_Payments', 'init'));

	/**
	 * PMProGateway_gatewayname Class
	 *
	 * Handles cointopay integration.
	 *
	 */
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

	if (!is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')) {
	
		add_action('admin_notices', 'cointopay_notice_edd');
	    deactivate_plugins( plugin_basename( __FILE__ ) );
		return;
	
	}
	function cointopay_notice_edd() {
	 echo '<div id="message" class="error fade"><p style="line-height: 150%">';
	
		_e('<strong>Cointopay Gateway for Easy Digital Downloads</strong></a> requires the Easy Digital Downloads plugin to be activated. Please <a href="https://wordpress.org/plugins/easy-digital-downloads/">install / activate Paid Memberships Pro</a> first.', 'EDDGateway_cointopay');
	
		echo '</p></div>';
	}
/**
 * Manual Gateway does not need a CC form, so remove it.
 *
 * @since 1.0
 * @return void
 */

require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/easy-digital-downloads.php");
require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/includes/payments/class-edd-payment.php");
final class EDD_Cointopay_Payments {

	private static $instance;
	public $gateway_id      = 'cointopay';
	public $client          = null;
	public $redirect_uri    = null;
	public $checkout_uri    = null;
	public $signin_redirect = null;
	public $reference_id    = null;
	public $doing_ipn       = false;
	public $is_setup        = null;

	/**
	 * Get things going
	 *
	 * @access private
	 * @since  2.4
	 * @return void
	 */
	private function __construct() {

		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			// The Cointopay Login & Pay libraries require PHP 5.3
			return;
		}

		$this->reference_id = ! empty( $_REQUEST['cointopay_reference_id'] ) ? sanitize_text_field( $_REQUEST['cointopay_reference_id'] ) : '';

		// Run this separate so we can ditch as early as possible
		$this->register();
        //echo $this->gateway_id;die();
		if ( ! edd_is_gateway_active( $this->gateway_id ) ) {
			return;
		}
		$this->config();
		$this->includes();
		//$this->setup_client();
		$this->filters();
		$this->actions();

	}
	/**
	 * Retrieve current instance
	 *
	 * @access private
	 * @since  2.4
	 * @return EDD_Cointopay_Payments instance
	 */
	public static function getInstance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof EDD_Cointopay_Payments ) ) {
			self::$instance = new EDD_Cointopay_Payments;
		}

		return self::$instance;

	}

	/**
	 * Register the payment gateway
	 *
	 * @access private
	 * @since  2.4
	 * @return void
	 */
	private function register() {
        
		add_filter( 'edd_payment_gateways', array( $this, 'register_cointopay_gateway' ), 1, 1 );

	}

	/**
	 * Setup constant configuration for file paths
	 *
	 * @access private
	 * @since  2.4
	 * @return void
	 */
	private function config() {

		if ( ! defined( 'EDD_COINTOPAY_CLASS_DIR' ) ) {
			$path = EDD_COINTOPAYGATEWAY_DIR . '/includes/cointopay';
			define( 'EDD_COINTOPAY_CLASS_DIR', trailingslashit( $path ) );
		}

	}

	/**
	 * Method to check if all the required settings have been filled out, allowing us to not output information without it.
	 *
	 * @since 2.7
	 * @return bool
	 */
	public function is_setup() {
		if ( null !== $this->is_setup ) {
			return $this->is_setup;
		}

		$required_items = array( 'merchant_id', 'secret_key' );

		$current_values = array(
			'merchant_id' => edd_get_option( 'cointopay_seller_id', '' ),
			'secret_key'  => edd_get_option( 'cointopay_SecurityCode', '' ),
		);

		$this->is_setup = true;

		foreach ( $required_items as $key ) {
			if ( empty( $current_values[ $key ] ) ) {
				$this->is_setup = false;
				break;
			}
		}

		return $this->is_setup;
	}

	/**
	 * Load additional files
	 *
	 * @access private
	 * @since  2.4
	 * @return void
	 */
	private function includes() {

		// Include the Cointopay Library
		require_once EDD_COINTOPAY_CLASS_DIR . 'init.php'; // Requires the other files itself
		require_once EDD_COINTOPAY_CLASS_DIR . 'version.php';

	}

	/**
	 * Add filters
	 *
	 * @since  2.4
	 * @return void
	 */
	private function filters() {

		add_filter( 'edd_accepted_payment_icons', array( $this, 'register_payment_icon' ), 10, 1 );
		add_filter( 'edd_show_gateways', array( $this, 'maybe_hide_gateway_select' ) );

		// Since the Cointopay Gateway loads scripts on page, it needs the scripts to load in the header.
		add_filter( 'edd_load_scripts_in_footer', '__return_false' );
		add_filter( 'edd_payment_confirm_cointopay', array( $this, 'edd_cointopay_success_page_content' ) );
		
		add_filter( 'edd_get_payment_transaction_id-cointopay', array( $this, 'edd_cointopay_get_payment_transaction_id' ), 10, 1 );
		//add_filter( 'edd_payment_details_transaction_id-cointopay', array( $this, 'edd_cointopay_link_transaction_id' ), 10, 2 );

		if ( is_admin() ) {
			
			add_filter( 'edd_settings_sections_gateways', array( $this, 'edd_register_cointopay_gateway_section' ), 1, 1 );
			add_filter( 'edd_settings_gateways', array( $this, 'register_cointopay_gateway_settings' ), 1, 1 );
			//add_filter( 'edd_payment_details_transaction_id-' . $this->gateway_id, array( $this, 'link_transaction_id' ), 10, 2 );
		}

	}

	/**
	 * Add actions
	 *
	 * @access private
	 * @since  2.4
	 * @return void
	 */
	private function actions() {

		add_action( 'wp_enqueue_scripts',                      array( $this, 'print_client' ), 10 );
		//add_action( 'wp_enqueue_scripts',                      array( $this, 'load_scripts' ), 11 );
		add_action( 'edd_pre_process_purchase',                array( $this, 'check_config' ), 1  );
		add_action( 'edd_cointopay_cc_form', '__return_false' );
		add_action( 'template_redirect',  array( $this, 'edd_cointopay_process_pdt_on_return' ) );
		add_action( 'edd_gateway_cointopay', array( $this, 'edd_process_cointopay_purchase' ) );

	}

	/**
	 * Show an error message on checkout if Cointopay is enabled but not setup.
	 *
	 * @since 2.7
	 */
	public function check_config() {
		$is_enabled = edd_is_gateway_active( $this->gateway_id );
		if ( ( ! $is_enabled || false === $this->is_setup() ) && 'cointopay' == edd_get_chosen_gateway() ) {
			edd_set_error( 'cointopay_gateway_not_configured', __( $is_enabled.'There is an error with the Cointopay Payments configuration.', 'EDDGateway-cointopay' ) );
		}
	}
	/**
	 * Register the gateway
	 *
	 * @since  2.4
	 * @param  $gateways array
	 * @return array
	 */
	public function register_cointopay_gateway( $gateways ) {
		
       
		$gateways[$this->gateway_id] = array(
				'admin_label'    => __( 'Cointopay', 'EDDGateway-cointopay' ),
				'checkout_label' => __( 'Cointopay', 'EDDGateway-cointopay' ),
				'supports'       => array( 'buy_now' )
			);

		return $gateways;

	}

	/**
	 * Register the payment icon
	 *
	 * @since  2.4
	 * @param  array $payment_icons Array of payment icons
	 * @return array                The array of icons with Cointopay Added
	 */
	public function register_payment_icon( $payment_icons ) {
		$payment_icons['cointopay'] = 'Cointopay';

		return $payment_icons;
	}

	/**
	 * Hides payment gateway select options after return from Cointopay
	 *
	 * @since  2.7.6
	 * @param  bool $show Should gateway select be shown
	 * @return bool
	 */
	public function maybe_hide_gateway_select( $show ) {

		if( ! empty( $_REQUEST['payment-mode'] ) && 'cointopay' == $_REQUEST['payment-mode'] && ! empty( $_REQUEST['cointopay_reference_id'] ) && ! empty( $_REQUEST['state'] ) && 'authorized' == $_REQUEST['state'] ) {

			$show = false;
		}

		return $show;
	}

	/**
	 * Register the payment gateways setting section
	 *
	 * @since  2.5
	 * @param  array $gateway_sections Array of sections for the gateways tab
	 * @return array                   Added Cointopay Payments into sub-sections
	 */
	public function edd_register_cointopay_gateway_section( $gateway_sections ) {
		$gateway_sections['cointopay'] = __( 'Cointopay Payments', 'EDDGateway-cointopay' );

		return $gateway_sections;
	}

	/**
	 * Register the gateway settings
	 *
	 * @since  2.4
	 * @param  $gateway_settings array
	 * @return array
	 */
	public function register_cointopay_gateway_settings( $gateway_settings ) {
//print_r($gateway_settings);
		$default_cointopay_settings = array(
			'cointopay' => array(
				'id'   => 'cointopay',
				'name' => '<strong>' . __( 'Cointopay Payments Settings', 'EDDGateway-cointopay' ) . '</strong>',
				'type' => 'header',
			),
			'cointopay_seller_id' => array(
				'id'   => 'cointopay_seller_id',
				'name' => __( 'Merchant ID', 'EDDGateway-cointopay' ),
				'desc' => __( 'Found in the Integration settings. Also called a Merchant ID', 'EDDGateway-cointopay' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'cointopay_mws_access_key' => array(
				'id'   => 'cointopay_SecurityCode',
				'name' => __( 'Security Code', 'EDDGateway-cointopay' ),
				'desc' => __( 'Found in the Integration settings', 'EDDGateway-cointopay' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'cointopay_mws_callback_url' => array(
				'id'       => 'cointopay_callback_url',
				'name'     => __( 'CointopayCallback URL', 'EDDGateway-cointopay' ),
				'desc'     => __( 'The Return URL to provide in your Application', 'EDDGateway-cointopay' ),
				'type'     => 'text',
				'size'     => 'large',
				'std'      => $this->get_cointopay_authenticate_redirect(),
				'faux'     => true,
			),
		);

		$default_cointopay_settings    = apply_filters( 'edd_default_cointopay_settings', $default_cointopay_settings );
		$gateway_settings['cointopay'] = $default_cointopay_settings;

		return $gateway_settings;

	}
/**
 * Get Cointopay Redirect
 *
 * @since 1.0.8.2
 * @param bool    $ssl_check Is SSL?
 * @param bool    $ipn       Is this an IPN verification check?
 * @return string
 */
function edd_get_cointopay_redirect( $ssl_check = false, $ipn = false ) {

	$paypal_uri = 'https://cointopay.com/MerchantAPI';

	

	return apply_filters( 'edd_cointopay_uri', $paypal_uri, $ssl_check, $ipn );
}
/**
 * Process Cointopay Purchase
 *
 * @since 1.0
 * @param array   $purchase_data Purchase Data
 * @return void
 */
function edd_process_cointopay_purchase( $purchase_data ) {
	if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
		wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
	}

	// Collect payment data
	$payment_data = array(
		'price'         => $purchase_data['price'],
		'date'          => $purchase_data['date'],
		'user_email'    => $purchase_data['user_email'],
		'purchase_key'  => $purchase_data['purchase_key'],
		'currency'      => edd_get_currency(),
		'downloads'     => $purchase_data['downloads'],
		'user_info'     => $purchase_data['user_info'],
		'cart_details'  => $purchase_data['cart_details'],
		'gateway'       => 'cointopay',
		'status'        => ! empty( $purchase_data['buy_now'] ) ? 'private' : 'pending'
	);

	// Record the pending payment
	$payment = edd_insert_payment( $payment_data );

	// Check payment
	if ( ! $payment ) {
		// Record the error
		edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed before sending buyer to Cointopay. Payment data: %s', 'easy-digital-downloads' ), json_encode( $payment_data ) ), $payment );
		// Problems? send back
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	} else {
		// Only send to Cointopay if the pending payment is created successfully
		//$listener_url = add_query_arg( 'edd-listener', 'IPN', home_url( 'index.php' ) );

		// Set the session data to recover this payment in the event of abandonment or error.
		EDD()->session->set( 'edd_resume_payment', $payment );

		// Get the success url
		$return_url = add_query_arg( array(
				'payment-confirmation' => 'cointopay',
				'payment-id' => $payment
			), get_permalink( edd_get_option( 'success_page', false ) ) );
		// Get the Cointopay redirect uri
		$cointopay_redirect = $this->edd_get_cointopay_redirect() . "?";
       
		// Setup Cointopay arguments
		$cointopay_args = array(
		     'Checkout'    => 'true',
			'SecurityCode'         => edd_get_option( 'cointopay_SecurityCode', false ),
			'MerchantID'         => edd_get_option( 'cointopay_seller_id', false ),
			'output'     => 'json',
			'inputCurrency' => edd_get_currency(),
			'AltCoinID' => 1,
			'CustomerReferenceNr'        => $payment,
			'returnurl'        => $return_url,
			'transactionconfirmurl' => $return_url,
			'transactionfailurl' => edd_get_failed_transaction_uri( '?payment-id=' . $payment ),
		);

		/*if ( ! empty( $purchase_data['user_info']['address'] ) ) {
			$paypal_args['address1'] = $purchase_data['user_info']['address']['line1'];
			$paypal_args['address2'] = $purchase_data['user_info']['address']['line2'];
			$paypal_args['city']     = $purchase_data['user_info']['address']['city'];
			$paypal_args['country']  = $purchase_data['user_info']['address']['country'];
		}
*/

		//$paypal_args = array_merge( $paypal_extra_args, $paypal_args );

		// Add cart items
		$i = 1;
		$paypal_sum = 0;
		if( is_array( $purchase_data['cart_details'] ) && ! empty( $purchase_data['cart_details'] ) ) {
			foreach ( $purchase_data['cart_details'] as $item ) {

				$item_amount = round( ( $item['subtotal'] / $item['quantity'] ) - ( $item['discount'] / $item['quantity'] ), 2 );

				if( $item_amount <= 0 ) {
					$item_amount = 0;
				}

				//$paypal_args['item_name_' . $i ] = stripslashes_deep( html_entity_decode( edd_get_cart_item_name( $item ), ENT_COMPAT, 'UTF-8' ) );
				//$paypal_args['quantity_' . $i ]  = $item['quantity'];
				$cointopay_args['Amount']    = $item_amount;

				/*if ( edd_use_skus() ) {
					$paypal_args['item_number_' . $i ] = edd_get_download_sku( $item['id'] );
				}*/

				$paypal_sum += ( $item_amount * $item['quantity'] );

				$i++;

			}
		}

		// Calculate discount
		$discounted_amount = 0.00;
		if ( ! empty( $purchase_data['fees'] ) ) {
			$i = empty( $i ) ? 1 : $i;
			foreach ( $purchase_data['fees'] as $fee ) {
				if ( empty( $fee['download_id'] ) && floatval( $fee['amount'] ) > '0' ) {
					// this is a positive fee
					//$cointopay_args['item_name_' . $i ] = stripslashes_deep( html_entity_decode( wp_strip_all_tags( $fee['label'] ), ENT_COMPAT, 'UTF-8' ) );
					//$cointopay_args['quantity_' . $i ]  = '1';
					$cointopay_args['Amount']    = edd_sanitize_amount( $fee['amount'] );
					$i++;
				} else if ( empty( $fee['download_id'] ) ) {

					// This is a negative fee (discount) not assigned to a specific Download
					$discounted_amount += abs( $fee['amount'] );
				}
			}
		}

		if ( $discounted_amount > '0' ) {
			//$cointopay_args['discount_amount_cart'] = edd_sanitize_amount( $discounted_amount );
		}

		if( $paypal_sum > $purchase_data['price'] ) {
			$difference = round( $paypal_sum - $purchase_data['price'], 2 );
			if( ! isset( $cointopay_args['Amount'] ) ) {
				$cointopay_args['Amount'] = 0;
			}
			$cointopay_args['Amount'] += $difference;
		}

		// Add taxes to the cart
		/*if ( edd_use_taxes() ) {

			$cointopay_args['tax_cart'] = edd_sanitize_amount( $purchase_data['tax'] );

		}*/

		$cointopay_args = apply_filters( 'edd_cointopay_redirect_args', $cointopay_args, $purchase_data );

		edd_debug_log( 'Cointopay arguments: ' . print_r( $cointopay_args, true ) );

		// Build query
		$cointopay_redirect .= http_build_query( $cointopay_args );

		// Fix for some sites that encode the entities
		$cointopay_redirect = str_replace( '&amp;', '&', $cointopay_redirect );
		//echo $cointopay_redirect;die();
        $result = $this->c2pCurl($cointopay_redirect, 'CBK7HZAG_SEUC7XJUAE9_MRGCDO3KWAQHOG15GD5LWI');
	      //  echo $result['url'];die();	
			/*if(!empty($result['url']))
			{
                header("location:".$result['url']." ");
                exit('not redirect');
			}*/
	if (is_string($result)){
		echo $result;exit();
		
	}
		// Redirect to Cointopay
		wp_redirect( $result['url'] );
		exit;
	}

}
function c2pCurl($url, $apiKey, $post = false) {
	global $c2pOptions;	
		//print_r($url);die();
	$curl = curl_init($url);
	$length = 0;
	if ($post)
	{	
		//curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
		$length = strlen($post);
	}
	
	$uname = base64_encode($apiKey);
	$header = array(
		"authentication:1",
				'cache-control: no-cache',
		);

	//curl_setopt($curl, CURLOPT_PORT, 443);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
	//curl_setopt($curl, CURLOPT_TIMEOUT, 20);
	//curl_setopt($curl, CURLOPT_VERBOSE, true);
	curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // verify certificate
	//curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // check existence of CN and verify that it matches hostname
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	//curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
	//curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
		
	$responseString = curl_exec($curl);
	
	if($responseString == false) {
		echo $response = curl_error($curl);
		exit();
	} else {
		//$response = $responseString;//json_decode($responseString, true);
		
		$results = json_decode($responseString);
		
					$response =  array(
					'result' => 'success',
					'url' => $results->RedirectURL
					);
	}
	curl_close($curl);
	return $response;
}
/**
 * Shows "Purchase Processing" message for Cointopay payments are still pending on site return.
 *
 * This helps address the Race Condition, as detailed in issue #1839
 *
 * @since 1.9
 * @return string
 */
function edd_cointopay_success_page_content( $content ) {
//echo "hiiiiiiiiiiiii";$_REQUEST['payment-id'];die();
	if ( ! isset( $_GET['payment-id'] ) && ! edd_get_purchase_session() ) {
		return $content;
	}

	edd_empty_cart();

	$payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

	if ( ! $payment_id ) {
		$session    = edd_get_purchase_session();
		$payment_id = edd_get_purchase_id_by_key( $session['purchase_key'] );
	}

	$payment = new EDD_Payment( $payment_id );
	if( isset($_GET['ConfirmCode']) && $payment->ID > 0 ) {
    $data = [ 
			   'mid' => edd_get_option( 'cointopay_seller_id', false ) , 
			   'TransactionID' => $_REQUEST['TransactionID'] ,
			   'ConfirmCode' => $_REQUEST['ConfirmCode']
		  ];
        $response = $this->validateOrder($data);
        if($response->Status !== $_REQUEST['status'])
         {
			// Payment is still pending so show processing indicator to fix the Race Condition, issue #
			ob_start();
	
			echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'Your purchase is processing. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
	
			$content = ob_get_clean();
				  
		 }
		else if($response->CustomerReferenceNr == $_REQUEST['CustomerReferenceNr']){
			// Purchase verified, set to completed
				if ($_REQUEST['status'] == 'paid' && $_REQUEST['notenough'] == 0) {
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'Your purchase is completed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();
				}
				if ($_REQUEST['status'] == 'paid' && $_REQUEST['notenough'] == 1) {
				// Payment is still pending so show processing indicator to fix the Race Condition, issue #
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'IPN: Payment failed from Cointopay because notenough. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();
				}
				if ($_REQUEST['status'] == 'failed' && $_REQUEST['notenough'] == 0) {
				// Payment is still pending so show processing indicator to fix the Race Condition, issue #
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'Your purchase is failed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();
				}
				if ($_REQUEST['status'] == 'failed' && $_REQUEST['notenough'] == 1) {
				// Payment is still pending so show processing indicator to fix the Race Condition, issue #
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'Your purchase is failed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();
				}

			

		} 
		 else if($response == 'not found')
              {
				// Payment is still pending so show processing indicator to fix the Race Condition, issue #
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'We have detected different order status. Your order has been halted. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();
			  }
         else {

				// Payment is still pending so show processing indicator to fix the Race Condition, issue #
				ob_start();
		
				echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'We have detected different order status. Your order has been halted. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
		
				$content = ob_get_clean();

		}
	
	}
	else{
		// Payment is still pending so show processing indicator to fix the Race Condition, issue #
		ob_start();

		echo '<div id="edd-payment-processing">
	<p>'.printf( __( 'Your purchase is processing. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads' ), edd_get_success_page_uri() );
	echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';

		$content = ob_get_clean();
	}
	return $content;

}

/**
 * Mark payment as complete on return from Cointopay if a Cointopay Identity Token is present.
 *
 * See https://github.com/easydigitaldownloads/easy-digital-downloads/issues/6197
 *
 * @since 2.8.13
 * @return void
 */
function edd_cointopay_process_pdt_on_return() {
//echo "hello".$_REQUEST['payment-id'];die();
	if ( ! isset( $_GET['payment-id'] ) ) {
		return;
	}

	//$token = edd_get_option( 'paypal_identity_token' );

	if( ! edd_is_success_page() || ! edd_is_gateway_active( 'cointopay' ) ) {
		return;
	}

	$payment_id = isset( $_GET['payment-id'] ) ? absint( $_GET['payment-id'] ) : false;

	if( empty( $payment_id ) ) {
		return;
	}
//echo "hello".$_REQUEST['payment-id'];die();
	$payment = new EDD_Payment( $payment_id );
	if( isset($_GET['ConfirmCode']) && $payment->ID > 0 ) {
    $data = [ 
			   'mid' => edd_get_option( 'cointopay_seller_id', false ) , 
			   'TransactionID' => $_REQUEST['TransactionID'] ,
			   'ConfirmCode' => $_REQUEST['ConfirmCode']
		  ];
        $response = $this->validateOrder($data);
        if($response->Status !== $_REQUEST['status'])
         {
			 
			edd_debug_log( 'Attempt to verify Cointopay payment with PDF is in processing.' );
			$payment->status = 'pending';
			$payment->save();
				  
		 }
		else if($response->CustomerReferenceNr == $_REQUEST['CustomerReferenceNr']){
				// Purchase verified, set to completed
				if ($_REQUEST['status'] == 'paid' && $_REQUEST['notenough'] == 0) {
				$payment->status = 'publish';
				$payment->transaction_id = sanitize_text_field( $_REQUEST['TransactionID'] );
				$payment->save();
				}
				if ($_REQUEST['status'] == 'paid' && $_REQUEST['notenough'] == 1) {
				edd_debug_log( 'IPN: Payment failed from Cointopay because notenough' );
				$payment->status = 'failed';
				$payment->save();
				}
				if ($_REQUEST['status'] == 'failed' && $_REQUEST['notenough'] == 0) {
				edd_debug_log( 'Payment failed from Cointopay.' . print_r( $request, true ) );
				$payment->status = 'failed';
				$payment->save();
				}
				if ($_REQUEST['status'] == 'failed' && $_REQUEST['notenough'] == 1) {
				edd_debug_log( 'Payment failed from Cointopay.' );
				$payment->status = 'failed';
				$payment->save();
				}

		} 
		 else if($response == 'not found')
              {
				edd_debug_log( 'Attempt to verify Cointopay payment with PDF failed.' );
				$payment->status = 'failed';
				$payment->save();
			  }
         else {

			     edd_debug_log( 'Attempt to verify Cointopay payment with PDF failed.' );
				$payment->status = 'failed';
				$payment->save();

		}
	
	}
	else{
		$payment->status = 'pending';
		$payment->save();
	
	}


}
function  validateOrder($data)
   {
       //$this->pp($data);
       //https://cointopay.com/v2REAPI?MerchantID=14351&Call=QA&APIKey=_&output=json&TransactionID=230196&ConfirmCode=YGBMWCNW0QSJVSPQBCHWEMV7BGBOUIDQCXGUAXK6PUA
       $params = array(
       "authentication:1",
       'cache-control: no-cache',
       );
        $ch = curl_init();
        curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
        //CURLOPT_USERPWD => $this->apikey,
        CURLOPT_POSTFIELDS => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $params,
        CURLOPT_USERAGENT => 1,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC
        )
        );
        $response = curl_exec($ch);
        $results = json_decode($response);
        if($results->CustomerReferenceNr)
        {
            return $results;
        }
        edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'We have detected different order status. Your order has not been found.', 'easy-digital-downloads' ) ) );
		// If errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=cointopay' );
   }

/**
 * Given a Payment ID, extract the transaction ID
 *
 * @since  2.1
 * @param  string $payment_id       Payment ID
 * @return string                   Transaction ID
 */
function edd_cointopay_get_payment_transaction_id( $payment_id ) {

	$transaction_id = '';
	$notes = edd_get_payment_notes( $payment_id );

	foreach ( $notes as $note ) {
		if ( preg_match( '/^Cointopay Transaction ID: ([^\s]+)/', $note->comment_content, $match ) ) {
			$transaction_id = $match[1];
			continue;
		}
	}

	return apply_filters( 'edd_cointopay_set_payment_transaction_id', $transaction_id, $payment_id );
}


/**
 * Given a transaction ID, generate a link to the Cointopay transaction ID details
 *
 * @since  2.2
 * @param  string $transaction_id The Transaction ID
 * @param  int    $payment_id     The payment ID for this transaction
 * @return string                 A link to the Cointopay transaction details
 */
function edd_cointopay_link_transaction_id( $transaction_id, $payment_id ) {

	$payment = new EDD_Payment( $payment_id );
	//$sandbox = 'test' == $payment->mode ? 'sandbox.' : '';
	$paypal_base_url = 'https://app.cointopay.com/MerchantAPI?Checkout=true';
	$transaction_url = '<a href="' . esc_url( $paypal_base_url . $transaction_id ) . '" target="_blank">' . $transaction_id . '</a>';

	return apply_filters( 'edd_cointopay_link_payment_details_transaction_id', $transaction_url );

}

	/**
	 * Capture authentication after returning from Cointopay
	 *
	 * @since  2.4
	 * @return void
	 */
	public function capture_oauth() {

		if ( ! isset( $_GET['edd-listener'] ) || $_GET['edd-listener'] !== 'cointopay' ) {
			return;
		}

		if ( ! isset( $_GET['state'] ) || $_GET['state'] !== 'return_auth' ) {
			return;
		}

		if( empty( $_GET['access_token'] ) || false === strpos( $_GET['access_token'], 'Atza' ) ) {
			return;
		}

		try {

			$profile = $this->client->getUserInfo( $_GET['access_token'] );

			EDD()->session->set( 'cointopay_access_token', $_GET['access_token'] );
			EDD()->session->set( 'cointopay_profile', $profile );

		} catch( Exception $e ) {

			wp_die( print_r( $e, true ) );

		}

	}


	/**
	 * Retrieve the checkout URL for Cointopay after authentication is complete
	 *
	 * @since  2.4
	 * @return string
	 */
	private function get_cointopay_checkout_uri() {

		if ( is_null( $this->checkout_uri ) ) {
			$this->checkout_uri = esc_url_raw( add_query_arg( array( 'payment-mode' => 'cointopay' ), edd_get_checkout_uri() ) );
		}

		return $this->checkout_uri;

	}

	/**
	 * Retrieve the return URL for Cointopay after authentication on Cointopay is complete
	 *
	 * @since  2.4
	 * @return string
	 */
	private function get_cointopay_authenticate_redirect() {

		if ( is_null( $this->redirect_uri ) ) {
			$this->redirect_uri = esc_url_raw( add_query_arg( array( 'edd-listener' => 'cointopay', 'state' => 'return_auth' ), edd_get_checkout_uri() ) );
		}

		return $this->redirect_uri;

	}

	/**
	 * Retrieve the URL to send customers too once sign-in is complete
	 *
	 * @since  2.4
	 * @return string
	 */
	private function get_cointopay_signin_redirect() {

		if ( is_null( $this->signin_redirect ) ) {
			$this->signin_redirect = esc_url_raw( add_query_arg( array( 'edd-listener' => 'cointopay', 'state' => 'signed-in' ), home_url() ) );
		}

		return $this->signin_redirect;

	}

	/**
	 * Retrieve the IPN URL for Cointopay
	 *
	 * @since  2.4
	 * @return string
	 */
	private function get_cointopay_ipn_url() {

		return esc_url_raw( add_query_arg( array( 'edd-listener' => 'cointopay' ), home_url( 'index.php' ) ) );

	}

	/**
	 * Removes the requirement for entering the billing address
	 *
	 * Address is pulled directly from Cointopay
	 *
	 * @since  2.4
	 * @return void
	 */
	public function disable_address_requirement() {

		if( ! empty( $_POST['edd-gateway'] ) && $this->gateway_id == $_REQUEST['edd-gateway'] ) {
			add_filter( 'edd_require_billing_address', '__return_false', 9999 );
		}

	}


}

/**
 * Load EDD_Cointopay_Payments
 *
 * @since  2.4
 * @return object EDD_Cointopay_Payments
 */
function EDD_Cointopay() {
	return EDD_Cointopay_Payments::getInstance();
}
EDD_Cointopay();
