<?php

use EventEspresso\Square\api\order\CancelOrder;
use EventEspresso\Square\api\SquareApi;
use EventEspresso\Square\domain\Domain;

/**
 * Class EED_SquareOnsite
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EED_SquareOnsite extends EED_Module
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
        if (
            (
                class_exists('EventEspresso\core\domain\services\database\DbStatus')
                && EventEspresso\core\domain\services\database\DbStatus::isOnline()
            )
            || EE_Maintenance_Mode::instance()->models_can_query()
        ) {
            // Cancel the Square order on abandoned/failed transactions.
            add_action(
                'AHEE__EE_Cron_Tasks__process_expired_transactions__incomplete_transaction',
                [__CLASS__, 'cancelSquareOrder'],
                10,
                1
            );
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
    }


    /**
     * Cancels the Order on an abandoned transaction.
     *
     * @param EE_Transaction $transaction
     * @return void
     * @throws ReflectionException|EE_Error
     */
    public static function cancelSquareOrder(EE_Transaction $transaction)
    {
        // Don't touch free or paid transactions.
        if ($transaction->is_free() || $transaction->paid() > 0) {
            return;
        }
        $payments = $transaction->payments();
        // Also skip if any payments were successful.
        foreach ($payments as $payment) {
            if ($payment instanceof EE_Payment && $payment->status() === EEM_Payment::status_id_approved) {
                return;
            }
        }

        // Transaction is considered abandoned. Now we can try canceling the associated Order.
        $paymentMethod = $transaction->payment_method();
        // No PM ? No go.
        if (! $paymentMethod instanceof EE_Payment_Method) {
            return;
        }
        $order_id      = $transaction->get_extra_meta('order_id', true, false);
        $order_version = $transaction->get_extra_meta('order_version', true, false);
        $pm_settings   = $paymentMethod->settings_array();
        $access_token  = ! empty($pm_settings[ Domain::META_KEY_ACCESS_TOKEN ])
            ? EED_SquareOnsiteOAuth::decryptString(
                $pm_settings[ Domain::META_KEY_ACCESS_TOKEN ],
                $pm_settings['debug_mode']
            )
            : false;
        if ($order_id && $access_token) {
            $SquareApi = new SquareApi(
                $access_token,
                $pm_settings[ Domain::META_KEY_APPLICATION_ID ],
                $pm_settings[ Domain::META_KEY_USE_DIGITAL_WALLET ],
                $pm_settings['debug_mode'],
                $pm_settings[ Domain::META_KEY_LOCATION_ID ]
            );
            $CancelOrder = new CancelOrder($SquareApi, $transaction->ID());
            $CancelOrder->cancel($order_id, $order_version);
        }
    }


    /**
     * Gets the country ISO by its name
     *
     * @param string $country_name
     * @return string
     * @throws EE_Error|ReflectionException
     */
    public static function getCountryIsoByName(string $country_name): string
    {
        $country_model = EEM_Country::instance();
        $country = method_exists($country_model, 'getCountryByName')
            ? $country_model->getCountryByName($country_name)
            : $country_model->get_one([['CNT_name' => $country_name]]);
        return $country instanceof EE_Country ? $country->ID() : '';
    }
}
