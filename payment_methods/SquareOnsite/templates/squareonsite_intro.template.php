<?php

printf(
    esc_html__(
        'Square is an on-site payment method for accepting payments with credit and debit cards. This payment method is available to event organizers in the US, Canada, Japan, Australia, and the United Kingdom. %1$sApple Pay%2$s and %3$sGoogle Pay%4$s are also available in specific countries. An account with Square is required to accept payments. You can create one %5$son their website%6$s.',
        'event_espresso'
    ),
    '<a href="https://developer.squareup.com/docs/payment-form/add-digital-wallets/apple-pay#prerequisites-and-assumptions" target="_blank">',
    '</a>',
    '<a href="https://developer.squareup.com/docs/payment-form/add-digital-wallets/google-pay#prerequisites-and-assumptions" target="_blank">',
    '</a>',
    '<a href="https://eventespresso.com/go/square/" target="_blank">',
    '</a>'
);
