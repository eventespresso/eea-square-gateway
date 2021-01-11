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
    <!-- GooglePay button -->
    <button id="eea-square-pm-google-pay" class="button-google-pay sq-input"></button>
    <!-- ApplePay button -->
    <button id="eea-square-pm-apple-pay" class="apple-pay-button apple-pay-button-black sq-input"></button>

    <div id="sq-card-se"></div>
    <input type="submit" id="eea-square-pay-button" class="button-credit-card" value="<?php esc_html_e('Pay', 'event_espresso');?>" style="display: none;">

    <p id="eea-square-response-pg" class="clear" style="display: none;"></p>
    <div class="clear"></div>
</div>
