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
     * @var bool
     */
    protected $promo_affects_tax = false;

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
        $this->promo_affects_tax = $this->promotionsAffectTaxes();
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
        $event_line_items = $transaction->items_purchased();
        // List actual line items and promotions.
        if ($event_line_items) {
            $this->addPromotionLineItems($event_line_items, $currency);
            $this->addMainLineItems($event_line_items, $currency);
        }
        $this->applyTaxes($transaction->tax_items(), $currency);
        // Also add a fulfillment. This is required for the order to be displayed in the dashboard.
        $this->addOrderFulfillment($transaction);
        $order = $this->buildOrder($transaction->ID(), $customer_id);
        $order = $this->adjustResponseTotal($order, $payment_amount, $currency);
        // Create Order request.
        $create_response = $this->api->sendRequest($order, $this->post_url);
        return $this->validateOrder($create_response);
    }


    /**
     * Applies a discount if this is a partial payment. Otherwise, Square won't like it.
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
            $item_discount         = [
                'uid'          => 'partial-payment-' . rand(1, 9999),
                'name'         => esc_html__('A previously received payment', 'event_espresso'),
                'amount_money' => [
                    'amount'   => $paidAmount,
                    'currency' => $currency,
                ],
                'type'         => 'FIXED_AMOUNT',
                'scope'        => 'LINE_ITEM',
            ];
            $this->order_items->addDiscount($item_discount);
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
            foreach ($tax_line_items as $tax_line_item) {
                if ($tax_line_item instanceof EE_Line_Item) {
                    $tax_item_total   = $this->gateway->convertToSubunits($tax_line_item->total());
                    $tax_as_line_item = [
                        'uid'              => 'event-tax-' . $tax_line_item->OBJ_ID(),
                        'name'             => $tax_line_item->name(),
                        'quantity'         => '1',
                        'base_price_money' => [
                            'amount'   => $tax_item_total,
                            'currency' => $currency,
                        ],
                    ];
                    if ($this->promo_affects_tax) {
                        $tax_as_line_item['applied_discounts'] = $this->order_items->discountIDs();
                    }
                    $this->order_items->addItem($tax_as_line_item);
                    // Sub from the line item total so that the addOtherLineItems() doesn't add the difference.
                    $this->order_items->subtractFromTotal($this->gateway->convertToFloat($tax_item_total));
                    $this->order_items->incrementCount();
                }
            }
        }
    }


    /**
     * Adds all promotions applied in the payment.
     *
     * @param array  $event_line_items
     * @param string $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function addPromotionLineItems(array $event_line_items, string $currency)
    {
        foreach ($event_line_items as $discount_item) {
            if ($discount_item instanceof EE_Line_Item && $discount_item->OBJ_type() === 'Promotion') {
                if ($this->promo_affects_tax) {
                    $discount_total = $discount_item->total();
                } else {
                    $discount_total = $discount_item->pretaxTotal();
                }
                $item_discount        = [
                    'uid'          => 'discount-' . $discount_item->ID(),
                    'name'         => $discount_item->name(),
                    'amount_money' => [
                        'amount'   => $this->gateway->convertToSubunits(abs($discount_total)),
                        'currency' => $currency,
                    ],
                    'type'         => 'FIXED_AMOUNT',
                    'scope'        => 'LINE_ITEM',
                ];
                $this->order_items->addDiscount($item_discount);
                // Count the total to double-check later.
                $this->order_items->addToTotal($discount_total);
            }
        }
    }


    /**
     * Adds line items and applied promotions.
     *
     * @param array  $event_line_items
     * @param string $currency
     * @throws EE_Error
     * @throws ReflectionException
     */
    private function addMainLineItems(array $event_line_items, string $currency): void
    {
        foreach ($event_line_items as $event_item) {
            if ($event_item instanceof EE_Line_Item && $event_item->OBJ_type() !== 'Promotion') {
                $item_money      = $this->gateway->convertToSubunits($event_item->unit_price());
                $order_line_item = [
                    'uid'               => (string) $event_item->ID(),
                    'name'              => $event_item->name(),
                    'quantity'          => (string) $event_item->quantity(),
                    'base_price_money'  => [
                        'amount'   => $item_money,
                        'currency' => $currency,
                    ],
                    'applied_discounts' => $this->order_items->discountIDs(),
                ];
                $this->order_items->addItem($order_line_item);
                // Count the total to double-check later.
                $this->order_items->addToTotal($event_item->total());
                $this->order_items->incrementCount();
            }
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
            $display_name       = $primary_registrant->attendeeName();
        } catch (EE_Error | ReflectionException $e) {
            $display_name = 'Unknown';
        }
        $this->order_items->addFulfillment([
            'type'             => 'SHIPMENT',
            'state'            => 'PROPOSED',
            'shipment_details' => [
                'recipient' => [
                    'display_name' => $display_name,
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
     * Gets EE config. Do the promotions affect taxes ?
     *
     * @return bool
     */
    private function promotionsAffectTaxes(): bool
    {
        if ($this->promotions_config instanceof EE_Promotions_Config) {
            return $this->promotions_config->affects_tax();
        }
        return false;
    }


    /**
     * Adjusts the Order total if there is small difference.
     * This can occur when Square uses bankers rounding on calculating the order totals.
     *
     * @param array  $order
     * @param float  $payment_amount
     * @param string $currency
     * @return array
     */
    private function adjustResponseTotal(array $order, float $payment_amount, string $currency): array
    {
        // First calculate the order to see if the prices match.
        $calculate_response = $this->api->sendRequest($order, $this->post_url . 'calculate');
        if (! is_string($calculate_response) && isset($calculate_response->order)) {
            $square_order = $calculate_response->order;
            // If order total and event total don't match try adjusting the total money mount.
            $event_money  = $this->gateway->convertToSubunits($payment_amount);
            $order_offset = (int) $square_order->total_money->amount - $event_money;
            // Just to be safe 10 is the max allowed adjustment.
            if ($order_offset !== 0 && abs($order_offset) <= 10) {
                $adjustment_name = esc_html__('Total calculation adjustment', 'event_espresso');
                if ($order_offset < 0) {
                    // Looks like the end total is lower. Need to add a line item to make order total "right".
                    $offset_order_line_item         = [
                        'uid'              => 'event-total-adjustment' . rand(1, 999),
                        'name'             => $adjustment_name,
                        'quantity'         => '1',
                        'base_price_money' => [
                            'amount'   => abs($order_offset),
                            'currency' => $currency,
                        ],
                    ];
                    $order['order']['line_items'][] = $offset_order_line_item;
                } else {
                    // End total is higher. Need to add a fixed discount.
                    $offset_discount               = [
                        'uid'          => 'adjustment-' . rand(1, 999),
                        'name'         => $adjustment_name,
                        'amount_money' => [
                            'amount'   => abs($order_offset),
                            'currency' => $currency,
                        ],
                        'type'         => 'FIXED_AMOUNT',
                        'scope'        => 'ORDER',
                    ];
                    $order['order']['discounts'][] = $offset_discount;
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
                ],
            ];
        }
        return $apiResponse->order;
    }
}
