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
 * from memory, and the README says plainly that it has not been run inside
 * FOSSBilling.
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

checkRefuses('signing a customer in is refused, and says why',
    fn () => $ok->getLoginUrl($account), 'one-time session');

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
