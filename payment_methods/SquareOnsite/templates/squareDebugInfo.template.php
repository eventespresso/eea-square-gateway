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
                    <td><?php esc_html_e('CVV incorrect', 'event_espresso'); ?></td>
                    <td>911</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Postal code incorrect', 'event_espresso'); ?></td>
                    <td>99999</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Card declined number', 'event_espresso'); ?></td>
                    <td>4000000000000002</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Card on file auth declined', 'event_espresso'); ?></td>
                    <td>4000000000000010</td>
                </tr>
                <tr>
                    <td>
                        <p>
                            <?php esc_html_e('SCA.', 'event_espresso'); ?>
                        </p>
                        <p>
                            <?php esc_html_e('Modal with verification questions.', 'event_espresso'); ?>
                        </p>
                    </td>
                    <td>
                        <p>5333330000000008</p>
                        <?php esc_html_e('Verification Challenges:', 'event_espresso'); ?><br/>
                        1. Thomason<br/>
                        2. St Louis & Dallas<br/>
                        3. Smith
                    </td>
                </tr>
            </tbody>
        </table>
</div>
