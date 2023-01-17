<?php

namespace EventEspresso\Square\api\customers;

use EE_Error;
use EE_Registration;
use EED_SquareOnsite;
use EventEspresso\Square\api\SquareApi;
use ReflectionException;

/**
 * Class CustomersApi
 *
 * Handles Square Customers API calls.
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\customers
 * @since   1.0.3.p
 */
class CustomersApi
{
    /**
     * @var SquareApi
     */
    protected $api;

    /**
     * @var string
     */
    protected $post_url;

    /**
     * Customer's billing info.
     *
     * @var array
     */
    protected $billing_info = [];

    /**
     * @var EE_Registration
     */
    protected $primary_registrant;


    /**
     * Class constructor.
     *
     * @param SquareApi $api
     * @param array $billing_info
     * @param EE_Registration $primary_registrant
     */
    public function __construct(SquareApi $api, array $billing_info, EE_Registration $primary_registrant)
    {
        $this->api                = $api;
        $this->post_url           = $this->api->apiEndpoint() . 'customers/';
        $this->billing_info       = $billing_info;
        $this->primary_registrant = $primary_registrant;
    }


    /**
     * Searches the customer profiles associated with a Square account using a supported query filter.
     *
     * @param       $filter
     * @param array $sort
     * @param int   $limit
     * @return array
     */
    public function search($filter, array $sort = ['field' => 'CREATED_AT'], int $limit = 1): array
    {
        $search_parameters = [
            'query' => [
                'filter' => $filter,
                'sort'   => $sort,
            ],
            'limit' => $limit,
        ];
        // Submit the search request.
        $response = $this->api->sendRequest($search_parameters, $this->post_url . 'search');
        // Do we have an error ?
        if ($this->isErrorResponse($response)) {
            return $response;
        }
        if (! $this->hasCustomerData($response)) {
            // Square returns an empty body if nothing is found, so we return an empty array.
            return [];
        }
        // Got customer/s, return it.
        return $response->customers;
    }


    /**
     * Searches for the customer profile by his email.
     *
     * @param string $email
     * @return array
     */
    public function findByEmail(string $email): array
    {
        $filter['email_address'] = ['exact' => $email];
        return $this->search($filter);
    }


    /**
     * Creates a new customer for a business.
     *
     * @return Object|array
     */
    public function create()
    {
        try {
            $country_iso = EED_SquareOnsite::getCountryIsoByName($this->billing_info['country']);
        } catch (EE_Error | ReflectionException $e) {
            $country_iso = '';
        }
        $parameters = [
            'given_name'    => $this->billing_info['first_name'] ?? '',
            'family_name'   => $this->billing_info['last_name'] ?? '',
            'email_address' => $this->billing_info['email'] ?? '',
            'address' => [
                'address_line_1' => $this->billing_info['address'] ?? '',
                'address_line_2' => $this->billing_info['address2'] ?? '',
                'locality'       => $this->billing_info['city'] ?? '',
                'administrative_district_level_1' => $this->billing_info['state'] ?? '',
                'postal_code'    => $this->billing_info['zip'] ?? '',
                'country'        => $country_iso ?? '',
            ],
            'phone_number' => $this->billing_info['phone'] ?? '',
            'reference_id' => (string) $this->primary_registrant->attendee_ID(),
            'note'         => 'An event attendee'
        ];

        // Submit the create request.
        $response = $this->api->sendRequest($parameters, $this->post_url);
        // Do we have an error ?
        if ($this->isErrorResponse($response)) {
            return $response;
        }
        if (! $this->hasCustomerData($response)) {
            $request_error['error']['message'] = esc_html__(
                'Error. Customer was not saved in Square. Unrecognized error.',
                'event_espresso'
            );
            return $request_error;
        }
        // Got it, return it.
        return $response->customer;
    }


    /**
     * Check the response for a customer object.
     *
     * @param $response
     * @return bool
     */
    private function hasCustomerData($response): bool
    {
        return $response && isset($response->customer);
    }


    /**
     * Check the response for errors.
     *
     * @param $response
     * @return bool
     */
    private function isErrorResponse($response): bool
    {
        return is_array($response) && isset($response['error']);
    }
}
