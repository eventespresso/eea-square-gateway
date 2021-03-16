<?php

use EventEspresso\Square\domain\Domain;
use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\exceptions\InvalidInterfaceException;

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
     * @return EED_Module|EED_SquareOnsiteOAuth
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
        if (! isset($_GET['webhook_action'], $_GET['nonce'])
            || $_GET['webhook_action'] !== 'eea_square_grab_access_token'
        ) {
            // Not it. Ignore it.
            return;
        }
        // Check that we have all the required parameters and the nonce is ok.
        if (! isset(
            $_GET['square_slug'],
            $_GET[ Domain::META_KEY_EXPIRES_AT ],
            $_GET[ Domain::META_KEY_ACCESS_TOKEN ],
            $_GET[ Domain::META_KEY_MERCHANT_ID ],
            $_GET[ Domain::META_KEY_REFRESH_TOKEN ],
            $_GET[ Domain::META_KEY_APPLICATION_ID ],
            $_GET[ Domain::META_KEY_LIVE_MODE ],
            $_GET[ Domain::META_KEY_LOCATION_ID ],
        )
            || ! wp_verify_nonce($_GET['nonce'], 'eea_square_grab_access_token')
        ) {
            // This is an error. Close the window.
            EED_SquareOnsiteOAuth::closeOauthWindow(esc_html__('Nonce fail!', 'event_espresso'));
        }

        // Get pm data.
        $squarePm = EEM_Payment_Method::instance()->get_one_by_slug(sanitize_key($_GET['square_slug']));
        if (! $squarePm instanceof EE_Payment_Method) {
            EED_SquareOnsiteOAuth::closeOauthWindow(
                esc_html__(
                    'Could not specify the payment method!',
                    'event_espresso'
                )
            );
        }
        // Update the PM data.
        $squarePm->update_extra_meta(
            Domain::META_KEY_ACCESS_TOKEN,
            sanitize_text_field($_GET[ Domain::META_KEY_ACCESS_TOKEN ])
        );
        $squarePm->update_extra_meta(
            Domain::META_KEY_APPLICATION_ID,
            sanitize_text_field($_GET[ Domain::META_KEY_APPLICATION_ID ])
        );
        $squarePm->update_extra_meta(
            Domain::META_KEY_LOCATION_ID,
            sanitize_text_field($_GET[ Domain::META_KEY_LOCATION_ID ])
        );
        $squarePm->update_extra_meta(
            Domain::META_KEY_SQUARE_DATA,
            [
                Domain::META_KEY_REFRESH_TOKEN => sanitize_text_field($_GET[ Domain::META_KEY_REFRESH_TOKEN ]),
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
     * @throws ReflectionException
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
        if (array_key_exists('debugMode', $_POST)
            && in_array($_POST['debugMode'], ['0', '1'], true)
            && $square->debug_mode() !== (int) $_POST['debugMode']
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
                    'PAYMENTS_WRITE PAYMENTS_READ ORDERS_WRITE ORDERS_READ ITEMS_WRITE ITEMS_READ'
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
     * If LOCAL_MIDDLEMAN_SERVER is defined, requests wi be sent to connect.eventespresso.dev.
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
        $squareData = [];
        $square = EED_SquareOnsiteOAuth::getSubmittedPm($_POST);
        if ($square) {
            $squareData = $square->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
            $accessToken = $square->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true);
        }
        $connected = true;
        if (empty($accessToken)
            || ! isset($squareData[ Domain::META_KEY_USING_OAUTH ])
            || ! $squareData[ Domain::META_KEY_USING_OAUTH ]
        ) {
            $connected = false;
        }
        echo wp_json_encode([
            'connected' => $connected,
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
            EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $response->get_error_message());
        } else {
            $responseBody = (isset($response['body']) && $response['body']) ? json_decode($response['body']) : false;
            // For any error (besides already being disconnected), give an error response.
            if ($responseBody === false
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

                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg);
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
        $squareRefreshToken = $squareData[ Domain::META_KEY_REFRESH_TOKEN ];
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
            if ($responseBody === false
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

            if (! wp_verify_nonce($responseBody->nonce, 'eea_square_refresh_access_token')
                || ! isset(
                    $responseBody->expires_at,
                    $responseBody->application_id,
                    $responseBody->access_token,
                    $responseBody->refresh_token,
                    $responseBody->merchant_id,
                    $responseBody->location_id,
                )
            ) {
                // This is an error.
                $errMsg = esc_html__('Could not get the refresh token.', 'event_espresso');
                EED_SquareOnsiteOAuth::errorLogAndExit($squarePm, $errMsg, false);
            }

            // Update the PM data.
            $squarePm->update_extra_meta(
                Domain::META_KEY_APPLICATION_ID,
                sanitize_text_field($responseBody->application_id)
            );
            $squarePm->update_extra_meta(
                Domain::META_KEY_ACCESS_TOKEN,
                sanitize_text_field($responseBody->access_token)
            );
            $squarePm->update_extra_meta(
                Domain::META_KEY_LOCATION_ID,
                sanitize_text_field($responseBody->application_id)
            );

            // Some PM data is combined to reduce DB calls.
            $squarePm->update_extra_meta(
                Domain::META_KEY_SQUARE_DATA,
                [
                    Domain::META_KEY_REFRESH_TOKEN => sanitize_text_field($responseBody->refresh_token),
                    Domain::META_KEY_EXPIRES_AT    => sanitize_key($responseBody->expires_at),
                    Domain::META_KEY_MERCHANT_ID   => sanitize_text_field($responseBody->merchant_id),
                    Domain::META_KEY_USING_OAUTH   => true,
                    Domain::META_KEY_THROTTLE_TIME => date("Y-m-d H:i:s"),
                ]
            );
        }
    }


    /**
     * Checks if the token can/should be refreshed and requests a new one if required.
     *
     * @param EE_Payment_Method $squarePm
     * @return boolean
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
                // Throttle, allowing only once per 3 days.
                if (intval($lastChecked) < 3) {
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

                // Request a refresh if less than 5 days left.
                if (intval($daysLeft) <= 5) {
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
    public static function isAuthenticated($squarePm)
    {
        if (! $squarePm) {
            return false;
        }
        $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        $accessToken = $squarePm->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true);
        $authType = $squarePm->get_extra_meta(Domain::META_KEY_AUTH_TYPE, true);
        if ($authType === 'oauth'
            && isset($squareData[ Domain::META_KEY_USING_OAUTH ])
            && $squareData[ Domain::META_KEY_USING_OAUTH ]
            && ! empty($accessToken)
        ) {
            return true;
        }
        return false;
    }


    /**
     * Log an error, return a json message and exit.
     *
     * @param EE_Payment_Method|boolean $squarePm
     * @param string $errMsg
     * @param bool $doExit Should we echo json and exit
     * @return void
     */
    public static function errorLogAndExit($squarePm, $errMsg = null, $doExit = true)
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
        $js_out = '
        <script type="text/javascript">';
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
