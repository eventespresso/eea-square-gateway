<?php

use EventEspresso\Square\api\EESquareOrder;
use EventEspresso\Square\api\EESquarePayment;

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
     * @param array       $billing_info
     * @return EE_Payment|EEI_Payment
     * @throws EE_Error
     */
    public function do_direct_payment($payment, $billing_info = [])
    {
        $failedStatus = $this->_pay_model->failed_status();
        $approvedStatus = $this->_pay_model->approved_status();
        $declinedStatus = $this->_pay_model->declined_status();

        // Check the payment.
        $isValidPayment = $this->isPaymentValid($payment, $billing_info);
        if ($isValidPayment->details() === 'error' && $isValidPayment->status() === $failedStatus) {
            return $isValidPayment;
        }
        $transaction = $payment->transaction();
        // A default error message just in case.
        $paymentMgs = esc_html__('Unrecognized Error.', 'event_espresso');

        // Generate idempotency key if not already set.
        $transId = $transaction->ID();
        $theTransId = (! empty($transId)) ? $transId : uniqid();
        $preNum = substr(number_format(time() * rand(2, 99999), 0, '', ''), 0, 30);
        $keyPrefix = $this->_debug_mode ? 'TEST-payment' : 'event-payment';
        $idempotencyKey = $keyPrefix . '-' . $preNum . '-' . $theTransId;
        $referenceId = $keyPrefix . '-' . $theTransId;
        // Save the gateway transaction details.
        $payment->set_extra_accntng('Reference Id: ' . $referenceId . ' Idempotency Key: ' . $idempotencyKey);

        // Create an Order for this transaction.
        $order = $this->createAnOrder($payment);
        if (is_string($order)) {
            $errorMessage = (string) $order;
            $orderError = esc_html__('No order created !', 'event_espresso');
            return $this->setPaymentStatus($payment, $failedStatus, $orderError, $errorMessage);
        }

        // Now create the Payment.
        $processedPayment = $this->createAndProcessPayment($payment, $billing_info, $order);
        if ($processedPayment instanceof EE_Payment && $processedPayment->status() === $approvedStatus) {
            return $processedPayment;
        }

        // Something went wrong if we got here.
        $payment = $this->setPaymentStatus(
            $payment,
            $declinedStatus,
            esc_html__('Was not able to create a payment. Please contact admin.', 'event_espresso'),
            $paymentMgs
        );

        return $payment;
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EEI_Payment  $payment
     * @return Object|string
     */
    public function createAnOrder($payment)
    {
        $ordersApi = new EESquareOrder($payment, $this, $this->_debug_mode);
        $ordersApi->setApplicationId($this->_application_id);
        $ordersApi->setAccessToken($this->_access_token);
        $ordersApi->setUseDwallet($this->_use_dwallet);
        $ordersApi->setLocationId($this->_location_id);
        return $ordersApi->create();
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EEI_Payment  $payment
     * @param array        $billing_info
     * @param Object       $order
     * @return Object|string
     */
    public function createPaymentObject($payment, $billing_info, $order)
    {
        $paymentsApi = new EESquarePayment($payment, $this, $this->_debug_mode);
        $paymentsApi->setApplicationId($this->_application_id);
        $paymentsApi->setAccessToken($this->_access_token);
        $paymentsApi->setUseDwallet($this->_use_dwallet);
        $paymentsApi->setLocationId($this->_location_id);
        $paymentsApi->setsquareToken($billing_info['eea_square_token']);
        if ($order && is_object($order)) {
            $paymentsApi->setOrderId($order->id);
        }
        return $paymentsApi;
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EEI_Payment  $payment
     * @param array        $billing_info
     * @param Object       $order
     * @return Object|string
     */
    public function createAndProcessPayment($payment, $billing_info, $order = null)
    {
        $failedStatus = $this->_pay_model->failed_status();
        $approvedStatus = $this->_pay_model->approved_status();
        $declinedStatus = $this->_pay_model->declined_status();

        // Payment object.
        $squarePayment = $this->createPaymentObject($payment, $billing_info, $order);
        $responsePayment = $squarePayment->create();

        // If it's a string - it's an error. So pass that message further.
        if (is_string($responsePayment)) {
            return $this->setPaymentStatus($payment, $failedStatus, '', $responsePayment);
        }

        $paymentMgs = esc_html__('Unrecognized Error.', 'event_espresso');
        // Get the payment object and check the status.
        if ($responsePayment->status === 'COMPLETED') {
            $paidAmount = $responsePayment->amount_money->amount;
            $payment = $this->setPaymentStatus(
                $payment,
                $approvedStatus,
                'Square payment ID: ' . $responsePayment->id . ', status: ' . $responsePayment->status,
                '',
                $paidAmount
            );
            // Save card details.
            if (isset($responsePayment->card_details)) {
                $this->savePaymentDetails($payment, $responsePayment);
            }
            // Return as the payment is COMPLETE.
            return $payment;
        } elseif ($responsePayment->status === 'APPROVED') {
            // Huh, this should have auto completed... Ok, try to complete the payment.
            // Submit the payment.
            $completeResponse = $squarePayment->complete($responsePayment->id);
            if (is_object($completeResponse) && isset($completeResponse->payment)) {
                $completePayment = $completeResponse->payment;
                if ($completePayment->status === 'COMPLETED') {
                    $paidAmount = $completePayment->amount_money->amount;
                    $payment = $this->setPaymentStatus(
                        $payment,
                        $approvedStatus,
                        'Square payment ID: ' . $completePayment->id . ', status: ' . $completePayment->status,
                        '',
                        $paidAmount
                    );
                    // Save card details.
                    if (isset($responsePayment->card_details)) {
                        $this->savePaymentDetails($payment, $completePayment);
                    }
                    // Return as the payment is COMPLETE.
                    return $payment;
                } else {
                    $paymentMgs = esc_html__('Unknown error. Please contact admin.', 'event_espresso');
                }
            } else {
                $paymentMgs = esc_html__('Unknown error. Please contact admin.', 'event_espresso');
            }
        }

        // Seems like something went wrong if we got here.
        $payment = $this->setPaymentStatus(
            $payment,
            $declinedStatus,
            'Square payment ID: ' . $responsePayment->id . ', status: ' . $responsePayment->status,
            $paymentMgs
        );

        return $payment;
    }


    /**
     * Set a payment status and log the data. Universal for success and failed payments.
     *
     * @param EEI_Payment  $payment
     * @param string       $status
     * @param array|string $responseData
     * @param string       $errMessage
     * @param int          $paidAmount
     * @return EEI_Payment
     */
    public function setPaymentStatus(EEI_Payment $payment, $status, $responseData, $errMessage, $paidAmount = 0)
    {
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
     * @return int in the currency's smallest unit (e.g., pennies)
     */
    public function convertToSubunits($amount)
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
     * @return EEI_Payment
     * @throws EE_Error
     */
    public function isPaymentValid($payment, $billingInfo)
    {
        $failedStatus = $this->_pay_model->failed_status();
        if (! $payment instanceof EEI_Payment) {
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
