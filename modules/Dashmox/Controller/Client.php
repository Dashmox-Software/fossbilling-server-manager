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
        $app->get('/dashmox/:id/open', 'get_open', ['id' => '[0-9]+'], static::class);
    }

    public function get_panel(\Box_App $app, $id): string
    {
        $this->di['is_client_logged'];
        $view = $this->di['api_client']->dashmox_panel(['order_id' => $id]);

        return $app->render('mod_dashmox_panel', ['order_id' => (int) $id, 'panel' => $view]);
    }

    /**
     * Sign this customer into their panel and send them there.
     *
     * A route of its own rather than a link on the page, because the link the
     * panel mints lives ninety seconds and is spent when opened: one rendered
     * into the page would be dead before anybody read the page, and a page
     * carrying a live credential must never be cached. So the credential exists
     * only between this call and the redirect that spends it.
     *
     * The client API is what checks the order belongs to whoever is asking, as
     * it does for the page. Nothing is checked twice here, because two places
     * that each decide who may see something is how one of them ends up wrong.
     */
    /**
     * Two things about redirecting, both of which have to be right.
     *
     * `Box_App::redirect()` **returns** a response rather than sending one, so
     * it has to be returned from here. Called for its effect and followed by
     * `return ''`, the browser gets 200 and an empty page: the button appears
     * to do nothing, which is exactly the fault the manager's own Jump to admin
     * had for a different reason.
     *
     * And `redirect()` puts its argument through the installation's own link
     * builder, which is right for a path of ours and wrong for the sign-on
     * link, an absolute address on another host entirely. `redirectUrl()` is
     * the one that sends an address as given.
     */
    public function get_open(\Box_App $app, $id)
    {
        $this->di['is_client_logged'];
        $answer = $this->di['api_client']->dashmox_signon(['order_id' => $id]);
        $url = (string) ($answer['url'] ?? '');
        if ($url === '') {
            // No sign-on to be had, which is a panel older than the feature, an
            // integration key without users.manage, or a website still being
            // built. The page says so and offers the plain sign-in link, which
            // is a better answer than an error page.
            return $app->redirect('/dashmox/' . (int) $id);
        }

        return $app->redirectUrl($url);
    }
}
