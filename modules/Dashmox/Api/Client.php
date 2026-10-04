<?php

declare(strict_types=1);

/**
 * What a customer may ask about their own hosting, and nothing else.
 *
 * The whole security of this module is `_getService` below. A customer reaches
 * this with an order id in a URL they can edit, so the lookup is by order id
 * *and* their own client id together: an order that is not theirs is not found
 * rather than refused, which is the same answer as an order that does not
 * exist. It is the pattern FOSSBilling's own hosting module uses, copied
 * deliberately rather than invented.
 */

namespace Box\Mod\Dashmox\Api;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * The panel's view of this customer's website.
     *
     * Read on this server with the server token, so the token never reaches a
     * browser and a customer never talks to the panel.
     */
    public function panel($data)
    {
        $service = $this->_getService($data);

        return $this->getService()->panelView($service);
    }

    /**
     * This customer's own hosting service, or nothing.
     *
     * Order id and client id together. Checking the order and then trusting its
     * id would let somebody read another customer's hosting by changing a
     * number in the address bar.
     */
    private function _getService($data): \Model_ServiceHosting
    {
        if (!isset($data['order_id'])) {
            throw new \FOSSBilling\Exception('Order ID is required');
        }
        $identity = $this->getIdentity();
        $order = $this->getDi()['db']->findOne(
            'ClientOrder',
            'id = ? and client_id = ?',
            [$data['order_id'], $identity->id]
        );
        if (!$order instanceof \Model_ClientOrder) {
            throw new \FOSSBilling\InformationException('Order not found');
        }

        $orderService = $this->getDi()['mod_service']('order');
        $orderService->assertOrderUsable($order);
        $service = $orderService->getOrderService($order);
        if (!$service instanceof \Model_ServiceHosting) {
            throw new \FOSSBilling\InformationException('That order is not a hosting account.');
        }

        return $service;
    }
}
