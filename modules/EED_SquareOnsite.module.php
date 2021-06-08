<?php

use EventEspresso\Square\api\EESquareOrder;

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
        if (EE_Maintenance_Mode::instance()->models_can_query()) {
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
        $orderId = $transaction->get_extra_meta('order_id', true, false);
        $orderVersion = $transaction->get_extra_meta('order_version', true, false);
        $paymentMethod = $transaction->payment_method();

        // No PM ? No go.
        if (! $paymentMethod instanceof EE_Payment_Method) {
            return;
        }
        $pmSettings = $paymentMethod->settings_array();
        $gateway = $paymentMethod->type_obj()->get_gateway();
        if ($orderId && is_array($pmSettings) && isset($pmSettings['access_token'])) {
            $ordersApi = new EESquareOrder($payment, $gateway, $pmSettings['debug_mode']);
            $ordersApi->setApplicationId($pmSettings['application_id']);
            $ordersApi->setAccessToken($pmSettings['access_token']);
            $ordersApi->setUseDwallet($pmSettings['use_dwallet']);
            $ordersApi->setLocationId($pmSettings['location_id']);
            $ordersApi->cancel($orderId, $orderVersion);
        }
    }
}
