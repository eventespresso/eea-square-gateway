<?php

namespace EventEspresso\Square\api;

/**
 * Class EESquareLocations
 *
 * Class that handles Square Locations API calls.
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EESquareLocations extends EESquareApiBase
{
    /**
     * Request a list of Locations for the merchant.
     *
     * @return Object|array
     */
    public function list()
    {
        return $this->request($this->apiEndpoint . 'locations');
    }


    /**
     * Make the API request.
     *
     * @param string $requestUrl
     * @return Object|array
     */
    public function request($requestUrl)
    {
        // Submit the GET request.
        $response = $this->sendRequest([], $requestUrl, 'GET');
        // If it's an array - it's an error. So pass that further.
        if (is_array($response) && isset($response['error'])) {
            return $response;
        }
        if (! isset($response->locations)) {
            $request_error['error']['message'] = esc_html__(
                'Error. No locations list returned in Locations request.',
                'event_espresso'
            );
            return $request_error;
        }
        // Got the list, return it.
        return $response->locations;
    }
}
