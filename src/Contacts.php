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

class Contacts
{
    const CLIENT_ID = 'ebff7ce8-f15f-4a19-8cfd-9fbcb3b21cb4';
    const CLIENT_BUILD_NUMBER = '2522Project44';
    const CLIENT_MASTERING_NUMBER = '2522B24';
    const CLIENT_VERSION = '2.1';

    protected array $headersDefault = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Origin' => 'https://www.icloud.com',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:103.0) Gecko/20100101 Firefox/103.0',
    ];
    protected array $sessionState = [];
    protected ?string $prefToken = null;
    protected ?string $syncToken = null;

    public function __construct(
        protected Session $session,
    ) {
        $this->sessionState = $this->session->getState();

        $prefToken = $this->session->httpCookieJar->getCookieByName('prefToken');
        if ($prefToken !== null) {
            $this->prefToken = $prefToken->getValue();
        }
        $syncToken = $this->session->httpCookieJar->getCookieByName('syncToken');
        if ($syncToken !== null) {
            $this->syncToken = $syncToken->getValue();
        }
    }

    protected function setTokenCookies(mixed $prefToken, mixed $syncToken): void
    {
        $this->prefToken = is_string($prefToken) ? $prefToken : null;
        $this->syncToken = is_string($syncToken) ? $syncToken : null;

        $prefToken = new \GuzzleHttp\Cookie\SetCookie([
            'Name' => 'prefToken',
            'Value' => $this->prefToken,
            'Domain' => '.icloud.com',
            'Path' => '/',
            'MaxAge' => 300,
            'Secure' => true,
            'Discard' => true,
            'HttpOnly' => true,
        ]);
        $this->session->httpCookieJar->setCookie($prefToken);
        $syncToken = new \GuzzleHttp\Cookie\SetCookie([
            'Name' => 'syncToken',
            'Value' => $this->syncToken,
            'Domain' => '.icloud.com',
            'Path' => '/',
            'MaxAge' => 300,
            'Secure' => true,
            'Discard' => true,
            'HttpOnly' => true,
        ]);
        $this->session->httpCookieJar->setCookie($syncToken);
        $this->session->saveState();
    }

    /**
     * @throws \BadMethodsCallException If not signed in
     * @throws \BadMethodsCallException User cannot access contacts service
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     */
    public function all(): array
    {
        if (!($this->sessionState['accountInfo'] ?? null)) {
            throw new \BadMethodCallException('Invalid state');
        }

        $webservice = $this->sessionState['accountInfo']['webservices']['contacts'];
        $baseURL = $webservice['url'] ?? null;
        $status = $webservice['status'] ?? null;
        if (!$baseURL || $status !== 'active') {
            throw new \BadMethodCallException('Cannot access contacts service');
        }

        $response = $this->session->guzzleHttpClient->request(
            method: 'GET',
            uri: $baseURL . '/co/startup',
            options: [
                'cookies' => $this->session->httpCookieJar,
                'headers' => $this->headersDefault,
                'query' => [
                    'clientBuildNumber' => self::CLIENT_BUILD_NUMBER,
                    'clientId' => self::CLIENT_ID,
                    'clientMasteringNumber' => self::CLIENT_MASTERING_NUMBER,
                    'clientVersion' => self::CLIENT_VERSION,
                    'dsid' => $this->sessionState['accountInfo']['dsInfo']['dsid'],
                    'locale' => 'en_GB',
                    'order' => 'last,first',
                ],
            ],
        );

        $body = \GuzzleHttp\Utils::jsonDecode(strval($response->getBody()), true);

        $this->setTokenCookies($body['prefToken'] ?? null, $body['syncToken'] ?? null);

        if (!is_array($body['contacts'] ?? null)) {
            throw new Exception\InvalidResponseException($body);
        }
        return [
            'groups' => is_array($body['groups'] ?? null) ? $body['groups'] : [],
            'contacts' => $body['contacts'],
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    public function create(array $contacts): array
    {
        foreach ($contacts as $c => $contact) {
            if (empty($contact['contactId'])) {
                $contacts[$c]['contactId'] = $this->newId();
            }
        }
        return $this->contactRequest($contacts, '');
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    public function update(array $contacts): array
    {
        return $this->contactRequest($contacts, 'PUT');
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    public function delete(array $contacts): array
    {
        $_contacts = [];
        foreach ($contacts as $contact) {
            if (!empty($contact['contactId']) && !empty($contact['etag'])) {
                $_contacts[] = [
                    'contactId' => $contact['contactId'],
                    'etag' => $contact['etag'],
                ];
            }
        }
        return $this->contactRequest($_contacts, 'DELETE');
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    protected function contactRequest(array $contacts, string $method = ''): array
    {
        if (!$this->syncToken) {
            $this->all();
            if (!$this->syncToken) {
                $e = 'Could not get contact sync token';
                throw new Exception\MissingSyncTokenException($e);
            }
        }

        $webservice = $this->sessionState['accountInfo']['webservices']['contacts'];
        $response = $this->session->guzzleHttpClient->request(
            method: 'POST',
            uri: $webservice['url'] . '/co/contacts/card/',
            options: [
                'body' => \GuzzleHttp\Utils::jsonEncode([
                    'contacts' => $contacts,
                ], true),
                'cookies' => $this->session->httpCookieJar,
                'headers' => $this->headersDefault,
                'query' => [
                    'clientBuildNumber' => self::CLIENT_BUILD_NUMBER,
                    'clientId' => self::CLIENT_ID,
                    'clientMasteringNumber' => self::CLIENT_MASTERING_NUMBER,
                    'clientVersion' => self::CLIENT_VERSION,
                    'dsid' => $this->sessionState['accountInfo']['dsInfo']['dsid'],
                    'method' => $method,
                    'locale' => 'en_GB',
                    'order' => 'last,first',
                    'prefToken' => $this->prefToken,
                    'syncToken' => $this->syncToken,
                ],
            ],
        );

        $body = \GuzzleHttp\Utils::jsonDecode(strval($response->getBody()), true);

        if (!empty($body['errorCode'])) {
            throw new Exception\InvalidResponseException($body);
        }

        $this->setTokenCookies($body['prefToken'] ?? null, $body['syncToken'] ?? null);

        return $body;
    }

    public function updateGroups(array $groups): array
    {
        if (!$this->syncToken) {
            $this->all();
            if (!$this->syncToken) {
                $e = 'Could not get contact sync token';
                throw new Exception\MissingSyncTokenException($e);
            }
        }

        $webservice = $this->sessionState['accountInfo']['webservices']['contacts'];
        $response = $this->session->guzzleHttpClient->request(
            method: 'POST',
            uri: $webservice['url'] . '/co/groups/card/',
            options: [
                'body' => \GuzzleHttp\Utils::jsonEncode([
                    'groups' => $groups,
                ], true),
                'cookies' => $this->session->httpCookieJar,
                'headers' => $this->headersDefault,
                'query' => [
                    'clientBuildNumber' => self::CLIENT_BUILD_NUMBER,
                    'clientId' => self::CLIENT_ID,
                    'clientMasteringNumber' => self::CLIENT_MASTERING_NUMBER,
                    'clientVersion' => self::CLIENT_VERSION,
                    'dsid' => $this->sessionState['accountInfo']['dsInfo']['dsid'],
                    'method' => 'PUT',
                    'locale' => 'en_GB',
                    'order' => 'last,first',
                    'prefToken' => $this->prefToken,
                    'syncToken' => $this->syncToken,
                ],
            ],
        );

        $body = \GuzzleHttp\Utils::jsonDecode(strval($response->getBody()), true);

        if (!empty($body['errorCode'])) {
            throw new Exception\InvalidResponseException($body);
        }

        $this->setTokenCookies($body['prefToken'] ?? null, $body['syncToken'] ?? null);

        return is_array($body['groups'] ?? null) ? $body['groups'] : [];
    }

    protected function newId(): string
    {
        $structure = [8, 4, 4, 4, 12];
        $chars = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C', 'D', 'E', 'F'];
        $id = [];
        foreach ($structure as $part) {
            $partStr = '';
            for ($i = 0; $i < $part; $i++) {
                $c = array_rand($chars, 1);
                $partStr .= $chars[$c];
            }
            $id[] = $partStr;
        }
        return implode('-', $id);
    }
}
