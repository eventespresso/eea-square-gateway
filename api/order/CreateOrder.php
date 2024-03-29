<?php

namespace EventEspresso\Square\api\order;

use EE_Error;
use EE_Line_Item;
use EE_Payment;
use EE_Promotions_Config;
use EE_Transaction;
use EEG_SquareOnsite;
use EEH_Money;
use EventEspresso\Square\api\SquareApi;
use ReflectionException;

/**
 * Class CreateOrder
 * Generates and sends create order requests through the Square API
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   1.0.0.p
 */
class CreateOrder extends OrdersApi
{

    /**
     * @var EEG_SquareOnsite
     */
    protected $gateway;

    /**
     * @var OrderItems
     */
    protected $order_items;

    /**
     * @var bool
     */
    protected $partial_payment = false;

    /**
     * @var EE_Promotions_Config
     */
    protected $promotions_config;


    /**
     * CreateOrder constructor.
     *
     * @param EEG_SquareOnsite          $gateway
     * @param SquareApi                 $api
     * @param int                       $TXN_ID
     * @param EE_Promotions_Config|null $promotions_config
     */
    public function __construct(
        EEG_SquareOnsite $gateway,
        SquareApi $api,
        int $TXN_ID = 0,
        EE_Promotions_Config $promotions_config = null
    ) {
        parent::__construct($api, $TXN_ID);
        $this->gateway           = $gateway;
        $this->promotions_config = $promotions_config;
    }


    /**
     * Create a Square Order.
     *
     * @param EE_Payment $payment
     * @param string     $customer_id
     * @return Object|array
     * @throws EE_Error
     * @throws ReflectionException
     */
    public function create(EE_Payment $payment, string $customer_id = '')
    {
        $this->order_items = new OrderItems();
        $transaction       = $payment->transaction();
        $currency          = $payment->currency_code();
        $payment_amount    = $payment->amount();

        $this->discountExistingPayments($transaction->total(), $transaction->paid(), $payment_amount, $currency);
        $this->applyTaxes($transaction->tax_items(), $currency);
        $eventLineItems = $transaction->items_purchased();
        // List actual line items and promotions.
        if ($eventLineItems) {
            $this->addPromotionLineItems($eventLineItems, $currency);
            $this->addRemainingLineItems($eventLineItems, $currency);
        }
        // Just in case we were not able to recognize some item, add the difference as an extra line item.
        $this->addOtherLineItems($transaction, $currency);
        // Also add a fulfillment. This is required for the order to be displayed in the dashboard.
        $this->addOrderFulfillment($transaction);

        $order = $this->buildOrder($transaction->ID(), $customer_id);
        // First calculate the order to see if the prices match.
        $calculateResponse = $this->api->sendRequest($order, $this->post_url . 'calculate');
        $order             = $this->adjustResponseTotal($calculateResponse, $order, $payment_amount, $currency);

        // Create Order request.
        $create_response = $this->api->sendRequest($order, $this->post_url);
        return $this->validateOrder($create_response);
    }


    /**
     * Applies a discount if this is a partial payment. Otherwise Square won't like it.
     *
     * @param float  $transaction_total
     * @param float  $paid_to_date
     * @param float  $payment_amount
     * @param string $currency
     * @throws EE_Error
     */
    private function discountExistingPayments(
        float $transaction_total,
        float $paid_to_date,
        float $payment_amount,
        string $currency
    ) {
        // Is this a partial payment ? If so, add the paid amount as a discount.
        if (EEH_Money::compare_floats($payment_amount, $transaction_total, '!=') && $paid_to_date > 0) {
            $this->partial_payment = true;
            $paidAmount            = $this->gateway->convertToSubunits(abs($paid_to_date));
            $itemDiscount          = [
                'uid'          => 'partial-payment-' . rand(1, 9999),
                'name'         => esc_html__('A previously received payment', 'event_espresso'),
                'amount_money' => [
                    'amount'   => $paidAmount,
                    'currency' => $currency,
                ],
                'type'         => 'FIXED_AMOUNT',
                'scope'        => 'LINE_ITEM',
            ];
            $this->order_items->addDiscount($itemDiscount);
        }
    }


    /**
     * Adds all taxes applied to the payment.
     *
     * @param array|null $tax_line_items
     * @param string     $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function applyTaxes(array $tax_line_items, string $currency)
    {
        if (! empty($tax_line_items)) {
            $promo_affects_tax = $this->promotionsAffectTaxes();
            foreach ($tax_line_items as $tax_line_item) {
                if ($tax_line_item instanceof EE_Line_Item) {
                    // If Taxes are counted before the discounts or this is a Partial payment
                    // - list the Taxes as simple Line Items so that Square doesn't count them by their own.
                    if (! $this->partial_payment && $promo_affects_tax) {
                        $limeItemTaxPercent = (string)$tax_line_item->percent();
                        $lineItemTaxUid     = 'tax-' . $tax_line_item->ID() . '-' . $limeItemTaxPercent;
                        $lineItemTax        = [
                            'uid'        => $lineItemTaxUid,
                            'name'       => $tax_line_item->name(),
                            'percentage' => $limeItemTaxPercent,
                            'scope'      => 'LINE_ITEM',
                            'type'       => 'ADDITIVE',
                        ];
                        $this->order_items->addTax($lineItemTax);
                    } else {
                        $taxAsLineItem = [
                            'uid'              => 'event-tax-' . $tax_line_item->OBJ_ID(),
                            'name'             => $tax_line_item->name(),
                            'quantity'         => '1',
                            'base_price_money' => [
                                'amount'   => $this->gateway->convertToSubunits($tax_line_item->total()),
                                'currency' => $currency,
                            ],
                        ];
                        $this->order_items->addItem($taxAsLineItem);
                        $this->order_items->incrementCount();
                    }
                }
            }
        }
    }


    /**
     * Adds all promotions applied in the payment.
     *
     * @param array  $eventLineItems
     * @param string $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function addPromotionLineItems(array $eventLineItems, string $currency)
    {
        foreach ($eventLineItems as $discountItem) {
            if ($discountItem instanceof EE_Line_Item && $discountItem->OBJ_type() === 'Promotion') {
                $itemDiscountAmount = $this->gateway->convertToSubunits(abs($discountItem->total()));
                $itemDiscount       = [
                    'uid'          => 'discount-' . $discountItem->ID(),
                    'name'         => $discountItem->name(),
                    'amount_money' => [
                        'amount'   => $itemDiscountAmount,
                        'currency' => $currency,
                    ],
                    'type'         => 'FIXED_AMOUNT',
                    'scope'        => 'LINE_ITEM',
                ];
                $this->order_items->addDiscount($itemDiscount);
                // Count the total to double check later.
                $this->order_items->addToTotal($discountItem->total());
            }
        }
    }


    /**
     * Adds line items and applied promotions.
     *
     * @param array  $eventLineItems
     * @param string $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function addRemainingLineItems(array $eventLineItems, string $currency)
    {
        foreach ($eventLineItems as $eventItem) {
            if ($eventItem instanceof EE_Line_Item && $eventItem->OBJ_type() !== 'Promotion') {
                $itemMoney     = $this->gateway->convertToSubunits($eventItem->unit_price());
                $orderLineItem = [
                    'uid'               => (string)$eventItem->ID(),
                    'name'              => $eventItem->name(),
                    'quantity'          => (string)$eventItem->quantity(),
                    'base_price_money'  => [
                        'amount'   => $itemMoney,
                        'currency' => $currency,
                    ],
                    'applied_discounts' => $this->order_items->discountIDs(),
                ];
                if ($eventItem->is_taxable()) {
                    $orderLineItem['applied_taxes'] = $this->order_items->taxIDs();
                }
                $this->order_items->addItem($orderLineItem);
                // Count the total to double check later.
                $this->order_items->addToTotal($eventItem->total());
                $this->order_items->incrementCount();
            }
        }
    }


    /**
     * In case we were not able to recognize some item, adds the difference as an extra line item.
     *
     * @param EE_Transaction $transaction
     * @param string         $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function addOtherLineItems(EE_Transaction $transaction, string $currency)
    {
        $total = $transaction->total() - $this->order_items->total() - $transaction->total_line_item()->get_total_tax();
        $itemizedSumDiffFromTxnTotal = round($total, 2);
        if (EEH_Money::compare_floats($itemizedSumDiffFromTxnTotal, 0, '!=')) {
            $extraItemMoney     = $this->gateway->convertToSubunits(abs($itemizedSumDiffFromTxnTotal));
            $extraOrderLineItem = [
                'uid'              => $this->order_items->count() . '_other',
                'name'             => esc_html__('Other (promotion/surcharge)', 'event_espresso'),
                'quantity'         => '1',
                'base_price_money' => [
                    'amount'   => $extraItemMoney,
                    'currency' => $currency,
                ],
            ];
            $this->order_items->addItem($extraOrderLineItem);
        }
    }


    /**
     * Add an order fulfillment.
     *
     * @param EE_Transaction $transaction
     */
    private function addOrderFulfillment(EE_Transaction $transaction)
    {
        try {
            $primary_registrant = $transaction->primary_registration();
            $display_name = $primary_registrant->attendeeName();
        } catch (EE_Error | ReflectionException $e) {
            $display_name = 'Unknown';
        }
        $this->order_items->addFulfillment([
            'type'  => 'SHIPMENT',
            'state' => 'PROPOSED',
            'shipment_details' => [
                'recipient' => [
                    'display_name' => $display_name
                ],
                'pickup_at' => date("c", time()),
            ],
        ]);
    }


    /**
     * Forms the Order.
     *
     * @param int    $TXN_ID
     * @param string $customer_id
     * @return array
     */
    private function buildOrder(int $TXN_ID, string $customer_id = ''): array
    {
        $key_prefix = $this->api->isSandboxMode() ? 'TEST-order' : 'event-order';
        // Form an order with all the line items and discounts.
        $order = [
            'idempotency_key' => $this->idempotency_key->value(),
            'order'           => [
                'reference_id' => "$key_prefix-$TXN_ID",
                'location_id'  => $this->api->locationId(),
                'line_items'   => $this->order_items->items(),
            ],
        ];

        // Add extra parameters. A customer in this case.
        if ($customer_id) {
            $order['order']['customer_id'] = $customer_id;
        }

        // Check the taxes and discounts before adding.
        if ($this->order_items->hasTaxes()) {
            $order['order']['taxes'] = $this->order_items->taxes();
        }
        if (! empty($this->order_items->hasDiscounts())) {
            $order['order']['discounts'] = $this->order_items->discounts();
        }
        // Fulfillment is required for the order to be displayed in the dashboard.
        if (! empty($this->order_items->hasFulfillments())) {
            $order['order']['fulfillments'] = $this->order_items->fulfillments();
        }
        return $order;
    }


    /**
     * Gets EE config. Do the the promotions affect taxes.
     *
     * @return bool
     */
    private function promotionsAffectTaxes(): bool
    {
        if ($this->promotions_config instanceof EE_Promotions_Config) {
            return $this->promotions_config->affects_tax();
        }
        return true;
    }


    /**
     * Adjusts the Order total if there is small difference.
     * This can occur when Square uses bankers rounding on calculating the order totals.
     *
     * @param mixed  $calculateResponse
     * @param array  $order
     * @param float  $payment_amount
     * @param string $currency
     * @return array
     */
    private function adjustResponseTotal(
        $calculateResponse,
        array $order,
        float $payment_amount,
        string $currency
    ): array {
        if (! is_string($calculateResponse) && isset($calculateResponse->order)) {
            $calculateOrder = $calculateResponse->order;
            // If order total and event total don't match try adjusting the total money mount.
            $eventMoney  = (int)$this->gateway->convertToSubunits($payment_amount);
            $orderOffset = (int)$calculateOrder->total_money->amount - $eventMoney;
            // 10 ? - just to be safe.
            if ($orderOffset !== 0 && abs($orderOffset) <= 10) {
                $adjustmentName = esc_html__('Total calculation adjustment', 'event_espresso');
                if ($orderOffset < 0) {
                    // Looks like the end total is lower. Need to add a line item to make order total "right".
                    $offsetOrderLineItem            = [
                        'uid'              => 'event-total-adjustment' . rand(1, 999),
                        'name'             => $adjustmentName,
                        'quantity'         => '1',
                        'base_price_money' => [
                            'amount'   => abs($orderOffset),
                            'currency' => $currency,
                        ],
                    ];
                    $order['order']['line_items'][] = $offsetOrderLineItem;
                } else {
                    // End total is higher. Need to add a fixed discount.
                    $offsetDiscount                = [
                        'uid'          => 'adjustment-' . rand(1, 999),
                        'name'         => $adjustmentName,
                        'amount_money' => [
                            'amount'   => abs($orderOffset),
                            'currency' => $currency,
                        ],
                        'type'         => 'FIXED_AMOUNT',
                        'scope'        => 'ORDER',
                    ];
                    $order['order']['discounts'][] = $offsetDiscount;
                }
            }
        }
        return $order;
    }


    /**
     * Makes sure that we have received an Order object back from the SquareApi.
     *
     * @param $apiResponse
     * @return Object|array
     */
    public function validateOrder($apiResponse)
    {
        if (! isset($apiResponse->order)) {
            return [
                'error' => [
                    'code'    => 'missing_order',
                    'message' => esc_html__(
                        'Unexpected error. A Square Order Response was not returned.',
                        'event_espresso'
                    ),
                ]
            ];
        }
        return $apiResponse->order;
    }
}
