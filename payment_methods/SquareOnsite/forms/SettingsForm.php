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
    protected array $squareData = [];

    /**
     *  The list of locations.
     *
     * @var array
     */
    protected array $locations_list = [];

    /**
     * Class constructor.
     *
     * @param  EE_PMT_SquareOnsite $paymentMethod
     * @param  EE_Payment_Method   $pmInstance
     * @throws EE_Error|ReflectionException
     */
    public function __construct(EE_PMT_SquareOnsite $paymentMethod, EE_Payment_Method $pmInstance)
    {
        $this->squareData     = $pmInstance->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true, []);
        $this->locations_list = $this->squareData[ Domain::META_KEY_LOCATIONS_LIST ] ?? [];

        // Fields for basic authentication settings.
        $this->addDigitalWalletToggle($paymentMethod, $pmInstance);
        // add the domain registering button
        $this->addRegisterDomainButton($paymentMethod, $pmInstance);
        // add the Locations select input
        $this->addLocationsList($paymentMethod, $pmInstance);

        // Build the PM form.
        parent::__construct();

        // Now add the OAuth section.
        $this->addSquareConnectButton($paymentMethod, $pmInstance);
    }


    /**
     *  Add the connect button to the PM settings page.
     *
     * @param  EE_PMT_SquareOnsite    $paymentMethod
     * @param  EE_Payment_Method      $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public function addSquareConnectButton(EE_PMT_SquareOnsite $paymentMethod, EE_Payment_Method $pmInstance)
    {
        // Validate connection first.
        $this->validateConnection($pmInstance);

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
     * Possibly exclude the default authentication fields.
     *
     * @param  EE_Payment_Method $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public function validateConnection(EE_Payment_Method $pmInstance)
    {
        // If there is an established connection we should check the debug mode and the connection.
        $pmDebugMode     = $pmInstance->debug_mode();
        $debugInput      = $this->get_input('PMD_debug_mode', false);
        $locationsSelect = $this->get_input(Domain::META_KEY_LOCATION_ID, false);

        // Disable the locations select if list is empty.
        if (! $this->locations_list) {
            $locationsSelect->disable();
        }

        if (isset($this->squareData[ Domain::META_KEY_USING_OAUTH ])
            && $this->squareData[ Domain::META_KEY_USING_OAUTH ]
        ) {
            // first check the token and refresh if needed
            EED_SquareOnsiteOAuth::checkAndRefreshToken($pmInstance);
            // check the credentials and the API connection
            $oauthHealthCheck = $this->oauthHealthCheck($pmInstance);
            // and reset the OAuth connection in case we are no longer authorized for some reason.
            if (isset($oauthHealthCheck['error'])) {
                if ($oauthHealthCheck['error']['code'] === 'NOT_AUTHORIZED') {
                    // Seems like the Token got revoked or outdated, reset the connection.
                    $oauthReset = $this->resetOauthSettings($pmInstance);
                    if ($oauthReset) {
                        EED_SquareOnsiteOAuth::errorLogAndExit(
                            $pmInstance,
                            'OAuth revoked',
                            $oauthHealthCheck,
                            false
                        );
                        $this->add_validation_error(
                            sprintf(
                                // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                                esc_html__(
                                    'The OAuth %1$sauthorization was revoked%2$s so the connection was reset. Please re-authorize (Connect) for the Square payment method to function properly.',
                                    'event_espresso'
                                ),
                                '<strong>',
                                '</strong>'
                            ),
                            'eea_square_oauth_connection_was_reset'
                        );
                    }
                } else {
                    EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'OAuth error', $oauthHealthCheck, false);
                    $this->add_validation_error(
                        sprintf(
                            // translators: %1$s: the error message.
                            esc_html__(
                                'There was an error while doing the authorization health check: "%1$s". Please re-authorize (Connect) for the Square payment method to function properly.',
                                'event_espresso'
                            ),
                            $oauthHealthCheck['error']['message']
                        ),
                        'eea_square_oauth_connection_reset_request'
                    );
                }
            }

            if (
                isset($this->squareData[ Domain::META_KEY_LIVE_MODE ])
                && $this->squareData[ Domain::META_KEY_LIVE_MODE ]
                && $pmDebugMode
            ) {
                EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'Debug vs live mode', $squareData, false);
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
                EED_SquareOnsiteOAuth::errorLogAndExit($pmInstance, 'Debug vs live mode', $squareData, false);
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
     * Checks if the OAuth credentials are still healthy and we are authorized.
     *
     * @param  EE_Payment_Method $pmInstance
     * @return array ['healthy' => true] | ['error' => ['message' => 'the_message', 'code' => 'the_code']]
     */
    public function oauthHealthCheck(EE_Payment_Method $pmInstance): array
    {
        // Double check main oAuth parameters.
        $access_token = $pmInstance->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true, '');
        $app_id       = $pmInstance->get_extra_meta(Domain::META_KEY_APPLICATION_ID, true, '');
        if (! $access_token || ! $app_id) {
            return [
                'error' => [
                    'code'    => 'NO_ACCESS_TOKEN',
                    'message' => esc_html__('One or more authentication parameters are missing', 'event_espresso')
                ]
            ];
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
     * Resets all that OAuth related settings.
     *
     * @param EE_Payment_Method $pmInstance
     * @return boolean
     * @throws ReflectionException
     */
    public function resetOauthSettings(EE_Payment_Method $pmInstance): bool
    {
        try {
            $pmInstance->delete_extra_meta(Domain::META_KEY_APPLICATION_ID);
            $pmInstance->delete_extra_meta(Domain::META_KEY_ACCESS_TOKEN);
            $pmInstance->delete_extra_meta(Domain::META_KEY_LOCATION_ID);
            $pmInstance->update_extra_meta(
                Domain::META_KEY_SQUARE_DATA,
                [
                    Domain::META_KEY_USING_OAUTH => false,
                ]
            );
        } catch (EE_Error $e) {
            return false;
        }
        return true;
    }


    /**
     * Adds a subsection with a Digital Wallet yes/no input.
     *
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return void
     * @throws EE_Error
     */
    private function addDigitalWalletToggle(
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ) {
        $this->add_subsections(
            [
                Domain::META_KEY_USE_DIGITAL_WALLET => new EE_Yes_No_Input(
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
                ),
            ],
            'square_oauth',
            false
        );
    }


    /**
     * Adds a subsection with a Register Domain button.
     *
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return void
     * @throws EE_Error
     */
    private function addRegisterDomainButton(
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ) {
        $this->add_subsections(
            [
                'register_domain' => new EE_Button_Input([
                    'button_content'  => esc_html__('Register my site Domain', 'event_espresso'),
                    'html_label_text' => sprintf(
                        esc_html__('Apple Pay Domain %s', 'event_espresso'),
                        $paymentMethod->get_help_tab_link()
                    ),
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
                ]),
            ],
            Domain::META_KEY_USE_DIGITAL_WALLET,
            false
        );
    }


    /**
     * Adds a subsection with a dropdown of a list of locations.
     *
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @return void
     * @throws EE_Error
     */
    private function addLocationsList(
        EE_PMT_SquareOnsite $paymentMethod,
        EE_Payment_Method $pmInstance
    ) {
        $this->add_subsections(
            [
                Domain::META_KEY_LOCATION_ID => new EE_Select_Input(
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
                ),
            ],
            'register_domain',
            false
        );

    }
}
