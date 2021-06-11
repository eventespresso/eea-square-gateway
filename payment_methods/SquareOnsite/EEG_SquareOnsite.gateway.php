<?php

use EventEspresso\Square\api\order\CreateOrder;
use EventEspresso\Square\api\order\PaymentApi;
use EventEspresso\Square\api\SquareApi;

/**
 * Class EEG_SquareOnsite
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EEG_SquareOnsite extends EE_Onsite_Gateway
{

    /**
     * Square Application ID used in API calls.
     * @var string
     */
    protected $_application_id = '';

    /**
     * Square Access Token that is used to process payments.
     * @var string
     */
    protected $_access_token = '';

    /**
     * Square use Digital Wallet.
     * @var string
     */
    protected $_use_dwallet = '';

    /**
     * Square Application Location ID.
     * @var string
     */
    protected $_location_id = '';

    /**
     * Currencies supported by this gateway.
     * @var array
     */
    protected $_currencies_supported = EE_Gateway::all_currencies_supported;


    /**
     * Process the payment.
     *
     * @param EEI_Payment $payment
     * @param array|null  $billing_info
     * @return EE_Payment|EEI_Payment
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function do_direct_payment($payment, $billing_info = null)
    {
        $failedStatus = $this->_pay_model->failed_status();
        $declinedStatus = $this->_pay_model->declined_status();
        // A default error message just in case.
        $paymentMgs = esc_html__('Unrecognized Error.', 'event_espresso');

        // Check the payment.
        $isValidPayment = $this->isPaymentValid($payment, $billing_info);
        if ($isValidPayment->details() === 'error' && $isValidPayment->status() === $failedStatus) {
            return $isValidPayment;
        }
        $transaction = $payment->transaction();

        // Do we already have an order ID ?
        $orderId = $transaction->get_extra_meta('order_id', true, false);
        if (! $orderId) {
            // Create an Order for this transaction.
            $order_api = $this->getOrderApi($payment);
            $order     = $order_api->sendRequest($payment);
            if (is_array($order) && isset($order['error'])) {
                $errorMessage = (string) $order['error']['message'];
                $orderError = esc_html__('No order created !', 'event_espresso');
                return $this->setPaymentStatus($payment, $failedStatus, $orderError, $errorMessage);
            }
            $orderId = $order->id;
            // Associate the Order with this transaction.
            $transaction->add_extra_meta('order_id', $orderId);
            $transaction->add_extra_meta('order_version', $order->version);
        }

        // Now create the Payment.
        $processedPayment = $this->createAndProcessPayment($payment, $billing_info, $orderId);
        if ($processedPayment instanceof EE_Payment) {
            return $processedPayment;
        }

        // Something went wrong if we got here.
        return $this->setPaymentStatus(
            $payment,
            $declinedStatus,
            esc_html__('Was not able to create a payment. Please contact admin.', 'event_espresso'),
            $paymentMgs
        );
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EE_Payment $payment
     * @return CreateOrder
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function getOrderApi(EE_Payment $payment): CreateOrder
    {
        return new CreateOrder(
            $this,
            $this->getSquareApi(),
            $payment->transaction()->ID(),
            $this->getPromotionsConfig()
        );
    }


    /**
     * Create a Square Payment for the transaction.
     *
     * @param EE_Payment $payment
     * @param array      $billing_info
     * @return Object
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function getPaymentApi(EE_Payment $payment, array $billing_info): PaymentApi
    {
        return new PaymentApi(
            $this,
            $this->getSquareApi(),
            $billing_info['eea_square_token'],
            $billing_info['eea_square_sca'],
            $payment->transaction()->ID()
        );
    }


    /**
     * @return EE_Promotions_Config|null
     */
    private function getPromotionsConfig(): ?EE_Promotions_Config
    {
        return class_exists('EE_Promotions_Config') ? EE_Registry::instance()->CFG->get_config(
            'addons',
            'promotions',
            'EE_Promotions_Config'
        ) : null;
    }


    /**
     * @return SquareApi
     */
    private function getSquareApi(): SquareApi
    {
        return new SquareApi(
            $this->_access_token,
            $this->_application_id,
            $this->_use_dwallet,
            $this->_debug_mode,
            $this->_location_id
        );
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EE_Payment $payment
     * @param array      $billing_info
     * @param string     $orderId
     * @return EE_Payment
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function createAndProcessPayment(EE_Payment $payment, array $billing_info, string $orderId = ''): EE_Payment
    {
        $approvedStatus = $this->_pay_model->approved_status();
        $declinedStatus = $this->_pay_model->declined_status();

        $payment_api = $this->getPaymentApi($payment, $billing_info);
        $payment_response = $payment_api->createPayment($payment, $orderId);

        // If it's a string - it's an error. So pass that message further.
        if (is_array($payment_response) && isset($payment_response['error'])) {
            return $this->setPaymentStatus($payment, $declinedStatus, '', $payment_response['error']['message']);
        }

        $paymentMgs = esc_html__('Unrecognized Error.', 'event_espresso');
        // Get the payment object and check the status.
        if ($payment_response->status === 'COMPLETED') {
            $this->updateEspressoPayment($payment, $payment_response, $approvedStatus);
            // Return as the payment is COMPLETE.
            return $payment;
        }
        if ($payment_response->status === 'APPROVED') {
            // Huh, this should have auto completed... Ok, try to complete the payment.
            // Submit the payment.
            $completeResponse = $payment_api->completePayment($payment, $payment_response->id, $orderId);
            if (is_object($completeResponse) && isset($completeResponse->payment)) {
                $completePayment = $completeResponse->payment;
                if ($completePayment->status === 'COMPLETED') {
                    $this->updateEspressoPayment($payment, $completePayment, $approvedStatus);
                    // Return as the payment is COMPLETE.
                    return $payment;
                }
            }
            $paymentMgs = esc_html__('Unknown error. Please contact admin.', 'event_espresso');
        }

        // Seems like something went wrong if we got here.
        return $this->setPaymentStatus(
            $payment,
            $declinedStatus,
            'Square payment ID: ' . $payment_response->id . ', status: ' . $payment_response->status,
            $paymentMgs
        );
    }


    /**
     * @param EE_Payment $payment
     * @param mixed      $payment_response
     * @param string     $status
     * @throws EE_Error
     * @since   $VID:$
     */
    private function updateEspressoPayment(EE_Payment $payment, $payment_response, string $status)
    {
        $payment = $this->setPaymentStatus(
            $payment,
            $status,
            'Square payment ID: ' . $payment_response->id . ', status: ' . $payment_response->status,
            '',
            $payment_response->amount_money->amount
        );
        // Save card details.
        if (isset($payment_response->card_details)) {
            $this->savePaymentDetails($payment, $payment_response);
        }
        // Save the gateway transaction details.
        $payment->set_extra_accntng('Square Payment ID: ' . $payment_response->id);
    }


    /**
     * Set a payment status and log the data. Universal for success and failed payments.
     *
     * @param EE_Payment   $payment
     * @param string       $status
     * @param array|string $responseData
     * @param string       $errMessage
     * @param int          $paidAmount
     * @return EE_Payment
     * @throws EE_Error
     */
    public function setPaymentStatus(
        EEI_Payment $payment,
        string $status,
        $responseData,
        string $errMessage,
        int $paidAmount = 0
    ): EE_Payment {
        if ($status === $this->_pay_model->approved_status()) {
            $paymentMsg = esc_html__('Success', 'event_espresso');
            $defaultLogText = 'Square payment:';
        } else {
            $paymentMsg = $errMessage;
            $defaultLogText = 'Square error:';
        }
        $this->log([$defaultLogText => $responseData], $payment);
        $payment->set_status($status);
        $payment->set_details('error');
        $payment->set_gateway_response($paymentMsg);
        if ($paidAmount) {
            $payment->set_amount($this->convertToFloat($paidAmount));
        }
        return $payment;
    }


    /**
     * Converts an amount into the currency's subunits.
     * Some currencies have no subunits, so leave them in the currency's main units.
     *
     * @param float $amount
     * @return float in the currency's smallest unit (e.g., pennies)
     */
    public function convertToSubunits(float $amount): float
    {
        $decimals = EE_PMT_SquareOnsite::getDecimalPlaces();
        return round($amount * pow(10, $decimals), $decimals);
    }


    /**
     * Converts an amount from currency's subunits to a float (as used by EE).
     *
     * @param $amount
     * @return float
     */
    public function convertToFloat($amount)
    {
        return $amount / pow(10, EE_PMT_SquareOnsite::getDecimalPlaces());
    }


    /**
     * Gets and saves some basic payment details.
     *
     * @param EEI_Payment $eePayment
     * @param stdClass $squarePayment
     * @return void
     */
    public function savePaymentDetails(EEI_Payment $eePayment, stdClass $squarePayment)
    {
        // Save payment ID.
        $eePayment->set_txn_id_chq_nmbr($squarePayment->id);
        // Save card details.
        $cardUsed = $squarePayment->card_details->card;
        $eePayment->set_details([
            'card_brand' => $cardUsed->card_brand,
            'last_4' => $cardUsed->last_4,
            'exp_month' => $cardUsed->exp_month,
            'exp_year' => $cardUsed->exp_year,
            'card_type' => $cardUsed->card_type,
        ]);
    }


    /**
     * Validates the payment.
     *
     * @param mixed $payment
     * @param array $billingInfo
     * @return EE_Payment
     * @throws EE_Error
     */
    public function isPaymentValid($payment, array $billingInfo): EE_Payment
    {
        $failedStatus = $this->_pay_model->failed_status();
        if (! $payment instanceof EE_Payment) {
            $payment = EE_Payment::new_instance();
            $errorMessage = esc_html__('Error. No associated payment was found.', 'event_espresso');
            return $this->setPaymentStatus($payment, $failedStatus, $errorMessage, $errorMessage);
        }
        // Check the transaction.
        $transaction = $payment->transaction();
        if (! $transaction instanceof EE_Transaction) {
            $errorMessage = esc_html__(
                'Could not process this payment because it has no associated transaction.',
                'event_espresso'
            );
            return $this->setPaymentStatus($payment, $failedStatus, $errorMessage, $errorMessage);
        }
        // Check for the payment nonce.
        if (empty($billingInfo['eea_square_token'])) {
            $errorMessage = esc_html__(
                'No or incorrect card nonce provided. Card nonce is required to process the transaction.',
                'event_espresso'
            );
            $tokenError = esc_html__('No or incorrect Card Nonce provided !', 'event_espresso');
            return $this->setPaymentStatus($payment, $failedStatus, $tokenError, $errorMessage);
        }
        // All looks good.
        return $payment;
    }
}
