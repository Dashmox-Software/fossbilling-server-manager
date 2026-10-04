<?php

declare(strict_types=1);

/**
 * The page a customer looks at.
 *
 * One route, rendering one template from what the client API returns. The API
 * is what checks the order belongs to whoever is asking, so this does no
 * checking of its own and must not start doing any: two places that each decide
 * who may see something is how one of them ends up wrong.
 */

namespace Box\Mod\Dashmox\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/dashmox/:id', 'get_panel', ['id' => '[0-9]+'], static::class);
    }

    public function get_panel(\Box_App $app, $id): string
    {
        $this->di['is_client_logged'];
        $view = $this->di['api_client']->dashmox_panel(['order_id' => $id]);

        return $app->render('mod_dashmox_panel', ['order_id' => (int) $id, 'panel' => $view]);
    }
}
