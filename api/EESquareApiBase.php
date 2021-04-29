<?php

namespace EventEspresso\Square\api;

use EE_Gateway;
use EE_Payment;

/**
 * Class EESquareApiBase
 *
 * A base class for all Square API components used in this add-on.
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
abstract class EESquareApiBase
{
    /**
     * @var EE_Payment The EE Payment for this API request.
     */
    protected $payment;

    /**
     * @var EE_Gateway The EE gateway.
     */
    protected $gateway;

    /**
     * @var bool Debug mode on or not ?
     */
    protected $sandboxMode;

    /**
     * @var string Square API endpoint.
     */
    protected $apiEndpoint = '';

    /**
     * @var string Square API location ID.
     */
    protected $locationId = '';

    /**
     * Square Application ID used in API calls.
     * @var string
     */
    protected $applicationId = '';

    /**
     * Square Access Token that is used to process payments.
     * @var string
     */
    protected $accessToken = '';

    /**
     * Square use Digital Wallet.
     * @var string
     */
    protected $useDwallet = '';


    /**
     *
     * @param bool $sandboxMode
     */
    public function __construct(bool $sandboxMode)
    {
        $this->sandboxMode = $sandboxMode;
        // Is this a sandbox request.
        $this->apiEndpoint = $this->sandboxMode
            ? 'https://connect.squareupsandbox.com/v2/'
            : 'https://connect.squareup.com/v2/';
    }


    /**
     * Do an API request.
     *
     * @param array  $bodyParameters
     * @param string $postUrl
     * @param string $method
     * @return Object|array
     */
    public function sendRequest(array $bodyParameters, string $postUrl, $method = 'POST')
    {
        $postParameters = [
            'method'      => $method,
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
        ];
        // Add body if this is a POST request.
        if ($method === 'POST') {
            $postParameters['body'] = json_encode($bodyParameters);
        }
        // Sent the request.
        $requestResult = wp_remote_request($postUrl, $postParameters);
        // Any errors ?
        if (is_wp_error($requestResult)) {
            $errMessage = $requestResult->get_error_messages();
            $request_error['error']['message'] = sprintf(
                // translators: %1$s: An error message.
                esc_html__('Request error encountered. Message: %1$s.', 'event_espresso'),
                $errMessage
            );
            $request_error['error']['code'] = $requestResult->get_error_code();
            return $request_error;
        } elseif (! isset($requestResult['body'])) {
            $request_error['error']['message'] = esc_html__('No response body provided.', 'event_espresso');
            $request_error['error']['code'] = 'no_body';
            return $request_error;
        }

        // Ok, get the response data.
        $apiResponse = json_decode($requestResult['body']);
        if (! $apiResponse) {
            $request_error['error']['message'] = esc_html__('Unable to read the response body.', 'event_espresso');
            $request_error['error']['code'] = 'unrecognizable_body';
            return $request_error;
        }
        // Check the data for errors.
        if (isset($apiResponse->errors)) {
            $responseErrorMessage = $responseErrorCode = '';
            $errorCodes = [];
            foreach ($apiResponse->errors as $responseError) {
                $responseErrorMessage .= $responseError->detail;
                $errorCodes[] = $responseError->code;
            }
            if ($errorCodes) {
                $responseErrorCode = implode(',', $errorCodes);
            }
            $request_error['error']['message'] = $responseErrorMessage;
            $request_error['error']['code'] = $responseErrorCode;
            return $request_error;
        }

        // Ok, the response seems to be just right. Return the data.
        return $apiResponse;
    }


    /**
     * Get the payment.
     *
     * @return EE_Payment
     */
    public function payment()
    {
        return $this->payment;
    }


    /**
     * Get the gateway.
     *
     * @return EE_Gateway
     */
    public function gateway()
    {
        return $this->gateway;
    }


    /**
     * Set Square Application ID used in API calls.
     *
     * @param string $applicationId
     * @return void
     */
    public function setApplicationId($applicationId)
    {
        $this->applicationId = $applicationId;
    }


    /**
     * Set Square Access Token that is used to process payments.
     *
     * @param string $accessToken
     * @return void
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }


    /**
     * Set Square use Digital Wallet.
     *
     * @param string $useDwallet
     * @return void
     */
    public function setUseDwallet($useDwallet)
    {
        $this->useDwallet = $useDwallet;
    }


    /**
     * Set Square Application Location ID.
     *
     * @param string $locationId
     * @return void
     */
    public function setLocationId($locationId)
    {
        $this->locationId = $locationId;
    }
}
