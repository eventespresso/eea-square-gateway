<?php

use Square\Models\CompletePaymentResponse;
use Square\Models\Money;
use Square\Models\CreatePaymentRequest;
use Square\Models\CreatePaymentResponse;
use Square\Models\Payment;
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
            $idempotencyKey = $keyPrefix . '-' . $preNum . '-' . $theTransId;
            $referenceId = $keyPrefix . '-' . $theTransId;
            // Save the gateway transaction details.
            $payment->set_extra_accntng('Reference Id: ' . $referenceId . ' Idempotency Key: ' . $idempotencyKey);

            // Payment amount.
            $sqMoney = $this->amountToMoney($payment->amount(), $payment);

            // Create an Order for this transaction.
            $order = $this->createAnOrder($payment, $transaction, $theTransId);
            if (is_string($order)) {
                $errorMessage = (string) $order;
                $orderError = esc_html__('No order created !', 'event_espresso');
                return $this->setPaymentStatus($payment, $failedStatus, $orderError, $errorMessage);
            }

            // Create the payment request object.
            $body = new CreatePaymentRequest(
                $billing_info['eea_square_token'],
                $idempotencyKey,
                $sqMoney
            );
            // Setup some payment parameters.
            $body->setAutocomplete(false);
            $body->setOrderId($order->id);
            $body->setReferenceId($referenceId);
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
                        '',
                        'Unsuitable result. Expecting a CreatePaymentResponse. Result of type - ' . $resultType
                    );
                    return $payment;
                }
                // Get the payment object and check the status.
                $squarePayment = $result->getPayment();
                $paymentStatus = $squarePayment->getStatus();
                if ($paymentStatus === 'COMPLETED') {
                    $paidAmount = $this->getSquareAmount($squarePayment);
                    $payment = $this->setPaymentStatus(
                        $payment,
                        $approvedStatus,
                        'Square payment ID: ' . $squarePayment->getId() . ', status: ' . $squarePayment->getStatus(),
                        '',
                        $paidAmount
                    );
                    // Save card details.
                    $this->savePaymentDetails($payment, $squarePayment);
                    // Return as the payment is COMPLETE.
                    return $payment;
                } elseif ($paymentStatus === 'APPROVED') {
                    // Huh, this should have auto completed... Ok, try to complete the payment.
                    $completeResponse = $paymentsApi->completePayment($squarePayment->getId());
                    if ($completeResponse->isSuccess()) {
                        $completePaymentResult = $completeResponse->getResult();
                        // Make sure the result of the expected type.
                        if ($completePaymentResult instanceof CompletePaymentResponse) {
                            $squareCompletePayment = $completePaymentResult->getPayment();
                            $paidAmount = $this->getSquareAmount($squareCompletePayment);
                            $payment = $this->setPaymentStatus(
                                $payment,
                                $approvedStatus,
                                'Square payment ID: ' . $squareCompletePayment->getId()
                                    . ', status: ' . $squareCompletePayment->getStatus(),
                                '',
                                $paidAmount
                            );
                            // Save card details.
                            $this->savePaymentDetails($payment, $squareCompletePayment);
                            // Return as the payment is COMPLETE.
                            return $payment;
                        }
                    } else {
                        $gotErrors = $completeResponse->getErrors();
                        $paymentMgs = ! empty($gotErrors)
                            ? $gotErrors[0]->getDetail()
                            : 'Unknown error. Please contact admin.';
                    }
                }
                $payment = $this->setPaymentStatus(
                    $payment,
                    $declinedStatus,
                    'Square payment ID: ' . $squarePayment->getId() . ', status: ' . $squarePayment->getStatus(),
                    $paymentMgs
                );
            } else {
                $errors = $apiResponse->getErrors();
                $payment = $this->setPaymentStatus($payment, $declinedStatus, $errors, $errors[0]->getDetail());
            }
        } catch (ApiException $exception) {
            $payment = $this->setPaymentStatus(
                $payment,
                $failedStatus,
                $exception->getTraceAsString(),
                $exception->getMessage()
            );
        }

        return $payment;
    }


    /**
     * Create a Square Order for a transaction.
     *
     * @param EEI_Payment     $payment
     * @param EEI_Transaction $transaction
     * @param string          $theTransId
     * @throws ReflectionException
     * @throws EE_Error
     * @return Object|string
     */
    public function createAnOrder(
        EEI_Payment $payment,
        EEI_Transaction $transaction,
        $theTransId
    ) {
        $orderItems = $orderTaxes = $orderDiscounts = $allTaxes = $itemDiscounts = [];
        $gatewayFormatter = $this->_get_gateway_formatter();
        $preNum = substr(number_format(time() * rand(2, 99999), 0, '', ''), 0, 30);
        $keyPrefix = $this->_debug_mode ? 'TEST-order' : 'event-order';
        $idempotencyKey = $keyPrefix . '-' . $preNum . '-' . $theTransId;
        $referenceId = $keyPrefix . '-' . $theTransId;
        $currency = $payment->currency_code();

        $itemNum = $itemizedSum = 0;
        $partialPayment = false;
        $transactionPaid = $transaction->paid();
        $allLineItems = $transaction->total_line_item();
        $taxLineItems = $transaction->tax_items();
        $eventLineItems = $transaction->items_purchased();

        // Is this a partial payment ? If so, add the paid amount as a discount.
        if (EEH_Money::compare_floats($payment->amount(), $transaction->total(), '!=') && $transactionPaid > 0) {
            $partialPayment = true;
            $paidAmount = $this->convertToSubunits(abs($transactionPaid));
            $itemDiscount = [
                'uid'          => 'partial-payment-' . rand(1, 9999),
                'name'         => esc_html__('A previously received payment', 'event_espresso'),
                'amount_money' => [
                    'amount'   => $paidAmount,
                    'currency' => $currency
                ],
                'type'         => 'FIXED_AMOUNT',
                'scope'        => 'LINE_ITEM'
            ];
            $orderDiscounts[] = $itemDiscount;
            // Also form an applicable discount array for separate line items.
            $itemDiscounts[] = [
                'discount_uid' => $itemDiscount['uid']
            ];
        }

        // Promotions Affect Taxes ?
        $promoAffectsTax = true;
        if (class_exists('EE_Promotions_Config')) {
            $promoConfig = EE_Registry::instance()->CFG->get_config('addons', 'promotions', 'EE_Promotions_Config');
            if ($promoConfig instanceof EE_Promotions_Config) {
                $promoAffectsTax = $promoConfig->affects_tax();
            }
        }

        // Add Taxes.
        if ($taxLineItems) {
            foreach ($taxLineItems as $taxItem) {
                if ($taxItem instanceof EE_Line_Item) {
                    if ($promoAffectsTax && ! $partialPayment) {
                        $limeItemTaxPercent = (string) $taxItem->percent();
                        $lineItemTaxUid = 'tax-' . $taxItem->ID() . '-' . $limeItemTaxPercent;
                        $lineItemTax = [
                            'uid'        => $lineItemTaxUid,
                            'name'       => $taxItem->name(),
                            'percentage' => $limeItemTaxPercent,
                            'scope'      => 'LINE_ITEM',
                            'type'       => 'ADDITIVE'
                        ];
                        $orderTaxes[] = $lineItemTax;
                        // Also form an applicable taxes for separate line items.
                        $allTaxes[] = [
                            'tax_uid' => $lineItemTaxUid
                        ];
                    } else {
                        $taxAsLineItem = [
                            'uid'              => 'event-tax-' . $taxItem->OBJ_ID(),
                            'name'             => $taxItem->name(),
                            'quantity'         => '1',
                            'base_price_money' => [
                                'amount'   => $this->convertToSubunits($taxItem->total()),
                                'currency' => $currency
                            ]
                        ];
                        $orderItems[] = $taxAsLineItem;
                        ++$itemNum;
                    }
                }
            }
        }

        // List actual line items and promotions.
        if ($eventLineItems) {
            // First add all promotions.
            foreach ($eventLineItems as $discountItem) {
                if ($discountItem instanceof EE_Line_Item && $discountItem->OBJ_type() === 'Promotion') {
                    $itemDiscountAmount = $this->convertToSubunits(abs($discountItem->total()));
                    $itemDiscount = [
                        'uid'          => 'discount-' . $discountItem->ID(),
                        'name'         => $gatewayFormatter->formatLineItemName($discountItem, $payment),
                        'amount_money' => [
                            'amount'   => $itemDiscountAmount,
                            'currency' => $currency
                        ],
                        'type'         => 'FIXED_AMOUNT',
                        'scope'        => 'LINE_ITEM'
                    ];
                    $orderDiscounts[] = $itemDiscount;
                    // Also form an applicable discount array for separate line items.
                    $itemDiscounts[] = [
                        'discount_uid' => $itemDiscount['uid']
                    ];
                    // Count the total to double check later.
                    $itemizedSum += $discountItem->total();
                }
            }

            // Now add line items and applied promotions.
            foreach ($eventLineItems as $eventItem) {
                if ($eventItem instanceof EE_Line_Item && $eventItem->OBJ_type() !== 'Promotion') {
                    $itemMoney = $this->convertToSubunits($eventItem->unit_price());
                    $orderLineItem = [
                        'uid'               => (string) $eventItem->ID(),
                        'name'              => $gatewayFormatter->formatLineItemName($eventItem, $payment),
                        'quantity'          => (string) $eventItem->quantity(),
                        'base_price_money'  => [
                            'amount'   => $itemMoney,
                            'currency' => $currency
                        ],
                        'applied_discounts' => $itemDiscounts,
                        'applied_taxes'     => $allTaxes
                    ];
                    $orderItems[] = $orderLineItem;
                    // Count the total to double check later.
                    $itemizedSum += $eventItem->total();
                    ++$itemNum;
                }
            }
        }

        // Just in case we were not able to recognize some item, add the difference as an extra line item.
        $itemizedSumDiffFromTxnTotal = round(
            $transaction->total() - $itemizedSum - $allLineItems->get_total_tax(),
            2
        );
        if (EEH_Money::compare_floats($itemizedSumDiffFromTxnTotal, 0, '!=')) {
            $extraItemMoney = $this->convertToSubunits(abs($itemizedSumDiffFromTxnTotal));
            $extraOrderLineItem = [
                'uid'              => $itemNum . '_other',
                'name'             => esc_html__('Other (promotion/surcharge)', 'event_espresso'),
                'quantity'         => '1',
                'base_price_money' => [
                    'amount'   => $extraItemMoney,
                    'currency' => $currency
                ]
            ];
            $orderItems[] = $extraOrderLineItem;
        }

        // Form an order with all the line items and discounts.
        $orderBody = [
            'idempotency_key' => $idempotencyKey,
            'order'           => [
                'reference_id' => $referenceId,
                'location_id'  => $this->_location_id,
                'line_items'   => $orderItems
            ],
        ];

        // Check the taxes and discounts before adding.
        if (! empty($orderTaxes)) {
            $orderBody['order']['taxes'] = $orderTaxes;
        }
        if (! empty($orderDiscounts)) {
            $orderBody['order']['discounts'] = $orderDiscounts;
        }

        // First calculate the order to see if the prices match.
        $calculateResponse = $this->sendRequest($orderBody, true);
        if (! is_string($calculateResponse) && isset($calculateResponse->order)) {
            $calculateOrder = $calculateResponse->order;
            // If order total and event total don't match try adjusting the total money mount.
            // This can occur when Square uses bankers rounding on calculating the order totals.
            $eventMoney = (int) $this->convertToSubunits($payment->amount());
            $orderOffset = (int) $calculateOrder->total_money->amount - $eventMoney;
            // 10 ? - just to be safe.
            if ($orderOffset !== 0 && abs($orderOffset) <= 10) {
                $adjustmentName = esc_html__('Total calculation adjustment', 'event_espresso');
                if ($orderOffset < 0) {
                    // Looks like the end total is lower. Need to add a line item to make order total "right".
                    $offsetOrderLineItem = [
                        'uid'              => 'event-total-adjustment' . rand(1, 999),
                        'name'             => $adjustmentName,
                        'quantity'         => '1',
                        'base_price_money' => [
                            'amount'   => abs($orderOffset),
                            'currency' => $currency
                        ]
                    ];
                    $orderBody['order']['line_items'][] = $offsetOrderLineItem;
                } else {
                    // End total is higher. Need to add a fixed discount.
                    $offsetDiscount = [
                        'uid'          => 'adjustment-' . rand(1, 999),
                        'name'         => $adjustmentName,
                        'amount_money' => [
                            'amount'   => abs($orderOffset),
                            'currency' => $currency
                        ],
                        'type'         => 'FIXED_AMOUNT',
                        'scope'        => 'ORDER'
                    ];
                    $orderBody['order']['discounts'][] = $offsetDiscount;
                }
            }
        }

        // Create Order request.
        $createOrderResponse = $this->sendRequest($orderBody);

        // If it's a string - it's an error. So pass that message further.
        if (is_string($createOrderResponse)) {
            return $createOrderResponse;
        }
        if (! isset($createOrderResponse->order)) {
            return esc_html__('Unexpected error. No order returned in Order create response.', 'event_espresso');
        }
        // Order created ok, return it.
        return $createOrderResponse->order;
    }


    /**
     * Do an API request.
     *
     * @param array $bodyParameters
     * @param bool $calculate
     * @return Object|string
     */
    public function sendRequest(array $bodyParameters, $calculate = false)
    {
        $postParameters = [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'headers'     => [
                'Authorization' => 'Bearer ' . $this->_access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'        => json_encode($bodyParameters)
        ];

        // Is this a sandbox request.
        $postUrl = $this->_debug_mode
            ? 'https://connect.squareupsandbox.com/v2/orders'
            : 'https://connect.squareup.com/v2/orders';
        // Is this a calculate request ?
        if ($calculate) {
            $postUrl .= '/calculate';
        }

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
     * Convert the string payment amount to Square Money object.
     *
     * @param float      $itemPrice
     * @param EEI_Payment $payment
     * @return Money
     */
    public function amountToMoney($itemPrice, EEI_Payment $payment)
    {
        $money = new Money();
        $payAmount = $this->convertToSubunits($itemPrice);
        $money->setAmount($payAmount);
        $money->setCurrency($payment->currency_code());
        return $money;
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
     * Gets the paid amount from a Square payment.
     *
     * @param Payment $payment
     * @return boolean|int
     */
    public function getSquareAmount(Payment $payment)
    {
        // Try getting the paid amount.
        $paidMoney = $payment->getAmountMoney();
        return $paidMoney instanceof Money ? $paidMoney->getAmount() : false;
    }


    /**
     * Gets and saves some basic payment details.
     *
     * @param EEI_Payment $eePayment
     * @param Payment $squarePayment
     * @return void
     */
    public function savePaymentDetails(EEI_Payment $eePayment, Payment $squarePayment)
    {
        // Save payment ID.
        $eePayment->set_txn_id_chq_nmbr($squarePayment->getId());
        // Save card details.
        $cardDetails = $squarePayment->getCardDetails();
        $cardUsed = $cardDetails->getCard();
        $eePayment->set_details([
            'card_brand' => $cardUsed->getCardBrand(),
            'last_4' => $cardUsed->getLast4(),
            'exp_month' => $cardUsed->getExpMonth(),
            'exp_year' => $cardUsed->getExpYear(),
            'cardholder_name' => $cardUsed->getCardholderName(),
        ]);
    }
}
