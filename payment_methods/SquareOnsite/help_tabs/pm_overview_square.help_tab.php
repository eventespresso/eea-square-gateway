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
        <strong><?php esc_html_e('Logo URL', 'event_espresso'); ?></strong><br />
        <?php esc_html_e(
            'Upload a logo that will appear at the top of the Square checkout, for best results use 125px by 125px.',
            'event_espresso'
        ); ?>
    </li>
</ul>
