<?php
/**
 * Plugin Name: Cointopay Gateway for Easy Digital Downloads
 * Description: Cointopay payment gateway for Easy Digital Downloads
 * Author: Cointopay.com
 * Version: 1.5
 * Author URI: https://cointopay.com/
 * Plugin URI: https://cointopay.com/
 *
 * @author   Cointopay <info@cointopay.com>
 * @license  GNU General Public License <http://www.gnu.org/licenses/>
 * @link     cointopay.com
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

define("EDD_COINTOPAYGATEWAY_DIR", dirname(__FILE__));

/**
 * EDD_Cointopay_Payments Class
 *
 * Handles cointopay integration.
 */
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if (!is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')) {
    add_action('admin_notices', 'cointopayNoticeEdd');
    deactivate_plugins(plugin_basename(__FILE__));
    return;
}
    
/**
 * Add the gateways notice
 *
 * @return string
 */
function cointopayNoticeEdd()
{
    echo '<div id="message" class="error fade"><p style="line-height: 150%">';

    _e('<strong>Cointopay Gateway for Easy Digital Downloads</strong></a> requires the Easy Digital Downloads plugin to be activated. Please <a href="https://wordpress.org/plugins/easy-digital-downloads/">install / activate Easy Digital Downloads</a> first.', 'EDDGateway_cointopay');

    echo '</p></div>';
}
    
require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/easy-digital-downloads.php");
require_once(ABSPATH . "wp-content/plugins/easy-digital-downloads/includes/payments/class-edd-payment.php");

/**
 * Define Cointopay Class
 */
final class EDD_Cointopay_Payments
{
    private static $_instance;
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
    private function __construct()
    {
        if (version_compare(phpversion(), 5.3, '<')) {
            // The Cointopay Login & Pay libraries require PHP 5.3
            return;
        }
        add_filter('edd_payment_confirm_cointopay', array( $this, 'eddCointopaysuccessPagecontent' ));
        if (isset($_REQUEST['cointopay_reference_id'])) {
            $cointopay_reference_id = '';
            $cointopay_reference_id =  sanitize_text_field($_REQUEST['cointopay_reference_id']);
      
            $this->reference_id = ! empty($_REQUEST['cointopay_reference_id']) ? $_REQUEST['cointopay_reference_id'] : '';
        }

        // Run this separate so we can ditch as early as possible
        $this->_register();
        if (! edd_is_gateway_active($this->gateway_id)) {
            return;
        }
        $this->_config();
        $this->_includes();
        $this->_filters();
        $this->_actions();
    }
    /**
     * Retrieve current instance
     *
     * @access private
     * @since  2.4
     * @return EDD_Cointopay_Payments instance
     */
    public static function getInstance()
    {
        if (! isset(self::$_instance) && ! (self::$_instance instanceof EDD_Cointopay_Payments)) {
            self::$_instance = new EDD_Cointopay_Payments;
        }

        return self::$_instance;
    }

    /**
     * Register the payment gateway
     *
     * @access private
     * @since  2.4
     * @return void
     */
    private function _register()
    {
        add_filter('edd_payment_gateways', array( $this, 'registerCointopaygateway' ), 1, 1);
    }

    /**
     * Setup constant configuration for file paths
     *
     * @access private
     * @since  2.4
     * @return void
     */
    private function _config()
    {
        if (! defined('EDD_COINTOPAY_CLASS_DIR')) {
            $path = EDD_COINTOPAYGATEWAY_DIR . '/includes/cointopay';
            define('EDD_COINTOPAY_CLASS_DIR', trailingslashit($path));
        }
    }

    /**
     * Method to check if all the required settings have been filled out, allowing us to not output information without it.
     *
     * @since  2.7
     * @return bool
     */
    public function isSetup()
    {
        if (null !== $this->is_setup) {
            return $this->is_setup;
        }

        $required_items = array( 'merchant_id', 'secret_key' );

        $current_values = array(
            'merchant_id' => edd_get_option('cointopay_seller_id', ''),
            'secret_key'  => edd_get_option('cointopay_SecurityCode', ''),
        );

        $this->is_setup = true;

        foreach ($required_items as $key) {
            if (empty($current_values[ $key ])) {
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
    private function _includes()
    {

        // Include the Cointopay Library
        include_once EDD_COINTOPAY_CLASS_DIR . 'init.php'; // Requires the other files itself
        include_once EDD_COINTOPAY_CLASS_DIR . 'version.php';
    }

    /**
     * Add filters
     *
     * @since  2.4
     * @return void
     */
    private function _filters()
    {
        //add_filter('edd_accepted_payment_icons', array( $this, 'registerPaymenticon' ), 10, 1);
        add_filter('edd_show_gateways', array( $this, 'maybeHidegatewaySelect' ));

        // Since the Cointopay Gateway loads scripts on page, it needs the scripts to load in the header.
        add_filter('edd_load_scripts_in_footer', '__return_false');
        
        
        add_filter('edd_get_payment_transaction_id-cointopay', array( $this, 'eddCointopaygetPaymenttransactionId' ), 10, 1);

        if (is_admin()) {
            add_filter('edd_settings_sections_gateways', array( $this, 'eddRegistercointopayGatewaysection' ), 1, 1);
            add_filter('edd_settings_gateways', array( $this, 'registerCointopaygatewaySettings' ), 1, 1);
        }
    }

    /**
     * Add actions
     *
     * @access private
     * @since  2.4
     * @return void
     */
    private function _actions()
    {
        add_action('edd_cointopay_cc_form', '__return_false');
        add_action('template_redirect', array( $this, 'eddCointopayprocessPdtonReturn' ));
        add_action('edd_gateway_cointopay', array( $this, 'eddProcesscointopayPurchase' ));
    }

    /**
     * Show an error message on checkout if Cointopay is enabled but not setup.
     *
     * @since  2.7
     * @return string
     */
    public function checkConfig()
    {
        $is_enabled = edd_is_gateway_active($this->gateway_id);
        if ((! $is_enabled || false === $this->isSetup()) && 'cointopay' == edd_get_chosen_gateway()) {
            edd_set_error('cointopay_gateway_not_configured', __($is_enabled.'There is an error with the Cointopay Payments configuration.', 'EDDGateway-cointopay'));
        }
    }
    /**
     * Register the gateway
     *
     * @param array $gateways $args {
     *                        Optional. An array of arguments.
     *
     * @type   type $key Description. Default 'value'. Accepts 'value', 'value'.
     *    (aligned with Description, if wraps to a new line)
     * @type   type $key Description.
     * }
     * @return array
     * @since  2.4
     */
    public function registerCointopaygateway($gateways)
    {
        $gateways[$this->gateway_id] = array(
                'admin_label'    => __('Cointopay', 'EDDGateway-cointopay'),
                'checkout_label' => __('Cointopay', 'EDDGateway-cointopay'),
                'supports'       => array( 'buy_now' )
            );

        return $gateways;
    }

    /**
     * Register the payment icon
     *
     * @param array $payment_icons $args {
     *                             Optional. An array of arguments.
     *
     * @type   type $key Description. Default 'value'. Accepts 'value', 'value'.
     *    (aligned with Description, if wraps to a new line)
     * @type   type $key Description.
     * }
     * @return array
     */
    public function registerPaymenticon($payment_icons)
    {
        $payment_icons['cointopay'] = 'Cointopay';
		return $payment_icons;
    }

    /**
     * Hides payment gateway select options after return from Cointopay
     *
     * @param bool $show Should gateway select be shown
     *
     * @return void
     */
    public function maybeHidegatewaySelect($show)
    {
        if (! empty( $_REQUEST['payment-mode'] ) && 'cointopay' == $_REQUEST['payment-mode'] && ! empty( $_REQUEST['cointopay_reference_id'] ) && ! empty( $_REQUEST['state'] ) && 'authorized' == $_REQUEST['state']) {
            $show = false;
        }

        return $show;
    }

    /**
     * Register the payment gateways setting section
     *
     * @param array $gateway_sections $args {
     *                                Optional. An array of arguments.
     *
     * @type   type $key Description. Default 'value'. Accepts 'value', 'value'.
     *    (aligned with Description, if wraps to a new line)
     * @type   type $key Description.
     * }
     * @return array
     */
    public function eddRegistercointopayGatewaysection($gateway_sections)
    {
        $gateway_sections['cointopay'] = __('Cointopay Payments', 'EDDGateway-cointopay');

        return $gateway_sections;
    }

    /**
     * Register the gateway settings
     *
     * @param array $gateway_settings $args {
     *                                Optional. An array of arguments.
     *
     * @type   type $key Description. Default 'value'. Accepts 'value', 'value'.
     *    (aligned with Description, if wraps to a new line)
     * @type   type $key Description.
     * }
     * @return array
     */
    public function registerCointopaygatewaySettings($gateway_settings)
    {
        $default_cointopay_settings = array(
            'cointopay' => array(
                'id'   => 'cointopay',
                'name' => '<strong>' . __('Cointopay Payments Settings', 'EDDGateway-cointopay') . '</strong>',
                'type' => 'header',
            ),
            'cointopay_seller_id' => array(
                'id'   => 'cointopay_seller_id',
                'name' => __('Merchant ID', 'EDDGateway-cointopay'),
                'desc' => __('Found in the Integration settings. Also called a Merchant ID', 'EDDGateway-cointopay'),
                'type' => 'text',
                'size' => 'regular',
            ),
            'cointopay_mws_access_key' => array(
                'id'   => 'cointopay_SecurityCode',
                'name' => __('Security Code', 'EDDGateway-cointopay'),
                'desc' => __('Found in the Integration settings', 'EDDGateway-cointopay'),
                'type' => 'text',
                'size' => 'regular',
            ),
            'cointopay_mws_callback_url' => array(
                'id'       => 'cointopay_callback_url',
                'name'     => __('CointopayCallback URL', 'EDDGateway-cointopay'),
                'desc'     => __('The Return URL to provide in your Application', 'EDDGateway-cointopay'),
                'type'     => 'text',
                'size'     => 'large',
                'std'      => $this->_getCointopayauthenticateRedirect(),
                'faux'     => true,
            ),
        );

        $default_cointopay_settings    = apply_filters('edd_default_cointopay_settings', $default_cointopay_settings);
        $gateway_settings['cointopay'] = $default_cointopay_settings;

        return $gateway_settings;
    }
    
    /**
     * Process Cointopay Purchase
     *
     * @param array $purchase_data Purchase Data
     *
     * @return void
     */
    public function eddProcesscointopayPurchase($purchase_data)
    {
        if (! wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
            wp_die(__('Nonce verification has failed', 'easy-digital-downloads'), __('Error', 'easy-digital-downloads'), array( 'response' => 403 ));
        }
        $cointopay_amount = 0;

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
        'status'        => ! empty($purchase_data['buy_now']) ? 'private' : 'pending'
        );

        // Record the pending payment
        $payment = edd_insert_payment($payment_data);

        // Check payment
        if (! $payment) {
        
            // Record the error
            edd_record_gateway_error(__('Payment Error', 'easy-digital-downloads'), sprintf(__('Payment creation failed before sending buyer to Cointopay. Payment data: %s', 'easy-digital-downloads'), json_encode($payment_data)), $payment);
            // Problems? send back
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        } else {

            // Set the session data to recover this payment in the event of abandonment or error.
            EDD()->session->set('edd_resume_payment', $payment);

            // Get the success url
            $return_url = add_query_arg(
                array(
                'payment-confirmation' => 'cointopay',
                'payment-id' => $payment
                ),
                get_permalink(edd_get_option('success_page', false))
            );
        
            // Add cart items
            $i = 1;
            $cointopay_sum = 0;
            if (is_array($purchase_data['cart_details']) && ! empty($purchase_data['cart_details'])) {
                foreach ($purchase_data['cart_details'] as $item) {
                    $item_amount = round(($item['subtotal'] / $item['quantity']) - ($item['discount'] / $item['quantity']), 2);

                    if ($item_amount <= 0) {
                        $item_amount = 0;
                    }

                    $cointopay_args['Amount']    = $item_amount;
                    $cointopay_amount    = $item_amount;

                    $cointopay_sum += ($item_amount * $item['quantity']);

                    $i++;
                }
            }

            // Calculate discount
            $discounted_amount = 0.00;
            if (! empty($purchase_data['fees'])) {
                $i = empty($i) ? 1 : $i;
                foreach ($purchase_data['fees'] as $fee) {
                    if (empty($fee['download_id']) && floatval($fee['amount']) > '0') {
                        // this is a positive fee
                        $cointopay_args['Amount']    = edd_sanitize_amount($fee['amount']);
                        $cointopay_amount    = edd_sanitize_amount($fee['amount']);
                        $i++;
                    } elseif (empty($fee['download_id'])) {

                        // This is a negative fee (discount) not assigned to a specific Download
                        $discounted_amount += abs($fee['amount']);
                    }
                }
            }

            if ($discounted_amount > '0') {
                //$cointopay_args['discount_amount_cart'] = edd_sanitize_amount( $discounted_amount );
            }

            if ($cointopay_sum > $purchase_data['price']) {
                $difference = round($cointopay_sum - $purchase_data['price'], 2);
                if (! isset($cointopay_amount)) {
                    $cointopay_amount = 0;
                }
                $cointopay_args['Amount'] += $difference;
                $cointopay_amount += $difference;
            }

            // Fix for some sites that encode the entities
            $params = array(
            'body' => 'SecurityCode=' . edd_get_option('cointopay_SecurityCode', false) . '&MerchantID=' . edd_get_option('cointopay_seller_id', false) . '&Amount=' . $cointopay_sum . '&AltCoinID=1&output=json&inputCurrency=' . edd_get_currency() . '&CustomerReferenceNr=' . $payment . '&returnurl='.rawurlencode(esc_url($return_url)).'&transactionconfirmurl='.rawurlencode(esc_url($return_url)).'&transactionfailurl='.rawurlencode(esc_url(edd_get_failed_transaction_uri('?payment-id=' . $payment))),
            'authentication' => 1,
            'cache-control' => 'no-cache',
            );
            $url = 'https://app.cointopay.com/MerchantAPI?Checkout=true';
            $response = wp_safe_remote_post($url, $params);
           //  echo "<pre>";print_r($response);die();
            if (!is_wp_error($response) && 200 == $response['response']['code'] && 'OK' == $response['response']['message']) {
                $results = json_decode($response['body']);
                // Redirect to Cointopay
                wp_redirect($results->RedirectURL);
                exit;
            } else {
                // Record the error
                edd_record_gateway_error(__('Payment Error', 'easy-digital-downloads'), sprintf(__('Payment creation failed before sending buyer to Cointopay. Payment data: %s', 'easy-digital-downloads'), json_encode($payment_data)), $payment);
                // Problems? send back
                edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
            }
        }
    }
    
    /**
     * Shows "Purchase Processing" message for Cointopay payments are still pending on site return.
     *
     * This helps address the Race Condition, as detailed in issue #1839
     *
     * @param string $content Contents
     *
     * @return string
     */
    public function eddCointopaysuccessPagecontent($content)
    {
        $paymentID = intval($_GET['payment-id']);
        if (! isset($paymentID) && ! edd_get_purchase_session()) {
            return $content;
        }

        edd_empty_cart();

        $payment_id = isset($paymentID) ? absint($paymentID) : false;

        if (! $payment_id) {
            $session    = edd_get_purchase_session();
            $payment_id = edd_get_purchase_id_by_key($session['purchase_key']);
        }

        $payment = new EDD_Payment($payment_id);
        $ConfirmCode = sanitize_text_field($_GET['ConfirmCode']);
        if (isset($ConfirmCode) && $payment->ID > 0) {
            $data = [
               'mid' => edd_get_option('cointopay_seller_id', false) ,
               'TransactionID' => sanitize_text_field($_REQUEST['TransactionID']) ,
               'ConfirmCode' => sanitize_text_field($_REQUEST['ConfirmCode'])
            ];
            $response = $this->validateOrder($data);
            $o_status = sanitize_text_field($_REQUEST['status']);
            $notenough = sanitize_text_field($_REQUEST['notenough']);
            if ($response->Status !== $o_status) {
                // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                ob_start();
    
                echo '<div id="edd-payment-processing">
	<p>'.printf(__('Your purchase is processing. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
    
                $content = ob_get_clean();
            } elseif ($response->CustomerReferenceNr == intval($_REQUEST['CustomerReferenceNr'])) {
                // Purchase verified, set to completed
                if ($o_status == 'paid' && $notenough == 0) {
                    ob_start();
        
                    echo '<div id="edd-payment-processing">
	<p>'.printf(__('Your purchase is completed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                    echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                    $content = ob_get_clean();
                }
                if ($o_status == 'paid' && $notenough == 1) {
                    // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                    ob_start();
        
                    echo '<div id="edd-payment-processing">
	<p>'.printf(__('IPN: Payment failed from Cointopay because notenough. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                    echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                    $content = ob_get_clean();
                }
                if ($o_status == 'failed' && $notenough == 0) {
                    // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                    ob_start();
        
                    echo '<div id="edd-payment-processing">
	<p>'.printf(__('Your purchase is failed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                    echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                    $content = ob_get_clean();
                }
                if ($o_status == 'failed' && $notenough == 1) {
                    // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                    ob_start();
        
                    echo '<div id="edd-payment-processing">
	<p>'.printf(__('Your purchase is failed. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                    echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                    $content = ob_get_clean();
                }
            } elseif ($response == 'not found') {
                // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                ob_start();
        
                echo '<div id="edd-payment-processing">
	<p>'.printf(__('We have detected different order status. Your order has been halted. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                $content = ob_get_clean();
            } else {

                // Payment is still pending so show processing indicator to fix the Race Condition, issue #
                ob_start();
        
                echo '<div id="edd-payment-processing">
	<p>'.printf(__('We have detected different order status. Your order has been halted. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
                echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';
        
                $content = ob_get_clean();
            }
        } else {
            // Payment is still pending so show processing indicator to fix the Race Condition, issue #
            ob_start();

            echo '<div id="edd-payment-processing">
	<p>'.printf(__('Your purchase is processing. This page will reload automatically in 8 seconds. If it does not, click <a href="%s">here</a>.', 'easy-digital-downloads'), edd_get_success_page_uri());
            echo '<span class="edd-cart-ajax"><i class="edd-icon-spinner edd-icon-spin"></i></span>
	<script type="text/javascript">setTimeout(function(){ window.location = "'.edd_get_success_page_uri().'" }, 8000);</script>
</div>';

            $content = ob_get_clean();
        }
        return $content;
    }

    /**
     * Mark payment as complete on return from Cointopay.
     *
     * @return void
     */
    public function eddCointopayprocessPdtonReturn()
    {
        //echo "sdasd";die();
       
        if (! isset($_GET['payment-id'])) {
            return;
        }
		 $paymentID = intval($_GET['payment-id']);

        //$token = edd_get_option( 'paypal_identity_token' );

        if (! edd_is_success_page() || ! edd_is_gateway_active('cointopay')) {
            return;
        }

        $payment_id = isset($paymentID) ? absint($paymentID) : false;

        if (empty($payment_id)) {
            return;
        }

        $payment = new EDD_Payment($payment_id);
        $o_status = sanitize_text_field($_REQUEST['status']);
        $notenough = sanitize_text_field($_REQUEST['notenough']);
        $ConfirmCode = sanitize_text_field($_REQUEST['ConfirmCode']);
        if (isset($ConfirmCode) && $payment->ID > 0) {
            $data = [
               'mid' => edd_get_option('cointopay_seller_id', false) ,
               'TransactionID' => sanitize_text_field($_REQUEST['TransactionID']) ,
               'ConfirmCode' => sanitize_text_field($_REQUEST['ConfirmCode'])
            ];
            $response = $this->validateOrder($data);
            if ($response->Status !== $o_status) {
                edd_debug_log('Attempt to verify Cointopay payment with PDF is in processing.');
                $payment->status = 'pending';
                $payment->save();
            } elseif ($response->CustomerReferenceNr == intval($_REQUEST['CustomerReferenceNr'])) {
                // Purchase verified, set to completed
                if ($o_status == 'paid' && $notenough == 0) {
                    $payment->status = 'publish';
                    $payment->transaction_id = sanitize_text_field($_REQUEST['TransactionID']);
                    $payment->save();
                }
                if ($o_status == 'paid' && $notenough == 1) {
                    edd_debug_log('IPN: Payment failed from Cointopay because notenough');
                    $payment->status = 'failed';
                    $payment->save();
                }
                if ($o_status == 'failed' && $notenough == 0) {
                    edd_debug_log('Payment failed from Cointopay.' . print_r($request, true));
                    $payment->status = 'failed';
                    $payment->save();
                }
                if ($o_status == 'failed' && $notenough == 1) {
                    edd_debug_log('Payment failed from Cointopay.');
                    $payment->status = 'failed';
                    $payment->save();
                }
            } elseif ($response == 'not found') {
                edd_debug_log('Attempt to verify Cointopay payment with PDF failed.');
                $payment->status = 'failed';
                $payment->save();
            } else {
                edd_debug_log('Attempt to verify Cointopay payment with PDF failed.');
                $payment->status = 'failed';
                $payment->save();
            }
        } else {
            $payment->status = 'pending';
            $payment->save();
        }
    }
    
    /**
     * Check for valid Cointopay server callback
     *
     * @param array $data $args {
     *                    Optional. An array of arguments.
     *
     * @type   type $key Description. Default 'value'. Accepts 'value', 'value'.
     *    (aligned with Description, if wraps to a new line)
     * @type   type $key Description.
     * }
     * @return string
     **/
    public function validateOrder($data)
    {
        $params = array(
            'body' => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
            'authentication' => 1,
            'cache-control' => 'no-cache',
        );


        $url = 'https://app.cointopay.com/v2REAPI?';

        $response = wp_safe_remote_post($url, $params);
        $results = json_decode($response['body']);
        if ($results->CustomerReferenceNr) {
            return $results;
        }
        edd_record_gateway_error(__('Payment Error', 'easy-digital-downloads'), sprintf(__('We have detected different order status. Your order has not been found.', 'easy-digital-downloads')));
        // If errors are present, send the user back to the purchase page so they can be corrected
        edd_send_back_to_checkout('?payment-mode=cointopay');
    }

    /**
     * Given a Payment ID, extract the transaction ID
     *
     * @param string $payment_id Payment ID
     *
     * @return string                  Transaction ID
     */
    public function eddCointopaygetPaymenttransactionId($payment_id)
    {
        $transaction_id = '';
        $notes = edd_get_payment_notes($payment_id);

        foreach ($notes as $note) {
            if (preg_match('/^Cointopay Transaction ID: ([^\s]+)/', $note->comment_content, $match)) {
                $transaction_id = $match[1];
                continue;
            }
        }

        return apply_filters('edd_cointopay_set_payment_transaction_id', $transaction_id, $payment_id);
    }

    /**
     * Retrieve the return URL for Cointopay after authentication on Cointopay is complete
     *
     * @return string
     */
    private function _getCointopayauthenticateRedirect()
    {
        if (is_null($this->redirect_uri)) {
            $this->redirect_uri = esc_url_raw(add_query_arg(array( 'edd-listener' => 'cointopay', 'state' => 'return_auth' ), edd_get_checkout_uri()));
        }

        return $this->redirect_uri;
    }
}

/**
 * Load EDD_Cointopay_Payments
 *
 * @since  2.4
 * @return object EDD_Cointopay_Payments
 */
function EDD_cointopay()
{
    return EDD_Cointopay_Payments::getInstance();
}
EDD_cointopay();
