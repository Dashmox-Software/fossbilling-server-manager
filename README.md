# FOSSBilling Server Manager for Dashmox

Sell hosting from FOSSBilling and let it create, suspend, restore and terminate
accounts on your own Linux server. This is the official FOSSBilling server
manager for [Dashmox](https://dashmox.com), a hosting control panel you run
yourself.

Free to use, and free to read before you install it. Nothing here is obfuscated
or encoded.

## What it does

| FOSSBilling asks for | What the panel does |
| --- | --- |
| `createAccount` | Creates the customer, then their website under it |
| `suspendAccount` and `unsuspendAccount` | Flips the whole customer, which signs out their people and reconfigures their websites |
| `cancelAccount` | Suspends. Cancelling is not deleting |
| `terminateAccount` | Removes every website first, then the customer |
| `changeAccountPackage` | Moves the customer onto another hosting plan you already sell |
| `synchronizeAccount` | Reads the panel's current belief back onto the account |
| `testConnection` | Checks the panel is reachable and the credential works |

**Cancelling suspends rather than deletes, on purpose.** An account that removed
somebody's websites the moment an invoice lapsed would take customers off the air
over a billing dispute. Terminating is the destructive one and says so.

## Requirements

* A Dashmox panel on your own server. See [dashmox.com](https://dashmox.com).
* **FOSSBilling**, with PHP 8.1 or newer.
* **An integration key**, which comes with a Pro or Business licence. Create one
  in the panel under **Server**, then **Integrations**.

Grant the key these permissions: `customers.manage`, `sites.create`,
`sites.view`, `sites.edit`, `sites.delete`, `server.view`. It reaches only the
routes that name a permission it holds, so nothing else is open to it whatever
else you tick.

The key belongs to the installation rather than to a person, so it does not stop
working when the administrator who made it leaves.

## Installing

1. Copy the `library` folder into your FOSSBilling root, so the manager lands at
   `library/Server/Manager/Dashmox.php`.
2. In FOSSBilling go to **System**, then **Hosting plans**, then **Servers**, and
   add a server whose manager is **Dashmox**.
3. Put your panel's address in the hostname field and the integration key in the
   **Access token** field.
4. Save, then use **Test connection**.

Then point a hosting plan at that server and set its name to a hosting plan that
exists on the panel.

## What it cannot do, and why

Five of the thirteen methods FOSSBilling defines have no route behind them on a
Dashmox panel. Each **refuses by name** rather than returning success, because a
method that quietly does nothing tells you a customer's password changed when it
did not.

| Method | Why |
| --- | --- |
| `getLoginUrl` | No one time session is minted, so there is no "log in to your panel" |
| `getResellerLoginUrl` | A licence covers a server, and there is no reseller role |
| `changeAccountPassword` | The route exists and is deliberately closed to integration credentials |
| `changeAccountUsername` | A website's Linux account name is fixed when it is created |
| `changeAccountIp` | The panel allocates addresses itself |

Three fields on a FOSSBilling package also map onto nothing: bandwidth, mailbox
count and database count. The panel meters none of them as per website numbers,
so sending them would be inventing a limit that is not enforced.

## How it behaves when things go wrong

Provisioning is asynchronous. Creating a website answers with the job that builds
it, so the manager records the website and polls its status rather than assuming
the work finished.

Two refusals are not failures and are retried rather than reported: a website
with work still queued, and one already being removed. Both clear on their own.

If the panel's licence lapses, every request for a key is refused and the manager
says so as something for you to fix rather than as a credential problem. Nothing
is deleted, and it works again once a licence is applied.

## Tests

Two suites, neither of which needs FOSSBilling or a network.

```
php tests/client_test.php
php tests/fossbilling_test.php
```

`fossbilling_test.php` covers the manager's own logic: which fields it reads off
a package, how it maps an account, and that the five unsupported methods refuse
rather than silently succeed.

## Support

Found a problem? Open an issue on this repository.

Pro and Business customers get a guaranteed reply through support at
[dashmox.com](https://dashmox.com). Issues here are answered when we can. Pull
requests are read, and not every one will be merged: this manager provisions real
hosting, and a mistake in it suspends somebody's customers.

## Licence

MIT. Use it, change it, ship it inside whatever you are running. See
[LICENSE](LICENSE).

## The other two

The same thing exists for other billing systems, built on the same client:

* [WHMCS provisioning module](https://github.com/Dashmox-Software/whmcs-provisioning-module)
* [Blesta provisioning module](https://github.com/Dashmox-Software/blesta-module)
