<?php

echo $this->Form->input('concordpay.concordpay_merchant', array(
	'label' => __d('concordpay', 'Merchant ID'),
	'type'  => 'text',
	'value' => $data['PaymentMethodValue'][0]['value']
	));

echo $this->Form->input('concordpay.concordpay_secret_key', array(
	'label' => __d('concordpay', 'Secret key'),
	'type'  => 'text',
	'value' => $data['PaymentMethodValue'][1]['value']
	));

// Импортируем статусы заказов в форму
App::import('Model', 'Order');
$this->Order = new Order();

$this->Order->OrderStatus->unbindModel(array('hasMany' => array('OrderStatusDescription')));
$this->Order->OrderStatus->bindModel(
    array(
        'hasOne' => array(
            'OrderStatusDescription' => array(
                'className' => 'OrderStatusDescription',
                'conditions' => 'language_id = ' . $this->Session->read('Customer.language_id')
            )
        )
    )
);

$status_list = $this->Order->OrderStatus->find('all', array('order' => array('OrderStatus.order ASC')));
$concordpay_order_status_list = array();

foreach ($status_list as $status) {
    $status_key = $status['OrderStatus']['id'];
    $concordpay_order_status_list[$status_key] = $status['OrderStatusDescription']['name'];
}

echo $this->Form->input('concordpay.concordpay_order_status_declined', array(
    'label'    => __d('concordpay', 'Declined order status'),
    'type'     => 'select',
    'options'  => $concordpay_order_status_list,
    'selected' => $data['PaymentMethodValue'][2]['value'] ?? '',
));

echo $this->Form->input('concordpay.concordpay_order_status_refunded', array(
    'label'    => __d('concordpay', 'Refunded order status'),
    'type'     => 'select',
    'options'  => $concordpay_order_status_list,
    'selected' => $data['PaymentMethodValue'][3]['value'] ?? '',
));

echo $this->Form->input('concordpay.concordpay_language', array(
    'label'    => __d('concordpay', 'Payment page language'),
    'type'     => 'select',
    'options'  => ['en' => 'en', 'ru'  => 'ru', 'ua' => 'ua'],
    'selected' => $data['PaymentMethodValue'][4]['value'] ?? 'en',
));
?>