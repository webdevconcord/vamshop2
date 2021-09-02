<?php

App::uses('PaymentAppController', 'Payment.Controller');

/**
 * Class ConcordpayController
 *
 * @property Order $Order
 * @property PaymentMethod $PaymentMethod
 * @property OrderBaseComponent OrderBase
 */
class ConcordpayController extends PaymentAppController
{
    const SIGNATURE_SEPARATOR = ';';
    const PURCHASE_URL        = 'https://pay.concord.ua/api/';
    const CURRENCY_HRYVNA     = 'UAH';

    const CONCORDPAY_ORDER_STATUS_APPROVED = 'Approved';

    const RESPONSE_TYPE_REVERSE = 'reverse';
    const RESPONSE_TYPE_PAYMENT = 'payment';

    /**
     * @var string[]
     */
    protected $keysForPurchaseSignature = array(
        'merchant_id',
        'order_id',
        'amount',
        'currency_iso',
        'description'
    );

    /**
     * @var string[]
     */
    protected $keysForResponseSignature = array(
        'merchantAccount',
        'orderReference',
        'amount',
        'currency'
    );

    /**
     * @var string[]
     */
    protected $operationType = array(
        'payment',
        'reverse'
    );

    /**
     * @var string[]
     */
    public $uses = array('PaymentMethod', 'Order');

    /**
     * @var string[]
     */
    public $components = array('OrderBase');

    /**
     * @var string
     */
    public $module_name = 'Concordpay';

    /**
     * @var string
     */
    public $icon = 'concordpay.png';

    /**
     * @var int[]
     */
    public $params = array('unit_id' => 0, 'account_id' => 0, 'online' => 1);

    public function settings()
    {
        $this->set('data', $this->PaymentMethod->findByAlias($this->module_name));
    }

    public function install()
    {
        $new_module = array();
        $new_module['PaymentMethod']['active']  = '1';
        $new_module['PaymentMethod']['default'] = '0';
        $new_module['PaymentMethod']['name']    = Inflector::humanize($this->module_name);
        $new_module['PaymentMethod']['icon']    = $this->icon;
        $new_module['PaymentMethod']['alias']   = $this->module_name;

        $new_module['PaymentMethodValue'][0]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][0]['key']   = 'concordpay_merchant';
        $new_module['PaymentMethodValue'][0]['value'] = '';

        $new_module['PaymentMethodValue'][1]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][1]['key']   = 'concordpay_secret_key';
        $new_module['PaymentMethodValue'][1]['value'] = '';

        $new_module['PaymentMethodValue'][2]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][2]['key']   = 'concordpay_order_status_declined';
        $new_module['PaymentMethodValue'][2]['value'] = '';

        $new_module['PaymentMethodValue'][3]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][3]['key']   = 'concordpay_order_status_refunded';
        $new_module['PaymentMethodValue'][3]['value'] = '';

        $new_module['PaymentMethodValue'][4]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][4]['key']   = 'concordpay_language';
        $new_module['PaymentMethodValue'][4]['value'] = '';

        $this->PaymentMethod->saveAll($new_module);

        $this->Session->setFlash(__('Module Installed'));
        $this->redirect('/payment_methods/admin/');
    }

    public function uninstall()
    {
        $module_id = $this->PaymentMethod->findByAlias($this->module_name);

        $this->PaymentMethod->delete($module_id['PaymentMethod']['id'], true);

        $this->Session->setFlash(__('Module Uninstalled'));
        $this->redirect('/payment_methods/admin/');
    }

    /**
     * Confirmation order page.
     * After confirming the order, the merchant will be redirected to the payment system page
     *
     * @return string
     */
    public function before_process()
    {
        $order = $this->OrderBase->get_order();

        $content = $this->_buildForm($order);
        $content .= '<button class="btn btn-default" type="submit" value="{lang}Confirm Order{/lang}">
                      <i class="fa fa-check"></i>{lang}Confirm Order{/lang}</button></form>';

        return $content;
    }

    /**
     * Empty function, but left as a hook
     */
    public function after_process()
    {
    }

    /**
     * Callback method. Checking the payment result.
     * If the payment system reported that the order was paid, its status is automatically changed.
     *
     */
    public function result()
    {
        if (empty($_POST)) {
            $response = json_decode(file_get_contents("php://input"), true);

            $order_id = explode('_', $response['orderReference']);
            if (is_array($order_id) && !empty($order_id[0])) {
                $order_id = $order_id[0];
            } else {
                die(__('Order ID not found!'));
            }

            if (!isset($response['type']) || !in_array($response['type'], $this->operationType, true)) {
                die(__('Unknown operation type!'));
            }

            if ($this->checkResponse($response) !== true) {
                die(__('Invalid merchant signature!'));
            }

            $payment_method = $this->PaymentMethod->find(
                'first',
                array('conditions' => array('alias' => $this->module_name))
            );
            $order_data = $this->Order->find('first', array('conditions' => array('Order.id' => $order_id)));

            $order_amount = number_format($order_data['Order']['total'], 2);
            if (isset($response['amount']) && (float)$response['amount'] !== (float)$order_amount) {
                die(__('Invalid order amount!'));
            }

            //Changing the order status to the one specified in the payment module settings
            if ($response['transactionStatus'] === self::CONCORDPAY_ORDER_STATUS_APPROVED) {
                if ($response['type'] === self::RESPONSE_TYPE_PAYMENT) {
                    // Ordinary payment.
                    $order_data['Order']['order_status_id'] = $payment_method['PaymentMethod']['order_status_id'];
                    $this->Order->save($order_data);
                } elseif ($response['type'] === self::RESPONSE_TYPE_REVERSE) {
                    // Refunded payment.
                    $order_data['Order']['order_status_id'] = $payment_method['PaymentMethodValue'][3]['value'];
                    $this->Order->save($order_data);
                }
            }
        }
    }

    /**
     * Displaying the payment form on the merchant's account page.
     *
     * @param int $order_id
     * @return string
     */
    public function payment_after($order_id = 0)
    {
        if (!empty($order_id)) {
            $order = $this->Order->read(null, $order_id);
            $content = $this->_buildForm($order);
            if ($order['Order']['order_status_id'] == 1) {
                $content .= '<button class="btn btn-default" type="submit" value="{lang}Pay Now{/lang}">
                              <i class="fa fa-check"></i>{lang}Pay Now{/lang}</button></form>';
            }

            return $content;
        }

        return '';
    }

    /**
     * @param $options
     * @return string
     */
    private function getRequestSignature($options)
    {
        return $this->getSignature($options, $this->keysForPurchaseSignature);
    }

    /**
     * @param $options
     * @param $keys
     * @return string
     */
    private function getSignature($options, $keys)
    {
        $settings = $this->PaymentMethod->PaymentMethodValue->find(
            'first',
            array('conditions' => array('key' => 'concordpay_secret_key'))
        );
        $secret_key = $settings['PaymentMethodValue']['value'];

        $hash = array();
        foreach ($keys as $dataKey) {
            if (!isset($options[$dataKey])) {
                continue;
            }
            if (is_array($options[$dataKey])) {
                foreach ($options[$dataKey] as $v) {
                    $hash[] = $v;
                }
            } else {
                $hash [] = $options[$dataKey];
            }
        }
        $hash = implode(self::SIGNATURE_SEPARATOR, $hash);

        return hash_hmac('md5', $hash, $secret_key);
    }

    /**
     * @param $data
     * @return bool
     */
    private function checkResponse($data)
    {
        $signature = $this->getSignature($data, $this->keysForResponseSignature);

        return $signature === $data['merchantSignature'];
    }

    /**
     * @param $order
     * @return string
     */
    private function _buildForm($order)
    {
        global $config;

        $payment_url = $this::PURCHASE_URL;

        $settings = $this->PaymentMethod->PaymentMethodValue->find(
            'first',
            array('conditions' => array('key' => 'concordpay_merchant'))
        );
        $merchant = $settings['PaymentMethodValue']['value'];

        $amount = number_format($order['Order']['total'], 2, '.', '');

        $content       = $this->validationJS;
        $productNames  = array();
        $productQty    = array();
        $productPrices = array();
        foreach ($order['OrderProduct'] as $_item) {
            $productNames[] = $_item['name'];
            $productPrices[] = $_item['price'];
            $productQty[] = $_item['quantity'];
        }

        $success_url = FULL_BASE_URL . BASE . '/page/success' . $config['URL_EXTENSION'];
        $fail_url    = FULL_BASE_URL . BASE . '/page/checkout' . $config['URL_EXTENSION'];
        $result_url  = FULL_BASE_URL . BASE . '/payment/concordpay/result/';

        /**
         * Check phone
         */
        $phone = str_replace(['+', ' ', '(', ')', '-'], ['', '', '', '', ''], $order['Order']['phone']);
        if (strlen($phone) == 10) {
            $phone = '38' . $phone;
        } elseif (strlen($phone) == 11) {
            $phone = '3' . $phone;
        }

        $fields = [
            'operation'    => 'Purchase',
            'merchant_id'  => $merchant,
            'amount'       => $amount,
            'order_id'     => $order['Order']['id'] . '_' . time(),
            'currency_iso' => self::CURRENCY_HRYVNA,
            'description'  => __('Payment by card on the site') . ' ' . htmlspecialchars($_SERVER["HTTP_HOST"]) .
                ', ' . $order['Order']['bill_name'] . ', ' . $order['Order']['phone'],
            'add_params'   => [],
            'approve_url'  => $success_url,
            'decline_url'  => $fail_url,
            'cancel_url'   => $fail_url,
            'callback_url' => $result_url,
            // Statistics.
            'client_first_name' => $this->getName($order['Order']['bill_name'])['client_first_name'],
            'client_last_name'  => $this->getName($order['Order']['bill_name'])['client_last_name'],
            'email'             => $order['Order']['email'] ?? '',
            'phone'             => $phone ?? '',
        ];

        $fields['signature'] = $this->getRequestSignature($fields);

        $content .= '<form name="Concordpay" id="ConcordpayForm" method="post" action="' . $payment_url . '">';

        foreach ($fields as $name => $value) {
            $content .= $this->printInput($name, $value);
        }

        return $content;
    }

    /**
     * @param $fullname
     * @return false|string[]
     */
    protected function getName($fullname)
    {
        $names = explode(' ', $fullname);

        $names['client_first_name'] = $names[0] ?? '';
        $names['client_last_name']  = $names[1] ?? '';

        return $names;
    }

    /**
     * Prints inputs in form.
     *
     * @param string       $name Attribute name.
     * @param array|string $value Attribute value.
     * @return string
     */
    protected function printInput($name, $value)
    {
        $str = '';
        if (!is_array($value)) {
            return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '">';
        }
        foreach ($value as $v => $data_key) {
            $str .= $this->printInput($name . '[' . $v .']', $data_key);
        }
        return $str;
    }
}
