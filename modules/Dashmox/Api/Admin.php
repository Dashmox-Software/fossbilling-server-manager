<?php

declare(strict_types=1);

/**
 * The same view for staff, on any order rather than only their own.
 *
 * Separate from the client API rather than sharing it with a flag: a flag that
 * switches off an ownership check is a flag somebody passes from the wrong
 * place one day.
 */

namespace Box\Mod\Dashmox\Api;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    public function panel($data)
    {
        if (!isset($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required');
        }
        $order = $this->getDi()['db']->getExistingModelById(
            'ClientOrder',
            $data['order_id'],
            'Order not found'
        );
        $service = $this->getDi()['mod_service']('order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\InformationException('That order is not a hosting account.');
        }

        return $this->getService()->panelView($service);
    }
}
