<?php

/**
 * What can be tested of the FOSSBilling manager without FOSSBilling.
 *
 * The manager extends Server_Manager and takes Server_Account, Server_Package
 * and Server_Client objects, none of which exist outside a FOSSBilling install.
 * So this declares the smallest stand-ins that carry the getters the manager
 * actually calls, and drives the manager against a fake transport.
 *
 * That is honest about what it proves: the manager's own logic (which fields
 * it reads, what order termination happens in, that unsupported operations
 * refuse rather than silently succeed) and nothing about whether FOSSBilling
 * loads it. The class and method signatures were taken from FOSSBilling's own
 * Server_Manager, Server_Account, Server_Package and Server_Client rather than
 * from memory.
 *
 * It has been run inside FOSSBilling since 2026-11-01: 0.8.9 on a real server,
 * where it is listed as a manager, its connection test passes, and an order
 * placed through it provisioned a website on a real panel. What that found is
 * in the README under Proved on a real install, and no amount of fake transport
 * would have found any of it.
 *
 *     php tests/fossbilling_test.php
 */

// The pieces FOSSBilling would provide. Declared before the manager is loaded,
// because the manager extends Server_Manager at parse time.
class Server_Exception extends Exception
{
    public array $placeholders;

    public function __construct(string $message, array $placeholders = [], int $code = 0)
    {
        // FOSSBilling substitutes these when it shows the message. Doing the
        // same here means a test can assert on what somebody would actually
        // read rather than on a template.
        $shown = $message;
        foreach ($placeholders as $key => $value) {
            $shown = str_replace($key, (string) $value, $shown);
        }
        parent::__construct($shown, $code);
        $this->placeholders = $placeholders;
    }
}

abstract class Server_Manager
{
    protected array $_config = [];

    public function __construct(array $options)
    {
        foreach (['ip', 'host', 'secure', 'username', 'password', 'accesshash', 'port', 'config'] as $key) {
            if (isset($options[$key])) {
                $this->_config[$key] = $options[$key];
            }
        }
        $this->init();
    }

    protected function init()
    {
    }

    public static function getSecretFields(): array
    {
        return [];
    }
}

class Server_Client
{
    private array $v;

    public function __construct(array $v = [])
    {
        $this->v = $v;
    }

    public function getFirstName(): ?string { return $this->v['first'] ?? null; }
    public function getLastName(): ?string { return $this->v['last'] ?? null; }
    public function getCompany(): ?string { return $this->v['company'] ?? null; }
    public function getEmail(): ?string { return $this->v['email'] ?? null; }
    public function getAddress1(): ?string { return $this->v['address1'] ?? null; }
    public function getAddress2(): ?string { return $this->v['address2'] ?? null; }
    public function getCity(): ?string { return $this->v['city'] ?? null; }
    public function getState(): ?string { return $this->v['state'] ?? null; }
    public function getCountry(): ?string { return $this->v['country'] ?? null; }
    public function getZip(): ?string { return $this->v['zip'] ?? null; }
    public function getTelephone(): ?string { return $this->v['phone'] ?? null; }
}

class Server_Package
{
    private ?string $name;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }

    public function getName(): ?string { return $this->name; }
}

class Server_Account
{
    private array $v;

    public function __construct(array $v = [])
    {
        $this->v = $v;
    }

    public function getUsername(): ?string { return $this->v['username'] ?? null; }
    public function getDomain(): ?string { return $this->v['domain'] ?? null; }
    public function getNote(): ?string { return $this->v['note'] ?? null; }
    public function setNote(?string $note): static { $this->v['note'] = $note; return $this; }
    public function getSuspended(): ?bool { return $this->v['suspended'] ?? null; }
    public function setSuspended(bool $s): static { $this->v['suspended'] = $s; return $this; }
    public function getClient(): ?Server_Client { return $this->v['client'] ?? null; }
    public function getPackage(): ?Server_Package { return $this->v['package'] ?? null; }
}

require_once __DIR__ . '/../library/Server/Manager/Dashmox.php';

/**
 * The manager with its network replaced.
 *
 * FOSSBilling builds a manager from a config array and nothing else, so there
 * is nowhere to pass a transport in. The manager leaves a seam for exactly
 * this, and this is what goes through it.
 */
class TestableManager extends Server_Manager_Dashmox
{
    /** @var callable */
    private $fake;

    public function __construct(array $config, callable $fake)
    {
        $this->fake = $fake;
        parent::__construct($config);
    }

    protected function transport(): ?callable
    {
        return $this->fake;
    }
}

/**
 * A transport that records what it was asked and answers what it was told to.
 *
 * $answers is bound by reference deliberately. Bound by value, the closure gets
 * the same copy back on every call, so array_shift never advances and every
 * request is answered with the first response. That is not a test that fails,
 * it is a test that passes while the second call reads the first call's body.
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
    echo "FAIL: $what\n  wanted: " . var_export($wanted, true) . "\n  got:    " . var_export($got, true) . "\n";
}

function checkRefuses($what, callable $run, string $mentions)
{
    global $failures, $ran;
    $ran++;
    try {
        $run();
    } catch (Server_Exception $e) {
        if (stripos($e->getMessage(), $mentions) !== false) {
            return;
        }
        $failures++;
        echo "FAIL: $what\n  refusal did not mention '$mentions': " . $e->getMessage() . "\n";

        return;
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL: $what (threw " . get_class($e) . ": " . $e->getMessage() . ")\n";

        return;
    }
    $failures++;
    echo "FAIL: $what (nothing was thrown)\n";
}

// ---------------------------------------------------------------------------
// Configuration is refused before anything is attempted.
// ---------------------------------------------------------------------------
checkRefuses('a server with no token is refused at configuration',
    function () { new Server_Manager_Dashmox(['host' => 'panel.example.com']); },
    'server token');

checkRefuses('a server with no hostname is refused at configuration',
    function () { new Server_Manager_Dashmox(['accesshash' => 'dashmox_abc']); },
    'hostname');

$ok = new Server_Manager_Dashmox(['host' => 'p.example.com', 'accesshash' => 'dashmox_abc']);
check('a configured server builds', $ok instanceof Server_Manager_Dashmox, true);

// ---------------------------------------------------------------------------
// The token is declared secret, so FOSSBilling does not show it back.
// ---------------------------------------------------------------------------
check('the token is a secret field', Server_Manager_Dashmox::getSecretFields(), ['accesshash']);

$form = Server_Manager_Dashmox::getForm();
check('the form is labelled', $form['label'], 'Dashmox');
$fields = $form['form']['credentials']['fields'];
check('the form asks for one credential', count($fields), 1);
check('and it is the token', $fields[0]['name'], 'accesshash');
check('marked secret', $fields[0]['secret'], true);

// ---------------------------------------------------------------------------
// What a Dashmox panel cannot do is refused by name, not silently accepted.
//
// This is the half that matters most. A manager returning true while doing
// nothing tells a host their customer's password changed when it did not.
// ---------------------------------------------------------------------------
$account = new Server_Account(['domain' => 'a.example.com']);

checkRefuses('an account with no domain is refused, because there is nowhere to go',
    fn () => $ok->getLoginUrl(new Server_Account([])), 'no domain');

// ---------------------------------------------------------------------------
// "Jump to admin" asks the panel to sign the host in, and falls back to the
// sign-in page when it cannot.
//
// Driven through the transport seam, so these never open a socket. Without it
// this would connect to a hostname that does not exist, wait for that to fail,
// and pass on the fallback: green, slow, and proving nothing about the call it
// is here to test.
// ---------------------------------------------------------------------------
$seenSignon = [];
$signingIn = new TestableManager(
    ['host' => 'p.example.com', 'accesshash' => 'dashmox_abc'],
    recorder([
        // idsOf has no recorded note, so it finds the website by domain first.
        ['status' => 200, 'body' => '[{"id":"s7","domain":"a.example.com","customer_id":"c7"}]'],
        ['status' => 201, 'body' => '{"url":"https://p.example.com:8443/api/v1/sign-on?token=abc123","expires_in":90}'],
    ], $seenSignon)
);
check('jumping to the panel returns the one-time sign-on link',
    $signingIn->getLoginUrl($account),
    'https://p.example.com:8443/api/v1/sign-on?token=abc123');
// http, because this fixture's server has no secure flag set, which is what
// the manager reads to choose the scheme.
check('and it asked the website it found, by id',
    $seenSignon[1]['url'], 'http://p.example.com:8443/api/v1/sites/s7/sign-on');
check('and said where the request came from, for the panel audit log',
    strpos((string) $seenSignon[1]['body'], 'FOSSBilling') !== false, true);

// A panel older than the feature answers 404. Most panels will be, for a while.
$seenOld = [];
$oldPanel = new TestableManager(
    ['host' => 'p.example.com', 'accesshash' => 'dashmox_abc'],
    recorder([
        ['status' => 200, 'body' => '[{"id":"s7","domain":"a.example.com","customer_id":"c7"}]'],
        ['status' => 404, 'body' => '{"error":"not found"}'],
    ], $seenOld)
);
check('a panel with no sign-on falls back to the sign-in page on that website',
    $oldPanel->getLoginUrl($account), 'https://a.example.com/dashmox');

// An integration key the operator did not give users.manage answers 403. That
// is the operator's switch for this and theirs to turn on, so it is a
// fallback rather than an error thrown at whoever pressed the button.
$seenNoScope = [];
$noScope = new TestableManager(
    ['host' => 'p.example.com', 'accesshash' => 'dashmox_abc'],
    recorder([
        ['status' => 200, 'body' => '[{"id":"s7","domain":"a.example.com","customer_id":"c7"}]'],
        ['status' => 403, 'body' => '{"error":"this token may not do that"}'],
    ], $seenNoScope)
);
check('a key without users.manage falls back rather than failing',
    $noScope->getLoginUrl($account), 'https://a.example.com/dashmox');

// And a panel that cannot be reached at all. The sign-in page is served by the
// website, so it is still a true answer when the panel's API is down.
$unreachable = new TestableManager(
    ['host' => 'p.example.com', 'accesshash' => 'dashmox_abc'],
    function () { throw new \Dashmox\Whmcs\ApiError(0, 'could not connect'); }
);
check('a panel that cannot be reached still opens the sign-in page',
    $unreachable->getLoginUrl($account), 'https://a.example.com/dashmox');

checkRefuses('reseller sign-in is refused',
    fn () => $ok->getResellerLoginUrl($account), 'reseller');

checkRefuses('password changes are refused, and say it is deliberate',
    fn () => $ok->changeAccountPassword($account, 'hunter2'), 'deliberately closed');

checkRefuses('username changes are refused',
    fn () => $ok->changeAccountUsername($account, 'newname'), 'username changes');

checkRefuses('domain changes are refused',
    fn () => $ok->changeAccountDomain($account, 'b.example.com'), 'primary domain');

checkRefuses('choosing an address is refused',
    fn () => $ok->changeAccountIp($account, '203.0.113.5'), 'allocates that itself');

// ---------------------------------------------------------------------------
// An account the panel knows nothing about is an error somebody can act on,
// not a crash and not a quiet success.
// ---------------------------------------------------------------------------
checkRefuses('suspending an unrecorded account says so',
    fn () => $ok->suspendAccount(new Server_Account([])), 'nothing to change');

checkRefuses('changing the plan of an unrecorded account says so',
    fn () => $ok->changeAccountPackage(new Server_Account([]), new Server_Package('Pro')),
    'cannot be changed');

// Terminating one is not an error: a termination retried after a partial run
// finds nothing left to remove.
check('terminating an account the panel never had is not an error',
    $ok->terminateAccount(new Server_Account([])), true);

// ---------------------------------------------------------------------------
// The identifiers survive a round trip through the note, which is the only
// place FOSSBilling offers to keep them.
// ---------------------------------------------------------------------------
$withIds = new Server_Account(['note' => 'dashmox customer=c1 site=s1']);
$reflect = new ReflectionClass($ok);
$idsOf = $reflect->getMethod('idsOf');
$idsOf->setAccessible(true);
check('the customer id is read back', $idsOf->invoke($ok, $withIds)['customer_id'], 'c1');
check('the website id is read back', $idsOf->invoke($ok, $withIds)['site_id'], 's1');

$noteFor = $reflect->getMethod('noteFor');
$noteFor->setAccessible(true);
$written = $noteFor->invoke($ok, 'c9', 's9');
check('and what is written can be read again',
    $idsOf->invoke($ok, new Server_Account(['note' => $written]))['customer_id'], 'c9');

// A note somebody typed over is not a crash; it reads as no account recorded.
check('a note that is not ours reads as nothing recorded',
    $idsOf->invoke($ok, new Server_Account(['note' => 'renewed by hand']))['customer_id'], '');

echo "\n$ran checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
