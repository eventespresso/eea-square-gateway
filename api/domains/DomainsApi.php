<?php

namespace EventEspresso\Square\api\domains;

use EventEspresso\Square\api\SquareApi;

/**
 * Class LocationsApi
 * handles Square Locations API calls
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\api\domains
 * @since   $VID:$
 */
class DomainsApi
{
    /**
     * @var SquareApi
     */
    protected SquareApi $api;

    /**
     * @var string
     */
    protected string $request_url;


    /**
     * CancelOrder constructor.
     *
     * @param SquareApi $api
     */
    public function __construct(SquareApi $api)
    {
        $this->api         = $api;
        $this->request_url = $this->api->apiEndpoint() . 'apple-pay/domains';
    }


    /**
     * Send a domain registration request.
     *
     * @param string $domain_name
     * @return array
     */
    public function registerDomain(string $domain_name): array
    {
        // Submit the GET request.
        $response = $this->api->sendRequest(['domain_name' => $domain_name], $this->request_url);
        // If it's an array - it's an error. So pass that further.
        if (is_array($response) && isset($response['error'])) {
            return $response;
        }
        if (! isset($response->status)) {
            $request_error['error']['message'] = esc_html__(
                'Error. Domain registration response unrecognizable.',
                'event_espresso'
            );
            return $request_error;
        }
        // Got registration status.
        return ['status' => $response->status];
    }
}
