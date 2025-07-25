<?php

/**
 * To the extent possible under law, https://github.com/thisispiers has waived
 * all copyright and related rights to this software library.
 *
 * Apple, the Apple logo, iCloud, the iCloud logo and other Apple trademarks,
 * service marks, graphics, and logos used in connection with the Service are
 * trademarks or registered trademarks of Apple Inc. in the US and/or other
 * countries. A list of Apple’s trademarks can be found here:
 * https://www.apple.com/legal/trademark/appletmlist.html
 * Other trademarks, service marks, graphics, and logos used in connection with
 * the Service may be the trademarks of their respective owners. You are granted
 * no right or license in any of the aforesaid trademarks, and further agree
 * that you shall not remove, obscure, or alter any proprietary notices
 * (including trademark and copyright notices) that may be affixed to or
 * contained within the Service.
 */

namespace thisispiers\Acloud;

class Session
{
    const CLIENT_ID = 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d';

    public \GuzzleHttp\ClientInterface $guzzleHttpClient;
    public \GuzzleHttp\Cookie\CookieJar $httpCookieJar;
    protected string $endpointAuth = 'https://idmsa.apple.com/appleauth/auth';
    protected string $endpointSetup = 'https://setup.icloud.com/setup/ws/1';
    /** @var array<string, string> */
    protected array $headersDefault = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Origin' => 'https://www.icloud.com',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:103.0) Gecko/20100101 Firefox/103.0',
    ];
    /** @var array<string, string> */
    protected array $headersAuth = [];
    protected string $path = '';
    /** @var array<string, string|array<string, array<mixed>|bool|float|int|object|string|null>> */
    protected array $state = [
        'accountInfo' => [],
        'scnt' => '',
        'sessionId' => '',
        'sessionToken' => '',
        'trustToken' => '',
        'username' => '',
    ];

    public function __construct(
        ?string $path = '',
    ) {
        $this->headersAuth = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Origin' => 'https://idmsa.apple.com',
            'Referer' => 'https://idmsa.apple.com/',
            'User-Agent' => $this->headersDefault['User-Agent'],
            'X-Apple-I-FD-Client-Info' => \GuzzleHttp\Utils::jsonEncode([
                'U' => $this->headersDefault['User-Agent'],
                'L' => 'en-GB',
                'Z' => 'GMT+01:00',
                'V' => '1.1',
                'F' => '',
            ]),
            'X-Apple-OAuth-Client-Id' => self::CLIENT_ID,
            'X-Apple-OAuth-Client-Type' => 'firstPartyAuth',
            'X-Apple-OAuth-Response-Mode' => 'web_message',
            'X-Apple-OAuth-Response-Type' => 'code',
            'X-Apple-Widget-Key' => self::CLIENT_ID,
        ];

        $this->httpCookieJar = new \GuzzleHttp\Cookie\CookieJar();
        $this->guzzleHttpClient = new \GuzzleHttp\Client([
            'cookies' => $this->httpCookieJar,
        ]);

        $this->loadState($path);
    }

    public function getHttpCookieJar(): \GuzzleHttp\Cookie\CookieJarInterface
    {
        return $this->httpCookieJar;
    }

    public function loadState(?string $path = ''): bool
    {
        if ($path !== null && $path !== '') {
            $this->path = trim($path);
        }
        if (is_readable($this->path)) {
            $objs = file_get_contents($this->path);
            $objs = unserialize($objs !== false ? $objs : '');
        } else {
            $objs = null;
        }

        if (!is_array($objs) || !is_array($objs['state'] ?? null)) {
            return false;
        }

        $this->state = [];
        foreach ($objs['state'] as $k => $v) {
            if (!is_string($k)) { continue; }
            if (is_string($v)) {
                $this->state[$k] = $v;
            }
            if (is_array($v)) {
                $__v = [];
                foreach ($v as $_k => $_v) {
                    if (!is_string($_k)) { continue; }
                    if (
                        is_array($_v)
                        || is_bool($_v)
                        || is_float($_v)
                        || is_int($_v)
                        || is_object($_v)
                        || is_string($_v)
                        || is_null($_v)
                    ) {
                        $__v[$_k] = $_v;
                    }
                }
                $this->state[$k] = $__v;
            }
        }

        if (($objs['httpCookieJar'] ?? null) instanceof \GuzzleHttp\Cookie\CookieJar) {
            if (!$this->validateCookies($objs['httpCookieJar'])) {
                // so that $this->isSignedIn() returns false
                unset($this->state['accountInfo']);
            }
            $this->httpCookieJar = $objs['httpCookieJar'];
            $this->guzzleHttpClient = new \GuzzleHttp\Client([
                'cookies' => $this->httpCookieJar,
            ]);
        }

        return true;
    }

    public function saveState(): bool
    {
        if ($this->path === '') {
            return false;
        }

        file_put_contents($this->path, serialize([
            'state' => $this->state,
            'httpCookieJar' => $this->httpCookieJar,
        ]));

        return true;
    }

    /**
     * @return array<string, string|array<string, array<mixed>|bool|float|int|object|string|null>>
     */
    public function getState(): array
    {
        return $this->state;
    }

    public function isSignedIn(): bool
    {
        return is_array($this->state['accountInfo'] ?? null)
            && count($this->state['accountInfo']) > 0;
    }

    protected function validateCookies(
        \GuzzleHttp\Cookie\CookieJar $httpCookieJar,
    ): bool {
        if ($httpCookieJar->getCookieByName('x-apple-webauth-token') === null) {
            return false;
        }

        try {
            $this->guzzleHttpClient->request(
                method: 'POST',
                uri: $this->endpointSetup . '/validate',
                options: [
                    'cookies' => $httpCookieJar,
                    'headers' => $this->headersDefault,
                ],
            );

            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            return false;
        }
    }

    /**
     * Call this method first to create a new session.
     *
     * If a verification code is needed for signing in, this method returns
     * "MFA" or the obfuscated phone number the code was sent to. Retrieve the
     * verification code from the user and pass it to `verifyMFACode()`.
     *
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException
     */
    public function signIn(
        #[\SensitiveParameter]
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): true|string {
        if ($this->isSignedIn()) {
            return true;
        }

        $signInData = [
            'accountName' => $username,
            'password' => $password,
            'trustTokens' => [],
        ];
        if (
            ($this->state['username'] ?? '') === $username
            && ($this->state['trustToken'] ?? '') !== ''
            && is_string($this->state['trustToken'])
        ) {
            $trustToken = $this->state['trustToken'];
            $signInData['trustTokens'][] = $trustToken;
        } else {
            $trustToken = '';
        }

        $this->state['username'] = $username;
        $this->saveState();

        try {
            $signInResponse = $this->guzzleHttpClient->request(
                method: 'POST',
                uri: $this->endpointAuth . '/signin',
                options: [
                    'body' => \GuzzleHttp\Utils::jsonEncode($signInData),
                    'cookies' => $this->httpCookieJar,
                    'headers' => $this->headersAuth,
                    'query' => [
                        'isRememberMeEnabled' => 'true',
                    ],
                ],
            );

            $this->handleSignInResponse($signInResponse);
            $this->getAccountInfo($trustToken);

            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if (
                $e->getResponse()->getStatusCode() === 409
                && $this->handleSignInResponse($e->getResponse())
            ) {
                // send verification code via SMS if no trusted devices
                $accountInfo = $this->getAccountInfo($trustToken, false);
                $dsInfo = $accountInfo['dsInfo'] ?? null;
                $hasTrustedDevice = is_array($dsInfo) && boolval($dsInfo['hasICloudQualifyingDevice'] ?? false);
                if (!$hasTrustedDevice) {
                    $phoneNumber = $this->sendMFACodeSMS();
                    if ($phoneNumber !== false) {
                        return $phoneNumber;
                    }
                }

                return 'MFA';
            } else {
                throw $e;
            }
        }
    }

    protected function handleSignInResponse(
        \Psr\Http\Message\ResponseInterface $response
    ): bool {
        $scnt = $response->getHeader('scnt')[0] ?? '';
        $this->state['scnt'] = $scnt;

        $sessionId = $response->getHeader('x-apple-session-token')[0] ?? '';
        $this->state['sessionId'] = $sessionId;
        $this->state['sessionToken'] = $sessionId;

        $this->saveState();

        return $this->hasSignInResponse();
    }

    protected function hasSignInResponse(): bool
    {
        return $this->httpCookieJar->getCookieByName('aasp') !== null
            && ($this->state['scnt'] ?? '') !== ''
            && ($this->state['sessionId'] ?? '') !== '';
    }

    /**
     * @throws \BadMethodCallException signIn() must be called first
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON encoding error
     * @throws \InvalidArgumentException JSON decoding error
     */
    public function sendMFACodeSMS(): false|string
    {
        if ($this->isSignedIn()) {
            return false;
        }
        if (!$this->hasSignInResponse()) {
            $message = 'Call signIn() before calling sendMFACodeSMS()';
            throw new \BadMethodCallException($message);
        }

        try {
            $phoneResponse = $this->guzzleHttpClient->request(
                method: 'PUT',
                uri: $this->endpointAuth . '/verify/phone',
                options: [
                    'body' => \GuzzleHttp\Utils::jsonEncode([
                        'mode' => 'sms',
                        'phoneNumber' => [
                            'id' => 1,
                        ],
                    ]),
                    'cookies' => $this->httpCookieJar,
                    'headers' => $this->getMFAHeaders(),
                ],
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $body = strval($e->getResponse()?->getBody());
            $verification = \GuzzleHttp\Utils::jsonDecode($body, true);
            if (is_array($verification) && is_array($verification['serviceErrors'] ?? null)) {
                $messages = [];
                foreach ($verification['serviceErrors'] as $serviceError) {
                    if (
                        is_array($serviceError)
                        && is_string($serviceError['message'] ?? null)
                        && $serviceError['message'] !== ''
                    ) {
                        $messages[] = $serviceError['message'];
                    }
                }
                throw new \Exception(implode('. ', $messages));
            } else {
                throw $e;
            }
        }

        $body = strval($phoneResponse->getBody());
        $verification = \GuzzleHttp\Utils::jsonDecode($body, true);
        if (!is_array($verification)) {
            return false;
        }
        $trustedPhoneNumber = $verification['trustedPhoneNumber'] ?? null;
        return $trustedPhoneNumber['numberWithDialCode'] ?? false;
    }

    /**
     * This library requires a trusted device to approve web access. Accounts
     * that have turned off web access or do not have a trusted device will fail
     * to sign in.
     *
     * @throws \BadMethodCallException signIn() must be called first
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException Provided verification code is not 6 digits
     * @throws \InvalidArgumentException JSON encoding error
     * @throws \InvalidArgumentException JSON decoding error
     */
    public function verifyMFACode(string $code): bool
    {
        if ($this->isSignedIn()) {
            return true;
        }
        if (strlen($code) !== 6) {
            $message = 'Provided verification code is not 6 digits';
            throw new \InvalidArgumentException($message);
        }
        if (!$this->hasSignInResponse()) {
            $message = 'Call signIn() before calling verifyMFACode()';
            throw new \BadMethodCallException($message);
        }

        $mfaResponse = $this->guzzleHttpClient->request(
            method: 'POST',
            uri: $this->endpointAuth . '/verify/trusteddevice/securitycode',
            options: [
                'body' => \GuzzleHttp\Utils::jsonEncode([
                    'securityCode' => [
                        'code' => $code,
                    ],
                ]),
                'cookies' => $this->httpCookieJar,
                'headers' => $this->getMFAHeaders(),
            ],
        );
        if ($mfaResponse->getStatusCode() === 204) {
            $tokenTrusted = $this->getTrustToken();
            if (is_string($this->state['trustToken'] ?? null)) {
                $this->getAccountInfo($this->state['trustToken']);
            }

            return $tokenTrusted;
        } else {
            return false;
        }
    }

    /**
     * @throws \BadMethodCallException
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     */
    protected function getTrustToken(): bool
    {
        if (!$this->hasSignInResponse()) {
            $message = 'Call signIn() before calling getTrustToken()';
            throw new \BadMethodCallException($message);
        }
        $trustResponse = $this->guzzleHttpClient->request(
            method: 'GET',
            uri: $this->endpointAuth . '/2sv/trust',
            options: [
                'cookies' => $this->httpCookieJar,
                'headers' => $this->getMFAHeaders(),
            ],
        );
        return $this->handleTrustResponse($trustResponse);
    }

    /**
     * @return array<string, string>
     */
    protected function getMFAHeaders(): array
    {
        return array_merge($this->headersAuth, [
            'scnt' => is_string($v = $this->state['scnt'] ?? '') ? $v : '',
            'X-Apple-ID-Session-Id' => is_string($v = $this->state['sessionId'] ?? '') ? $v : '',
        ]);
    }

    protected function handleTrustResponse(
        \Psr\Http\Message\ResponseInterface $response,
    ): bool {
        $sessionToken = $response->getHeader('x-apple-session-token')[0] ?? '';
        $this->state['sessionToken'] = $sessionToken;

        $trustToken = $response->getHeader('x-apple-twosv-trust-token')[0] ?? '';
        $this->state['trustToken'] = $trustToken;

        $this->saveState();

        return $this->state['sessionToken'] !== ''
            && $this->state['trustToken'] !== '';
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException
     * @return bool|array<mixed>
     */
    protected function getAccountInfo(
        string $trustToken = '',
        bool $saveState = true,
    ): bool|array {
        $response = $this->guzzleHttpClient->request(
            method: 'POST',
            uri: $this->endpointSetup . '/accountLogin',
            options: [
                'body' => \GuzzleHttp\Utils::jsonEncode([
                    'dsWebAuthToken' => $this->state['sessionToken'],
                    'extended_login' => true,
                    'trustToken' => $trustToken,
                ]),
                'cookies' => $this->httpCookieJar,
                'headers' => $this->headersDefault,
            ],
        );
        if (count($response->getHeader('set-cookie')) > 0) {
            $body = strval($response->getBody());
            $accountInfo = \GuzzleHttp\Utils::jsonDecode($body, true);
            if (!is_array($accountInfo)) {
                return false;
            }
            if ($saveState) {
                $this->state['accountInfo'] = $accountInfo;
                $this->saveState();

                return true;
            } else {
                return $accountInfo;
            }
        }

        return false;
    }
}
