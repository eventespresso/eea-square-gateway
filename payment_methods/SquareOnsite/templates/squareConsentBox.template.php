<?php if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
} ?>

<div class="eea-square-privacy-consent-assertion">
    <?php echo $input->get_html_for_input(); // already escaped ?>
</div>
