<?php if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
} ?>

<div id="square-sandbox-panel" class="sandbox-panel">
    <h6 class="important-notice">
        <?php esc_html_e('Debug Mode is turned ON. Payments will NOT be processed', 'event_espresso'); ?>
    </h6>

    <p class="test-credit-cards-info-pg">
        <strong><?php esc_html_e('Credit Card Numbers Used for Testing', 'event_espresso'); ?></strong><br/>
        <span class="small-text">
            <?php esc_html_e(
                'Use the following credit card information to test payments with Square:',
                'event_espresso'
            ); ?>
        </span>
    </p>

    <div class="tbl-wrap">
        <table id="square-test-credit-cards" class="test-credit-card-data-tbl">
            <thead>
                <tr>
                    <td><?php esc_html_e('Card Number', 'event_espresso'); ?></td>
                    <td><?php esc_html_e('Exp, CVV, ZIP', 'event_espresso'); ?></td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>4111111111111111</td>
                    <td><?php esc_html_e('Any', 'event_espresso'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <h4><?php esc_html_e('How do I test specific error codes?', 'event_espresso'); ?></h4>
        <table id="square-test-errors" class="test-card-errors">
            <thead>
                <tr>
                    <td><?php esc_html_e('Desired error state', 'event_espresso'); ?></td>
                    <td><?php esc_html_e('Test values', 'event_espresso'); ?></td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e('Card CVV incorrect', 'event_espresso'); ?></td>
                    <td>CVV: 911</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Card postal code incorrect', 'event_espresso'); ?></td>
                    <td>Postal code: 99999</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Card declined number', 'event_espresso'); ?></td>
                    <td>Card number: 4000000000000002</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Card on file auth declined', 'event_espresso'); ?></td>
                    <td>PAN: 4000000000000010</td>
                </tr>
            </tbody>
        </table>
</div>
