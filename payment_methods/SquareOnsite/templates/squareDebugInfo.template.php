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
                    <td><?php esc_html_e('Exp Date (MM/YY)', 'event_espresso'); ?></td>
                    <td><?php esc_html_e('CVV/CVV2', 'event_espresso'); ?></td>
                    <td><?php esc_html_e('ZIP Code', 'event_espresso'); ?></td>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>4111111111111111</td>
                    <td><?php esc_html_e('Any date greater than today', 'event_espresso'); ?></td>
                    <td>111</td>
                    <td><?php esc_html_e('Any', 'event_espresso'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <h4><?php esc_html_e('How do I test specific error codes?', 'event_espresso'); ?></h4>
    <ul>
        <li><strong>4000000000000341</strong> - <?php esc_html_e(
            'charge fail; Attaching this card will succeed, but attempts to charge the customer will fail.',
            'event_espresso'
        ); ?></li>
        <li><strong>4000000000000002</strong> - <?php esc_html_e(
            'card_declined; Use this special card number.',
            'event_espresso'
        ); ?></li>
    </ul>
</div>
