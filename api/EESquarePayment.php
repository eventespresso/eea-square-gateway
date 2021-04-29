<?php

namespace EventEspresso\Square\api;

use EE_Error;
use EE_Gateway;
use EE_Payment;
use ReflectionException;

/**
 * Class EESquarePayment
 *
 * Class that handles Square Payment API calls.
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EESquarePayment extends EESquareApiBase
{
    /**
     * Square payment token.
     * @var string
     */
    protected $squareToken = '';

    /**
     * Square order ID.
     * @var string
     */
    protected $orderId = '';


    /**
     *
     * @param EE_Payment $payment
     * @param EE_Gateway $gateway
     * @param bool       $sandboxMode
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function __construct(EE_Payment $payment, EE_Gateway $gateway, bool $sandboxMode)
    {
        // Set all the required properties.
        $this->payment = $payment;
        $this->gateway = $gateway;
        $this->transaction = $payment->transaction();
        $this->sandboxMode = $sandboxMode;
        $transId = $this->transaction->ID();
        $this->transactionId = (! empty($transId)) ? $transId : uniqid();
        $this->preNumber = substr(number_format(time() * rand(2, 99999), 0, '', ''), 0, 30);

        parent::__construct($sandboxMode);
    }


    /**
     * Create a Square Payment.
     *
     * @return Object|array
     */
    public function create()
    {
        return $this->request($this->apiEndpoint . 'payments');
    }


    /**
     * Complete the Payment.
     *
     * @param int $paymentId
     * @return Object|array
     */
    public function complete($paymentId)
    {
        return $this->request($this->apiEndpoint . 'payments/' . $paymentId . '/complete');
    }


    /**
     * Make the API request.
     *
     * @param string $requestUrl
     * @return Object|array
     */
    public function request($requestUrl)
    {
        $keyPrefix = $this->sandboxMode ? 'TEST-payment' : 'event-payment';
        $referenceId = $keyPrefix . '-' . $this->transactionId();

        // Form a payment.
        $paymentBody = [
            'source_id'       => $this->squareToken,
            'idempotency_key' => $this->getIdempotencyKey(),
            'amount_money' => [
                'amount'   => $this->gateway->convertToSubunits($this->payment->amount()),
                'currency' => $this->payment->currency_code()
            ],
            'location_id'     => $this->locationId,
            'reference_id'    => $referenceId,
        ];

        $paymentBody['note'] = sprintf(
            // translators: %1$s: site name, %2$s: transaction ID.
            esc_html__('Event Espresso - %1$s, Transaction %2$s', 'event_espresso'),
            wp_specialchars_decode(get_bloginfo(), ENT_QUOTES),
            $this->transaction->ID()
        );
        // Do we have an order to associate with this payment.
        if ($this->orderId) {
            $paymentBody['order_id'] = $this->orderId;
        }

        // Submit the payment.
        $response = $this->sendRequest($paymentBody, $requestUrl);
        // If it's an array - it's an error. So pass that further.
        if (is_array($response) && isset($response['error'])) {
            return $response;
        }
        if (! isset($response->payment)) {
            $request_error['error']['message'] = esc_html__(
                'Unexpected error. No order returned in Order create response.',
                'event_espresso'
            );
            return $request_error;
        }
        // Payment created ok, return it.
        return $response->payment;
    }


    /**
     * Get Square payment Token.
     *
     * @return string
     */
    public function squareToken()
    {
        return $this->squareToken;
    }


    /**
     * Set Square payment Token.
     *
     * @param string $squareToken
     * @return void
     */
    public function setsquareToken($squareToken)
    {
        $this->squareToken = $squareToken;
    }


    /**
     * Get Square payment Token.
     *
     * @return string
     */
    public function orderId()
    {
        return $this->orderId;
    }


    /**
     * Set Square payment Token.
     *
     * @param string $orderId
     * @return void
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;
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
}
