<?php


namespace EventEspresso\Square\api;

use EE_Error;
use EE_Gateway;
use EEI_Payment;
use EE_Transaction;
use ReflectionException;

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
     * @var EEI_Payment The EE Payment for this API request.
     */
    protected $payment;

    /**
     * @var EE_Gateway The EE gateway.
     */
    protected $gateway;

    /**
     * @var EE_Transaction The current transaction that's using this API.
     */
    protected $transaction;

    /**
     * @var int The transaction ID.
     */
    protected $transactionId;

    /**
     * @var int The transaction ID.
     */
    protected $preNumber;

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
     * @param EEI_Payment $payment
     * @param EE_Gateway  $gateway
     * @param bool        $sandboxMode
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function __construct(EEI_Payment $payment, EE_Gateway $gateway, $sandboxMode)
    {
        // Set all the required properties.
        $this->payment = $payment;
        $this->gateway = $gateway;
        $this->sandboxMode = $sandboxMode;
        $this->transaction = $payment->transaction();
        $transId = $this->transaction->ID();
        $this->transactionId = (! empty($transId)) ? $transId : uniqid();
        $this->preNumber = substr(number_format(time() * rand(2, 99999), 0, '', ''), 0, 30);

        // Is this a sandbox request.
        $this->apiEndpoint = $this->sandboxMode
            ? 'https://connect.squareupsandbox.com/v2/'
            : 'https://connect.squareup.com/v2/';
    }


    /**
     * Do an API request.
     *
     * @param array $bodyParameters
     * @param string $postUrl
     * @return Object|string
     */
    public function sendRequest(array $bodyParameters, $postUrl)
    {
        $postParameters = [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'body'        => json_encode($bodyParameters)
        ];
        // Sent the request.
        $requestResult = wp_remote_post($postUrl, $postParameters);
        // Any errors ?
        if (is_wp_error($requestResult)) {
            $errMessage = $requestResult->get_error_messages();
            return sprintf(
                // translators: %1$s: An error message.
                esc_html__('Request error encountered. Message: %1$s.', 'event_espresso'),
                $errMessage
            );
        } elseif (! isset($requestResult['body'])) {
            return esc_html__('No response body provided.', 'event_espresso');
        }

        // Ok, get the response data.
        $apiResponse = json_decode($requestResult['body']);
        if (! $apiResponse) {
            return esc_html__('Unable to read the response body.', 'event_espresso');
        }
        // Check the data for errors.
        if (isset($apiResponse->errors)) {
            $responseErrorMessage = '';
            foreach ($apiResponse->errors as $responseError) {
                $responseErrorMessage .= $responseError->detail;
            }
            return $responseErrorMessage;
        }

        // Ok, the response seems to be just right. Return the data.
        return $apiResponse;
    }


    /**
     * Generate the Idempotency key for the API call.
     *
     * @return string
     */
    public function getIdempotencyKey()
    {
        $keyPrefix = $this->sandboxMode ? 'TEST-payment' : 'event-payment';
        return $keyPrefix . '-' . $this->preNumber() . '-' . $this->transactionId();
    }


    /**
     * Get the payment.
     *
     * @return EEI_Payment
     */
    public function payment()
    {
        return $this->payment;
    }


    /**
     * Get the payment.
     *
     * @return EE_Gateway
     */
    public function gateway()
    {
        return $this->gateway;
    }


    /**
     * Get the transaction.
     *
     * @return EE_Transaction
     */
    public function transaction()
    {
        return $this->transaction;
    }


    /**
     * Get the transactionId.
     *
     * @return int
     */
    public function transactionId()
    {
        return $this->transactionId;
    }


    /**
     * Get the preNumber.
     *
     * @return int
     */
    public function preNumber()
    {
        return $this->preNumber;
    }


    /**
     * Set Square Application ID used in API calls.
     *
     * @param string $applicationId
     * @return string
     */
    public function setApplicationId($applicationId)
    {
        $this->applicationId = $applicationId;
    }


    /**
     * Set Square Access Token that is used to process payments.
     *
     * @param string $accessToken
     * @return string
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }


    /**
     * Set Square use Digital Wallet.
     *
     * @param string $useDwallet
     * @return string
     */
    public function setUseDwallet($useDwallet)
    {
        $this->useDwallet = $useDwallet;
    }


    /**
     * Set Square Application Location ID.
     *
     * @param string $locationId
     * @return string
     */
    public function setLocationId($locationId)
    {
        $this->locationId = $locationId;
    }
}