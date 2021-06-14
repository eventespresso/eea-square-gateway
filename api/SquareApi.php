<?php

namespace EventEspresso\Square\api;

use EventEspresso\Square\domain\Domain;

/**
 * Class SquareApi
 *
 * A base class for all Square API components used in this add-on.
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api
 * @since   $VID:$
 */
class SquareApi
{

    /**
     * Square Access Token that is used to process payments.
     *
     * @var string
     */
    protected $accessToken = '';

    /**
     * @var string Square API endpoint.
     */
    protected $apiEndpoint = '';

    /**
     * Square Application ID used in API calls.
     *
     * @var string
     */
    protected $applicationId = '';

    /**
     * @var string Square API location ID.
     */
    protected $locationId = '';

    /**
     * @var ResponseHandler
     */
    protected $response_handler;

    /**
     * @var bool Debug mode on or not ?
     */
    protected $sandboxMode;

    /**
     * Square use Digital Wallet.
     *
     * @var string
     */
    protected $useDwallet = '';


    /**
     *
     * @param string $accessToken
     * @param string $applicationId
     * @param string $useDwallet
     * @param bool   $sandboxMode
     * @param string $locationId
     */
    public function __construct(
        string $applicationId,
        string $accessToken,
        string $useDwallet,
        bool $sandboxMode = true,
        string $locationId = ''
    ) {
        $this->accessToken   = $accessToken;
        $this->applicationId = $applicationId;
        $this->locationId    = $locationId;
        $this->sandboxMode   = $sandboxMode;
        $this->useDwallet    = $useDwallet;
        // Is this a sandbox request.
        $this->apiEndpoint = $this->sandboxMode
            ? 'https://connect.squareupsandbox.com/v2/'
            : 'https://connect.squareup.com/v2/';
        $this->response_handler = new ResponseHandler();
    }


    /**
     * @return string
     */
    public function apiEndpoint(): string
    {
        return $this->apiEndpoint;
    }


    /**
     * @return string
     */
    public function applicationId(): string
    {
        return $this->applicationId;
    }


    /**
     * @return string
     */
    public function locationId(): string
    {
        return $this->locationId;
    }


    /**
     * @return bool
     */
    public function isSandboxMode(): bool
    {
        return $this->sandboxMode;
    }


    /**
     * @return string
     */
    public function useDwallet(): string
    {
        return $this->useDwallet;
    }


    /**
     * Do an API request.
     *
     * @param array  $bodyParameters
     * @param string $postUrl
     * @param string $method
     * @return Object|array
     */
    public function sendRequest(array $bodyParameters, string $postUrl, string $method = 'POST')
    {
        $postParameters = $this->getPostParameters($bodyParameters, $method);
        // Sent the request.
        $requestResult = wp_remote_request($postUrl, $postParameters);
        // Any errors ?
        $this->response_handler->checkForRequestErrors($requestResult);
        if ($this->response_handler->isInvalid()) {
            return $this->response_handler->errors();
        }
        // Ok, get the response data.
        $apiResponse = json_decode($requestResult['body']);
        // Any errors ?
        $this->response_handler->checkForResponseErrors($apiResponse);
        if ($this->response_handler->isInvalid()) {
            return $this->response_handler->errors();
        }
        // Ok, the response seems to be just right. Return the data.
        return $apiResponse->order();
    }


    /**
     * @param array  $bodyParameters
     * @param string $method
     * @return array
     */
    private function getPostParameters(array $bodyParameters, string $method): array
    {
        $postParameters = [
            'method'      => $method,
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => [
                'Square-Version' => Domain::SQUARE_API_VERSION,
                'Authorization'  => 'Bearer ' . $this->accessToken,
                'Content-Type'   => 'application/json',
            ],
        ];
        // Add body if this is a POST request.
        if ($method === 'POST' || $method === 'PUT') {
            $postParameters['body'] = json_encode($bodyParameters);
        }
        return $postParameters;
    }
}
