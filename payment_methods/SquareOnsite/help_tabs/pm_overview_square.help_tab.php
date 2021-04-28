<p><strong>
    <?php esc_html_e('Square Gateway', 'event_espresso'); ?>
</strong></p>
<p>
    <?php printf(
        esc_html__(
            'Adjust the settings for the Square payment gateway. More information can be found on %1$ssquareup.com%2$s.',
            'event_espresso'
        ),
        '<a href="https://developer.squareup.com/" target="_blank">',
        '</a>'
    ); ?>
</p>
<p><strong><?php esc_html_e('Square Settings', 'event_espresso'); ?></strong></p>
<ul>
    <li>
        <strong><?php esc_html_e('Square OAuth', 'event_espresso'); ?></strong><br />
        <?php printf(
            esc_html__(
                '%1$sThe OAuth flow%2$s is a secure way to connect your Square account to our app and start receiving payments.',
                'event_espresso'
            ),
            '<a href="https://developer.squareup.com/docs/oauth-api/overview" target="_blank">',
            '</a>'
        ); ?><br/>
        <?php esc_html_e(
            'These are the the permissions that will be requested by our app:',
            'event_espresso'
        ); ?>
        <ul>
            <li>
                <?php esc_html_e('Create payments and refunds', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Create payments and refunds', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Update and Create Square orders', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Read my Square orders', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Modify my item library', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Read my item library', 'event_espresso'); ?>
            </li>
            <li>
                <?php esc_html_e('Read my merchant profile information', 'event_espresso'); ?>
            </li>
        </ul>
    </li>
    <li>
        <strong><?php esc_html_e('Enable Digital Wallet', 'event_espresso'); ?></strong><br />
        <?php printf(
            esc_html__(
                'Allows payments with digital wallets like Apple Pay, Google Pay. You can read the %1$sdocumentation%2$s for more information.',
                'event_espresso'
            ),
            '<a href="https://developer.squareup.com/docs/payments-digital-wallets" target="_blank">',
            '</a>'
        ); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Merchant Location', 'event_espresso'); ?></strong><br />
        <?php printf(
            esc_html__(
                'Choose your business location. Transactions made through this payment method will be associated with the selected location. Read the %1$sdocumentation%2$s for more information.',
                'event_espresso'
            ),
            '<a href="https://developer.squareup.com/docs/locations-api" target="_blank">',
            '</a>'
        ); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Logo URL', 'event_espresso'); ?></strong><br />
        <?php esc_html_e(
            'Upload a logo that will appear at the top of the Square checkout, for best results use 125px by 125px.',
            'event_espresso'
        ); ?>
    </li>
</ul>
