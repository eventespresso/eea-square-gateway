<?php

namespace EventEspresso\Square\domain;

/**
 * Class Domain
 * domain data regarding the Square Add-on
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class Domain
{

    /**
     * Name of Extra Meta that stores the Square Application ID used in API calls.
     */
    const META_KEY_APPLICATION_ID = 'application_id';

    /**
     * Name of the Extra Meta that stores the Square Access Token that is used to process payments.
     */
    const META_KEY_ACCESS_TOKEN = 'access_token';

    /**
     * Name of the Extra Meta that stores the Square Refresh Token that is used to refresh the access token.
     */
    const META_KEY_REFRESH_TOKEN = 'refresh_token';

    /**
     * Name of the Extra Meta that stores the expiration date of the access token.
     */
    const META_KEY_EXPIRES_AT = 'expires_at';

    /**
     * Name of the Extra Meta that stores the PM authentication type (OAuth or using personal Square app credentials).
     */
    const META_KEY_AUTH_TYPE = 'authentication_type';

    /**
     * Name of the Extra Meta that stores whether or not the above credentials were provided by Square or directly
     * entered into this plugin. If it doesn't exist, they were manually entered.
     */
    const META_KEY_USING_OAUTH = 'using_square_oauth';

    /**
     * Name of the Extra Meta that stores whether the credentials were for the Square sandbox or live mode.
     */
    const META_KEY_LIVE_MODE = 'livemode';

    /**
     * Name of the Extra Meta that stores the Event Espresso Square Account's merchant id.
     */
    const META_KEY_MERCHANT_ID = 'merchant_id';

    /*
     * Name of the Extra Meta that stores the Square account location ID.
     */
    const META_KEY_LOCATION_ID = 'location_id';
}
