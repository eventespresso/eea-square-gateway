<?php

use EventEspresso\core\services\loaders\LoaderFactory;
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
    public static function instance()
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
        if (EE_Maintenance_Mode::instance()->models_can_query()) {
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
        if (EE_Maintenance_Mode::instance()->models_can_query()) {
            // Request Square initial OAuth data.
            add_action('wp_ajax_squareRequestConnectData', [__CLASS__, 'getConnectionData']);
            // Update the OAuth status.
            add_action('wp_ajax_squareUpdateConnectionStatus', [__CLASS__, 'updateConnectionStatus']);
            // Square disconnect.
            add_action('wp_ajax_squareRequestDisconnect', [__CLASS__, 'disconnectAccount']);
        }
    }


    /**
     * Fetch the user’s authorization credentials.
     * This will handle the user return from the Square authentication page.
     *
     * We expect them to return to a page like:
     * /?webhook_action=eea_square_grab_access_token&access_token=123qwe&nonce=qwe123&refresh_token=123qwe&application_id=123qwe&square_slug=square1&square_user_id12345&livemode=1
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
            ! isset($_GET['webhook_action'], $_GET['nonce'])
            || $_GET['webhook_action'] !== 'eea_square_grab_access_token'
        ) {
            // Not it. Ignore it.
            return;
        }
        // Check that we have all the required parameters and the nonce is ok.
        if (! wp_verify_nonce($_GET['nonce'], 'eea_square_grab_access_token')) {
            // This is an error. Close the window.
            EED_SquareOnsiteOAuth::closeOauthWindow(esc_html__('Nonce fail!', 'event_espresso'));
        }
        if (
            empty($_GET['square_slug'])
            || empty($_GET[ Domain::META_KEY_EXPIRES_AT ])
            || empty($_GET[ Domain::META_KEY_ACCESS_TOKEN ])
            || empty($_GET[ Domain::META_KEY_MERCHANT_ID ])
            || empty($_GET[ Domain::META_KEY_REFRESH_TOKEN ])
            || empty($_GET[ Domain::META_KEY_APPLICATION_ID ])
            || empty($_GET[ Domain::META_KEY_LIVE_MODE ])
        ) {
            // Missing parameters for some reason. Can't proceed.
            EED_SquareOnsiteOAuth::closeOauthWindow(esc_html__('Missing OAuth required parameters.', 'event_espresso'));
        }

        // Get pm data.
        $squarePm = EEM_Payment_Method::instance()->get_one_by_slug(sanitize_key($_GET['square_slug']));
        if (! $squarePm instanceof EE_Payment_Method) {
            EED_SquareOnsiteOAuth::closeOauthWindow(
                esc_html__(
                    'Could not specify the payment method !',
                    'event_espresso'
                )
            );
        }
        // Update the PM data.
        $squarePm->update_extra_meta(
            Domain::META_KEY_ACCESS_TOKEN,
            EED_SquareOnsiteOAuth::encryptString($_GET[ Domain::META_KEY_ACCESS_TOKEN ], $squarePm->debug_mode())
        );
        $squarePm->update_extra_meta(
            Domain::META_KEY_APPLICATION_ID,
            sanitize_text_field($_GET[ Domain::META_KEY_APPLICATION_ID ])
        );
        $refresh_token = EED_SquareOnsiteOAuth::encryptString(
            sanitize_text_field($_GET[ Domain::META_KEY_REFRESH_TOKEN ]),
            $squarePm->debug_mode()
        );
        $squarePm->update_extra_meta(
            Domain::META_KEY_SQUARE_DATA,
            [
                Domain::META_KEY_REFRESH_TOKEN => $refresh_token,
                Domain::META_KEY_EXPIRES_AT    => sanitize_key($_GET[ Domain::META_KEY_EXPIRES_AT ]),
                Domain::META_KEY_MERCHANT_ID   => sanitize_text_field($_GET[ Domain::META_KEY_MERCHANT_ID ]),
                Domain::META_KEY_LIVE_MODE     => sanitize_key($_GET[ Domain::META_KEY_LIVE_MODE ]),
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
        $squareSlug = sanitize_key($_POST['submittedPm']);
        // Just save the debug mode option if it was changed..
        // It simplifies the rest of this process. PM settings might also not be saved after the OAuth process.
        if (
            array_key_exists('debugMode', $_POST)
            && in_array($_POST['debugMode'], ['0', '1'], true)
            && (bool) $square->debug_mode() !== (bool) $_POST['debugMode']
        ) {
            $square->save(['PMD_debug_mode' => $_POST['debugMode']]);
        }
        $nonce = wp_create_nonce('eea_square_grab_access_token');
        // OAuth return handler.
        $redirectUri = add_query_arg(
            [
                'webhook_action'           => 'eea_square_grab_access_token',
                'square_slug'              => $squareSlug,
                'nonce'                    => $nonce,
                Domain::META_KEY_LIVE_MODE => $square->debug_mode() ? '0' : '1',
            ],
            site_url()
        );
        // Request URL should look something like:
        // @codingStandardsIgnoreStart
        // https://connect.eventespresso.dev/squarepayments/forward?return_url=http%253A%252F%252Fsrc.wordpress-develop.dev%252Fwp-admin%252Fadmin.php%253Fpage%253Dwc-settings%2526amp%253Btab%253Dintegration%2526amp%253Bsection%253Dsquareconnect%2526amp%253Bwc_square_token_nonce%253D6585f05708&scope=read_write
        // @codingStandardsIgnoreEnd
        $request_url = add_query_arg(
            [
                'return_url' => rawurlencode($redirectUri),
                'scope'      => urlencode(
                    'PAYMENTS_WRITE PAYMENTS_READ ORDERS_WRITE ORDERS_READ MERCHANT_PROFILE_READ'
                ),
                'modal'      => true
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
    public static function getMiddlemanBaseUrl(EE_Payment_Method $paymentMethod)
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
            $postArgs['sslverify'] = false;
        }
        $postUrl = EED_SquareOnsiteOAuth::getMiddlemanBaseUrl($squarePm) . 'deauthorize';
        // POST https://connect.eventespresso.dev/square/deauthorize
        // Request the token.
        $response = wp_remote_post($postUrl, $postArgs);

        if (is_wp_error($response)) {
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $response->get_error_message(), true, true);
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

                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, true, true);
            }
        }

        echo wp_json_encode([
            'squareSuccess' => true,
        ]);
        exit();
    }


    /**
     * Refresh the access token.
     *
     * @param EE_Payment_Method $squarePm
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidInterfaceException
     * @throws InvalidDataTypeException
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function refreshToken(EE_Payment_Method $squarePm)
    {
        if (! $squarePm instanceof EE_Payment_Method) {
            $errMsg = esc_html__('Could not specify the payment method.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, false);
        }
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        if (! isset($squareData[ Domain::META_KEY_REFRESH_TOKEN ]) || ! $squareData[ Domain::META_KEY_REFRESH_TOKEN ]) {
            $errMsg = esc_html__('Could not find the refresh token.', 'event_espresso');
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, false);
        }
        $squareRefreshToken = EED_SquareOnsiteOAuth::decryptString(
            $squareData[ Domain::META_KEY_REFRESH_TOKEN ],
            $squarePm->debug_mode()
        );
        $nonce = wp_create_nonce('eea_square_refresh_access_token');

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
            $postArgs['sslverify'] = false;
        }
        $postUrl = EED_SquareOnsiteOAuth::getMiddlemanBaseUrl($squarePm) . 'refresh';

        // POST https://connect.eventespresso.dev/square/refresh
        // Request the new token.
        $response = wp_remote_post($postUrl, $postArgs);
        if (is_wp_error($response)) {
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $response->get_error_message(), false);
        } else {
            $responseBody = (isset($response['body']) && $response['body']) ? json_decode($response['body']) : false;
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
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, false);
            }

            if (
                ! wp_verify_nonce($responseBody->nonce, 'eea_square_refresh_access_token')
                || empty($responseBody->expires_at)
                || empty($responseBody->application_id)
                || empty($responseBody->access_token)
                || empty($responseBody->refresh_token)
                || empty($responseBody->merchant_id)
            ) {
                // This is an error.
                $errMsg = esc_html__('Could not get the refresh token and/or other parameters.', 'event_espresso');
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, false);
            }

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
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $locationsList['error']['message'], false);
            }

            // And update the location ID if not set.
            $defaultLocation = EED_SquareOnsiteOAuth::updateLocation($squarePm, $locationsList);
        }
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
    public static function updateLocation(EE_Payment_Method $squarePm, array $locationsList)
    {
        // Check if the selected location is saved, just in case merchant forgot to hit save.
        $location = $squarePm->get_extra_meta(Domain::META_KEY_LOCATION_ID, true);
        if (! $location || ! isset($locationsList[ $location ])) {
            $defaultLocation = array_key_first($locationsList);
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
     * Checks if the token can/should be refreshed and requests a new one if required.
     *
     * @param EE_Payment_Method $squarePm
     * @return boolean
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function checkAndRefreshToken($squarePm)
    {
        // Check if OAuthed first.
        if (EED_SquareOnsiteOAuth::isAuthenticated($squarePm)) {
            // Throttle the requests a bit.
            $now = new DateTime('now');
            $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
            if (isset($squareData[ Domain::META_KEY_THROTTLE_TIME ]) && $squareData[ Domain::META_KEY_THROTTLE_TIME ]) {
                $throttleTime = new DateTime($squareData[ Domain::META_KEY_THROTTLE_TIME ]);
                $lastChecked = $now->diff($throttleTime)->format('%a');
                // Throttle, allowing only once per 2 days.
                if (intval($lastChecked) < 2) {
                    return false;
                }
            }
            $squareData[ Domain::META_KEY_THROTTLE_TIME ] = date("Y-m-d H:i:s");
            $squarePm->update_extra_meta(Domain::META_KEY_SQUARE_DATA, $squareData);

            // Now check the token's validation date.
            if (isset($squareData[ Domain::META_KEY_EXPIRES_AT ]) && $squareData[ Domain::META_KEY_EXPIRES_AT ]) {
                $expiresAt = new DateTime($squareData[ Domain::META_KEY_EXPIRES_AT ]);
                $timeLeft = $now->diff($expiresAt);
                $daysLeft = $timeLeft->format('%a');

                // Refresh the token on a 6th day or up.
                if (intval($daysLeft) <= 24) {
                    EED_SquareOnsiteOAuth::refreshToken($squarePm);
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * Checks if already OAuth'ed.
     *
     * @param EE_Payment_Method $squarePm
     * @return boolean
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function isAuthenticated($squarePm): bool
    {
        if (! $squarePm) {
            return false;
        }
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        $accessToken = EED_SquareOnsiteOAuth::decryptString(
            $squarePm->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, ''),
            $squarePm->debug_mode()
        );
        if (
            isset($squareData[ Domain::META_KEY_USING_OAUTH ])
            && $squareData[ Domain::META_KEY_USING_OAUTH ]
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
     * @return string|null
     */
    public static function decryptString(string $text, bool $sandbox_mode): ?string
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
        return $encryptor->decrypt($text, $key_identifier);
    }


    /**
     * Log an error, return a json message and exit.
     *
     * @param EE_Payment_Method|boolean $squarePm
     * @param null                      $errMsg
     * @param bool                      $doExit Should we echo json and exit
     * @param bool                      $alert Tells frontend to show an alert or not
     * @return void
     * @throws EE_Error
     */
    public static function errorLogAndExit($squarePm, $errMsg = null, $doExit = true, $alert = false)
    {
        if ($squarePm instanceof EE_Payment_Method) {
            $squarePm->type_obj()->get_gateway()->log(
                ['Square error' => $errMsg],
                'Payment_Method'
            );
        }
        // Do we echo json and exit ?
        if ($doExit) {
            echo wp_json_encode([
                'squareError' => $errMsg,
                'alert'       => $alert,
            ]);
            exit();
        }
    }


    /**
     * Log an error and close the OAuth window with JS.
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
}
