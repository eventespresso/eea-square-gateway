<?php if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
}

/**
 * Square embedded payment form.
 *
 * @package         Event Espresso
 * @subpackage      eea-square-gateway
 */
?>
<div id="eea-square-pm-form-div">
    <!-- Apple Pay button -->
    <div id="apple-pay-button" class="apple-pay-button"></div>
    <!-- Google Pay button -->
    <div id="eea-square-pm-google-pay" class="google-pay-button"></div>

    <div id="sq-card-se"></div>
    <input type="submit" id="eea-square-pay-button" class="button-credit-card" value="<?php esc_html_e('Pay', 'event_espresso');?>" style="display: none;">

    <p id="eea-square-response-pg" class="clear" style="display: none;"></p>
    <div class="clear"></div>
</div>
