<?php

use EventEspresso\Square\domain\Domain;

/**
 * Class EED_OAuthHealthCheck
 *
 * Includes methods that check Square connection (oAuth health).
 *
 * @author  Nazar Kolivoshka
 * @package EventEspresso\Square\modules
 * @since   1.0.4.p
 */
class EED_OAuthHealthCheck extends EED_Module
{
    /**
     * @param $WP
     * @return void
     */
    public function run($WP)
    {
    }


    /**
     * For hooking into EE Core, other modules, etc
     *
     * @access public
     * @return void
     */
    public static function set_hooks()
    {
        // Health check cron event.
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
        // Health check cron event.
        self::scheduleCronEvents();
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
                 '<a href="' . $pm_settings_page . '">',
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
    public static function scheduledHealthCheck(): void
    {
        // Get all active Square payment methods, as with payment methods pro you can activate few.
        $user_id = get_current_user_id();
        $active_payment_methods = EEM_Payment_Method::instance()->get_all_active(
            EEM_Payment_Method::scope_cart,
            [['PMD_slug' => ['LIKE', '%square%']]]
        );
        foreach ($active_payment_methods as $payment_method) {
            if (EED_SquareOnsiteOAuth::isAuthenticated($payment_method)) {
                // Check the token and the API connection.
                $oauth_health_check = self::check($payment_method);
                if (isset($oauth_health_check['error'])) {
                    // Try a force refresh.
                    $refreshed = EED_SquareOnsiteOAuth::refreshToken($payment_method);
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
     * @param EE_Payment_Method $pm_instance
     * @return array ['healthy' => true] | ['error' => ['message' => 'the_message', 'code' => 'the_code']]
     * @throws Exception
     */
    public static function check(EE_Payment_Method $pm_instance): array
    {
        try {
            // Check the token.
            if (EED_OAuthHealthCheck::isTokenDue($pm_instance)) {
                $refreshed = EED_SquareOnsiteOAuth::refreshToken($pm_instance);
                if (! $refreshed) {
                    return [
                        'error' => [
                            'code'    => 'REFRESH_FAIL',
                            'message' => esc_html__('Could not refresh the token', 'event_espresso')
                        ]
                    ];
                }
            }
        } catch (Exception $e) {
            return [
                'error' => [
                    'code'    => 'EXCEPTION_THROWN',
                    'message' => $e->getMessage()
                ]
            ];
        }
        // Check the API requests. (disabled because of error suspicions on the client side)
        return EED_OAuthHealthCheck::APIHealthCheck($pm_instance);
    }


    /**
     * Do an API health check by sending a request and checking for a correct response.
     *
     * @param EE_Payment_Method $pm_instance
     * @return array
     * @throws Exception
     */
    public static function APIHealthCheck(EE_Payment_Method $pm_instance): array
    {
        $locations = EED_SquareOnsiteOAuth::getMerchantLocations($pm_instance);
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
     * @param EE_Payment_Method $square_pm
     * @return boolean
     * @throws EE_Error
     * @throws ReflectionException
     * @throws Exception
     */
    public static function isTokenDue(EE_Payment_Method $square_pm): bool
    {
        // Check the token's validation date.
        $square_data = $square_pm->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        if (! empty($square_data[ Domain::META_KEY_EXPIRES_AT ])) {
            $expires_at = strtotime($square_data[ Domain::META_KEY_EXPIRES_AT ]);
            $days_left  = floor(($expires_at - time()) / (60 * 60 * 24));
            // Refresh the token on a 6th day or up, assuming that expiration frame in 30 days.
            if (intval($days_left) <= 24) {
                return true;
            }
        }
        return false;
    }
}
