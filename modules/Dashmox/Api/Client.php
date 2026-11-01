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
     * A one-time link into this customer's own panel.
     *
     * Minted here, on this server, with the server token, and handed back for
     * one redirect. It lives ninety seconds and is spent when opened, so it is
     * never stored and never rendered into a page: a page holding a live
     * credential is a page that must not be cached, and this way there is
     * nothing to cache.
     *
     * Null when the panel offers no sign-on, which the page turns into the
     * plain link it offered before rather than an error.
     */
    public function signon($data)
    {
        $service = $this->_getService($data);
        $identity = $this->getIdentity();
        // What the panel writes into its audit log. It cannot check this, so it
        // is a claim, but "client 41 from FOSSBilling" answers "who went in"
        // and "the billing system" does not.
        $who = 'FOSSBilling client ' . $identity->id;

        return ['url' => $this->getService()->signOnUrl($service, $who)];
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
