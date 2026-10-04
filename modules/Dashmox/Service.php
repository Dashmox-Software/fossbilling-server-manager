<?php

declare(strict_types=1);

/**
 * What a Dashmox website is doing, for the pages that show it.
 *
 * The server manager beside this provisions and suspends; it cannot draw
 * anything, because FOSSBilling asks a Server_Manager for thirteen operations
 * and never for a view. So the page a customer actually looks at lives here, in
 * a module, which is FOSSBilling's own mechanism for adding one.
 *
 * Everything is read with the server token the manager already holds, from this
 * server, and nothing about the panel reaches the browser: the customer's page
 * is rendered from what this returns. A customer who edits the order id in the
 * address bar gets somebody else's order only if the check in Api/Client.php is
 * wrong, so that check is the one thing in this module worth reading twice.
 */

namespace Box\Mod\Dashmox;

class Service implements \FOSSBilling\InjectionAwareInterface
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

    /**
     * Everything the panel will tell us about one hosting service.
     *
     * Each reading is fetched on its own and a failure in one does not lose the
     * others: a server that cannot answer for bandwidth should still show the
     * disk figure rather than an empty page. What could not be read says so.
     */
    public function panelView(\Model_ServiceHosting $service): array
    {
        $view = [
            'available' => false,
            'problem' => '',
            'domain' => $service->sld . $service->tld,
            'username' => $service->username,
            'site' => null,
            'storage' => null,
            'bandwidth' => null,
            'certificate' => null,
            'unreadable' => [],
        ];

        try {
            $panel = $this->panelFor($service);
        } catch (\Exception $e) {
            $view['problem'] = $e->getMessage();

            return $view;
        }

        $siteId = $this->siteIdOf($panel, $view['domain']);
        if ($siteId === '') {
            $view['problem'] = 'This panel has no website for ' . $view['domain'] . ' yet. '
                . 'It appears once the order has been set up.';

            return $view;
        }
        $ids = ['site_id' => $siteId];

        foreach ([
            'site' => fn () => $panel->site($ids['site_id']),
            'storage' => fn () => $panel->storage($ids['site_id']),
            'bandwidth' => fn () => $panel->bandwidth($ids['site_id'], '30d'),
            'certificate' => fn () => $panel->certificate($ids['site_id']),
        ] as $key => $read) {
            try {
                $view[$key] = $read();
            } catch (\Exception $e) {
                // Named rather than swallowed. A figure that is quietly absent
                // reads as a figure that is zero.
                $view['unreadable'][$key] = $this->explain($e);
            }
        }

        $view['available'] = $view['site'] !== null;
        if (!$view['available'] && $view['unreadable'] !== []) {
            $view['problem'] = reset($view['unreadable']);
        }

        return $view;
    }

    /**
     * The panel this service is hosted on, built from what FOSSBilling holds
     * about its server. The token never leaves this process.
     */
    private function panelFor(\Model_ServiceHosting $service): \Dashmox\Whmcs\Panel
    {
        $server = $this->getDi()['db']->load('ServiceHostingServer', $service->service_hosting_server_id);
        if (!$server instanceof \Model_ServiceHostingServer) {
            throw new \FOSSBilling\InformationException('This service has no server recorded.');
        }
        if ($server->manager !== 'Dashmox') {
            throw new \FOSSBilling\InformationException('This service is not on a Dashmox server.');
        }

        $this->requireClient();

        $config = json_decode((string) ($server->config ?? ''), true) ?: [];
        $scheme = $server->secure ? 'https' : 'http';
        $port = (int) ($server->port ?: 8443);
        $host = $server->hostname ?: $server->ip;
        $verify = !isset($config['tls_verify']) || (bool) $config['tls_verify'];

        $token = (string) ($server->accesshash ?? '');
        if ($token === '') {
            throw new \FOSSBilling\InformationException(
                'This server has no Dashmox integration key configured.'
            );
        }

        return new \Dashmox\Whmcs\Panel(new \Dashmox\Whmcs\Client($scheme . '://' . $host . ':' . $port, $token, $verify));
    }

    /**
     * The shared client, loaded from wherever the server manager put it.
     *
     * The module and the manager ship separately and either may be installed
     * first, so this looks in both places rather than assuming one.
     */
    private function requireClient(): void
    {
        if (class_exists('\Dashmox\Whmcs\Panel')) {
            return;
        }
        foreach ([
            __DIR__ . '/lib',
            dirname(__DIR__, 2) . '/library/Server/Manager/lib',
        ] as $directory) {
            if (is_file($directory . '/Client.php')) {
                require_once $directory . '/Client.php';
                require_once $directory . '/Panel.php';

                return;
            }
        }
        throw new \FOSSBilling\InformationException(
            'The Dashmox server manager is not installed, so there is nothing to read from.'
        );
    }

    /**
     * The panel's website for a domain.
     *
     * By domain rather than by a recorded id, because there is nowhere on a
     * FOSSBilling hosting service to record one: `Server_Account::setNote()`
     * sets a property on an object FOSSBilling discards, and `service_hosting`
     * has no column for it. A domain is unique across a panel, so at most one
     * website can answer to it.
     */
    private function siteIdOf(\Dashmox\Whmcs\Panel $panel, string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        try {
            foreach ($panel->sites() as $site) {
                if (strtolower((string) ($site['domain'] ?? '')) === $domain) {
                    return (string) ($site['id'] ?? '');
                }
            }
        } catch (\Exception $e) {
            return '';
        }

        return '';
    }

    /** A refusal in words a customer can act on, never a stack trace. */
    private function explain(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? 'The panel did not answer.' : $message;
    }
}
