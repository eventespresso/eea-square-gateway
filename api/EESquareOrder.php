<?php

namespace EventEspresso\Square\api;

use EE_Error;
use EE_Gateway;
use EE_Line_Item;
use EE_Registry;
use EE_Transaction;
use EEH_Money;
use EE_Payment;
use ReflectionException;

/**
 * Class EESquareOrder
 *
 * Class that handles Square Orders API calls.
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EESquareOrder extends EESquareApiBase
{
    /**
     * @var EE_Transaction The current transaction that's using this API.
     */
    protected $transaction;

    /**
     * @var int The transaction ID.
     */
    protected $transactionId;

    /**
     * @var int A prefix for for the idempotency key.
     */
    protected $preNumber;


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
        // Required properties.
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
     * Create a Square Order.
     *
     * @throws ReflectionException
     * @throws EE_Error
     * @return Object|array
     */
    public function create()
    {
        $payment = $this->payment();
        $gateway = $this->gateway();
        $transaction = $this->transaction();
        $currency = $payment->currency_code();
        $payAmount = $payment->amount();
        $orderItems = $orderTaxes = $orderDiscounts = $allTaxes = $itemDiscounts = [];
        $idempotencyKey = $this->getIdempotencyKey();
        $keyPrefix = $this->sandboxMode ? 'TEST-order' : 'event-order';
        $referenceId = $keyPrefix . '-' . $this->transactionId();

        $itemNum = $itemizedSum = 0;
        $partialPayment = false;
        $transactionPaid = $transaction->paid();
        $allLineItems = $transaction->total_line_item();
        $taxLineItems = $transaction->tax_items();
        $eventLineItems = $transaction->items_purchased();

        // Is this a partial payment ? If so, add the paid amount as a discount.
        if (EEH_Money::compare_floats($payAmount, $transaction->total(), '!=') && $transactionPaid > 0) {
            $partialPayment = true;
            $paidAmount = $gateway->convertToSubunits(abs($transactionPaid));
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
                    // If Taxes are counted before the discounts or this is a Partial payment
                    // - list the Taxes as simple Line Items so that Square doesn't count them by their own.
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
                                'amount'   => $gateway->convertToSubunits($taxItem->total()),
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
                    $itemDiscountAmount = $gateway->convertToSubunits(abs($discountItem->total()));
                    $itemDiscount = [
                        'uid'          => 'discount-' . $discountItem->ID(),
                        'name'         => $discountItem->name(),
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
                    $itemMoney = $gateway->convertToSubunits($eventItem->unit_price());
                    $orderLineItem = [
                        'uid'               => (string) $eventItem->ID(),
                        'name'              => $eventItem->name(),
                        'quantity'          => (string) $eventItem->quantity(),
                        'base_price_money'  => [
                            'amount'   => $itemMoney,
                            'currency' => $currency
                        ],
                        'applied_discounts' => $itemDiscounts,
                    ];
                    if ($eventItem->is_taxable()) {
                        $orderLineItem['applied_taxes'] = $allTaxes;
                    }
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
            $extraItemMoney = $gateway->convertToSubunits(abs($itemizedSumDiffFromTxnTotal));
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

        // Build the post URL.
        $postUrl = $this->apiEndpoint . 'orders';
        // Form an order with all the line items and discounts.
        $orderBody = [
            'idempotency_key' => $idempotencyKey,
            'order'           => [
                'reference_id' => $referenceId,
                'location_id'  => $this->locationId,
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
        $calculateResponse = $this->sendRequest($orderBody, $postUrl . '/calculate');
        if (! is_string($calculateResponse) && isset($calculateResponse->order)) {
            $calculateOrder = $calculateResponse->order;
            // If order total and event total don't match try adjusting the total money mount.
            // This can occur when Square uses bankers rounding on calculating the order totals.
            $eventMoney = (int) $gateway->convertToSubunits($payAmount);
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
        $createOrderResponse = $this->sendRequest($orderBody, $postUrl);

        // If it's an array - it's an error. So pass that further.
        if (is_array($createOrderResponse) && isset($createOrderResponse['error'])) {
            return $createOrderResponse;
        }
        if (! isset($createOrderResponse->order)) {
            $request_error['error']['message'] = esc_html__(
                'Unexpected error. No order returned in Order create response.',
                'event_espresso'
            );
            return $request_error;
        }
        // Order created ok, return it.
        return $createOrderResponse->order;
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
}
