<?php

namespace EventEspresso\Square\payment_methods\SquareOnsite\forms;

use EE_Password_Input;
use EE_Payment_Method_Form;
use EE_PMT_SquareOnsite;
use EE_Payment_Method;
use EE_Select_Input;
use EE_Text_Input;
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
                Domain::META_KEY_AUTH_TYPE => new EE_Select_Input(
                    [
                        'oauth'    => esc_html__('OAuth (recommended)', 'event_espresso'),
                        'personal' => esc_html__('Using personal credentials', 'event_espresso')
                    ],
                    [
                        'html_label_text' => esc_html__('Authentication Type', 'event_espresso'),
                        'required'        => true,
                        'default'         => 'oauth',
                        'html_help_text'  => esc_html__(
                            'Using OAuth with Square is recommended. It\'s an easier way to authenticate with our app.',
                            'event_espresso'
                        ),
                        'html_id'         => $pmInstance->slug() . '-authentication'
                    ]
                ),
                Domain::META_KEY_APPLICATION_ID => new EE_Text_Input([
                    'html_label_text' => sprintf(
                        // translators: %1$s: Help tab link as icon.
                        esc_html__('Application ID %1$s', 'event_espresso'),
                        $paymentMethod->get_help_tab_link()
                    ),
                    'html_id'         => $pmInstance->slug() . '-app-id',
                    'required'        => true,
                ]),
                Domain::META_KEY_ACCESS_TOKEN => new EE_Password_Input([
                    'html_label_text' => sprintf(
                        // translators: %1$s: Help tab link as icon.
                        esc_html__('Access Token %1$s', 'event_espresso'),
                        $paymentMethod->get_help_tab_link()
                    ),
                    'html_id'         => $pmInstance->slug() . '-access-token',
                    'required'        => true,
                ]),
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
    public function addSquareConnectButton($paymentMethod, $pmInstance)
    {
        // If there is an established connection we should check the debug mode and the connection.
        $usingSquareOauth = $pmInstance->get_extra_meta(Domain::META_KEY_USING_OAUTH, true, false);
        $connectionLiveMode = $pmInstance->get_extra_meta(Domain::META_KEY_LIVE_MODE, true);
        $pmDebugMode = $pmInstance->debug_mode();
        $debugInput = $this->get_input('PMD_debug_mode', false);
        $modeInput = $this->get_input(Domain::META_KEY_AUTH_TYPE, false);
        if ($usingSquareOauth) {
            if ($connectionLiveMode && $pmDebugMode) {
                $this->add_validation_error(
                    sprintf(
                        // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                        esc_html__(
                            // @codingStandardsIgnoreStart
                            '%1$sSquare Payment Method%2$s is in debug mode but the authentication with %1$sSquare%2$s is in Live mode. Payments will not be processed correctly! If you wish to test this payment method, please reset the connection and use sandbox credentials to authenticate with Square.',
                            // @codingStandardsIgnoreEnd
                            'event_espresso'
                        ),
                        '<strong>',
                        '</strong>'
                    ),
                    'ee4_square_live_connection_but_pm_debug_mode'
                );
            } elseif (! $connectionLiveMode && ! $pmDebugMode) {
                $this->add_validation_error(
                    sprintf(
                        // translators: %1$s: opening strong html tag. $2$s: closing strong html tag.
                        esc_html__(
                            // @codingStandardsIgnoreStart
                            '%1$sSquare Payment Method%2$s is in live mode but the authentication with %1$sSquare%2$s is in sandbox mode. Payments will not be processed correctly! If you wish to process real payments with this payment method, please reset the connection and use live credentials to authenticate with Square.',
                            // @codingStandardsIgnoreEnd
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
            // Do the same for the Authentication type.
            if (method_exists($modeInput, 'isDisabled')) {
                $modeInput->disable();
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

        // Add the connect button before the app id field.
        $oauthTemplate = new OAuthForm($paymentMethod, $pmInstance);
        $this->add_subsections(
            [
                'square_oauth' => new EE_Form_Section_HTML($oauthTemplate->get_html_and_js()),
            ],
            Domain::META_KEY_APPLICATION_ID
        );
    }


    /**
     * Override the default method to do some form validation filtering.
     *
     * @param array $requestData
     * @throws EE_Error
     */
    public function _normalize($requestData)
    {
        parent::_normalize($requestData);
        // Filter out the authentication fields.
        $this->filterAuthFields();
    }


    /**
     * Possibly exclude the default authentication fields.
     *
     * @return void
     * @throws EE_Error
     */
    public function filterAuthFields()
    {
        $appId = $this->get_input(Domain::META_KEY_APPLICATION_ID);
        $accessToken = $this->get_input(Domain::META_KEY_ACCESS_TOKEN);
        $authType = $this->get_input_value(Domain::META_KEY_AUTH_TYPE);
        // If 'OAuth' option is selected, discard the app ID and access token fields in case they are empty.
        if ($authType === 'oauth') {
            $appId->disable();
            $accessToken->disable();
        }
    }
}
