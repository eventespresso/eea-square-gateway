<?php

use EventEspresso\Square\domain\Domain;

/**
 * Class EED_OAuthHealthCheck
 *
 * Includes methods that check Square connection (oAuth health).
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\modules
 * @since   $VID:$
 */
class EED_OAuthHealthCheck extends EED_Module
{
    /**
     * @param $WP
     * @return void
     */
    public function run($WP)
    {
        // The health check admin notice.
        self::scheduleCronEvents();
    }


    /**
     * For hooking into EE Core admin, other modules, etc.
     *
     * @access public
     * @return void
     */
    public static function set_hooks_admin()
    {
        // oAuth health check admin notice.
        add_action('admin_notices', [__CLASS__, 'adminNotice']);
    }


    /**
     * Schedule cron events here.
     *
     * @return void
     */
    public static function scheduleCronEvents()
    {
        // API health check event.
        if (! wp_next_scheduled(Domain::CRON_EVENT_HEALTH_CHECK)) {
            // Should check to update twice a day.
            wp_schedule_event(time(), 'twicedaily', Domain::CRON_EVENT_HEALTH_CHECK);
        }
        add_action(Domain::CRON_EVENT_HEALTH_CHECK, [__CLASS__, 'scheduledHealthCheck']);
    }


    /**
     * Admin notice about the health check fail.
     *
     * @return void
     */
    public static function adminNotice()
    {
        $user_id = get_current_user_id();
        $pm_slug = get_user_meta($user_id, Domain::ADMIN_NOTICE_HEALTH_CHECK, true);
        // No PM to notice ? No admin notice.
        if (! $pm_slug) {
            return;
        }

        $pm_settings_page = get_admin_url(
            get_current_blog_id(),
            'admin.php?page=espresso_payment_settings&payment_method=' . $pm_slug
        );
        echo '<div class="error"><p>'
             . sprintf(
                 esc_html__(
                     '%1$s Event Espresso Square %2$s payment method failed the authorization health check! Please try to %3$sre-authorize (reconnect) for the Square payment method%4$s to function properly.',
                     'event_espresso'
                 ),
                 '<strong>',
                 '</strong>',
                 '<a href="' . $pm_settings_page. '">',
                 '</a>'
             )
             . '</p></div>';
    }


    /**
     * Schedule the health check.
     *
     * @return void
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function scheduledHealthCheck()
    {
        // Get all active Square payment methods, as with payment methods pro you can activate few.
        $user_id = get_current_user_id();
        $active_payment_methods = EEM_Payment_Method::instance()->get_all_active(
            EEM_Payment_Method::scope_cart,
            [['PMD_slug' => ['LIKE', '%square%']]]
        );
        foreach ($active_payment_methods as $payment_method) {
            $square_data = $payment_method->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true, []);
            if (isset($square_data[ Domain::META_KEY_USING_OAUTH ])
                && $square_data[ Domain::META_KEY_USING_OAUTH ]
            ) {
                // First check the token and refresh if it's time to.
                self::checkAndRefreshToken($payment_method);
                // Check the credentials and the API connection.
                $oauth_health_check = self::check($payment_method);
                if (isset($oauth_health_check['error'])) {
                    // Try a force refresh.
                    $refreshed = self::checkAndRefreshToken($payment_method, true);
                    // If we still have an error display it to the admin and continue using the "old" oauth key.
                    if (! $refreshed) {
                        // Add an admin notice.
                        update_user_meta($user_id, Domain::ADMIN_NOTICE_HEALTH_CHECK, $payment_method->slug());
                    }
                } else {
                    // Disable the admin notice.
                    update_user_meta($user_id, Domain::ADMIN_NOTICE_HEALTH_CHECK, '');
                }
            }
        }
    }


    /**
     * Checks if the OAuth credentials are still healthy and that we are authorized.
     *
     * @param  EE_Payment_Method $pmInstance
     * @return array ['healthy' => true] | ['error' => ['message' => 'the_message', 'code' => 'the_code']]
     */
    public static function check(EE_Payment_Method $pmInstance): array
    {
        $error = [
            'error' => [
                'code'    => 'NO_ACCESS_TOKEN',
                'message' => esc_html__('One or more authentication parameters are missing', 'event_espresso')
            ]
        ];
        // Double check main oAuth parameters.
        try {
            $access_token = $pmInstance->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, '');
            $app_id       = $pmInstance->get_extra_meta(Domain::META_KEY_APPLICATION_ID, true, '');
        } catch (EE_Error | ReflectionException $e) {
            return $error;
        }
        if (! $access_token || ! $app_id) {
            return $error;
        }

        // Request a list of locations to check API requests.
        $locations = EED_SquareOnsiteOAuth::getMerchantLocations($pmInstance);
        if (is_array($locations) && isset($locations['error'])) {
            switch ($locations['error']['code']) {
                case 'ACCESS_TOKEN_EXPIRED':
                case 'ACCESS_TOKEN_REVOKED':
                case 'INSUFFICIENT_SCOPES':
                    // We have an error. Put it under NOT_AUTHORIZED category for easy identification.
                    $locations['error']['code'] = 'NOT_AUTHORIZED';
                    return $locations;
                default:
                    return $locations;
            }
        }
        return ['healthy' => true];
    }


    /**
     * Checks if the token can/should be refreshed and requests a new one if required.
     *
     * @param EE_Payment_Method $squarePm
     * @param bool $forceRefresh
     * @return boolean
     * @throws EE_Error
     * @throws ReflectionException
     * @throws Exception
     */
    public static function checkAndRefreshToken(EE_Payment_Method $squarePm, bool $forceRefresh = false): bool
    {
        // Check if OAuthed first.
        if (EED_SquareOnsiteOAuth::isAuthenticated($squarePm)) {
            // Is this a force refresh ?
            if ($forceRefresh) {
                return EED_SquareOnsiteOAuth::refreshToken($squarePm);
            }

            // Check the token's validation date.
            $now = new DateTime('now');
            $squareData = $squarePm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
            if (! empty($squareData[ Domain::META_KEY_EXPIRES_AT ])) {
                $expiresAt = new DateTime($squareData[ Domain::META_KEY_EXPIRES_AT ]);
                $daysLeft  = $now->diff($expiresAt)->format('%a');

                // Refresh the token on a 6th day or up, assuming that expiration frame in 30 days.
                if (intval($daysLeft) <= 24) {
                    return EED_SquareOnsiteOAuth::refreshToken($squarePm);
                }
            }
        }
        return false;
    }
}
