<?php

namespace EventEspresso\Square\api\order;

/**
 * Class OrderItems
 * organizes and stores line item data required when generating a Square Order
 *
 * @author  Brent Christensen
 * @package EventEspresso\Square\api\order
 * @since   $VID:$
 */
class OrderItems
{
    /**
     * total number of items in the order
     *
     * @var int
     */
    protected $count = 0;

    /**
     * @var array
     */
    protected $discounts = [];

    /**
     * @var array
     */
    protected $discount_IDs = [];

    /**
     * @var array
     */
    protected $items = [];

    /**
     * total money amount of items in order
     *
     * @var float
     */
    protected $total = 0;

    /**
     * @var array
     */
    protected $taxes = [];

    /**
     * @var array
     */
    protected $tax_IDs = [];



    /**
     * @param array $discount
     */
    public function addDiscount(array $discount)
    {
        $this->discounts[] = $discount;
        // Also track discount UUIDs separately, for line items 'applied_discounts' parameter.
        $this->addDiscountID($discount['uid']);
    }


    /**
     * @param array $item
     */
    public function addItem(array $item)
    {
        $this->items[] = $item;
    }


    /**
     * @param string $discount_ID
     */
    public function addDiscountID(string $discount_ID)
    {
        $this->discount_IDs[] = ['discount_uid' => $discount_ID];
    }


    /**
     * @param array $tax
     */
    public function addTax(array $tax)
    {
        $this->taxes[] = $tax;
        // Also track tax UUIDs separately. Needed to apply the IDs to each line item ('applied_taxes' parameter).
        $this->addTaxID($tax['uid']);
    }


    /**
     * @param string $tax_ID
     */
    public function addTaxID(string $tax_ID)
    {
        $this->tax_IDs[] = ['tax_uid' => $tax_ID];
    }


    /**
     * @return int
     */
    public function count(): int
    {
        return $this->count;
    }


    /**
     * @return array
     */
    public function discounts(): array
    {
        return $this->discounts;
    }


    /**
     * @return array
     */
    public function discountIDs(): array
    {
        return $this->discount_IDs;
    }


    /**
     * @return void
     */
    public function incrementCount()
    {
        $this->count++;
    }


    /**
     * @return bool
     */
    public function hasTaxes(): bool
    {
        return ! empty($this->taxes);
    }


    /**
     * @return bool
     */
    public function hasDiscounts(): bool
    {
        return ! empty($this->discounts);
    }


    /**
     * @return array
     */
    public function items(): array
    {
        return $this->items;
    }


    /**
     * @return array
     */
    public function taxes(): array
    {
        return $this->taxes;
    }


    /**
     * @return array
     */
    public function taxIDs(): array
    {
        return $this->tax_IDs;
    }


    /**
     * @return float
     */
    public function total(): float
    {
        return $this->total;
    }


    /**
     * @param float $total
     */
    public function addToTotal(float $total)
    {
        $this->total += $total;
    }
}
