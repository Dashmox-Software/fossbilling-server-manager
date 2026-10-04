<?php

/**
 * Dashmox server manager for FOSSBilling.
 *
 * The second integration, and the point of it is that it is shaped nothing like
 * the first. WHMCS asks for a function per operation taking an array it built;
 * FOSSBilling asks for a class implementing thirteen abstract methods taking
 * objects. If the panel's API had been quietly designed around WHMCS, this is
 * where it would show, because nothing here could be bent to fit.
 *
 * What it showed is written down in the README and worth stating here too: five
 * of the thirteen have no route behind them on a Dashmox panel, and that is a fact
 * about the panel rather than about FOSSBilling. They refuse by name, which is what a
 * server manager should do, because a method that silently does nothing is
 * worse than one that says it cannot.
 *
 * Everything under lib/ is shared with the WHMCS module. That sharing is
 * deliberate: the piece that talks to the panel is the piece with tests behind
 * it, and two copies of it would agree until the day they did not.
 */

// The client lives once in this repository and is copied beside each module
// when one is packaged, so a downloaded module is self-contained while there is
// still only one copy to fix. Either location works: its own, if this is an
// unpacked download, or the shared one, if this is the repository.
$dashmoxLib = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../../lib';
require_once $dashmoxLib . '/Client.php';
require_once $dashmoxLib . '/Panel.php';

use Dashmox\Whmcs\ApiError;
use Dashmox\Whmcs\Client;
use Dashmox\Whmcs\Panel;

class Server_Manager_Dashmox extends Server_Manager
{
    /**
     * What FOSSBilling asks an administrator for.
     *
     * The token goes in a field of its own rather than in username and password,
     * because it is neither: it belongs to the installation, not to a person,
     * and there is no account name to put beside it. Marked secret so it is not
     * shown back after saving.
     */
    public static function getForm(): array
    {
        return [
            'label' => 'Dashmox',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'accesshash',
                            'type' => 'text',
                            'label' => 'Server token',
                            'placeholder' => 'dashmox_… made in the panel under Server → Integrations',
                            'required' => true,
                            'secret' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['accesshash'];
    }

    /**
     * Said before anything is attempted, so a half-configured server fails while
     * somebody is looking at the form rather than during a customer's order.
     */
    public function init(): void
    {
        if (empty($this->_config['host'])) {
            throw new Server_Exception(
                'The ":server_manager" server manager is not fully configured. Please configure the :missing',
                [':server_manager' => 'Dashmox', ':missing' => 'hostname'],
                2001
            );
        }
        if (empty($this->_config['accesshash'])) {
            throw new Server_Exception(
                'The ":server_manager" server manager is not fully configured. Please configure the :missing',
                [':server_manager' => 'Dashmox', ':missing' => 'server token'],
                2001
            );
        }
    }

    /** The panel, built from what FOSSBilling holds about this server. */
    private function panel(): Panel
    {
        $scheme = empty($this->_config['secure']) ? 'http' : 'https';
        $port = empty($this->_config['port']) ? 8443 : (int) $this->_config['port'];
        $verify = !isset($this->_config['config']['tls_verify'])
            || (bool) $this->_config['config']['tls_verify'];

        return new Panel(new Client(
            $scheme . '://' . $this->_config['host'] . ':' . $port,
            trim((string) $this->_config['accesshash']),
            $verify
        ));
    }

    /**
     * A refusal from the panel, in FOSSBilling's own terms.
     *
     * The panel's sentence is carried rather than replaced: "this customer still
     * owns 2 website(s)" tells somebody what to do next, and a status code does
     * not. Two cases are worth naming because the panel's own words are true but
     * the reason is elsewhere.
     */
    private function fail(ApiError $e): never
    {
        if ($e->isUnlicensed()) {
            throw new Server_Exception(
                'The ":server_manager" panel has no paid licence in force, so it refuses every integration request. The token is kept and works again once a licence is applied.',
                [':server_manager' => 'Dashmox'],
                2002
            );
        }
        if ($e->isTemporary()) {
            throw new Server_Exception(
                'The ":server_manager" panel is still working on this website: :detail. This clears on its own; try again shortly.',
                [':server_manager' => 'Dashmox', ':detail' => $e->getMessage()],
                2003
            );
        }
        throw new Server_Exception(
            'The ":server_manager" panel refused: :detail',
            [':server_manager' => 'Dashmox', ':detail' => $e->getMessage()],
            2004
        );
    }

    public function testConnection(): bool
    {
        try {
            $this->panel()->health();

            return true;
        } catch (ApiError $e) {
            $this->fail($e);
        }
    }

    /**
     * Create the owner, then the website under it.
     *
     * FOSSBilling hands over a client and a package as objects. The client's
     * contact details map onto the panel's customer; the package's name is
     * matched against the plans the operator already sells, because the panel
     * refuses to let an integration invent a plan and that refusal is right.
     */
    public function createAccount(Server_Account $account): bool
    {
        $client = $account->getClient();
        $package = $account->getPackage();

        try {
            $panel = $this->panel();
            $made = $panel->createAccount(
                [
                    'name' => trim(($client?->getFirstName() ?? '') . ' ' . ($client?->getLastName() ?? '')),
                    'email' => $client?->getEmail() ?? '',
                    'company' => $client?->getCompany() ?? '',
                    'phone' => $client?->getTelephone() ?? '',
                    'address' => trim(($client?->getAddress1() ?? '') . ' ' . ($client?->getAddress2() ?? '')),
                    'city' => $client?->getCity() ?? '',
                    'region' => $client?->getState() ?? '',
                    'postal_code' => $client?->getZip() ?? '',
                    'country' => $client?->getCountry() ?? '',
                    'package_id' => $this->packageIdFor($panel, $package),
                ],
                [
                    'name' => $account->getDomain() ?: $account->getUsername(),
                    'domain' => $account->getDomain(),
                    'runtime' => 'php',
                    // No certificate yet. Somebody ordering hosting usually
                    // points the domain afterwards, and the panel refuses to
                    // issue for a name that does not resolve to it, which would
                    // fail the whole order. The panel's own sweep issues one as
                    // soon as the name starts resolving here.
                    'ssl' => false,
                ]
            );
            // Recorded on the account so later operations address the panel by
            // id rather than searching by domain. A domain can be renamed or
            // exist twice across customers; a termination that found the wrong
            // website would not be recoverable.
            $account->setNote($this->noteFor($made['customer_id'], $made['site']['id'] ?? ''));

            return true;
        } catch (ApiError $e) {
            $this->fail($e);
        }
    }

    /**
     * Suspension is the whole account, not one website.
     *
     * Non-payment is about the customer: it flips them, signs out their people
     * and reconfigures each of their websites. Suspending a single website is a
     * different operation and is not what an overdue invoice means.
     */
    public function suspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, true);
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, false);
    }

    private function setSuspended(Server_Account $account, bool $suspended): bool
    {
        $panel = $this->panel();
        $ids = $this->idsOf($account, $panel);
        if ($ids['customer_id'] === '') {
            throw new Server_Exception(
                'This service has no :server_manager customer recorded, so there is nothing to change.',
                [':server_manager' => 'Dashmox'],
                2005
            );
        }
        try {
            $panel->setCustomerSuspended($ids['customer_id'], $suspended);

            return true;
        } catch (ApiError $e) {
            $this->fail($e);
        }
    }

    /**
     * FOSSBilling separates cancelling from terminating; the panel does not.
     *
     * Cancelling here suspends rather than deletes. A cancelled account that
     * removed somebody's websites the moment an invoice lapsed would be a
     * product that takes customers off the air over a billing dispute, and the
     * panel's own design refuses that.
     * Terminating is the destructive one and says so.
     */
    public function cancelAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, true);
    }

    /**
     * Every website first, then the account.
     *
     * The panel refuses a customer who still owns websites and says how many, so
     * this has an order rather than being one call. A website with work still
     * queued refuses removal too, and that refusal clears on its own, so it is
     * reported as "try again" rather than as a failed termination.
     */
    public function terminateAccount(Server_Account $account): bool
    {
        $panel = $this->panel();
        $ids = $this->idsOf($account, $panel);
        if ($ids['customer_id'] === '') {
            return true;
        }
        try {
            $panel = $this->panel();
            if ($ids['site_id'] !== '') {
                $site = $panel->site($ids['site_id']);
                if (!empty($site['domain'])) {
                    $panel->deleteSite($ids['site_id'], $site['domain']);
                }
            }
            $customer = $panel->customer($ids['customer_id']);
            if ($customer === null) {
                return true;
            }
            $panel->deleteCustomer($ids['customer_id'], $customer['name']);

            return true;
        } catch (ApiError $e) {
            if ($e->getStatus() === 409 && str_contains($e->getMessage(), 'still owns')) {
                throw new Server_Exception(
                    'The website is still being removed, so the account cannot go yet: :detail Terminate again once it has finished.',
                    [':detail' => $e->getMessage()],
                    2006
                );
            }
            $this->fail($e);
        }
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $panel = $this->panel();
        $ids = $this->idsOf($account, $panel);
        if ($ids['customer_id'] === '') {
            throw new Server_Exception(
                'This service has no :server_manager customer recorded, so its plan cannot be changed.',
                [':server_manager' => 'Dashmox'],
                2005
            );
        }
        try {
            $panel = $this->panel();
            $panel->setPackage($ids['customer_id'], $this->packageIdFor($panel, $package));

            return true;
        } catch (ApiError $e) {
            $this->fail($e);
        }
    }

    /**
     * What the panel currently believes, read back onto the account.
     *
     * WHMCS has no equivalent of this, which is the clearest single sign that
     * the two systems are shaped differently.
     */
    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $panel = $this->panel();
        $ids = $this->idsOf($account, $panel);
        if ($ids['customer_id'] === '') {
            return $account;
        }
        try {
            $customer = $panel->customer($ids['customer_id']);
            if ($customer !== null && isset($customer['suspended'])) {
                $account->setSuspended((bool) $customer['suspended']);
            }

            return $account;
        } catch (ApiError $e) {
            $this->fail($e);
        }
    }

    // --- What a Dashmox panel has no route for -------------------------------
    //
    // Refused by name rather than stubbed. A method that returns true while
    // doing nothing tells a host their customer's password changed when it did
    // not, which is worse than an error somebody can read.

    public function getLoginUrl(?Server_Account $account): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'signing a customer in from here. The panel mints no one-time session; every hosted domain answers /dashmox with a redirect to its sign-in page.',
        ]);
    }

    public function getResellerLoginUrl(?Server_Account $account): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'reseller accounts. A Dashmox licence covers a server, and there is no reseller role.',
        ]);
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'password changes from an integration. The route exists and is deliberately closed to integration credentials: setting a website account password is the difference between doing an account\'s work and becoming it.',
        ]);
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'username changes. A website\'s Linux account name is fixed when it is created.',
        ]);
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'changing a website\'s primary domain from an integration. Add the domain in the panel instead.',
        ]);
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(':type: does not support :action:', [
            ':type:' => 'Dashmox',
            ':action:' => 'choosing which address a website answers on. The panel allocates that itself.',
        ]);
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * The panel's plan matching this package, by name.
     *
     * Creating and editing packages is closed to integration credentials on
     * purpose: the catalogue is the operator's, and a billing system sells the
     * plans a panel already has rather than inventing them. So a package whose
     * name matches nothing is an error somebody can fix in one place, not a
     * silent account on no plan.
     */
    private function packageIdFor(Panel $panel, ?Server_Package $package): string
    {
        $wanted = trim((string) ($package?->getName() ?? ''));
        if ($wanted === '') {
            return '';
        }
        foreach ($panel->packages() as $plan) {
            if (isset($plan['name']) && strcasecmp(trim($plan['name']), $wanted) === 0) {
                return (string) $plan['id'];
            }
        }
        throw new Server_Exception(
            'The ":server_manager" panel sells no plan called ":name". Create it in the panel under Customers → Packages, or rename the product to match one that exists.',
            [':server_manager' => 'Dashmox', ':name' => $wanted],
            2007
        );
    }

    /** Where the panel's two identifiers are kept, since there is nowhere else. */
    private function noteFor(string $customerId, string $siteId): string
    {
        return 'dashmox customer=' . $customerId . ' site=' . $siteId;
    }

    /**
     * The panel's ids for this account.
     *
     * Preferred from the note the creation wrote, and found by domain when
     * there is none, which on FOSSBilling is always: `setNote()` sets a
     * property on an object FOSSBilling discards. There is no `note` column on
     * `service_hosting` and nothing reads one back, so for a long while every
     * operation after provisioning found no ids and refused to do anything,
     * politely. Suspending for non-payment did nothing and reported success.
     *
     * Looking it up by domain is exact rather than a guess. A domain is unique
     * across a panel, `domains.name TEXT NOT NULL UNIQUE COLLATE NOCASE`, so at
     * most one website can answer to it and the customer is whichever owns that
     * website.
     */
    private function idsOf(Server_Account $account, ?Panel $panel = null): array
    {
        $ids = ['customer_id' => '', 'site_id' => ''];
        $note = (string) ($account->getNote() ?? '');
        if (preg_match('/dashmox customer=([A-Za-z0-9_-]*) site=([A-Za-z0-9_-]*)/', $note, $found)
            && $found[1] !== '' && $found[2] !== '') {
            $ids['customer_id'] = $found[1];
            $ids['site_id'] = $found[2];

            return $ids;
        }

        $domain = strtolower(trim((string) $account->getDomain()));
        if ($domain === '' || $panel === null) {
            return $ids;
        }
        try {
            foreach ($panel->sites() as $site) {
                if (strtolower((string) ($site['domain'] ?? '')) !== $domain) {
                    continue;
                }
                $ids['site_id'] = (string) ($site['id'] ?? '');
                $ids['customer_id'] = (string) ($site['customer_id'] ?? '');

                return $ids;
            }
        } catch (ApiError $e) {
            // Nothing found is nothing found. The caller says what that means
            // for the operation it was about to do.
        }

        return $ids;
    }
}
