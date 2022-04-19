<?php

use EventEspresso\Square\api\customers\CustomersApi;
use EventEspresso\Square\api\order\CreateOrder;
use EventEspresso\Square\api\payment\PaymentApi;
use EventEspresso\Square\api\SquareApi;
use EventEspresso\Square\domain\Domain;

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
     * Square API object.
     * @var SquareApi
     */
    protected $square_api;

    /**
     * Square payment API object.
     * @var PaymentApi
     */
    protected $payment_api;

    /**
     * Square customers API object.
     * @var CustomersApi
     */
    protected $customers_api;


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
        $payment_status['failed']   = $this->_pay_model->failed_status();
        $payment_status['declined'] = $this->_pay_model->declined_status();
        // A default error message just in case.
        $payment_mgs = esc_html__('Unrecognized Error.', 'event_espresso');
        // Check the payment.
        $isValidPayment = $this->isPaymentValid($payment, $billing_info);
        if ($isValidPayment->details() === 'error' && $isValidPayment->status() === $payment_status['failed']) {
            return $isValidPayment;
        }

        $transaction    = $payment->transaction();
        $payment_method = $transaction->payment_method();
        $customer_id    = '';
        // Check if we have the correct permissions before we try to use the API.
        $oauth_permissions = $payment_method->get_extra_meta(Domain::META_KEY_PERMISSIONS, true);
        if ($oauth_permissions) {
            // Customers API:
            if (
                strpos($oauth_permissions, Domain::PERMISSIONS_SCOPE_CUSTOMERS) !== false
                && ! empty($billing_info['consent_box']['consent'])
            ) {
                // Get the Customer ID.
                $customer_id = $this->getCustomerId($transaction, $billing_info);
            }
        }
        // Get the order ID.
        $order_id = $this->getOrderId($payment, $transaction, $payment_status, $customer_id);

        // Now create the Payment.
        $processedPayment = $this->createAndProcessPayment($payment, $billing_info, $order_id);
        if ($processedPayment instanceof EE_Payment) {
            return $processedPayment;
        }

        // Something went wrong if we got here.
        return $this->setPaymentStatus(
            $payment,
            $payment_status['declined'],
            esc_html__('Was not able to create a payment. Please contact admin.', 'event_espresso'),
            $payment_mgs
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
    private function getCreateOrderApi(EE_Payment $payment): CreateOrder
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
     * @param EE_Payment      $payment
     * @param array           $billing_info
     * @return Object
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function getPaymentApi(EE_Payment $payment, array $billing_info): PaymentApi
    {
        $this->payment_api = $this->payment_api ?? new PaymentApi(
            $this,
            $this->getSquareApi(),
            $billing_info,
            $payment->transaction()->ID()
        );
        return $this->payment_api;
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
        $this->square_api = $this->square_api ?? new SquareApi(
            EED_SquareOnsiteOAuth::decryptString($this->_access_token, $this->_debug_mode),
            $this->_application_id,
            $this->_use_dwallet,
            $this->_debug_mode,
            $this->_location_id
        );
        return $this->square_api;
    }


    /**
     * @param array           $billing_info
     * @param EE_Registration $primary_registrant
     * @return CustomersApi
     */
    private function getCustomersApi(array $billing_info, EE_Registration $primary_registrant): CustomersApi
    {
        $this->customers_api = $this->customers_api ?? new CustomersApi(
            $this->getSquareApi(),
            $billing_info,
            $primary_registrant
        );
        return $this->customers_api;
    }


    /**
     * Create a Square Order for the transaction.
     *
     * @param EE_Payment     $payment
     * @param array          $billing_info
     * @param string         $order_id
     * @return EE_Payment
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function createAndProcessPayment(
        EE_Payment $payment,
        array $billing_info,
        string $order_id = ''
    ): EE_Payment {
        $payment_status['approved'] = $this->_pay_model->approved_status();
        $payment_status['declined'] = $this->_pay_model->declined_status();

        $payment_api      = $this->getPaymentApi($payment, $billing_info);
        $payment_response = $payment_api->createPayment($payment, $order_id);

        // If it's an array - it's an error. So pass that message further.
        if (is_array($payment_response) && isset($payment_response['error'])) {
            return $this->setPaymentStatus($payment, $payment_status['declined'], '', $payment_response['error']['message']);
        }

        $payment_mgs = esc_html__('Unrecognized Error.', 'event_espresso');
        // Get the payment object and check the status.
        if ($payment_response->status === 'COMPLETED') {
            $this->updateEspressoPayment($payment, $payment_response, $payment_status['approved']);
            // Return as the payment is COMPLETE.
            return $payment;
        }
        if ($payment_response->status === 'APPROVED') {
            // Huh, this should have auto completed... Ok, try to complete the payment.
            // Submit the payment.
            $complete_response = $payment_api->completePayment($payment, $payment_response->id, $order_id);
            if (is_object($complete_response) && isset($complete_response->payment)) {
                $completePayment = $complete_response->payment;
                if ($completePayment->status === 'COMPLETED') {
                    $this->updateEspressoPayment($payment, $completePayment, $payment_status['approved']);
                    // Return as the payment is COMPLETE.
                    return $payment;
                }
            }
            $payment_mgs = esc_html__('Unknown error. Please contact admin.', 'event_espresso');
        }

        // Seems like something went wrong if we got here.
        return $this->setPaymentStatus(
            $payment,
            $payment_status['declined'],
            'Square payment ID: ' . $payment_response->id . ', status: ' . $payment_response->status,
            $payment_mgs
        );
    }


    /**
     * @param EE_Payment $payment
     * @param mixed      $payment_response
     * @param string     $status
     * @throws EE_Error
     * @since   1.0.0.p
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


    /**
     * Get Square Order ID.
     *
     * @param EEI_Payment     $payment
     * @param EEI_Transaction $transaction
     * @param array           $payment_status
     * @param string          $customer_id
     * @return string
     */
    public function getOrderId(
        EEI_Payment $payment,
        EEI_Transaction $transaction,
        array $payment_status,
        string $customer_id
    ): string {
        // Do we already have an order ID ?
        $order_id = $transaction->get_extra_meta('order_id', true, false);
        if (! $order_id) {
            // Create an Order for this transaction.
            try {
                $create_order_api = $this->getCreateOrderApi($payment);
                $order            = $create_order_api->create($payment, $customer_id);
                if (is_array($order) && isset($order['error'])) {
                    $error_message = (string) $order['error']['message'];
                    $order_error = esc_html__('No order created !', 'event_espresso');
                    return $this->setPaymentStatus($payment, $payment_status['failed'], $order_error, $error_message);
                }
                $order_id = $order->id;
                // Associate the Order with this transaction.
                $transaction->add_extra_meta('order_id', $order_id);
                $transaction->add_extra_meta('order_version', $order->version);
                return $order_id;
            } catch (EE_Error | ReflectionException $e) {
                return '';
            }
        }
        return '';
    }


    /**
     * Get Square Customer ID.
     *
     * @param EEI_Transaction $transaction
     * @param                 $billing_info
     * @return string
     */
    public function getCustomerId(EEI_Transaction $transaction, $billing_info): string
    {
        // Do we already have a Customer ID for this transaction ?
        $customer_id = $transaction->get_extra_meta('customer_id', true, '');
        if (! $customer_id) {
            // Create a customer for this transaction.
            $primary_registrant = $transaction->primary_registration();
            $customers_api      = $this->getCustomersApi($billing_info, $primary_registrant);
            // Search to see if this user already exists as a customer.
            $found_customer     = $customers_api->findByEmail($billing_info['email']);
            if (! $found_customer) {
                $customer = $customers_api->create();
                if (is_object($customer)) {
                    $customer_id = $customer->id;
                }
            } else if (is_array($found_customer) && ! empty($found_customer[0]->id)) {
                // Customer already exists. Need only one.
                $customer_id = $found_customer[0]->id;
            }
        }
        // Associate the Customer with this transaction.
        $transaction->add_extra_meta('customer_id', $customer_id);
        // Just make sure we return a string.
        return $customer_id ?? '';
    }
}
