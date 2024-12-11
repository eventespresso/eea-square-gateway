<?php

use EventEspresso\core\services\loaders\LoaderFactory;
use EventEspresso\PaymentMethods\Manager;
use EventEspresso\Square\api\domains\DomainsApi;
use EventEspresso\Square\api\locations\LocationsApi;
use EventEspresso\Square\api\SquareApi;
use EventEspresso\Square\domain\Domain;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\exceptions\InvalidInterfaceException;
use EventEspresso\Square\tools\encryption\SquareEncryptionKeyManager;
use EventEspresso\Square\tools\encryption\SquareOpenSSLEncryption;

/**
 * Class EED_SquareOnsiteOAuth
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EED_SquareOnsiteOAuth extends EED_Module
{
    /**
     * @return EED_Module
     */
    public static function instance(): EED_Module
    {
        return parent::get_instance(__CLASS__);
    }


    /**
     * Run - initial module setup
     *
     * @param WP $WP
     * @return void
     */
    public function run($WP)
    {
    }


    /**
     * For hooking into EE Core, other modules, etc.
     *
     * @access public
     * @return void
     */
    public static function set_hooks()
    {
        if (
            (
                class_exists('EventEspresso\core\domain\services\database\DbStatus')
                && EventEspresso\core\domain\services\database\DbStatus::isOnline()
            )
            || EE_Maintenance_Mode::instance()->models_can_query()
        ) {
            // A hook to handle the process after the return from Square website.
            add_action('init', [__CLASS__, 'requestAccess'], 20);
        }
    }


    /**
     * For hooking into EE Admin Core, other modules, etc.
     *
     * @access public
     * @return void
     */
    public static function set_hooks_admin()
    {
        if (
            (
                class_exists('EventEspresso\core\domain\services\database\DbStatus')
                && EventEspresso\core\domain\services\database\DbStatus::isOnline()
            )
            || EE_Maintenance_Mode::instance()->models_can_query()
        ) {
            // Request Square initial OAuth data.
            add_action('wp_ajax_squareRequestConnectData', [__CLASS__, 'getConnectionData']);
            // Update the OAuth status.
            add_action('wp_ajax_squareUpdateConnectionStatus', [__CLASS__, 'updateConnectionStatus']);
            // Square disconnect.
            add_action('wp_ajax_squareRequestDisconnect', [__CLASS__, 'disconnectAccount']);
            // register domain with Apple Pay
            add_action('wp_ajax_squareRegisterDomain', [__CLASS__, 'registerDomainAjax']);
        }
    }


    /**
     * Fetch the user’s authorization credentials.
     * This will handle the user return from the Square authentication page.
     *
     * We expect them to return to a page like:
     * /?webhook=eepSquareGetAccessToken&access_token=123qwe&nonce=qwe123&refresh_token=123qwe&application_id=123qwe&square_slug=square1&square_user_id12345&livemode=1
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function requestAccess()
    {
        // Check if this is the webhook from Square.
        if (
            ! isset($_GET['webhook'], $_GET['nonce'])
            || $_GET['webhook'] !== 'eepSquareGetAccessToken'
        ) {
            // Not it. Ignore it.
            return;
        }

        // Get the payment method.
        $square_pm = EEM_Payment_Method::instance()->get_one_by_slug(sanitize_key($_GET['square_slug']));
        if (! $square_pm instanceof EE_Payment_Method) {
            EED_SquareOnsiteOAuth::closeOauthWindow(
                esc_html__(
                    'Could not specify the payment method !',
                    'event_espresso'
                )
            );
        }

        // Check that we have all the required parameters and the nonce is ok.
        if (! wp_verify_nonce($_GET['nonce'], 'eepSquareGetAccessToken')) {
            // This is an error. Log and close the window.
            $err_msg = esc_html__('Nonce fail!', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($square_pm, $err_msg, [], true, true);
        }
        // log the incoming data, don't exit
        EED_SquareOnsiteOAuth::errorLogAndExit(
            $square_pm,
            esc_html__('Request access request response', 'event_espresso'),
            $_GET,
            false
        );
        if (
            empty($_GET['square_slug'])
            || empty($_GET[ Domain::META_KEY_EXPIRES_AT ])
            || empty($_GET[ Domain::META_KEY_ACCESS_TOKEN ])
            || empty($_GET[ Domain::META_KEY_MERCHANT_ID ])
            || empty($_GET[ Domain::META_KEY_APPLICATION_ID ])
            || ! isset($_GET[ Domain::META_KEY_REFRESH_TOKEN ])
            || ! isset($_GET[ Domain::META_KEY_LIVE_MODE ])
        ) {
            // Missing parameters for some reason. Can't proceed.
            $err_msg = esc_html__('Missing OAuth required parameters.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($square_pm, $err_msg, [], true, true);
        }

        // Update the PM data.
        $square_pm->update_extra_meta(
            Domain::META_KEY_ACCESS_TOKEN,
            EED_SquareOnsiteOAuth::encryptString($_GET[ Domain::META_KEY_ACCESS_TOKEN ], $square_pm->debug_mode())
        );
        $square_pm->update_extra_meta(
            Domain::META_KEY_APPLICATION_ID,
            sanitize_text_field($_GET[ Domain::META_KEY_APPLICATION_ID ])
        );
        /**
         * Save the permissions scope. Used for checking the permissions before using the API.
         * @since 1.0.4.p
         */
        $square_pm->update_extra_meta(Domain::META_KEY_PERMISSIONS, Domain::PERMISSIONS_SCOPE_ALL);

        $refresh_token = EED_SquareOnsiteOAuth::encryptString(
            sanitize_text_field($_GET[ Domain::META_KEY_REFRESH_TOKEN ]),
            $square_pm->debug_mode()
        );
        $square_pm->update_extra_meta(
            Domain::META_KEY_SQUARE_DATA,
            [
                Domain::META_KEY_REFRESH_TOKEN => $refresh_token,
                Domain::META_KEY_EXPIRES_AT    => sanitize_key($_GET[ Domain::META_KEY_EXPIRES_AT ]),
                Domain::META_KEY_MERCHANT_ID   => sanitize_text_field($_GET[ Domain::META_KEY_MERCHANT_ID ]),
                Domain::META_KEY_LIVE_MODE     => (bool) sanitize_key($_GET[ Domain::META_KEY_LIVE_MODE ]),
                Domain::META_KEY_USING_OAUTH   => true,
                Domain::META_KEY_THROTTLE_TIME => date("Y-m-d H:i:s"),
            ]
        );

        // Write JS to pup-up window to close it and refresh the parent.
        EED_SquareOnsiteOAuth::closeOauthWindow('');
    }


    /**
     * Return information needed to request the OAuth page.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error
     */
    public static function getConnectionData()
    {
        $square = EED_SquareOnsiteOAuth::getSubmittedPm($_POST);
        if (! $square instanceof EE_Payment_Method) {
            $errMsg = esc_html__('Could not specify the payment method.', 'event_espresso');
            echo wp_json_encode(['squareError' => $errMsg]);
            exit();
        }
        $square_slug = sanitize_key($_POST['submittedPm']);
        // Just save the debug mode option if it was changed..
        // It simplifies the rest of this process. PM settings might also not be saved after the OAuth process.
        if (
            array_key_exists('debugMode', $_POST)
            && in_array($_POST['debugMode'], ['0', '1'], true)
            && $square->debug_mode() !== (bool) $_POST['debugMode']
        ) {
            $square->save(['PMD_debug_mode' => $_POST['debugMode']]);
        }
        $nonce = wp_create_nonce('eepSquareGetAccessToken');
        // OAuth return handler.
        $redirect_uri = add_query_arg(
            [
                'webhook'                  => 'eepSquareGetAccessToken',
                'square_slug'              => $square_slug,
                'nonce'                    => $nonce,
                Domain::META_KEY_LIVE_MODE => $square->debug_mode() ? '0' : '1',
            ],
            site_url()
        );
        // Request URL should look something like:
        // https://connect.eventespresso.dev/squarepayments/forward?return_url=http%253A%252F%252Fsrc.wordpress-develop.dev%252Fwp-admin%252Fadmin.php%253Fpage%253Dwc-settings%2526amp%253Btab%253Dintegration%2526amp%253Bsection%253Dsquareconnect%2526amp%253Bwc_square_token_nonce%253D6585f05708&scope=read_write
        $request_url = add_query_arg(
            [
                'return_url'  => rawurlencode($redirect_uri),
                'api_version' => Domain::SQUARE_API_VERSION,
                'scope'       => urlencode(Domain::PERMISSIONS_SCOPE_ALL),
                'modal'       => true
            ],
            EED_SquareOnsiteOAuth::getMiddlemanBaseUrl($square) . 'forward'
        );
        echo wp_json_encode([
            'squareSuccess' => true,
            'requestUrl'    => $request_url,
        ]);
        exit();
    }


    /**
     * Gets the base URL to all the Square middleman services for Event Espresso.
     * If LOCAL_MIDDLEMAN_SERVER is defined, requests will be sent to connect.eventespresso.test.
     *
     * @param EE_Payment_Method $paymentMethod
     * @return string
     */
    public static function getMiddlemanBaseUrl(EE_Payment_Method $paymentMethod): string
    {
        $middlemanTarget = defined('LOCAL_MIDDLEMAN_SERVER') ? 'test' : 'com';
        // If this PM is used under different provider accounts, you might need an account indicator.
        $accountIndicator = defined('EE_SQUARE_PM_ACCOUNT_INDICATOR') ? EE_SQUARE_PM_ACCOUNT_INDICATOR : '';
        $testingPostfix = $paymentMethod->debug_mode() ? '_sandbox' : '';
        $path = 'square' . $accountIndicator . $testingPostfix;
        return 'https://connect.eventespresso.' . $middlemanTarget . '/' . $path . '/';
    }


    /**
     * Check the connection status and update the interface.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error|ReflectionException
     */
    public static function updateConnectionStatus()
    {
        $accessToken = null;
        $squareData = $locationsList = [];
        $square = EED_SquareOnsiteOAuth::getSubmittedPm($_POST);
        if ($square) {
            $squareData = $square->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
            $accessToken = $square->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true);
        }
        $connected = $defaultLocation = false;
        if (
            $accessToken
            && isset($squareData[ Domain::META_KEY_USING_OAUTH ])
            && $squareData[ Domain::META_KEY_USING_OAUTH ]
        ) {
            // Refresh the locations list.
            $locationsList = EED_SquareOnsiteOAuth::updateLocationsList($square);
            // Check for an error
            if (isset($locationsList['error'])) {
                echo wp_json_encode([
                    'error' => $locationsList['error']['message'],
                ]);
                exit();
            }

            // And update the location ID if not set.
            $defaultLocation = EED_SquareOnsiteOAuth::updateLocation($square, $locationsList);
            $connected = true;
        }
        echo wp_json_encode([
            'connected'    => $connected,
            'location'     => $defaultLocation,
            'locationList' => $locationsList,
        ]);
        exit();
    }


    /**
     * Retrieve the payment method from the _POST data.
     *
     * @param $postData array
     * @return EE_Payment_Method|bool
     */
    public static function getSubmittedPm($postData)
    {
        try {
            // Check if all the needed parameters are present.
            $submittedPm = isset($postData['submittedPm'])
                ? sanitize_text_field($postData['submittedPm'])
                : false;
            $squarePm = EEM_Payment_Method::instance()->get_one_by_slug($submittedPm);
            if ($squarePm instanceof EE_Payment_Method) {
                return $squarePm;
            }
        } catch (EE_Error $error) {
            return false;
        }
        return false;
    }


    /**
     * Disconnect the current client's account from the EE Square app.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error|ReflectionException
     */
    public static function disconnectAccount()
    {
        $squarePm = EED_SquareOnsiteOAuth::getSubmittedPm($_POST);
        if (! $squarePm) {
            $errMsg = esc_html__('Could not specify the payment method.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg);
        }
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        if (! isset($squareData[ Domain::META_KEY_MERCHANT_ID ]) || ! $squareData[ Domain::META_KEY_MERCHANT_ID ]) {
            echo wp_json_encode(
                [
                    'squareError' => esc_html__('Could not specify the connected merchant.', 'event_espresso'),
                    'alert'       => true,
                ]
            );
            exit();
        }
        $squareMerchantId = $squareData[ Domain::META_KEY_MERCHANT_ID ];

        // We don't need any credentials info anymore, so remove it.
        // This way, even if there is an error communicating with Square,
        // at least we will have forgotten the old connection details so we can use new ones.
        $squarePm->delete_extra_meta(Domain::META_KEY_APPLICATION_ID);
        $squarePm->delete_extra_meta(Domain::META_KEY_ACCESS_TOKEN);
        $squarePm->delete_extra_meta(Domain::META_KEY_LOCATION_ID);
        $squarePm->update_extra_meta(
            Domain::META_KEY_SQUARE_DATA,
            [
                Domain::META_KEY_USING_OAUTH => false,
            ]
        );

        // Tell Square that the account has been disconnected.
        $postArgs = [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'body'        => [
                'merchant_id' => $squareMerchantId,
                'api_version' => Domain::SQUARE_API_VERSION,
            ],
        ];
        if (defined('LOCAL_MIDDLEMAN_SERVER')) {
            $postArgs['sslverify'] = Manager::verifySSL();
        }
        $postUrl = EED_SquareOnsiteOAuth::getMiddlemanBaseUrl($squarePm) . 'deauthorize';
        // POST https://connect.eventespresso.dev/square/deauthorize
        $response = wp_remote_post($postUrl, $postArgs);
        // log the response, don't exit
        EED_SquareOnsiteOAuth::errorLogAndExit(
            $squarePm,
            esc_html__('Request deauthorize', 'event_espresso'),
            (array) $response,
            false
        );

        if (is_wp_error($response)) {
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $response->get_error_message(), [], true, false, true);
        } else {
            $responseBody = (isset($response['body']) && $response['body']) ? json_decode($response['body']) : false;
            // For any error (besides already being disconnected), give an error response.
            if (
                $responseBody === false
                || (
                    isset($responseBody->error)
                    && strpos($responseBody->error_description, 'This application is not connected') === false
                )
            ) {
                if (isset($responseBody->error_description)) {
                    $errMsg = $responseBody->error_description;
                } else {
                    $errMsg = esc_html__('Unknown response received!', 'event_espresso');
                }
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, [], true, false, true);
            }
        }

        echo wp_json_encode([
            'squareSuccess' => true,
        ]);
        exit();
    }


    /**
     * Register the validated (clients) domain with Apple Pay. Ajax call.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function registerDomainAjax()
    {
        $square_pm = EED_SquareOnsiteOAuth::getSubmittedPm($_POST);
        if (! $square_pm) {
            $err_msg = esc_html__('Could not specify the payment method.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($square_pm, $err_msg);
        }

        $response = EED_SquareOnsiteOAuth::registerDomain($square_pm);
        if (! empty($response['error'])) {
            // did we get an error ?
            EED_SquareOnsiteOAuth::errorLogAndExit($square_pm, $response['error'], [], true, true);
        } elseif (empty($response['status'])) {
            $error = esc_html__('Sorry, something went wrong. Got a bad response.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($square_pm, $error, [], true, true);
        }
        // if we got here, all should be good
        echo wp_json_encode($response);
        exit();
    }


    /**
     * Register the validated (clients) domain with Apple Pay.
     *
     * @param EE_Payment_Method $square_pm
     * @return array
     * @throws EE_Error|ReflectionException
     */
    public static function registerDomain(EE_Payment_Method $square_pm): array
    {
        $response     = [];
        $app_id       = $square_pm->get_extra_meta(Domain::META_KEY_APPLICATION_ID, true);
        $access_token = EED_SquareOnsiteOAuth::decryptString(
            $square_pm->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, ''),
            $square_pm->debug_mode()
        );
        $use_dw = $square_pm->get_extra_meta(Domain::META_KEY_USE_DIGITAL_WALLET, true, false);
        if (! $access_token || ! $app_id) {
            return ['error' => esc_html__('Missing required OAuth information.', 'event_espresso')];
        }

        // register the domain
        $square_api   = new SquareApi($access_token, $app_id, $use_dw, $square_pm->debug_mode());
        $domains_api  = new DomainsApi($square_api);
        $api_response = $domains_api->registerDomain(preg_replace('#^https?://#i', '', get_site_url()));
        // log
        $square_pm->type_obj()->get_gateway()->log(
            ['Domain registration' => json_encode($api_response)],
            $square_pm
        );
        if (is_wp_error($api_response)) {
            return ['error' => $api_response->get_error_message()];
        } else {
            if (! empty($api_response['error'])) {
                $err_msg = ! empty($api_response['error']['message'])
                    ? $api_response['error']['message']
                    : esc_html__('Unknown response received!', 'event_espresso');
                return ['error' => $err_msg];
            }
            // should be good
            $response['status'] = ! empty($api_response['status']) ? $api_response['status'] : 'unknown';
        }
        return $response;
    }


    /**
     * Refresh the access token.
     *
     * @param EE_Payment_Method $squarePm
     * @return bool
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error
     * @throws ReflectionException
     * @throws Exception
     */
    public static function refreshToken(EE_Payment_Method $squarePm): bool
    {
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        if (! isset($squareData[ Domain::META_KEY_REFRESH_TOKEN ]) || ! $squareData[ Domain::META_KEY_REFRESH_TOKEN ]) {
            $errMsg = esc_html__('Could not find the refresh token.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, $squareData, false);
            return false;
        }
        $squareRefreshToken = EED_SquareOnsiteOAuth::decryptString(
            $squareData[ Domain::META_KEY_REFRESH_TOKEN ],
            $squarePm->debug_mode()
        );
        $nonce = wp_create_nonce('eepSquareRefreshAccessToken');
        // Try refreshing the token.
        $postArgs = [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'blocking'    => true,
            'body'        => [
                'refresh_token' => $squareRefreshToken,
                'nonce'         => $nonce,
                'api_version'   => Domain::SQUARE_API_VERSION,
            ],
        ];
        if (defined('LOCAL_MIDDLEMAN_SERVER')) {
            $postArgs['sslverify'] = Manager::verifySSL();
        }
        $postUrl = EED_SquareOnsiteOAuth::getMiddlemanBaseUrl($squarePm) . 'refresh';
        // POST https://connect.eventespresso.dev/square/refresh
        // Request the new token.
        $response = wp_remote_post($postUrl, $postArgs);
        if (is_wp_error($response)) {
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $response->get_error_message(), [], false);
            return false;
        } else {
            $responseBody = (isset($response['body']) && $response['body']) ? json_decode($response['body']) : false;
            if (
                ! $responseBody
                || (
                    isset($responseBody->error)
                    && strpos($responseBody->error_description, 'This application is not connected') === false
                )
            ) {
                $errMsg = $responseBody->error_description ??
                          esc_html__('Unknown response received!', 'event_espresso');
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, (array) $response, false);
                return false;
            }
            if (
                empty($responseBody->nonce)
                || ! wp_verify_nonce($responseBody->nonce, 'eepSquareRefreshAccessToken')
                || empty($responseBody->expires_at)
                || empty($responseBody->application_id)
                || empty($responseBody->access_token)
                || empty($responseBody->refresh_token)
                || empty($responseBody->merchant_id)
            ) {
                // This is an error.
                $errMsg = esc_html__('Could not get the refresh token and/or other parameters.', 'event_espresso');
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, (array) $responseBody, false);
                return false;
            }
            // log the response, don't exit
            EED_SquareOnsiteOAuth::errorLogAndExit(
                $squarePm,
                esc_html__('Refresh the token', 'event_espresso'),
                (array) $responseBody,
                false
            );
            // Update the PM data.
            $squarePm->update_extra_meta(
                Domain::META_KEY_APPLICATION_ID,
                sanitize_text_field($responseBody->application_id)
            );
            $squarePm->update_extra_meta(
                Domain::META_KEY_ACCESS_TOKEN,
                EED_SquareOnsiteOAuth::encryptString($responseBody->access_token, $squarePm->debug_mode())
            );
            $refresh_token = EED_SquareOnsiteOAuth::encryptString(
                sanitize_text_field($responseBody->refresh_token),
                $squarePm->debug_mode()
            );
            // Some PM data is combined to reduce DB calls.
            $squarePm->update_extra_meta(
                Domain::META_KEY_SQUARE_DATA,
                [
                    Domain::META_KEY_REFRESH_TOKEN => $refresh_token,
                    Domain::META_KEY_EXPIRES_AT    => sanitize_key($responseBody->expires_at),
                    Domain::META_KEY_MERCHANT_ID   => sanitize_text_field($responseBody->merchant_id),
                    Domain::META_KEY_USING_OAUTH   => true,
                    Domain::META_KEY_THROTTLE_TIME => date("Y-m-d H:i:s"),
                    Domain::META_KEY_LIVE_MODE     => $squarePm->debug_mode() ? '0' : '1',
                ]
            );
            // Also refresh the locations list.
            $locationsList = EED_SquareOnsiteOAuth::updateLocationsList($squarePm);
            // Did we really get an error ?
            if (isset($locationsList['error'])) {
                EED_SquareOnsiteOAuth::errorLogAndExit(
                    $squarePm,
                    $locationsList['error']['message'],
                    (array) $locationsList,
                    false
                );
                return false;
            }
            // And update the location ID if not set.
            EED_SquareOnsiteOAuth::updateLocation($squarePm, $locationsList);
        }
        return true;
    }


    /**
     * Update the list of locations and set the 'main' location as a default location.
     *
     * @param EE_Payment_Method $squarePm
     * @return array
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function updateLocationsList(EE_Payment_Method $squarePm)
    {
        $locations = EED_SquareOnsiteOAuth::getMerchantLocations($squarePm);
        if (! is_array($locations) || isset($locations['error'])) {
            // We got an error.
            return $locations;
        }
        // Get all the locations.
        $locationsList = [];
        foreach ($locations as $location) {
            // Only allow locations that have the credit card processing capability.
            if (in_array('CREDIT_CARD_PROCESSING', $location->capabilities)) {
                $locationsList[ $location->id ] = $location->name;
            }
        }
        // And update the locations option/dropdown.
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        $squareData[ Domain::META_KEY_LOCATIONS_LIST ] = $locationsList;
        $squarePm->update_extra_meta(Domain::META_KEY_SQUARE_DATA, $squareData);
        return $locationsList;
    }


    /**
     * Update the location in case it's not set or not in the updated locations list.
     *
     * @param EE_Payment_Method $squarePm
     * @param array             $locationsList
     * @return string
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function updateLocation(EE_Payment_Method $squarePm, array $locationsList): string
    {
        // Check if the selected location is saved, just in case merchant forgot to hit save.
        $location = $squarePm->get_extra_meta(Domain::META_KEY_LOCATION_ID, true);
        if (! $location || ! isset($locationsList[ $location ])) {
            reset($locationsList);
            $defaultLocation = key($locationsList) ?? '';
            $squarePm->update_extra_meta(Domain::META_KEY_LOCATION_ID, $defaultLocation);
            return $defaultLocation;
        }
        return '';
    }


    /**
     * Get merchant locations from Square.
     *
     * @param EE_Payment_Method $pmInstance
     * @return Object|array
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function getMerchantLocations(EE_Payment_Method $pmInstance)
    {
        try {
            $access_token = EED_SquareOnsiteOAuth::decryptString(
                $pmInstance->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, ''),
                $pmInstance->debug_mode()
            );
            $application_id = $pmInstance->get_extra_meta(Domain::META_KEY_APPLICATION_ID, true);
            $use_digital_wallet = $pmInstance->get_extra_meta(Domain::META_KEY_USE_DIGITAL_WALLET, true);
        } catch (EE_Error | ReflectionException $e) {
            $error['error']['message'] = $e->getMessage();
            $error['error']['code'] = $e->getCode();
            return $error;
        }
        // Create the API object/helper.
        $SquareApi = new SquareApi($access_token, $application_id, $use_digital_wallet, $pmInstance->debug_mode());
        $locations_api = new LocationsApi($SquareApi);
        return $locations_api->listLocations();
    }


    /**
     * Checks if already OAuth'ed.
     *
     * @param EE_Payment_Method $squarePm
     * @return boolean
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function isAuthenticated(EE_Payment_Method $squarePm): bool
    {
        // access token ok ?
        $access_token = $squarePm->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, '');
        $square_data  = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        if (! $access_token || ! $square_data) {
            return false;
        }
        // now check if we can decrypt the token etc.
        $accessToken = EED_SquareOnsiteOAuth::decryptString($access_token, $squarePm->debug_mode());
        if (
            isset($square_data[ Domain::META_KEY_USING_OAUTH ])
            && $square_data[ Domain::META_KEY_USING_OAUTH ]
            && ! empty($accessToken)
        ) {
            return true;
        }
        return false;
    }


    /**
     * Encrypt a text field.
     *
     * @param string $text
     * @param bool   $sandbox_mode
     * @return string|null
     * @throws Exception
     */
    public static function encryptString(string $text, bool $sandbox_mode): ?string
    {
        // We sure we are getting something ?
        if (! $text) {
            return $text;
        }
        // Do encrypt.
        $encryptor = LoaderFactory::getLoader()->getShared(SquareOpenSSLEncryption::class);
        $sanitized_text = sanitize_text_field($text);
        $key_identifier = $sandbox_mode
            ? SquareEncryptionKeyManager::SANDBOX_ENCRYPTION_KEY_ID
            : SquareEncryptionKeyManager::PRODUCTION_ENCRYPTION_KEY_ID;
        return $encryptor->encrypt($sanitized_text, $key_identifier);
    }


    /**
     * Decrypt a text.
     *
     * @param string $text
     * @param bool   $sandbox_mode
     * @return string
     */
    public static function decryptString(string $text, bool $sandbox_mode): string
    {
        // Are we even getting something ?
        if (! $text) {
            return $text;
        }
        // Try decrypting.
        $encryptor = LoaderFactory::getLoader()->getShared(SquareOpenSSLEncryption::class);
        $key_identifier = $sandbox_mode
            ? SquareEncryptionKeyManager::SANDBOX_ENCRYPTION_KEY_ID
            : SquareEncryptionKeyManager::PRODUCTION_ENCRYPTION_KEY_ID;
        $decrypted = $encryptor->decrypt($text, $key_identifier);
        return $decrypted ?? '';
    }


    /**
     * Clean the array of data from sensitive information.
     *
     * @param array $data
     * @return array
     */
    private static function cleanDataArray(array $data): array
    {
        $sensitive_data = ['access_token', 'refresh_token', 'nonce'];
        foreach ($data as $key => $value) {
            $value = is_array($value) ? EED_SquareOnsiteOAuth::cleanDataArray($value) : $value;
            if (in_array($key, $sensitive_data)) {
                $data[ $key ] = empty($value) ? '**empty**' : '**hidden**';
            }
        }
        return $data;
    }


    /**
     * Log an error, return a json message and exit.
     *
     * @param EE_Payment_Method|boolean $square_pm
     * @param string                    $err_msg
     * @param array                     $data
     * @param bool                      $echo_json_and_exit Should we echo json and exit
     * @param bool                      $using_oauth
     * @param bool                      $show_alert         Tells frontend to show an alert or not
     * @return bool
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function errorLogAndExit(
        $square_pm,
        string $err_msg = '',
        array $data = [],
        bool $echo_json_and_exit = true,
        bool $using_oauth = false,
        bool $show_alert = false
    ): bool {
        $default_msg = 'Square error';
        if ($square_pm instanceof EE_Payment_Method) {
            if ($data) {
                $data = self::cleanDataArray($data);
                $default_msg = $err_msg;
                $err_msg = json_encode($data);
            }
            $square_pm->type_obj()->get_gateway()->log(
                [$default_msg => $err_msg],
                $square_pm
            );
        }
        // Do we echo json and exit ?
        if ($echo_json_and_exit) {
            if ($using_oauth) {
                self::closeOauthWindow($err_msg);
            } else {
                self::echoJsonError($err_msg, $show_alert);
            }
        }
        // Yes, simply always return true.
        return true;
    }


    /**
     * Close the OAuth window with JS.
     *
     * @param string $msg
     * @return void
     */
    public static function closeOauthWindow($msg = null)
    {
        $js_out = '<script type="text/javascript">';
        if (! empty($msg)) {
            $js_out .= '
                if ( window.opener ) {
                    try {
                        window.opener.console.log("' . $msg . '");
                    } catch (e) {
                        console.log("' . $msg . '");
                    }
                }
            ';
        }
        $js_out .= 'window.opener = self;
            window.close();
            </script>';

        echo $js_out;
        die();
    }


    /**
     * Close the OAuth window with JS.
     *
     * @param string $err_msg
     * @param bool   $show_alert
     * @return void
     */
    public static function echoJsonError(string $err_msg = '', bool $show_alert = false)
    {
        echo wp_json_encode([
            'squareError' => $err_msg,
            'alert'       => $show_alert,
        ]);
        exit();
    }
}
