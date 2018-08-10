<?php
namespace cointopay;

class Cointopay
{
    const VERSION = '1.0';
    const USER_AGENT_ORIGIN = 'Cointopay PHP Library';

    public static $merchant_id = '';
    public static $security_code = '';
    public static $default_currency = '';
    public static $user_agent = '';
    public static $selected_currency = '';

    public static function config($authentication)
    {
        if (isset($authentication['merchant_id']))
            self::$merchant_id = $authentication['merchant_id'];

        if (isset($authentication['security_code']))
            self::$security_code = $authentication['security_code'];

        if (isset($authentication['default_currency']))
            self::$default_currency = $authentication['default_currency'];

        if (isset($authentication['user_agent']))
            self::$user_agent = $authentication['user_agent'];

        if (isset($authentication['selected_currency']))
            self::$selected_currency = $authentication['selected_currency'];
    }

    public static function verifyMerchant($authentication = array())
    {
        try {
            $response = self::request('merchant', 'GET', array(), $authentication);
            if ($response != "testmerchant success") {
                return $response;
            }
            return true;
        } catch (\Exception $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }


    public static function request($url, $method = 'GET', $params = array(), $authentication = array())
    {
        $merchant_id = isset($authentication['merchant_id']) ? $authentication['merchant_id'] : self::$merchant_id;
        $security_code = isset($authentication['security_code']) ? $authentication['security_code'] : self::$security_code;
        $selected_currency = isset($authentication['selected_currency']) ? $authentication['selected_currency'] : self::$selected_currency;
        $user_agent = isset($authentication['user_agent']) ? $authentication['user_agent'] : (isset(self::$user_agent) ? self::$user_agent : (self::USER_AGENT_ORIGIN . ' v' . self::VERSION));
        $request_check = '';

        # Check if credentials was passed
        if (empty($merchant_id) || empty($security_code))
            \cointopay\Exception::throwException(400, array('reason' => 'CredentialsMissing'));

        if (isset($params) && !empty($params)) {
            $amount = $params['price'];
            $order_id = $params['order_id'];
            $currency = $params['currency'];
            $callback_url = $params['callback_url'];
            $cancel_url = $params['cancel_url'];
            $selected_currency = (isset($params['selected_currency']) && !empty($params['selected_currency'])) ? $params['selected_currency'] : 1;
        }

        if ($url == 'merchant') {
            $request_check = 'merchant';
            $url = "MerchantAPI?Checkout=true&MerchantID=$merchant_id&Amount=10&AltCoinID=$selected_currency&CustomerReferenceNr=testmerchant&SecurityCode=$security_code&inputCurrency=EUR&output=json&testmerchant";

            $result = self::callApi($url, $user_agent);
            return $result;
        } else {

            $url = "MerchantAPI?Checkout=true&MerchantID=$merchant_id&Amount=$amount&AltCoinID=$selected_currency&CustomerReferenceNr=$order_id&SecurityCode=$security_code&inputCurrency=$currency&output=json&testmerchant";
            $result = self::callApi($url, $user_agent);

            if ($result == 'testmerchant success') {

                $url = "MerchantAPI?Checkout=true&MerchantID=$merchant_id&Amount=$amount&AltCoinID=$selected_currency&CustomerReferenceNr=$order_id&SecurityCode=$security_code&output=json&inputCurrency=$currency&transactionconfirmurl=$callback_url&transactionfailurl=$cancel_url";
                $result = self::callApi($url, $user_agent);
                return $result;
            } else {
                return $result;
            }
        }
    }

    public static function callApi($url, $user_agent)
    {

        $url = 'https://cointopay.com/' . $url;

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => $user_agent
        ));
        $response = json_decode(curl_exec($curl), TRUE);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_status === 200) {
            return $response;
        } elseif ($http_status === 400) {
            return $response;
        } else {
            return $response;
        }
    }
}