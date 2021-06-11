<?php

namespace EventEspresso\Square\api\payment;

use EE_Error;
use EE_Payment;
use EEG_SquareOnsite;
use EventEspresso\Square\api\IdempotencyKey;
use EventEspresso\Square\api\SquareApi;
use ReflectionException;

/**
 * Class PaymentApi
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   $VID:$
 */
class PaymentApi
{

    /**
     * @var SquareApi
     */
    protected $api;

    /**
     * @var EEG_SquareOnsite
     */
    protected $gateway;

    /**
     * @var IdempotencyKey
     */
    protected $idempotency_key;

    /**
     * Square payment token.
     *
     * @var string
     */
    protected $payment_token = '';


    /**
     * @var string
     */
    protected $post_url;

    /**
     * Square verification token.
     *
     * @var string
     */
    protected $verificationToken = '';


    /**
     * CancelOrder constructor.
     *
     * @param EEG_SquareOnsite $gateway
     * @param SquareApi        $api
     * @param string           $payment_token
     * @param string           $verificationToken
     * @param int              $TXN_ID
     */
    public function __construct(
        EEG_SquareOnsite $gateway,
        SquareApi $api,
        string $payment_token,
        string $verificationToken,
        int $TXN_ID
    ) {
        $this->api               = $api;
        $this->gateway           = $gateway;
        $this->payment_token     = $payment_token;
        $this->verificationToken = $verificationToken;
        $this->post_url          = $this->api->apiEndpoint() . 'payments';
        $this->idempotency_key   = new IdempotencyKey($this->api->isSandboxMode(), $TXN_ID);
    }


    /**
     * Create a Square Payment.
     *
     * @param EE_Payment $payment
     * @param string     $order_id
     * @return Object|array
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function createPayment(EE_Payment $payment, string $order_id = '')
    {
        return $this->sendRequest($payment, $this->post_url, $order_id);
    }


    /**
     * Complete the Payment.
     *
     * @param EE_Payment $payment
     * @param int        $payment_id
     * @param string     $order_id
     * @return Object|array
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function completePayment(EE_Payment $payment, int $payment_id, string $order_id = '')
    {
        return $this->sendRequest($payment, "$this->post_url/$payment_id/complete", $order_id);
    }


    /**
     * Make the API request.
     *
     * @param EE_Payment $payment
     * @param string     $requestUrl
     * @param string     $order_id
     * @return Object|array
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function sendRequest(EE_Payment $payment, string $requestUrl, string $order_id)
    {
        // Form a payment.
        $payment_body = $this->buildPaymentBody($payment, $order_id);
        // Submit the payment.
        $response = $this->api->sendRequest($payment_body, $requestUrl);
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
     * @param EE_Payment $payment
     * @param string     $order_id
     * @return array
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function buildPaymentBody(EE_Payment $payment, string $order_id): array
    {
        $keyPrefix = $this->api->isSandboxMode() ? 'TEST-payment' : 'event-payment';
        $TXN_ID    = $payment->transaction()->ID();
        $payment_body = [
            'source_id'       => $this->payment_token,
            'idempotency_key' => $this->idempotency_key->value(),
            'amount_money'    => [
                'amount'   => $this->gateway->convertToSubunits($payment->amount()),
                'currency' => $payment->currency_code(),
            ],
            'location_id'     => $this->api->locationId(),
            'reference_id'    => "$keyPrefix-$TXN_ID",
        ];

        // Is there a verifications token (SCA) ?
        if ($this->verificationToken) {
            $payment_body['verification_token'] = $this->verificationToken;
        }

        $payment_body['note'] = sprintf(
        // translators: %1$s: site name, %2$s: transaction ID.
            esc_html__('Event Espresso - %1$s, Transaction %2$s', 'event_espresso'),
            wp_specialchars_decode(get_bloginfo(), ENT_QUOTES),
            $TXN_ID
        );
        // Do we have an order to associate with this payment.
        if ($order_id) {
            $payment_body['order_id'] = $order_id;
        }
        return $payment_body;
    }
}
