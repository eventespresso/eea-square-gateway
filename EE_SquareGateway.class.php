<?php

define('EEA_SQUARE_GATEWAY_PLUGIN_URL', plugin_dir_url(EEA_SQUARE_GATEWAY_PLUGIN_FILE));
define('EEA_SQUARE_GATEWAY_PLUGIN_BASENAME', plugin_basename(EEA_SQUARE_GATEWAY_PLUGIN_FILE));


/**
 * Class EE_SquareGateway
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EE_SquareGateway extends EE_Addon
{
    /**
     * Register this add-on with EE.
     *
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public static function registerAddon()
    {
        // Register add-on via EE Plugin API.
        EE_Register_Addon::register(
            'SquareGateway',
            [
                'version'              => EEA_SQUARE_GATEWAY_VERSION,
                'min_core_version'     => '4.9.26.rc.000',
                'main_file_path'       => EEA_SQUARE_GATEWAY_PLUGIN_FILE,
                'admin_callback'       => 'additionalAdminHooks',
                'payment_method_paths' => [
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS . 'SquareOnsite',
                ],
                // Register auto-loaders.
                'autoloader_paths' => [
                    'OAuthForm'       => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                        'SquareOnsite' . DS . 'forms' . DS . 'OAuthForm.php',
                    'BillingForm'     => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                        'SquareOnsite' . DS . 'forms' . DS . 'BillingForm.php',
                    'SettingsForm'    => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                        'SquareOnsite' . DS . 'forms' . DS . 'SettingsForm.php',
                    'EESquareApiBase' => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'api' . DS . 'EESquareApiBase.php',
                    'EESquareOrder'   => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'api' . DS . 'EESquareOrder.php',
                    'EESquarePayment' => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'api' . DS . 'EESquarePayment.php',
                ],
                'module_paths' => [
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'modules' . DS . 'EED_SquareOnsiteOAuth.module.php',
                ],
                // If plugin update engine is being used for auto-updates. not needed if PUE is not being used.
                'pue_options' => [
                    'pue_plugin_slug' => 'eea-square-gateway',
                    'plugin_basename' => EEA_SQUARE_GATEWAY_PLUGIN_BASENAME,
                    'checkPeriod'     => '24',
                    'use_wp_update'   => false,
                ],
                'namespace' => [
                    'FQNS' => 'EventEspresso\Square',
                    'DIR'  => __DIR__,
                ],
            ]
        );
    }


    /**
     * A safe space for addons to add additional logic like setting hooks
     * that will run immediately after addon registration
     * making this a great place for code that needs to be "omnipresent"
     *
     * @return void
     */
    public function after_registration()
    {
    }


    /**
     * Additional admin hooks.
     *
     * @access public
     * @return void
     */
    public static function additionalAdminHooks()
    {
        // Is admin and not in M-Mode ?
        if (is_admin() && ! EE_Maintenance_Mode::instance()->level()) {
            add_filter('plugin_action_links', ['EE_SquareGateway', 'pluginActions'], 10, 2);
        }
    }


    /**
     * Add a settings link to the Plugins page.
     *
     * @param $links
     * @param $file
     * @return array
     */
    public static function pluginActions($links, $file)
    {
        if ($file === EEA_SQUARE_GATEWAY_PLUGIN_BASENAME) {
            // Before other links
            array_unshift(
                $links,
                '<a href="admin.php?page=espresso_payment_settings">'
                    . esc_html__('Settings', 'event_espresso') . '</a>'
            );
        }
        return $links;
    }
}
// End of file EE_SquareGateway.class.php
