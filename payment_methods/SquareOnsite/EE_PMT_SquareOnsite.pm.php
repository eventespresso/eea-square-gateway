<?php

if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
}

use EventEspresso\Square\domain\Domain;
use EventEspresso\Square\payment_methods\SquareOnsite\forms\BillingForm;
use EventEspresso\Square\payment_methods\SquareOnsite\forms\OAuthForm;
use EventEspresso\Square\payment_methods\SquareOnsite\forms\SettingsForm;

/**
 * Class EE_PMT_SquareOnsite
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 */
class EE_PMT_SquareOnsite extends EE_PMT_Base
{
    /**
     * Path to the templates folder for Square payment method.
     * @var string
     */
    protected $_template_path = null;


    /**
     * Class constructor.
     *
     * @param null $pmInstance
     * @throws EE_Error
     */
    public function __construct($pmInstance = null)
    {
        $this->_template_path = dirname(__FILE__) . DS . 'templates' . DS;
        $this->_default_description = esc_html__('Please provide the following billing information.', 'event_espresso');
        $this->_default_button_url = EEA_SQUARE_GATEWAY_PLUGIN_URL . 'payment_methods' . DS . 'SquareOnsite'
            . DS . 'lib' . DS . 'default-cc-logo.png';
        $this->_pretty_name = esc_html__('Square', 'event_espresso');
        $this->_cache_billing_form = true;
        $this->_requires_https = true;

        // Load Square SDK.
        require_once(EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'vendor/autoload.php');
        // Load gateway.
        require_once(
            EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'payment_methods' . DS . 'SquareOnsite'
                . DS . 'EEG_SquareOnsite.gateway.php'
        );
        $this->_gateway = new EEG_SquareOnsite();

        // Log Square JS errors.
        add_action('wp_ajax_eeaSquareLogError', [__CLASS__, 'logJsError']);

        parent::__construct($pmInstance);
    }


    /**
     * Generate a new payment settings form.
     *
     * @return EE_Payment_Method_Form
     */
    public function generate_new_settings_form()
    {
        // Settings form.
        $pmForm = new SettingsForm($this, $this->_pm_instance);

        // Filter the form contents.
        $pmForm = apply_filters(
            'FHEE__EE_PMT_SquareOnsite__generate_new_settings_form__form_filtering',
            $pmForm,
            $this,
            $this->_pm_instance
        );

        return $pmForm;
    }


    /**
     * Creates a billing form for this payment method type.
     *
     * @param EE_Transaction|null $transaction
     * @param array               $extraArgs
     * @return EE_Billing_Info_Form
     * @throws EE_Error
     */
    public function generate_new_billing_form(EE_Transaction $transaction = null, $extraArgs = [])
    {
        $options = array_merge(
            ['transaction' => $transaction, 'template_path' => $this->_template_path, 'square_onsite_pmt' => $this],
            $extraArgs
        );
        return new BillingForm($this->_pm_instance, $options);
    }


    /**
     * Gets the number of decimal places we expect a currency to have.
     *
     * @param string $currency Accepted currency.
     * @return int
     */
    public static function getDecimalPlaces($currency = '')
    {
        if (! $currency) {
            $currency = EE_Registry::instance()->CFG->currency->code;
        }
        switch (strtoupper($currency)) {
            // Zero decimal currencies.
            case 'BIF':
            case 'CLP':
            case 'DJF':
            case 'GNF':
            case 'JPY':
            case 'KMF':
            case 'KRW':
            case 'MGA':
            case 'PYG':
            case 'RWF':
            case 'UGX':
            case 'VND':
            case 'VUV':
            case 'XAF':
            case 'XOF':
            case 'XPF':
                return 0;
            default:
                return 2;
        }
    }


    /**
     * Adds info to the help tab.
     *
     * @see EE_PMT_Base::help_tabs_config()
     * @return array
     */
    public function help_tabs_config()
    {
        return array(
            $this->get_help_tab_name() => array(
                'title' => esc_html__('Square settings', 'event_espresso'),
                'filename' => 'pm_overview_square'
            ),
        );
    }


    /**
     * Log JS TXN Error.
     *
     * @return void
     * @throws EE_Error
     * @throws ReflectionException
     */
    public static function logJsError()
    {
        if (isset($_POST['txn_id']) && ! empty($_POST['txn_id'])) {
            $paymentMethod = EEM_Payment_Method::instance()->get_one_of_type($_POST['pm_slug']);
            $transaction = EEM_Transaction::instance()->get_one_by_ID($_POST['txn_id']);
            $paymentMethod->type_obj()->get_gateway()->log(
                ['JS Error (Transaction: ' . $transaction->ID() . ')' => $_POST['message']],
                $transaction
            );
        }
    }
}