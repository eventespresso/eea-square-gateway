<?php

namespace EventEspresso\Square\api\locations;

use EventEspresso\Square\api\SquareApi;

/**
 * Class LocationsApi
 * handles Square Locations API calls
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\order
 * @since   $VID:$
 */
class LocationsApi
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
     * CancelOrder constructor.
     *
     * @param SquareApi        $api
     */
    public function __construct(SquareApi $api)
    {
        $this->api               = $api;
        $this->post_url          = $this->api->apiEndpoint() . 'locations';
    }


    /**
     * Request a list of Locations for the merchant.
     *
     * @return Object|array
     */
    public function listLocations()
    {
        // Submit the GET request.
        $response = $this->api->sendRequest([], $this->post_url, 'GET');
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
