<?php

use EventEspresso\core\exceptions\InvalidDataTypeException;
use EventEspresso\core\exceptions\InvalidEntityException;
use EventEspresso\core\exceptions\InvalidInterfaceException;
use EventEspresso\Square\domain\Domain;

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
     * @throws EE_Error
     */
    public static function registerAddon()
    {
        // Register add-on via EE Plugin API.
        EE_Register_Addon::register(
            'SquareGateway',
            [
                'plugin_slug'          => Domain::LICENSE_PLUGIN_SLUG,
                'version'              => EEA_SQUARE_GATEWAY_VERSION,
                'min_core_version'     => Domain::CORE_VERSION_REQUIRED,
                'main_file_path'       => EEA_SQUARE_GATEWAY_PLUGIN_FILE,
                'admin_callback'       => 'additionalAdminHooks',
                'payment_method_paths' => [
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS . 'SquareOnsite',
                ],
                // Register auto-loaders.
                'autoloader_paths'     => [
                    'OAuthForm'    => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                                      'SquareOnsite' . DS . 'forms' . DS . 'OAuthForm.php',
                    'BillingForm'  => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                                      'SquareOnsite' . DS . 'forms' . DS . 'BillingForm.php',
                    'SettingsForm' => EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS .
                                      'SquareOnsite' . DS . 'forms' . DS . 'SettingsForm.php',
                ],
                'module_paths'         => [
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'modules' . DS . 'EED_SquareOnsite.module.php',
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'modules' . DS . 'EED_SquareOnsiteOAuth.module.php',
                    EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'modules' . DS . 'EED_OAuthHealthCheck.module.php',
                ],
                'namespace'            => [
                    'FQNS' => 'EventEspresso\Square',
                    'DIR'  => __DIR__,
                ],
                'license'              => [
                    'beta'             => false,
                    'main_file_path'   => EEA_SQUARE_GATEWAY_PLUGIN_FILE,
                    'min_core_version' => Domain::CORE_VERSION_REQUIRED,
                    'plugin_id'        => Domain::LICENSE_PLUGIN_ID,
                    'plugin_name'      => Domain::LICENSE_PLUGIN_NAME,
                    'plugin_slug'      => Domain::LICENSE_PLUGIN_SLUG,
                    'version'          => EEA_SQUARE_GATEWAY_VERSION,
                    'wp_override'      => false,
                ],
                'pue_options'          => [
                    'pue_plugin_slug' => 'eea-square-gateway',
                    'plugin_basename' => EEA_SQUARE_GATEWAY_PLUGIN_BASENAME,
                    'checkPeriod'     => '24',
                    'use_wp_update'   => false,
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
        $this->registerDependencies();
    }


    /**
     * Register object dependencies.
     *
     * @return void
     * @throws InvalidArgumentException
     * @throws InvalidDataTypeException
     * @throws DomainException
     * @throws InvalidInterfaceException
     * @throws InvalidEntityException
     */
    protected function registerDependencies()
    {
        $this->dependencyMap()->registerDependencies(
            'EventEspresso\Square\tools\encryption\SquareOpenSSLEncryption',
            [
                'EventEspresso\core\services\encryption\Base64Encoder' => EE_Dependency_Map::load_from_cache,
            ]
        );
        $this->dependencyMap()->registerDependencies(
            'EventEspresso\Square\tools\encryption\SquareEncryptionKeyManager',
            [
                'EventEspresso\core\services\encryption\Base64Encoder' => EE_Dependency_Map::load_from_cache,
            ]
        );
    }


    /**
     * Additional admin hooks.
     *
     * @access public
     * @return void
     */
    public static function additionalAdminHooks()
    {
        // is admin and not in M-Mode ?
        if (
            is_admin()
            && (
                class_exists('EventEspresso\core\domain\services\database\MaintenanceStatus')
                && EventEspresso\core\domain\services\database\MaintenanceStatus::isDisabled()
            ) || ! EE_Maintenance_Mode::instance()->level()
        ) {
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
