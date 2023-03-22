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
    protected \GuzzleHttp\ClientInterface $guzzleHttpClient;
    protected \GuzzleHttp\Cookie\CookieJarInterface $httpCookieJar;
    protected string $clientMasteringNumber = '2018B29';
    protected string $clientVersion = '2.1';
    protected array $headersDefault = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Origin' => 'https://www.icloud.com',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:103.0) Gecko/20100101 Firefox/103.0',
    ];
    protected ?string $prefToken;
    protected array $state;
    protected ?string $syncToken;

    public function __construct(Session $session) {
        $this->state = $session->getState();
        $this->httpCookieJar = $session->getHttpCookieJar();
        $this->guzzleHttpClient = new \GuzzleHttp\Client([
            'cookies' => $this->httpCookieJar,
        ]);
    }

    /**
     * @throws \BadMethodsCallException If not signed in
     * @throws \BadMethodsCallException User cannot access contacts service
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     */
    public function list(): array
    {
        if (!($this->state['accountInfo'] ?? null)) {
            throw new \BadMethodCallException('Invalid state');
        }

        $webservice = $this->state['accountInfo']['webservices']['contacts'];
        $baseURL = $webservice['url'] ?? null;
        $status = $webservice['status'] ?? null;
        if (!$baseURL || $status !== 'active') {
            throw new \BadMethodCallException('Cannot access contacts service');
        }

        $response = $this->guzzleHttpClient->request(
            method: 'GET',
            uri: $baseURL . '/co/startup',
            options: [
                'cookies' => $this->httpCookieJar,
                'headers' => $this->headersDefault,
                'query' => [
                    'clientBuildNumber' => Session::CLIENT_BUILD_NUMBER,
                    'clientId' => Session::CLIENT_ID,
                    'clientMasteringNumber' => $this->clientMasteringNumber,
                    'clientVersion' => $this->clientVersion,
                    'dsid' => $this->state['accountInfo']['dsInfo']['dsid'],
                    'locale' => 'en_US',
                    'order' => 'first,last',
                ],
            ],
        );

        $body = \GuzzleHttp\Utils::jsonDecode(strval($response->getBody()), true);

        $this->prefToken = $body['prefToken'] ?? null;
        $this->syncToken = $body['syncToken'] ?? null;

        if (!isset($body['contacts'])) {
            throw new Exception\InvalidResponseException($body);
        }
        return $body['contacts'];
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    public function create(array $contacts): true
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
    public function update(array $contacts): true
    {
        return $this->contactRequest($contacts, 'PUT');
    }

    /**
     * @throws \GuzzleHttp\Exception\ClientException HTTP non-200 status code
     * @throws \InvalidArgumentException JSON decoding error
     * @throws \thisispiers\Acloud\Exception\InvalidResponseException Contacts service error
     * @throws \thisispiers\Acloud\Exception\MissingSyncTokenException
     */
    public function delete(array $contacts): true
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
    protected function contactRequest(array $contacts, string $method = ''): true
    {
        if (!$this->syncToken) {
            $this->list();
            if (!$this->syncToken) {
                $e = 'Could not get contact sync token';
                throw new Exception\MissingSyncTokenException($e);
            }
        }

        $webservice = $this->state['accountInfo']['webservices']['contacts'];
        $response = $this->guzzleHttpClient->request(
            method: 'POST',
            uri: $webservice['url'] . '/co/contacts/card/',
            options: [
                'body' => \GuzzleHttp\Utils::jsonDecode([
                    'contacts' => $contacts,
                ], true),
                'cookies' => $this->httpCookieJar,
                'headers' => $this->headersDefault,
                'query' => [
                    // 'clientBuildNumber' => Session::CLIENT_BUILD_NUMBER,
                    'clientId' => Session::CLIENT_ID,
                    // 'clientMasteringNumber' => $this->clientMasteringNumber,
                    'clientVersion' => $this->clientVersion,
                    'dsid' => $this->state['accountInfo']['dsInfo']['dsid'],
                    'method' => $method,
                    'locale' => 'en_US',
                    'order' => 'first,last',
                    'prefToken' => $this->prefToken,
                    'syncToken' => $this->syncToken,
                ],
            ],
        );

        $body = \GuzzleHttp\Utils::jsonDecode(strval($response->getBody()), true);

        if (!empty($body['errorCode'])) {
            throw new Exception\InvalidResponseException($body);
        }

        $this->prefToken = $body['prefToken'] ?? null;
        $this->syncToken = $body['syncToken'] ?? null;

        return true;
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
