<?php

namespace EventEspresso\Square\payment_methods\SquareOnsite\forms;

use EED_SquareOnsiteOAuth;
use EventEspresso\Square\domain\Domain;
use EE_Admin_Two_Column_Layout;
use EE_Error;
use EE_Form_Section_HTML;
use EE_Form_Section_Proper;
use EE_Payment_Method;
use EE_PMT_Base;
use EE_Simple_HTML_Validation_Strategy;
use EEH_HTML;
use ReflectionException;

/**
 * Class OAuthForm
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class OAuthForm extends EE_Form_Section_Proper
{
    /**
     *  Payment method.
     *
     * @var EE_PMT_Base
     */
    protected $paymentMethod = null;

    /**
     *  Payment method instance.
     *
     * @var EE_PMT_Base
     */
    protected $thePmInstance = null;

    /**
     *  Payment method slug.
     *
     * @var EE_PMT_Base
     */
    protected $pmSlug = null;

    /**
     *  Square OAuth button text.
     *
     * @var string
     */
    protected $oauthBtnText = '';

    /**
     *  Square OAuth button in sandbox mode text.
     *
     * @var string
     */
    protected $oauthBtnSandboxText = '';

    /**
     *  Square OAuth section sandbox mode text.
     *
     * @var string
     */
    protected $oauthedSandboxText = '';


    /**
     * Class constructor.
     *
     * @param EE_PMT_Base       $pmt
     * @param EE_Payment_Method $paymentMethod
     * @throws EE_Error
     * @throws ReflectionException|EE_Error
     */
    public function __construct(EE_PMT_Base $pmt, EE_Payment_Method $paymentMethod)
    {
        $this->paymentMethod = $pmt;
        $this->thePmInstance = $paymentMethod;
        $this->pmSlug = $this->thePmInstance->slug();
        $this->oauthBtnText = esc_html__('Connect with Square', 'event_espresso');
        $this->oauthBtnSandboxText = esc_html__('Connect with Square (sandbox)', 'event_espresso');
        $this->oauthedSandboxText = esc_html__('Test mode (using sandbox credentials)', 'event_espresso');
        $options = [
            'html_id'               => $this->pmSlug . '_oauth_form',
            'layout_strategy'       => new EE_Admin_Two_Column_Layout(),
            'validation_strategies' => [new EE_Simple_HTML_Validation_Strategy()],
            'subsections'           => $this->oauthFormContents()
        ];
        parent::__construct($options);
    }


    /**
     * Create the Connect and Disconnect buttons.
     *
     * @access public
     * @return array
     * @throws EE_Error
     * @throws ReflectionException
     */
    protected function oauthFormContents()
    {
        // The contents.
        $subsections = [];
        $fieldHeading = EEH_HTML::th(
            sprintf(
                // translators: %1$s: Help tab link as icon.
                esc_html__('Square OAuth: %1$s *', 'event_espresso'),
                $this->paymentMethod->get_help_tab_link()
            ),
            'eea_square_oauth_section_' . $this->pmSlug,
            'eea-square-oauth-section'
        );
        $squareData = $this->thePmInstance->get_extra_meta(Domain::META_KEY_SQUARE_DATA, true);

        // Now get the OAuth status.
        $isOauthed = EED_SquareOnsiteOAuth::isAuthenticated($this->thePmInstance);

        // Is this a test connection ?
        $debugModeText = isset($squareData[ Domain::META_KEY_LIVE_MODE ])
                && ! $squareData[ Domain::META_KEY_LIVE_MODE ]
            ? $this->oauthedSandboxText
            : '';
        $oauthedSandboxSection = ' ' . EEH_HTML::strong(
            $debugModeText,
            'eea_square_test_connected_txt_' . $this->pmSlug,
            'eea-square-test-connected-txt'
        );


        // Are we OAuth'ed ?
        $display_connect = $isOauthed ? ' square-connect-hidden' : '';
        $display_disconnect = $isOauthed ? '' : ' square-connect-hidden';

        // Section to be displayed if not connected.
        $subsections['square_connect_section'] = new EE_Form_Section_HTML(
            EEH_HTML::tr(
                $fieldHeading .
                EEH_HTML::td(
                    EEH_HTML::div(
                        EEH_HTML::link(
                            '#',
                            EEH_HTML::span($this->oauthBtnText),
                            '',
                            'eea_square_connect_btn_' . $this->pmSlug,
                            'button button--accent eea-square-connect-btn'
                        ),
                        "eea_square_connect_$this->pmSlug",
                        "square-connections-section-div eea-connect-section-{$this->pmSlug}$display_connect"
                    )
                    . EEH_HTML::div(
                        EEH_HTML::img(
                            EEA_SQUARE_GATEWAY_PLUGIN_URL . 'assets' . DS . 'lib' . DS . 'square-connected.png',
                            '',
                            'eea_square_connected_ico',
                            'eea-square-connected-ico'
                        )
                        . EEH_HTML::strong(
                            esc_html__('Connected.', 'event_espresso'),
                            'eea_square_connected_txt_' . $this->pmSlug,
                            'eea-square-connected-txt'
                        )
                        . $oauthedSandboxSection
                        . EEH_HTML::link(
                            '#',
                            EEH_HTML::span(esc_html__('Disconnect', 'event_espresso')),
                            '',
                            'eea_square_disconnect_btn_' . $this->pmSlug,
                            'button button--secondary eea-square-connect-btn'
                        ),
                        "eea_square_disconnect_$this->pmSlug",
                        "square-connections-section-div eea-disconnect-section-{$this->pmSlug}$display_disconnect"
                    ),
                    'square-connections-section-tr'
                )
            )
        );

        return $subsections;
    }


    /**
     * Add JS needed for this form. This is called automatically when displaying the form.
     *
     * @return string
     * @throws EE_Error
     */
    public function enqueue_js()
    {
        $squareParams = [
            'requestConnectionNotice' => esc_html__('Error while requesting the redirect URL.', 'event_espresso'),
            'blockedPopupNotice'      => esc_html__(
                // @codingStandardsIgnoreStart
                'The authentication process could not be executed. Please allow window pop-ups in your browser for this website in order to process a successful authentication.',
                // @codingStandardsIgnoreEnd
                'event_espresso'
            ),
            'debugIsOnNotice'         => esc_html__(
                // @codingStandardsIgnoreStart
                'The authentication with Square is in sandbox mode! If you wish to process real payments with this payment method, please reset the connection and use live credentials to authenticate with Square.',
                // @codingStandardsIgnoreEnd
                'event_espresso'
            ),
            'debugIsOffNotice'        => esc_html__(
                // @codingStandardsIgnoreStart
                'The authentication with Square is in Live mode! If you wish to test this payment method, please reset the connection and use sandbox credentials to authenticate with Square.',
                // @codingStandardsIgnoreEnd
                'event_espresso'
            ),
            'errorResponse'         => esc_html__('Error response received', 'event_espresso'),
            'oauthRequestErrorText' => esc_html__('OAuth request Error.', 'event_espresso'),
            'unknownContainer'      => esc_html__('Could not specify the parent form.', 'event_espresso'),
            'refreshAlert'          => esc_html__(
                'There was an unexpected error. Please refresh the page.',
                'event_espresso'
            ),
            'pmNiceName'            => esc_html__('Square Payments', 'event_espresso'),
            'connectBtnText'        => $this->oauthBtnText,
            'connectBtnSandboxText' => $this->oauthBtnSandboxText,
            'connectedSandboxText'  => $this->oauthedSandboxText,
            'canDisableInput'       => method_exists('EE_Form_Input_Base', 'isDisabled'),
            'espressoDefaultStyles' => EE_ADMIN_URL . 'assets/ee-admin-page.css',
            'wpStylesheet'          => includes_url('css/dashicons.min.css'),
        ];

        // Styles.
        wp_enqueue_style(
            'eea_square_oauth_form_styles',
            EEA_SQUARE_GATEWAY_PLUGIN_URL . 'assets' . DS . 'css' . DS . 'eea-square-oauth.css',
            [],
            EEA_SQUARE_GATEWAY_VERSION
        );

        // Scripts.
        wp_enqueue_script(
            'eea_square_oauth_form_scripts',
            EEA_SQUARE_GATEWAY_PLUGIN_URL . 'assets' . DS . 'js' . DS . 'eea-square-oauth.js',
            [],
            EEA_SQUARE_GATEWAY_VERSION
        );

        // Localize the script with some extra data.
        wp_localize_script('eea_square_oauth_form_scripts', 'eeaSquareOAuthParameters', $squareParams);

        // Also tell the script about each instance of this PM.
        self::$_js_localization['squareGateway'][ $this->pmSlug ] = [
            'pmSlug' => $this->pmSlug,
            'formId' => $this->html_id()
        ];

        parent::enqueue_js();
    }
}
