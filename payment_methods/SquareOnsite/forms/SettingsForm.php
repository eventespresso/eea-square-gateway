<?php

namespace EventEspresso\Square\payment_methods\SquareOnsite\forms;

use EE_Button_Input;
use EE_Payment_Method_Form;
use EE_PMT_SquareOnsite;
use EE_Payment_Method;
use EE_Select_Input;
use EE_Yes_No_Input;
use EED_SquareOnsiteOAuth;
use EventEspresso\Square\domain\Domain;
use EE_Error;
use EE_Form_Section_HTML;
use ReflectionException;

/**
 * Class SettingsForm
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class SettingsForm extends EE_Payment_Method_Form
{
    /**
     *  Payment method.
     *
     * @var array
     */
    protected $squareData = [];

    /**
     *  The list of locations.
     *
     * @var array
     */
    protected $locations_list = [];

    /**
     * Class constructor.
     *
     * @param  EE_PMT_SquareOnsite $paymentMethod
     * @param  EE_Payment_Method   $pmInstance
     * @throws EE_Error|ReflectionException
     */
    public function __construct(EE_PMT_SquareOnsite $paymentMethod, EE_Payment_Method $pmInstance)
    {
        $pmFormParams         = [];
        $this->squareData     = $pmInstance->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true, []);
        $this->locations_list = $this->squareData[ Domain::META_KEY_LOCATIONS_LIST ] ?? [];

        // add the Locations select input
        $pmFormParams = $this->addLocationsList($pmFormParams, $paymentMethod, $pmInstance);
        // fields for basic authentication settings
        $pmFormParams = $this->addDigitalWalletToggle($pmFormParams, $paymentMethod, $pmInstance);
        // add the domain registering button
        $pmFormParams = $this->addRegisterDomainButton($pmFormParams, $paymentMethod, $pmInstance);

        // Build the PM form.
        parent::__construct($pmFormParams);

        // Check if OAuth'ed in the correct origin (debug vs live).
        $this->checkOauthOrigin($pmInstance);
        // Validate connection.
        $this->validateConnection($pmInstance);
        // add the OAuth section
        $this->addSquareConnectButton($paymentMethod, $pmInstance);
        // Disable inputs if needed
        $this->toggleSubsections($pmInstance);
    }


    /**
     *  Add the connect button to the PM settings page.
     *
     * @param  EE_PMT_SquareOnsite $paymentMethod
     * @param  EE_Payment_Method   $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public function addSquareConnectButton(EE_PMT_SquareOnsite $paymentMethod, EE_Payment_Method $pmInstance)
    {
        // Add the connect button before the app id field.
        $oauthTemplate = new OAuthForm($paymentMethod, $pmInstance);
        $this->add_subsections(
            [
                'square_oauth' => new EE_Form_Section_HTML($oauthTemplate->get_html_and_js()),
            ],
            'PMD_debug_mode',
            false
        );
    }


    /**
     * Validate the connection.
     *
     * @param  EE_Payment_Method $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public function validateConnection(EE_Payment_Method $pmInstance)
    {
        if (isset($this->squareData[ Domain::META_KEY_USING_OAUTH ])
            && $this->squareData[ Domain::META_KEY_USING_OAUTH ]
        ) {
            // First check the token and refresh if it's time to.
            EED_SquareOnsiteOAuth::checkAndRefreshToken($pmInstance);
            // Check the credentials and the API connection.
            $oauthHealthCheck = EED_SquareOnsiteOAuth::oauthHealthCheck($pmInstance);
            if (isset($oauthHealthCheck['error'])) {
                // Try a force refresh.
                $refreshed = EED_SquareOnsiteOAuth::checkAndRefreshToken($pmInstance, true);
                // If we still have an error display it to the admin and continue using the "old" oauth key.
                if (! $refreshed) {
                    EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'OAuth error', $oauthHealthCheck, false);
                    $this->add_validation_error(
                        sprintf(
                            // translators: %1$s: the error message.
                            esc_html__(
                                'Authorization health check failed with error: "%1$s" Please try to re-authorize (reConnect) for the Square payment method to function properly.',
                                'event_espresso'
                            ),
                            $oauthHealthCheck['error']['message']
                        ),
                        'eea_square_oauth_connection_reset_request'
                    );
                }
            }
        }
    }


    /**
     * Check OAuth's origin nad possibly exclude the default authentication fields.
     *
     * @param  EE_Payment_Method $pmInstance
     * @return void
     * @throws EE_Error
     */
    public function checkOauthOrigin(EE_Payment_Method $pmInstance)
    {
        $pmDebugMode = $pmInstance->debug_mode();
        $debugInput  = $this->get_input('PMD_debug_mode', false);
        // Do nothing if not connected.
        if (! isset($this->squareData[ Domain::META_KEY_USING_OAUTH ])
            || ! $this->squareData[ Domain::META_KEY_USING_OAUTH ]
        ) {
            return;
        }

        // If there is an established connection we should check the debug mode and the connection.
        if (
            isset($this->squareData[ Domain::META_KEY_LIVE_MODE ])
            && $this->squareData[ Domain::META_KEY_LIVE_MODE ]
            && $pmDebugMode
        ) {
            EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'Debug vs live mode', $this->squareData, false);
            $this->add_validation_error(
                sprintf(
                    // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                    esc_html__(
                        '%1$sSquare Payment Method%2$s is in debug mode but the authentication with %1$sSquare%2$s is in Live mode. Payments will not be processed correctly! If you wish to test this payment method, please reset the connection and use sandbox credentials to authenticate with Square.',
                        'event_espresso'
                    ),
                    '<strong>',
                    '</strong>'
                ),
                'ee4_square_live_connection_but_pm_debug_mode'
            );
        } elseif (
            (! isset($this->squareData[ Domain::META_KEY_LIVE_MODE ])
            || ! $this->squareData[ Domain::META_KEY_LIVE_MODE ])
            && ! $pmDebugMode
        ) {
            EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'Debug vs live mode', $this->squareData, false);
            $this->add_validation_error(
                sprintf(
                    // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                    esc_html__(
                        '%1$sSquare Payment Method%2$s is in live mode but the authentication with %1$sSquare%2$s is in sandbox mode. Payments will not be processed correctly! If you wish to process real payments with this payment method, please reset the connection and use live credentials to authenticate with Square.',
                        'event_espresso'
                    ),
                    '<strong>',
                    '</strong>'
                ),
                'ee4_square_sandbox_connection_but_pm_not_in_debug_mode'
            );
        }
        // Don't allow changing the debug mode setting while connected.
        // And if we're creating the form for receiving POST data, ignore debug mode input's value.
        if (method_exists($debugInput, 'isDisabled')) {
            $debugInput->disable();
        }

        $debugInput->set_html_help_text(
            $debugInput->html_help_text()
            . '</p><p class="disabled-description">'
            . esc_html__(
                'You cannot enable or disable debug mode while connected. First disconnect, then change debug mode.',
                'event_espresso'
            )
        );
    }


    /**
     * Adds a subsection with a Digital Wallet yes/no input.
     *
     * @param array               $pmFormParams
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return array
     */
    private function addDigitalWalletToggle(
        array $pmFormParams,
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ): array {
        $pmFormParams['extra_meta_inputs'][ Domain::META_KEY_USE_DIGITAL_WALLET ] = new EE_Yes_No_Input(
            [
                'html_label_text' => sprintf(
                    // translators: %1$s: Help tab link as icon.
                    esc_html__('Enable Digital Wallet ? %s', 'event_espresso'),
                    $paymentMethod->get_help_tab_link()
                ),
                'html_help_text'  => esc_html__(
                    'Would you like to enable Google Pay and Apple Pay as payment options on the checkout page ?',
                    'event_espresso'
                ),
                'html_id'         => $pmInstance->slug() . '-use-dwallet',
                'default'         => false,
                'required'        => true,
            ]
        );
        return $pmFormParams;
    }


    /**
     * Adds a subsection with a Register Domain button.
     *
     * @param array               $pmFormParams
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return array
     */
    private function addRegisterDomainButton(
        array $pmFormParams,
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ): array {
        $pmFormParams['extra_meta_inputs'][ Domain::META_KEY_REG_DOMAIN_BUTTON ] = new EE_Button_Input([
            'button_content'  => esc_html__('Register my site Domain', 'event_espresso'),
            'html_label_text' => esc_html__('Apple Pay Domain', 'event_espresso'),
            'html_help_text'  => sprintf(
                esc_html__(
                    'To use Apple pay on your website, you need to register your %1$sverified domain%2$s with our app.',
                    'event_espresso'
                ),
                '<a href="https://developer.squareup.com/docs/web-payments/apple-pay#step-1-register-your-sandbox-domain-with-apple" target="_blank">',
                '</a>'
            ),
            'html_id'         => 'eea_apple_register_domain_' . $pmInstance->slug(),
            'html_class'      => 'eea-register-domain-btn',
        ]);
        return $pmFormParams;
    }


    /**
     * Adds a subsection with a dropdown of a list of locations.
     *
     * @param array               $pmFormParams
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return array
     */
    private function addLocationsList(
        array $pmFormParams,
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ): array {
        $pmFormParams['extra_meta_inputs'][ Domain::META_KEY_LOCATION_ID ] = new EE_Select_Input(
            $this->locations_list,
            [
                'html_label_text' => sprintf(
                    esc_html__('Merchant Location %s', 'event_espresso'),
                    $paymentMethod->get_help_tab_link()
                ),
                'html_help_text'  => esc_html__(
                    'Select the location you want your payments to be associated with.',
                    'event_espresso'
                ),
                'html_class'      => 'eea-locations-select-' . $pmInstance->slug(),
                'default'         => 'main',
                'required'        => true,
            ]
        );
        return $pmFormParams;
    }


    /**
     * Toggles subsections depending on the OAuth status etc.
     *
     * @param EE_Payment_Method $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    private function toggleSubsections(EE_Payment_Method $pmInstance)
    {
        // Disable the locations select if list is empty.
        $locationsSelect = $this->get_input(Domain::META_KEY_LOCATION_ID, false);
        if (! $this->locations_list) {
            $locationsSelect->disable();
        }

        $use_digital_wallet = $pmInstance->get_extra_meta(Domain::META_KEY_USE_DIGITAL_WALLET, true, false);
        // try registering this domain name with Apple Pay if Dwallet enabled
        if ($use_digital_wallet) {
            $this->registerBlogDomain($pmInstance);
        }

        // disable domain registration button if digital wallet not enabled
        $using_oauth     = $this->squareData[ Domain::META_KEY_USING_OAUTH ] ?? false;
        $domain_verified = ! empty($this->squareData[ Domain::META_KEY_DOMAIN_VERIFY ])
            ? $this->squareData[ Domain::META_KEY_DOMAIN_VERIFY ]
            : 'unknown';

        if (! $using_oauth || ! $use_digital_wallet || $domain_verified === 'VERIFIED') {
            $this->exclude([ Domain::META_KEY_REG_DOMAIN_BUTTON ]);
        }
    }


    /**
     * Checks and then registers this website domain name with EE App.
     *
     * @param EE_Payment_Method $pmInstance
     * @return void
     */
    private function registerBlogDomain(EE_Payment_Method $pmInstance)
    {
        try {
            if (
                empty($this->squareData[ Domain::META_KEY_DOMAIN_VERIFY ])
                || $this->squareData[ Domain::META_KEY_DOMAIN_VERIFY ] !== 'VERIFIED'
            ){
                $response = EED_SquareOnsiteOAuth::registerDomain($pmInstance);
                if (! empty($response['status'])) {
                    // save the status
                    $this->squareData[ Domain::META_KEY_DOMAIN_VERIFY ] = $response['status'];
                    $pmInstance->update_extra_meta(Domain::META_KEY_SQUARE_DATA, $this->squareData);
                }
            }
        } catch (EE_Error | ReflectionException $e) {
            // No action required here in this case.
        }
    }
}
