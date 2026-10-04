<?php

namespace Dashmox\Whmcs;

/**
 * A refusal from the panel, carrying the status and the sentence the panel sent.
 *
 * The panel answers every refusal as {"error": "..."} with the status that goes
 * with it, so a module never has to read a status code and invent an
 * explanation. Several of those sentences are meant to be shown to whoever is
 * watching: "this customer still owns 2 website(s)" tells an administrator what
 * to do next, and "permission denied" does not.
 */
class ApiError extends \Exception
{
    /** HTTP status the panel answered with. */
    public $status;

    public function __construct($status, $message)
    {
        parent::__construct($message, (int) $status);
        $this->status = (int) $status;
    }

    /**
     * The status the panel answered with.
     *
     * getCode() carries it too, because Exception takes a code, but a caller
     * reading `getStatus()` is obviously reading HTTP rather than whatever a
     * PHP exception code usually means.
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * A refusal that will clear on its own.
     *
     * Provisioning is asynchronous. A website with work still queued refuses to
     * be removed with 409 "site has an unfinished operation", and one already
     * being removed refuses with "website removal is already in progress".
     * Neither is a failure: the job finishes and the same call then works. A
     * module that reports these as errors makes an operator chase something
     * that was never wrong.
     */
    public function isTemporary()
    {
        if ($this->status !== 409) {
            return false;
        }
        return strpos($this->getMessage(), 'unfinished operation') !== false
            || strpos($this->getMessage(), 'removal is already in progress') !== false;
    }

    /**
     * The installation has no paid licence, so no token reaches anything.
     *
     * Worth telling apart from a bad credential, because the fix is completely
     * different and the token itself is fine: the panel keeps it and it works
     * again once a licence is applied.
     */
    public function isUnlicensed()
    {
        return $this->status === 403
            && strpos($this->getMessage(), 'Pro or Business licence') !== false;
    }
}

/**
 * The HTTP half of talking to a Dashmox panel, and nothing else.
 *
 * This knows about requests, JSON and refusals. It knows nothing about WHMCS,
 * hosting accounts or the order things happen in, which is what makes it the
 * part that can be tested: the transport is injectable, so the tests drive it
 * with canned answers and never open a socket. Everything WHMCS-shaped lives in
 * dashmox.php, and everything Dashmox-shaped lives in Panel.php.
 */
class Client
{
    /** @var string Base address with no trailing slash, e.g. https://panel.example.com:8443 */
    private $base;

    /** @var string The server token, sent as a bearer credential. */
    private $token;

    /** @var bool Whether to verify the panel's certificate. */
    private $verify;

    /** @var int Seconds to wait for a reply. */
    private $timeout;

    /** @var callable|null Injected for tests; null means cURL. */
    private $transport;

    public function __construct($base, $token, $verify = true, $timeout = 30, callable $transport = null)
    {
        $this->base = rtrim($base, '/');
        $this->token = $token;
        $this->verify = (bool) $verify;
        $this->timeout = (int) $timeout;
        $this->transport = $transport;
    }

    public function get($path, array $query = [])
    {
        return $this->send('GET', $path, null, $query);
    }

    public function post($path, array $body = null)
    {
        return $this->send('POST', $path, $body);
    }

    public function put($path, array $body = null)
    {
        return $this->send('PUT', $path, $body);
    }

    public function patch($path, array $body = null)
    {
        return $this->send('PATCH', $path, $body);
    }

    public function delete($path, array $body = null)
    {
        return $this->send('DELETE', $path, $body);
    }

    /**
     * One request, and the whole of this client's opinion about what came back.
     *
     * Returns ['status' => int, 'data' => array]. The status is returned rather
     * than swallowed because the panel uses it to say something real: writing a
     * disk quota answers 200 because it is done, while setting CPU and memory
     * limits answers 202 because the agent does that part afterwards. A caller
     * that waits for a result on the 202 is waiting for something that is not
     * coming back on that request.
     *
     * @throws ApiError on any status of 400 or more.
     */
    private function send($method, $path, array $body = null, array $query = [])
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $encoded = null;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $encoded = json_encode($body);
            if ($encoded === false) {
                throw new ApiError(0, 'the request could not be encoded as JSON');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $answer = $this->transport
            ? call_user_func($this->transport, $method, $url, $encoded, $headers)
            : $this->curl($method, $url, $encoded, $headers);

        $status = isset($answer['status']) ? (int) $answer['status'] : 0;
        $raw = isset($answer['body']) ? $answer['body'] : '';

        $data = [];
        if ($raw !== '' && $raw !== null) {
            $decoded = json_decode($raw, true);
            // A panel answers JSON. Anything else means something in front of it
            // replied instead (a proxy, a captive portal, an error page) and
            // saying so is more use than "unexpected error".
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($status >= 400) {
                    throw new ApiError($status, 'the panel answered ' . $status . ' with something that is not JSON');
                }
                throw new ApiError($status, 'the panel answered with something that is not JSON');
            }
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($status >= 400) {
            $message = isset($data['error']) && is_string($data['error'])
                ? $data['error']
                : 'the panel refused the request with status ' . $status;
            throw new ApiError($status, $message);
        }

        // Headers as well as the body. Lists page with the cursor for the next,
        // older page in X-Next-Cursor rather than in the body, so the response
        // shape never changes, which means a client that reads only the body
        // can see the first page and believe it is the whole thing. Additive:
        // everything already here reads 'status' and 'data'.
        $headers = [];
        if (isset($answer['headers']) && is_array($answer['headers'])) {
            foreach ($answer['headers'] as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        }

        return ['status' => $status, 'data' => $data, 'headers' => $headers];
    }

    /**
     * The default transport.
     *
     * Kept to one small method with no logic in it, because it is the one part
     * of this file the tests cannot reach.
     */
    private function curl($method, $url, $body, array $headers)
    {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $this->verify);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $this->verify ? 2 : 0);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $seen = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$seen) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $seen[trim($parts[0])] = trim($parts[1]);
            }

            return strlen($line);
        });
        $raw = curl_exec($handle);
        if ($raw === false) {
            $reason = curl_error($handle);
            curl_close($handle);
            throw new ApiError(0, 'could not reach the panel: ' . $reason);
        }
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $raw, 'headers' => $seen];
    }
}
