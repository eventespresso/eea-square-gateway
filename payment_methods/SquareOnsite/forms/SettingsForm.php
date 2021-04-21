<?php

namespace EventEspresso\Square\payment_methods\SquareOnsite\forms;

use EE_Payment_Method_Form;
use EE_PMT_SquareOnsite;
use EE_Payment_Method;
use EE_Yes_No_Input;
use EventEspresso\Square\api\EESquareLocations;
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
     * Class constructor.
     *
     * @param EE_PMT_SquareOnsite $paymentMethod
     * @param EE_Payment_Method   $pmInstance
     * @throws EE_Error|ReflectionException
     */
    public function __construct(EE_PMT_SquareOnsite $paymentMethod, EE_Payment_Method $pmInstance)
    {
        // Fields for basic authentication settings.
        $pmFormParams = [
            'extra_meta_inputs' => [
                Domain::META_KEY_USE_DIGITAL_WALLET => new EE_Yes_No_Input(
                    [
                        'html_label_text' => sprintf(
                            // translators: %1$s: Help tab link as icon.
                            esc_html__('Enable Digital Wallet ? %s', 'event_espresso'),
                            $paymentMethod->get_help_tab_link()
                        ),
                        'html_help_text'  => esc_html__(
                            // @codingStandardsIgnoreStart
                            'Would you like to enable Google Pay and Apple Pay as payment options on the checkout page ?',
                            // @codingStandardsIgnoreEnd
                            'event_espresso'
                        ),
                        'html_id'         => $pmInstance->slug() . '-use-dwallet',
                        'default'         => false,
                        'required'        => true,
                    ]
                ),
            ]
        ];
        // Build the PM form.
        parent::__construct($pmFormParams);

        // Now add the OAuth section.
        $this->addSquareConnectButton($paymentMethod, $pmInstance);
    }


    /**
     *  Add the connect button to the PM settings page.
     *
     * @param EE_PMT_SquareOnsite    $paymentMethod
     * @param EE_Payment_Method      $pmInstance
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
            Domain::META_KEY_USE_DIGITAL_WALLET
        );
    }


    /**
     * Possibly exclude the default authentication fields.
     *
     * @param EE_Payment_Method $pmInstance
     * @return void
     * @throws EE_Error|ReflectionException
     */
    public function validateConnection(EE_Payment_Method $pmInstance)
    {
        // If there is an established connection we should check the debug mode and the connection.
        $squareData = $pmInstance->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);
        $pmDebugMode = $pmInstance->debug_mode();
        $debugInput = $this->get_input('PMD_debug_mode', false);
        if (isset($squareData[ Domain::META_KEY_USING_OAUTH ]) && $squareData[ Domain::META_KEY_USING_OAUTH ]) {
            // First check the credentials and the API connection
            $oauthHealthCheck = $this->oauthHealthCheck($pmInstance);
            // and reset the OAuth connection in case we are no longer authorized for some reason.
            if (isset($oauthHealthCheck['error'])) {
                if ($oauthHealthCheck['error']['code'] === 'NOT_AUTHORIZED') {
                    // Seems like the Token got revoked or outdated, reset the connection.
                    $oauthReset = $this->resetOauthSettings($pmInstance);
                    if ($oauthReset) {
                        $this->doValidationError(
                            'The OAuth %1$sauthorization was revoked%2$s so the connection was reset. Please re-authorize (Connect) for the Square payment method to function properly.',
                            'eea_square_oauth_connection_was_reset'
                        );
                    }
                } else {
                    $this->doValidationError(
                        'There was an error while doing the authorization health check: "'
                            . $oauthHealthCheck['error']['message']
                            . '". Please re-authorize (Connect) for the Square payment method to function properly.',
                        'eea_square_oauth_connection_reset_request'
                    );
                }
            }

            if (
                isset($squareData[ Domain::META_KEY_LIVE_MODE ])
                && $squareData[ Domain::META_KEY_LIVE_MODE ]
                && $pmDebugMode
            ) {
                $this->doValidationError(
                    '%1$sSquare Payment Method%2$s is in debug mode but the authentication with %1$sSquare%2$s is in Live mode. Payments will not be processed correctly! If you wish to test this payment method, please reset the connection and use sandbox credentials to authenticate with Square.',
                    'ee4_square_live_connection_but_pm_debug_mode'
                );
            } elseif (
                (! isset($squareData[ Domain::META_KEY_LIVE_MODE ])
                || ! $squareData[ Domain::META_KEY_LIVE_MODE ])
                && ! $pmDebugMode
            ) {
                $this->doValidationError(
                    '%1$sSquare Payment Method%2$s is in live mode but the authentication with %1$sSquare%2$s is in sandbox mode. Payments will not be processed correctly! If you wish to process real payments with this payment method, please reset the connection and use live credentials to authenticate with Square.',
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
     * @param EE_Payment_Method $pmInstance
     * @return array ['healthy' => true] | ['error' => ['message' => 'the_message', 'code' => 'the_code']]
     */
    public function oauthHealthCheck(EE_Payment_Method $pmInstance)
    {
        // Request a list of locations.
        $locations = $this->getMechantLocations($pmInstance);
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
     * Get merchant locations from Square.
     *
     * @param EE_Payment_Method $pmInstance
     * @return Object|array
     */
    public function getMechantLocations(EE_Payment_Method $pmInstance)
    {
        try {
            $accessToken = $pmInstance->get_extra_meta(Domain::META_KEY_ACCESS_TOKEN, true);
            $appId = $pmInstance->get_extra_meta(Domain::META_KEY_APPLICATION_ID, true);
            $locationId = $pmInstance->get_extra_meta(Domain::META_KEY_LOCATION_ID, true);
            $dWallet = $pmInstance->get_extra_meta(Domain::META_KEY_USE_DIGITAL_WALLET, true);
        } catch (EE_Error | ReflectionException $e) {
            $error['error']['message'] = $e->getMessage();
            $error['error']['code'] = $e->getCode();
            return $error;
        }
        // Create the API object/helper.
        $listsApi = new EESquareLocations($pmInstance->debug_mode());
        $listsApi->setApplicationId($appId);
        $listsApi->setAccessToken($accessToken);
        $listsApi->setUseDwallet($dWallet);
        $listsApi->setLocationId($locationId);
        return $listsApi->list();
    }


    /**
     * Resets all that OAuth related settings.
     *
     * @param EE_Payment_Method $pmInstance
     * @return boolean
     * @throws ReflectionException
     */
    public function resetOauthSettings(EE_Payment_Method $pmInstance)
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
     * This simply adds the form validation error.
     *
     * @param string $errorMessage has to have two placeholders for the bold text.
     * @param string $errorName
     * @return void
     */
    protected function doValidationError(string $errorMessage, string $errorName)
    {
        $this->add_validation_error(
            sprintf(
                // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                esc_html__($errorMessage, 'event_espresso'),
                '<strong>',
                '</strong>'
            ),
            $errorName
        );
    }
}
