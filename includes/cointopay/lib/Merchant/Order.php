<?php
/**
 * Plugin Name: Cointopay Gateway for Easy Digital Downloads
 * Description: Cointopay payment gateway for Easy Digital Downloads
 * Author: Cointopay
 * Version: 1.0
 *
 * @category Addon
 * @package  Easy_Digital_Downloads
 * @author   Cointopay <info@cointopay.com>
 * @license  GNU General Public License <http://www.gnu.org/licenses/>
 * @link     cointopay.com
 */
 
namespace cointopay\Merchant;

use cointopay\Cointopay;
use cointopay\Merchant;

/**
 * Define Order Class
 *
 * @category Addon
 * @package  Easy_Digital_Downloads
 * @author   Cointopay <info@cointopay.com>
 * @license  GNU General Public License <http://www.gnu.org/licenses/>
 * @link     cointopay.com
 */
class Order extends Merchant
{
    private $_order;
    
    /**
     * Get things going
     *
     * @param array $order return order object
     *
     * @access private
     * @return void
     */
    public function __construct($order)
    {
        $this->_order = $order;
    }

    /**
     * Cointopay Order callback.
     *
     * @param array $params         Params Passing
     * @param array $options        Order Options
     * @param array $authentication Authentication
     *
     * @return array
     */
    public static function createOrFail($params, $options = array(), $authentication = array())
    {
        $order = Cointopay::request('orders', 'GET', $params, $authentication);
        return new self($order);
    }
    
    /**
     * Cointopay toHash Method.
     *
     * @return array
     */
    public function toHash()
    {
        return $this->_order;
    }

    /**
     * Cointopay get Method.
     *
     * @param string $name order name
     *
     * @return string
     */
    public function __get($name)
    {
        return $this->_order[$name];
    }
}
