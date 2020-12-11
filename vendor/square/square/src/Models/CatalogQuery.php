<?php

declare(strict_types=1);

namespace Square\Models;

/**
 * A query composed of one or more different types of filters to narrow the scope of targeted objects
 * when calling the `SearchCatalogObjects` endpoint.
 *
 * Although a query can have multiple filters, only one query is allowed per call to
 * [SearchCatalogObjects](#endpoint-Catalog-SearchCatalogObjects).
 *
 * When a query filter is based on an attribute, the attribute must be searchable.
 * Searchable attributes are listed as follows, along their parent types that can be searched for with
 * applicable query filters.
 *
 * Searchable attribute and objects queryable by searchable attributes **
 * - `name`:  `CatalogItem`, `CatalogItemVariation`, `CatelogCatogry`, `CatalogTax`, `CatalogDiscount`,
 * `CatalogModifier`, 'CatalogModifierList`, `CatalogItemOption`, `CatalogItemOptionValue`
 * - `description`: `CatalogItem`, `CatalogItemOptionValue`
 * - `abbreviation`: `CatalogItem`
 * - `upc`: `CatalogItemVariation`
 * - `sku`: `CatalogItemVariation`
 * - `caption`: `CatalogImage`
 * - `display_name`: `CatalogItemOption`
 *
 * For example, to search for [CatalogItem](#type-CatalogItem) objects by searchable attributes, you
 * can use
 * the `"name"`, `"description"`, or `"abbreviation"` attribute in an applicable query filter.
 */
class CatalogQuery implements \JsonSerializable
{
    /**
     * @var CatalogQuerySortedAttribute|null
     */
    private $sortedAttributeQuery;

    /**
     * @var CatalogQueryExact|null
     */
    private $exactQuery;

    /**
     * @var CatalogQueryPrefix|null
     */
    private $prefixQuery;

    /**
     * @var CatalogQueryRange|null
     */
    private $rangeQuery;

    /**
     * @var CatalogQueryText|null
     */
    private $textQuery;

    /**
     * @var CatalogQueryItemsForTax|null
     */
    private $itemsForTaxQuery;

    /**
     * @var CatalogQueryItemsForModifierList|null
     */
    private $itemsForModifierListQuery;

    /**
     * @var CatalogQueryItemsForItemOptions|null
     */
    private $itemsForItemOptionsQuery;

    /**
     * @var CatalogQueryItemVariationsForItemOptionValues|null
     */
    private $itemVariationsForItemOptionValuesQuery;

    /**
     * Returns Sorted Attribute Query.
     *
     * The query expression to specify the key to sort search results.
     */
    public function getSortedAttributeQuery(): ?CatalogQuerySortedAttribute
    {
        return $this->sortedAttributeQuery;
    }

    /**
     * Sets Sorted Attribute Query.
     *
     * The query expression to specify the key to sort search results.
     *
     * @maps sorted_attribute_query
     */
    public function setSortedAttributeQuery(?CatalogQuerySortedAttribute $sortedAttributeQuery): void
    {
        $this->sortedAttributeQuery = $sortedAttributeQuery;
    }

    /**
     * Returns Exact Query.
     *
     * The query filter to return the serch result by exact match of the specified attribute name and value.
     */
    public function getExactQuery(): ?CatalogQueryExact
    {
        return $this->exactQuery;
    }

    /**
     * Sets Exact Query.
     *
     * The query filter to return the serch result by exact match of the specified attribute name and value.
     *
     * @maps exact_query
     */
    public function setExactQuery(?CatalogQueryExact $exactQuery): void
    {
        $this->exactQuery = $exactQuery;
    }

    /**
     * Returns Prefix Query.
     *
     * The query filter to return the search result whose named attribute values are prefixed by the
     * specified attribute value.
     */
    public function getPrefixQuery(): ?CatalogQueryPrefix
    {
        return $this->prefixQuery;
    }

    /**
     * Sets Prefix Query.
     *
     * The query filter to return the search result whose named attribute values are prefixed by the
     * specified attribute value.
     *
     * @maps prefix_query
     */
    public function setPrefixQuery(?CatalogQueryPrefix $prefixQuery): void
    {
        $this->prefixQuery = $prefixQuery;
    }

    /**
     * Returns Range Query.
     *
     * The query filter to return the search result whose named attribute values fall between the specified
     * range.
     */
    public function getRangeQuery(): ?CatalogQueryRange
    {
        return $this->rangeQuery;
    }

    /**
     * Sets Range Query.
     *
     * The query filter to return the search result whose named attribute values fall between the specified
     * range.
     *
     * @maps range_query
     */
    public function setRangeQuery(?CatalogQueryRange $rangeQuery): void
    {
        $this->rangeQuery = $rangeQuery;
    }

    /**
     * Returns Text Query.
     *
     * The query filter to return the search result whose searchable attribute values contain all of the
     * specified keywords or tokens, independent of the token order or case.
     */
    public function getTextQuery(): ?CatalogQueryText
    {
        return $this->textQuery;
    }

    /**
     * Sets Text Query.
     *
     * The query filter to return the search result whose searchable attribute values contain all of the
     * specified keywords or tokens, independent of the token order or case.
     *
     * @maps text_query
     */
    public function setTextQuery(?CatalogQueryText $textQuery): void
    {
        $this->textQuery = $textQuery;
    }

    /**
     * Returns Items for Tax Query.
     *
     * The query filter to return the items containing the specified tax IDs.
     */
    public function getItemsForTaxQuery(): ?CatalogQueryItemsForTax
    {
        return $this->itemsForTaxQuery;
    }

    /**
     * Sets Items for Tax Query.
     *
     * The query filter to return the items containing the specified tax IDs.
     *
     * @maps items_for_tax_query
     */
    public function setItemsForTaxQuery(?CatalogQueryItemsForTax $itemsForTaxQuery): void
    {
        $this->itemsForTaxQuery = $itemsForTaxQuery;
    }

    /**
     * Returns Items for Modifier List Query.
     *
     * The query filter to return the items containing the specified modifier list IDs.
     */
    public function getItemsForModifierListQuery(): ?CatalogQueryItemsForModifierList
    {
        return $this->itemsForModifierListQuery;
    }

    /**
     * Sets Items for Modifier List Query.
     *
     * The query filter to return the items containing the specified modifier list IDs.
     *
     * @maps items_for_modifier_list_query
     */
    public function setItemsForModifierListQuery(?CatalogQueryItemsForModifierList $itemsForModifierListQuery): void
    {
        $this->itemsForModifierListQuery = $itemsForModifierListQuery;
    }

    /**
     * Returns Items for Item Options Query.
     *
     * The query filter to return the items containing the specified item option IDs.
     */
    public function getItemsForItemOptionsQuery(): ?CatalogQueryItemsForItemOptions
    {
        return $this->itemsForItemOptionsQuery;
    }

    /**
     * Sets Items for Item Options Query.
     *
     * The query filter to return the items containing the specified item option IDs.
     *
     * @maps items_for_item_options_query
     */
    public function setItemsForItemOptionsQuery(?CatalogQueryItemsForItemOptions $itemsForItemOptionsQuery): void
    {
        $this->itemsForItemOptionsQuery = $itemsForItemOptionsQuery;
    }

    /**
     * Returns Item Variations for Item Option Values Query.
     *
     * The query filter to return the item variations containing the specified item option value IDs.
     */
    public function getItemVariationsForItemOptionValuesQuery(): ?CatalogQueryItemVariationsForItemOptionValues
    {
        return $this->itemVariationsForItemOptionValuesQuery;
    }

    /**
     * Sets Item Variations for Item Option Values Query.
     *
     * The query filter to return the item variations containing the specified item option value IDs.
     *
     * @maps item_variations_for_item_option_values_query
     */
    public function setItemVariationsForItemOptionValuesQuery(
        ?CatalogQueryItemVariationsForItemOptionValues $itemVariationsForItemOptionValuesQuery
    ): void {
        $this->itemVariationsForItemOptionValuesQuery = $itemVariationsForItemOptionValuesQuery;
    }

    /**
     * Encode this object to JSON
     *
     * @return mixed
     */
    public function jsonSerialize()
    {
        $json = [];
        $json['sorted_attribute_query']                 = $this->sortedAttributeQuery;
        $json['exact_query']                            = $this->exactQuery;
        $json['prefix_query']                           = $this->prefixQuery;
        $json['range_query']                            = $this->rangeQuery;
        $json['text_query']                             = $this->textQuery;
        $json['items_for_tax_query']                    = $this->itemsForTaxQuery;
        $json['items_for_modifier_list_query']          = $this->itemsForModifierListQuery;
        $json['items_for_item_options_query']           = $this->itemsForItemOptionsQuery;
        $json['item_variations_for_item_option_values_query'] = $this->itemVariationsForItemOptionValuesQuery;

        return array_filter($json, function ($val) {
            return $val !== null;
        });
    }
}
