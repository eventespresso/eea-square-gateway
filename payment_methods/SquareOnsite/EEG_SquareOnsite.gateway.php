<?php

use Square\Models\CompletePaymentResponse;
use Square\Models\Money;
use Square\Models\CreatePaymentRequest;
use Square\Models\CreatePaymentResponse;
use Square\SquareClient;
use Square\Exceptions\ApiException;
use Square\Environment;

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
    protected $_application_id = null;

    /**
     * Square Access Token that is used to process payments.
     * @var string
     */
    protected $_access_token = null;

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
     * @throws ApiException
     */
    public function do_direct_payment($payment, $billing_info = [])
    {
        $failedStatus = $this->_pay_model->failed_status();
        $declinedStatus = $this->_pay_model->declined_status();
        $approvedStatus = $this->_pay_model->approved_status();

        // Check the payment.
        if (! $payment instanceof EEI_Payment) {
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
        if (empty($billing_info['eea_square_token'])) {
            $errorMessage = esc_html__(
                'No or incorrect card nonce provided. Card nonce is required to process the transaction.',
                'event_espresso'
            );
            $tokenError = esc_html__('No or incorrect Card Nonce provided !', 'event_espresso');
            return $this->setPaymentStatus($payment, $failedStatus, $tokenError, $errorMessage);
        }

        // Live or in debug mode ?
        $environment = $this->_debug_mode ? Environment::SANDBOX : Environment::PRODUCTION;

        // Charge through Square.
        try {
            $sqClient = new SquareClient([
                'accessToken' => $this->_access_token,
                'environment' => $environment,
            ]);

            // Generate idempotency key if not already set.
            $transId = $transaction->ID();
            $theTransId = (! empty($transId)) ? $transId : uniqid();
            $preNum = substr(number_format(time() * rand(2, 99999), 0, '', ''), 0, 30);
            $keyPrefix = $this->_debug_mode ? 'TEST-payment' : 'event-payment';
            $uniquePaymentKey = $keyPrefix . '-' . $preNum . '-' . $theTransId;
            $idempotencyKey = $keyPrefix . '-' . $theTransId;
            // Save the gateway transaction ID.
            $payment->set_txn_id_chq_nmbr($uniquePaymentKey);

            // Payment amount.
            $sqMoney = new Money();
            $payAmount = $this->convertToSubunits($payment->amount());
            $sqMoney->setAmount($payAmount);
            $sqMoney->setCurrency($payment->currency_code());

            // Create the payment request object.
            $body = new CreatePaymentRequest(
                $billing_info['eea_square_token'],
                $idempotencyKey,
                $sqMoney
            );
            // Setup some payment parameters.
            $body->setAutocomplete(false);
            $body->setReferenceId($uniquePaymentKey);
            $body->setNote($this->_get_gateway_formatter()->formatOrderDescription($payment));
            $paymentsApi = $sqClient->getPaymentsApi();

            // Submit the payment.
            $apiResponse = $paymentsApi->createPayment($body);
            if ($apiResponse->isSuccess()) {
                // A default error message just in case.
                $paymentMgs = esc_html__('Unrecognized Error.', 'event_espresso');
                $result = $apiResponse->getResult();
                // Is the result of the expected type ?
                if (! $result instanceof CreatePaymentResponse) {
                    $resultType = is_object($result) ? get_class($result) : gettype($result);
                    $payment = $this->setPaymentStatus(
                        $payment,
                        $failedStatus,
                        $result,
                        'Unsuitable result. Expecting a CreatePaymentResponse. Result of type - ' . $resultType
                    );
                    return $payment;
                }
                // Get the payment object and check the status.
                $squarePayment = $result->getPayment();
                $paymentStatus = $squarePayment->getStatus();
                if ($paymentStatus === 'COMPLETED') {
                    // Try getting the paid amount.
                    $paidMoney = $squarePayment->getAmountMoney();
                    $paidAmount = $paidMoney instanceof Money ? $paidMoney->getAmount() : false;
                    $payment = $this->setPaymentStatus($payment, $approvedStatus, $squarePayment, '', $paidAmount);
                    // Return as the payment is COMPLETE.
                    return $payment;
                } elseif ($paymentStatus === 'APPROVED') {
                    // Huh, this should have auto completed... Ok, try to complete the payment.
                    $completeResponse = $paymentsApi->completePayment($squarePayment->getId());
                    if ($completeResponse->isSuccess()) {
                        $completePaymentResult = $completeResponse->getResult();
                        // Make sure the result of the expected type.
                        if ($completePaymentResult instanceof CompletePaymentResponse) {
                            // Try getting the paid amount.
                            $squareCompletePayment = $completePaymentResult->getPayment();
                            $completePaidMoney = $squareCompletePayment->getAmountMoney();
                            $paidAmount = $completePaidMoney instanceof Money ? $completePaidMoney->getAmount() : false;
                            $payment = $this->setPaymentStatus(
                                $payment,
                                $approvedStatus,
                                $squareCompletePayment,
                                '',
                                $paidAmount
                            );
                            // Return as the payment is COMPLETE.
                            return $payment;
                        }
                    } else {
                        $paymentMgs = $apiResponse->getErrors()[0]->getDetail();
                    }
                }
                $payment = $this->setPaymentStatus(
                    $payment,
                    $declinedStatus,
                    $squarePayment,
                    $paymentMgs
                );
            } else {
                $errors = $apiResponse->getErrors();
                $payment = $this->setPaymentStatus($payment, $declinedStatus, $errors, $errors[0]->getDetail());
            }
        } catch (ApiException $exception) {
            $payment = $this->setPaymentStatus($payment, $failedStatus, $exception, $exception->getMessage());
        }

        return $payment;
    }


    /**
     * Set a payment status and log the data. Universal for success and failed payments.
     *
     * @param EEI_Payment  $payment
     * @param string       $status
     * @param array|object $responseData
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
        return $amount * pow(10, EE_PMT_SquareOnsite::getDecimalPlaces());
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
}