<p><strong>
    <?php esc_html_e('Square Gateway', 'event_espresso'); ?>
</strong></p>
<p>
    <?php printf(
        esc_html__(
            'Adjust the settings for the Square payment gateway. More information can be found on %1$ssquareup.com%2$s.',
            'event_espresso'
        ),
        '<a href="https://developer.squareup.com/">',
        '</a>'
    ); ?>
</p>
<p><strong><?php esc_html_e('Square Settings', 'event_espresso'); ?></strong></p>
<ul>
    <li>
        <strong><?php esc_html_e('Authentication Type', 'event_espresso'); ?></strong><br />
        <?php esc_html_e(
            'Whether you would like to use OAuth to authenticate our app to process payments for you, or use our own app credentials described below. Both meet PCI SAQ-A requirements. Both require you to have a Square account',
            'event_espresso'
        ); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Application ID', 'event_espresso'); ?></strong><br />
        <?php printf(
            esc_html__(
                'The "Application ID" of your Square application. The app can be created on %1$syour apps page%2$s.',
                'event_espresso'
            ),
            '<a href="https://developer.squareup.com/apps">',
            '</a>'
        ); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Access Token', 'event_espresso'); ?></strong><br />
        <?php esc_html_e('The "Access Token" of your Square application.', 'event_espresso'); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Logo URL', 'event_espresso'); ?></strong><br />
        <?php esc_html_e(
            'Upload a logo that will appear at the top of the Square checkout, for best results use 125px by 125px.',
            'event_espresso'
        ); ?>
    </li>
    <li>
        <strong><?php esc_html_e('Location ID', 'event_espresso'); ?></strong><br />
        <?php printf(
            esc_html__(
                'The "Location ID" of your Square application. It can be found in the %1$sSeller Dashboard%2$s, Accounts & Settings > Locations in the Business drop-down list in the left pane.',
                'event_espresso'
            ),
            '<a href="https://squareup.com/dashboard">',
            '</a>'
        ); ?>
    </li>
</ul>