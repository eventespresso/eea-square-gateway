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
    protected $oauthBtnText = null;

    /**
     *  Square OAuth button in sandbox mode text.
     *
     * @var string
     */
    protected $oauthBtnSandboxText = null;

    /**
     *  Square OAuth section sandbox mode text.
     *
     * @var string
     */
    protected $oauthedSandboxText = null;


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
error_log('oauthFormContents');
        // Check the token and refresh if needed.
        EED_SquareOnsiteOAuth::checkAndRefreshToken($this->thePmInstance);

        // Now get the OAuth status.
        $isOauthed = EED_SquareOnsiteOAuth::isAuthenticated($this->thePmInstance) && $this->thePmInstance->debug_mode();

        // The contents.
        $subsections = [];
        $fieldHeading = EEH_HTML::th(
            sprintf(
                // translators: %1$s: Help tab link as icon.
                esc_html__('Square OAuth: %1$s *', 'event_espresso'),
                $this->paymentMethod->get_help_tab_link()
            )
        );

        // Is this a test connection ?
        $livemode = $this->thePmInstance->get_extra_meta(Domain::META_KEY_LIVE_MODE, true);
        $livemodeText = (! $livemode)
            ? ' ' . EEH_HTML::strong(
                $this->oauthedSandboxText,
                'eea_square_test_connected_txt',
                'eea-square-test-connected-txt'
            )
            : '';

        // Section to be displayed if not connected.
        $subsections['square_connect_btn'] = new EE_Form_Section_HTML(
            EEH_HTML::tr(
                $fieldHeading .
                EEH_HTML::td(
                    EEH_HTML::link(
                        '#',
                        EEH_HTML::span($this->oauthBtnText),
                        '',
                        'eea_square_connect_btn_' . $this->pmSlug,
                        'eea-square-connect-btn'
                    )
                ),
                'eea_square_connect_' . $this->pmSlug,
                'eea-connect-section-' . $this->pmSlug,
                // Are we OAuth'ed ?
                ($isOauthed) ? 'display:none;' : ''
            )
        );

        // Section to be displayed when connected.
        $subsections['square_disconnect_btn'] = new EE_Form_Section_HTML(
            EEH_HTML::tr(
                $fieldHeading
                . EEH_HTML::td(
                    EEH_HTML::img(
                        EEA_SQUARE_GATEWAY_PLUGIN_URL . 'assets' . DS . 'lib' . DS . 'square-connected.png',
                        '',
                        'eea_square_connected_ico',
                        'eea-square-connected-ico'
                    )
                    . EEH_HTML::strong(
                        esc_html__('Connected.', 'event_espresso'),
                        'eea_square_connected_txt',
                        'eea-square-connected-txt'
                    )
                    . $livemodeText
                    . EEH_HTML::link(
                        '#',
                        EEH_HTML::span(esc_html__('Disconnect', 'event_espresso')),
                        '',
                        'eea_square_disconnect_btn_' . $this->pmSlug,
                        'eea-square-connect-btn light'
                    )
                ),
                'eea_square_disconnect_' . $this->pmSlug,
                'eea-disconnect-section-' . $this->pmSlug,
                // Are we OAuth'ed ?
                ($isOauthed) ? '' : 'display:none;'
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