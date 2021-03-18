<?php

namespace EventEspresso\Square\api;

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
     * Square payment token.
     * @var string
     */
    protected $orderId = '';


    /**
     * Create a Square Payment.
     *
     * @return Object|string
     */
    public function create()
    {
        return $this->request($this->apiEndpoint . 'payments');
    }


    /**
     * Complete the Payment.
     *
     * @param int $paymentId
     * @return Object|string
     */
    public function complete($paymentId)
    {
        return $this->request($this->apiEndpoint . 'payments/' . $paymentId . '/complete');
    }


    /**
     * Make the API request.
     *
     * @param string $requestUrl
     * @return Object|string
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
            'reference_id'    => $referenceId
        ];

        $paymentBody['note'] = sprintf(
            // translators: %1$s: site name; %2$s: payment name.
            esc_html__('Event Registrations from %1$s for %2$s', "event_espresso"),
            wp_specialchars_decode(get_bloginfo(), ENT_QUOTES),
            $this->payment->get_first_event_name()
        );
        // Do we have an order to associate with this payment.
        if ($this->orderId) {
            $paymentBody['order_id'] = $this->orderId;
        }

        // Submit the payment.
        $response = $this->sendRequest($paymentBody, $requestUrl);
        // If it's a string - it's an error. So pass that message further.
        if (is_string($response)) {
            return $response;
        }
        if (! isset($response->payment)) {
            return esc_html__('Unexpected error. No order returned in Order create response.', 'event_espresso');
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
     * @return string
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
     * @return string
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;
    }
}