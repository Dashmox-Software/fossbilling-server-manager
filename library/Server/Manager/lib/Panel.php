<?php

namespace Dashmox\Whmcs;

/**
 * The hosting lifecycle, in the panel's own terms.
 *
 * Client.php knows about HTTP. This knows what a hosting account is made of and
 * what order things have to happen in, and still knows nothing about WHMCS: it
 * takes plain values and returns plain arrays, so it can be read by somebody who
 * has never seen a WHMCS module, and driven by a test that has no WHMCS to run
 * inside.
 *
 * Every route used here was read out of the panel's own router table rather than
 * from documentation, and every one of them names a permission a server token
 * can hold. Routes that do not name a permission are closed to this credential
 * whatever it was granted; that is the panel's default and it is deliberate.
 */
class Panel
{
    /** @var Client */
    private $api;

    public function __construct(Client $api)
    {
        $this->api = $api;
    }

    /**
     * Is this credential good, and is the panel well?
     *
     * GET /api/v1/server/health needs server.view. It is the cheapest call that
     * proves all three things a module cares about at setup: the address is
     * right, the token is accepted, and the installation is licensed, because
     * an unlicensed panel refuses every token request whatever it was granted.
     */
    public function health()
    {
        return $this->api->get('/api/v1/server/health')['data'];
    }

    /** The plans the operator sells, which a module offers rather than invents. */
    public function packages()
    {
        $answer = $this->api->get('/api/v1/packages')['data'];

        return is_array($answer) ? $answer : [];
    }

    /**
     * Add a package, for the tool that pushes what WHMCS sells onto a panel.
     *
     * Only adds. There is no update and no delete here and the panel keeps
     * those closed to an integration on purpose: an allowance a customer is
     * already hosting under is not something a sync script should move.
     *
     * @param array $package name, and storage_bytes when there is a disk limit.
     */
    public function createPackage(array $package)
    {
        return $this->api->post('/api/v1/packages', $this->only($package, [
            'name', 'storage_bytes', 'cpu_percent', 'memory_max_mb', 'tasks_max',
        ]))['data'];
    }

    /**
     * Create the owner, then the website.
     *
     * Two calls, in that order, because a website belongs to a customer. The
     * customer carries the contact details a billing system already holds; the
     * panel refuses unknown keys outright, so only the fields it declares are
     * sent, and a stray one would come back as a flat "invalid JSON request".
     *
     * @param array $customer Keys from the panel's Customer: name, email,
     *                        company, phone, address, city, region,
     *                        postal_code, country, notes, package_id.
     * @param array $site     Keys from the panel's site body: name, domain,
     *                        ssl, runtime, php_version and the rest.
     *
     * @return array ['customer_id' => string, 'site' => array, 'job' => array]
     */
    public function createAccount(array $customer, array $site)
    {
        $made = $this->api->post('/api/v1/customers', $this->onlyCustomerFields($customer))['data'];
        if (empty($made['id'])) {
            throw new ApiError(0, 'the panel created a customer but returned no id');
        }

        $site['customer_id'] = $made['id'];
        // Answers 202, not 200: the reply carries the website and the job that
        // builds it. The website exists as a record immediately and is not
        // finished being built, which is why status is polled afterwards.
        $created = $this->api->post('/api/v1/sites', $site)['data'];

        return [
            'customer_id' => $made['id'],
            'site' => isset($created['site']) ? $created['site'] : [],
            'job' => isset($created['job']) ? $created['job'] : [],
        ];
    }

    /**
     * What a website is doing now: its status, and whether it is ready.
     *
     * Provisioning is asynchronous, so this is what a module polls after
     * creating one rather than assuming the work is done.
     */
    public function site($siteId)
    {
        return $this->api->get('/api/v1/sites/' . rawurlencode($siteId))['data'];
    }

    /**
     * Suspend or restore the whole account.
     *
     * The level a billing system wants for non-payment: it flips the customer,
     * signs out everybody belonging to them, and queues a reconfigure for each
     * of their websites. Suspending one website is a different operation, below,
     * and is not what "this invoice is overdue" means.
     */
    public function setCustomerSuspended($customerId, $suspended)
    {
        return $this->api->patch(
            '/api/v1/customers/' . rawurlencode($customerId),
            ['suspended' => (bool) $suspended]
        )['data'];
    }

    /** Suspend or restore one website. The same route both ways. */
    public function setSiteSuspended($siteId, $suspended)
    {
        return $this->api->post(
            '/api/v1/sites/' . rawurlencode($siteId) . '/suspend',
            ['suspended' => (bool) $suspended]
        )['data'];
    }

    /**
     * Remove one website. The caller echoes the primary domain back.
     *
     * The echo is not ceremony: removing a website is not recoverable, and the
     * panel refuses with 400 rather than accepting a delete that named nothing.
     * Answers 202 with the job that does it, so the website is gone from the
     * panel's point of view before the files are.
     */
    public function deleteSite($siteId, $primaryDomain)
    {
        return $this->api->delete(
            '/api/v1/sites/' . rawurlencode($siteId),
            ['confirm' => $primaryDomain]
        )['data'];
    }

    /**
     * Remove the account. Every website first.
     *
     * The panel refuses a customer who still owns websites and says how many, so
     * termination has an order rather than a single call. Each website's removal
     * is allowed to finish before the customer goes, because a website with work
     * still queued refuses to be removed at all.
     *
     * The echo here is the customer's name, not a domain.
     */
    public function deleteCustomer($customerId, $customerName)
    {
        return $this->api->delete(
            '/api/v1/customers/' . rawurlencode($customerId),
            ['confirm' => $customerName]
        )['data'];
    }

    /** Move the account onto another plan the operator already sells. */
    public function setPackage($customerId, $packageId)
    {
        return $this->api->put(
            '/api/v1/customers/' . rawurlencode($customerId) . '/package',
            ['package_id' => $packageId]
        )['data'];
    }

    /**
     * One customer, found in the list.
     *
     * No route returns a single customer to a server token, so this reads the
     * list and picks the id out of it. The list answers a bare JSON array, not
     * an object with a key.
     *
     * Worth the extra call where it is used: removing a customer makes the
     * caller echo the name back, and the name that has to match is the one the
     * panel holds, not the one a billing system holds today. Somebody renamed
     * after their account was created would otherwise fail that echo with a 400
     * nobody could explain from the outside.
     */
    public function customer($customerId)
    {
        $rows = $this->api->get('/api/v1/customers')['data'];
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && $row['id'] === $customerId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Set what a website is allowed on disk.
     *
     * Answers 200, because the figure is written to the website's row and that
     * is the whole operation. Zero means unlimited.
     */
    public function setStorageLimit($siteId, $limitBytes)
    {
        return $this->api->put(
            '/api/v1/sites/' . rawurlencode($siteId) . '/storage',
            ['limit_bytes' => (int) $limitBytes]
        )['data'];
    }

    /**
     * Set CPU, memory and process limits.
     *
     * All three are required together and zero means unlimited: the panel
     * refuses a partial set rather than guessing what the missing ones were.
     * Answers 202, not 200: the figures are recorded and handed to the agent,
     * so a caller waiting for a result on this request waits for something that
     * is not coming back on it.
     */
    public function setLimits($siteId, $cpuPercent, $memoryMaxMB, $tasksMax)
    {
        return $this->api->put(
            '/api/v1/sites/' . rawurlencode($siteId) . '/limits',
            [
                'cpu_percent' => (int) $cpuPercent,
                'memory_max_mb' => (int) $memoryMaxMB,
                'tasks_max' => (int) $tasksMax,
            ]
        )['data'];
    }

    /**
     * What the account holds and what it has used of it: counts, not bytes.
     *
     * Websites, databases, backups, cron jobs and mailboxes, against the limits
     * the plan gives. This is what is true now; it is not kept.
     */
    public function allowances($customerId)
    {
        return $this->api->get('/api/v1/customers/' . rawurlencode($customerId) . '/allowances')['data'];
    }

    /** What one website occupies right now, measured by walking its tree. */
    public function storage($siteId)
    {
        return $this->api->get('/api/v1/sites/' . rawurlencode($siteId) . '/storage')['data'];
    }

    /**
     * What one website has occupied over a billing period.
     *
     * The daily samples, kept for four hundred days, a year plus a billing lag,
     * because an invoice can be raised late or disputed later. Returns the
     * series and the peak across the window, which is the figure a plan priced
     * on disk is actually sold against.
     *
     * @param string $window One of 7d, 30d, 90d, 365d. The panel refuses others.
     */
    public function storageHistory($siteId, $window = '30d')
    {
        return $this->api->get(
            '/api/v1/sites/' . rawurlencode($siteId) . '/storage/history',
            ['window' => $window]
        )['data'];
    }

    /**
     * The peak of a window, or null when nothing has been measured yet.
     *
     * Null rather than zero on purpose. A server on its first day has taken no
     * samples, and reporting that as "used nothing" is a different claim from
     * "not measured": one of them would happily bill somebody for zero disk
     * while the sweep was broken.
     */
    public function peakBytes($siteId, $window = '30d')
    {
        $history = $this->storageHistory($siteId, $window);
        if (empty($history['samples'])) {
            return null;
        }

        return isset($history['peak_bytes']) ? (int) $history['peak_bytes'] : null;
    }

    /**
     * What one website has transferred, and over what period.
     *
     * Read off the website's own access log and kept across rotation. The
     * README said for months that the panel measured no transfer at all; that
     * stopped being true on 2026-09-23 and this is the route it left behind.
     *
     * @param string $window One of 7d, 30d, 90d, 365d.
     */
    public function bandwidth($siteId, $window = '30d')
    {
        return $this->api->get(
            '/api/v1/sites/' . rawurlencode($siteId) . '/bandwidth',
            ['window' => $window]
        )['data'];
    }

    /**
     * The certificate a website is serving, or null when it has none.
     *
     * Null rather than an exception for the ordinary case: a website created a
     * minute ago has no certificate yet, and a summary page saying "not yet"
     * is worth more than one that fails to draw.
     */
    public function certificate($siteId)
    {
        try {
            return $this->api->get('/api/v1/sites/' . rawurlencode($siteId) . '/ssl')['data'];
        } catch (ApiError $e) {
            if ($e->getStatus() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Every website this panel holds, however it got there.
     *
     * The orphan tool needs the panel's own answer rather than the billing
     * system's, because the whole point is finding what the billing system does
     * not know about.
     */
    public function sites()
    {
        $out = [];
        $cursor = '';
        do {
            $query = $cursor === '' ? [] : ['cursor' => $cursor];
            $page = $this->api->get('/api/v1/sites', $query);
            foreach ($page['data'] as $site) {
                $out[] = $site;
            }
            // The cursor for the next, older page travels in a header so the
            // response shape never changes. No header, no more pages, and a
            // client that never looked at headers would stop at page one and
            // report every website past it as an orphan.
            $cursor = isset($page['headers']['x-next-cursor']) ? $page['headers']['x-next-cursor'] : '';
        } while ($cursor !== '' && count($out) < 5000);

        return $out;
    }

    /** Outstanding work, which is what a 409 about an unfinished operation means. */
    public function jobs()
    {
        return $this->api->get('/api/v1/jobs')['data'];
    }

    /**
     * The panel refuses unknown keys, so only what it declares is sent.
     *
     * A billing system holds more about a person than a hosting panel needs, and
     * posting the lot would fail the whole request rather than ignoring the
     * extra. Listed from the panel's own Customer type.
     */
    private function onlyCustomerFields(array $customer)
    {
        return $this->only($customer, [
            'name', 'email', 'company', 'phone', 'address', 'city', 'region',
            'postal_code', 'country', 'notes', 'package_id',
        ]);
    }

    /**
     * Just the keys the panel declares, because it refuses the rest outright.
     *
     * The panel rejects an unknown key by failing the whole request, so one
     * stray field comes back as a flat "invalid JSON request" with nothing
     * saying which field. Filtering here is what keeps that from being the
     * error a host sees when their billing system holds one extra thing.
     */
    private function only(array $values, array $allowed)
    {
        $out = [];
        foreach ($allowed as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $out[$key] = $values[$key];
            }
        }

        return $out;
    }
}
