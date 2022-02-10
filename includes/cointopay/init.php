<?php
/**
 * Plugin Name: Cointopay Gateway for Easy Digital Downloads
 * Description: Cointopay payment gateway for Easy Digital Downloads
 * Author: Cointopay
 * Version: 1.5
 *
 * @category Addon
 * @package  Easy_Digital_Downloads
 * @author   Cointopay <info@cointopay.com>
 * @license  GNU General Public License <http://www.gnu.org/licenses/>
 * @link     cointopay.com
 */


// Cointopay Class
require dirname(__FILE__) . '/lib/Cointopay.php';

// Merchant Class
require dirname(__FILE__) . '/lib/Merchant.php';

// Order Class
require dirname(__FILE__) . '/lib/Merchant/Order.php';
