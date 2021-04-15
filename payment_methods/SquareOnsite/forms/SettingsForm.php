<?php

namespace EventEspresso\Square\payment_methods\SquareOnsite\forms;

use EE_Payment_Method_Form;
use EE_PMT_SquareOnsite;
use EE_Payment_Method;
use EE_Yes_No_Input;
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
            if (
                isset($squareData[ Domain::META_KEY_LIVE_MODE ])
                && $squareData[ Domain::META_KEY_LIVE_MODE ]
                && $pmDebugMode
            ) {
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
            } elseif (
                (! isset($squareData[ Domain::META_KEY_LIVE_MODE ])
                || ! $squareData[ Domain::META_KEY_LIVE_MODE ])
                && ! $pmDebugMode
            ) {
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
}
