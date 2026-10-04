<?php

/**
 * The part of this module that can honestly be tested.
 *
 * A WHMCS module cannot be unit tested without WHMCS: the entry points in
 * dashmox.php take an array WHMCS builds and return strings WHMCS reads. What can
 * be tested is everything underneath them, and that is where the mistakes live -
 * the header a token travels in, which field a refusal is read from, which
 * refusals mean "try again" rather than "this failed", and the order termination
 * happens in.
 *
 * So the transport is injected and these tests never open a socket. Run with:
 *
 *     php tests/client_test.php
 */

require_once __DIR__ . '/../library/Server/Manager/lib/Client.php';
require_once __DIR__ . '/../library/Server/Manager/lib/Panel.php';

use Dashmox\Whmcs\ApiError;
use Dashmox\Whmcs\Client;
use Dashmox\Whmcs\Panel;

$failures = 0;
$ran = 0;

function check($what, $got, $wanted)
{
    global $failures, $ran;
    $ran++;
    if ($got === $wanted) {
        return;
    }
    $failures++;
    echo "FAIL: $what\n";
    echo '  wanted: ' . var_export($wanted, true) . "\n";
    echo '  got:    ' . var_export($got, true) . "\n";
}

function checkThrows($what, callable $run, callable $inspect)
{
    global $failures, $ran;
    $ran++;
    try {
        $run();
    } catch (ApiError $e) {
        if ($inspect($e) === true) {
            return;
        }
        $failures++;
        echo "FAIL: $what\n";
        echo '  the refusal was not what was expected: ' . $e->getStatus() . ' ' . $e->getMessage() . "\n";

        return;
    } catch (\Exception $e) {
        $failures++;
        echo "FAIL: $what (threw " . get_class($e) . ": " . $e->getMessage() . ")\n";

        return;
    }
    $failures++;
    echo "FAIL: $what (nothing was thrown)\n";
}

/**
 * A transport that records what it was asked and answers what it was told to.
 *
 * $answers is bound by reference deliberately. Bound by value, the closure gets
 * the same copy back on every call, so array_shift never advances and every
 * request in a test is answered with the first response. That is not a test that
 * fails, which would be fine: it is a test that passes while the second call
 * reads the first call's body, which is the kind of green that hides things.
 */
function recorder(array $answers, array &$seen)
{
    return function ($method, $url, $body, $headers) use (&$answers, &$seen) {
        $seen[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        $next = array_shift($answers);
        if ($next === null) {
            return ['status' => 500, 'body' => '{"error":"the test ran out of answers"}'];
        }

        return $next;
    };
}

// ---------------------------------------------------------------------------
// The credential travels in the Authorization header, as a bearer token.
// ---------------------------------------------------------------------------
$seen = [];
$client = new Client('https://panel.example.com:8443', 'dashmox_abc123', true, 30,
    recorder([['status' => 200, 'body' => '{"ok":true}']], $seen));
$client->get('/api/v1/server/health');
check('the token is sent as a bearer credential',
    in_array('Authorization: Bearer dashmox_abc123', $seen[0]['headers'], true), true);
check('the base address is not doubled up',
    $seen[0]['url'], 'https://panel.example.com:8443/api/v1/server/health');

// A trailing slash on the address is somebody's typo, not a different panel.
$seen = [];
$client = new Client('https://panel.example.com:8443/', 'dashmox_abc123', true, 30,
    recorder([['status' => 200, 'body' => '{}']], $seen));
$client->get('/api/v1/jobs');
check('a trailing slash in the configured address is absorbed',
    $seen[0]['url'], 'https://panel.example.com:8443/api/v1/jobs');

// ---------------------------------------------------------------------------
// A refusal is read from the field the panel actually writes.
// ---------------------------------------------------------------------------
$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 400, 'body' => '{"error":"confirm must repeat the website\'s primary domain"}'],
], $seen));
checkThrows('the panel\'s own sentence is carried, not a status code',
    function () use ($client) {
        $client->delete('/api/v1/sites/abc', ['confirm' => 'wrong.example.com']);
    },
    function (ApiError $e) {
        return $e->getStatus() === 400
            && $e->getMessage() === "confirm must repeat the website's primary domain";
    });

// ---------------------------------------------------------------------------
// The refusals that are not failures.
// ---------------------------------------------------------------------------
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 409, 'body' => '{"error":"site has an unfinished operation"}'],
], $seen));
checkThrows('a website with queued work is temporary, not failed',
    function () use ($client) {
        $client->delete('/api/v1/sites/abc', ['confirm' => 'a.example.com']);
    },
    function (ApiError $e) {
        return $e->isTemporary() === true;
    });

$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 409, 'body' => '{"error":"website removal is already in progress"}'],
], $seen));
checkThrows('a removal already running is temporary too',
    function () use ($client) {
        $client->delete('/api/v1/sites/abc', ['confirm' => 'a.example.com']);
    },
    function (ApiError $e) {
        return $e->isTemporary() === true;
    });

// And the one that looks similar and is not: this needs somebody to act.
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 409, 'body' => '{"error":"this customer still owns 2 website(s); remove them before removing the customer"}'],
], $seen));
checkThrows('a customer still owning websites is not something to retry',
    function () use ($client) {
        $client->delete('/api/v1/customers/abc', ['confirm' => 'Someone']);
    },
    function (ApiError $e) {
        return $e->isTemporary() === false && $e->getStatus() === 409;
    });

// ---------------------------------------------------------------------------
// An unlicensed installation is not a broken credential.
// ---------------------------------------------------------------------------
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 403, 'body' => '{"error":"API tokens come with a Pro or Business licence, and this installation has none in force; its tokens are kept and work again once a licence is applied"}'],
], $seen));
checkThrows('a lapsed licence is told apart from a bad token',
    function () use ($client) {
        $client->get('/api/v1/sites');
    },
    function (ApiError $e) {
        return $e->isUnlicensed() === true;
    });

$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 403, 'body' => '{"error":"permission denied"}'],
], $seen));
checkThrows('an ordinary refusal is not mistaken for a licence problem',
    function () use ($client) {
        $client->get('/api/v1/sites');
    },
    function (ApiError $e) {
        return $e->isUnlicensed() === false;
    });

// ---------------------------------------------------------------------------
// 202 is done, and says so. A caller that waits on it waits forever.
// ---------------------------------------------------------------------------
$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 202, 'body' => '{"site":{"id":"s1","domain":"a.example.com"},"job":{"id":"j1"}}'],
], $seen));
$answer = $client->post('/api/v1/sites', ['name' => 'a']);
check('the status is returned rather than swallowed', $answer['status'], 202);
check('the body is decoded', $answer['data']['site']['id'], 's1');

// ---------------------------------------------------------------------------
// Something that is not the panel answered.
// ---------------------------------------------------------------------------
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 502, 'body' => '<html><body>Bad Gateway</body></html>'],
], $seen));
checkThrows('a proxy answering instead of the panel says so',
    function () use ($client) {
        $client->get('/api/v1/sites');
    },
    function (ApiError $e) {
        return strpos($e->getMessage(), 'not JSON') !== false;
    });

// ---------------------------------------------------------------------------
// The lifecycle: creating an account is two calls, in an order.
// ---------------------------------------------------------------------------
$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"id":"c1","name":"Someone"}'],
    ['status' => 202, 'body' => '{"site":{"id":"s1"},"job":{"id":"j1"}}'],
], $seen)));
$made = $panel->createAccount(
    ['name' => 'Someone', 'email' => 'someone@example.com', 'whmcs_client_id' => 42],
    ['name' => 'site', 'domain' => 'a.example.com']
);
check('the customer is created first', $seen[0]['url'], 'https://p/api/v1/customers');
check('then the website', $seen[1]['url'], 'https://p/api/v1/sites');
check('the website is given the customer it belongs to',
    json_decode($seen[1]['body'], true)['customer_id'], 'c1');
check('the created ids come back', $made['site']['id'], 's1');

// The panel refuses unknown keys outright, so a billing system's own fields
// must not travel with the customer.
$sent = json_decode($seen[0]['body'], true);
check('fields the panel does not declare are not sent',
    array_key_exists('whmcs_client_id', $sent), false);
check('fields it does declare are', $sent['email'], 'someone@example.com');

// ---------------------------------------------------------------------------
// Nothing measured is not the same as nothing used.
// ---------------------------------------------------------------------------
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"samples":[],"peak_bytes":0,"window":"30d"}'],
], $seen)));
check('a server with no samples yet reports null, not zero',
    $panel->peakBytes('s1'), null);

$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"samples":[{"total_bytes":10},{"total_bytes":90}],"peak_bytes":90,"window":"30d"}'],
], $seen)));
check('the peak of the window is what is reported', $panel->peakBytes('s1'), 90);
check('the window is asked for explicitly',
    strpos($seen[0]['url'], 'window=30d') !== false, true);

// ---------------------------------------------------------------------------
// Termination has an order, and the echo is the panel's name, not WHMCS's.
// ---------------------------------------------------------------------------
$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '[{"id":"c1","name":"Renamed In The Panel"},{"id":"c2","name":"Somebody Else"}]'],
], $seen)));
check('a customer is found by id in the bare list',
    $panel->customer('c1')['name'], 'Renamed In The Panel');

$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '[{"id":"other","name":"Somebody Else"}]'],
], $seen)));
check('a customer already gone reports absence rather than an error',
    $panel->customer('c1'), null);

$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"ok":true}'],
], $seen)));
$panel->deleteCustomer('c1', 'Renamed In The Panel');
check('a customer echoes the name the panel holds',
    json_decode($seen[0]['body'], true)['confirm'], 'Renamed In The Panel');

$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 202, 'body' => '{"id":"j1"}'],
], $seen)));
$panel->deleteSite('s1', 'a.example.com');
check('a website echoes its domain, which is a different value entirely',
    json_decode($seen[0]['body'], true)['confirm'], 'a.example.com');

$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 409, 'body' => '{"error":"this customer still owns 1 website(s); remove them before removing the customer"}'],
], $seen)));
checkThrows('the still-owns refusal carries how many are in the way',
    function () use ($panel) {
        $panel->deleteCustomer('c1', 'Someone');
    },
    function (ApiError $e) {
        return $e->getStatus() === 409
            && strpos($e->getMessage(), 'still owns 1 website(s)') !== false;
    });

// ---------------------------------------------------------------------------
// The quota and the limits answer differently, on purpose.
// ---------------------------------------------------------------------------
$seen = [];
$panel = new Panel(new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"limit_bytes":1073741824}'],
    ['status' => 202, 'body' => '{"id":"j2"}'],
], $seen)));
$panel->setStorageLimit('s1', 1073741824);
check('a disk quota is written straight to the website',
    json_decode($seen[0]['body'], true)['limit_bytes'], 1073741824);
$panel->setLimits('s1', 50, 512, 100);
$sent = json_decode($seen[1]['body'], true);
check('all three limits travel together, because a partial set is refused',
    [$sent['cpu_percent'], $sent['memory_max_mb'], $sent['tasks_max']], [50, 512, 100]);


// ---------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Reading a whole list, not just its first page.
//
// The panel pages lists and sends the cursor for the next page in a header, so
// the body's shape never changes. A client that reads only the body therefore
// sees page one and cannot tell it from the whole thing, and the tool this
// feeds names every website past page one as an orphan, which is a report that
// is worse than no report. The client did read only the body until this test.

$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '[{"id":"s1","primary_domain":"one.example"}]',
        'headers' => ['X-Next-Cursor' => 'page2']],
    ['status' => 200, 'body' => '[{"id":"s2","primary_domain":"two.example"}]',
        'headers' => ['X-Next-Cursor' => 'page3']],
    ['status' => 200, 'body' => '[{"id":"s3","primary_domain":"three.example"}]',
        'headers' => []],
], $seen));
$sites = (new Panel($client))->sites();
check('every page of websites is read', count($sites), 3);
check('the last page is included', $sites[2]['id'], 's3');
check('three requests were made', count($seen), 3);
check('the first asks for no cursor', strpos($seen[0]['url'], 'cursor='), false);
check('the second carries the cursor from the first', strpos($seen[1]['url'], 'cursor=page2') !== false, true);

// A header name is not case sensitive, and a server is free to send
// x-next-cursor. Reading it case sensitively would stop at page one against a
// perfectly correct panel.
$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '[{"id":"s1"}]', 'headers' => ['x-next-cursor' => 'more']],
    ['status' => 200, 'body' => '[{"id":"s2"}]', 'headers' => []],
], $seen));
check('the cursor header is matched whatever its case', count((new Panel($client))->sites()), 2);

// One page and no cursor is one request, not a second that comes back empty.
$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '[{"id":"only"}]', 'headers' => []],
], $seen));
check('a single page stops after one request', count((new Panel($client))->sites()), 1);
check('and makes no second request', count($seen), 1);

// -----------------------------------------------------------------------------
// Transfer, which the README said for months this panel did not measure.

$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 200, 'body' => '{"bytes":12345,"window":"30d"}', 'headers' => []],
], $seen));
check('transfer is read for a window', (new Panel($client))->bandwidth('s1', '30d')['bytes'], 12345);
check('the window is asked for', strpos($seen[0]['url'], 'window=30d') !== false, true);
check('and it is the bandwidth route', strpos($seen[0]['url'], '/sites/s1/bandwidth') !== false, true);

// -----------------------------------------------------------------------------
// A website with no certificate yet is an ordinary state, not a failure.
//
// A summary page that will not draw because a website created a minute ago has
// no certificate is worse than one that says "none yet". Every other refusal is
// still thrown, so this is not a blanket swallow.

$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 404, 'body' => '{"error":"no certificate"}', 'headers' => []],
], $seen));
check('no certificate reads as null', (new Panel($client))->certificate('s1'), null);

$seen = [];
$client = new Client('https://p', 't', true, 30, recorder([
    ['status' => 403, 'body' => '{"error":"not allowed"}', 'headers' => []],
], $seen));
checkThrows(
    'any other refusal about a certificate is still raised',
    function () use ($client) { (new Panel($client))->certificate('s1'); },
    function (ApiError $e) { return $e->getStatus() === 403; }
);

echo "\n$ran checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
