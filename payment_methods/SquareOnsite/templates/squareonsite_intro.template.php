<?php

printf(
    esc_html__(
        'Square is an on-site payment method for accepting payments with credit and debit cards. Apple Pay and Google Pay are also available. This payment method is available to event organizers in the US, Canada, Japan, Australia, and the United Kingdom. Apple Pay is only available for Square accounts based in Canada, the United Kingdom, and the United States. An account with Square is required to accept payments. You can create one %1$son their website%2$s.',
        'event_espresso'
    ),
    '<a href="https://eventespresso.com/go/square/" target="_blank">',
    '</a>'
);
