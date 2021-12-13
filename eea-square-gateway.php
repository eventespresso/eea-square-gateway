<?php
/*
    Plugin Name: Event Espresso - Square Gateway (EE 4.10+)
    Plugin URI: https://eventespresso.com
    Description: Square is an on-site payment method for Event Espresso for accepting credit and debit cards. An account with Square is required to accept payments.

    Version: 1.0.1.rc.002

    Author: Event Espresso
    Author URI: https://eventespresso.com
    Copyright 2020 Event Espresso (email : support@eventespresso.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA02110-1301USA
 */

define('EEA_SQUARE_GATEWAY_VERSION', '1.0.1.rc.002');
define('EEA_SQUARE_GATEWAY_PLUGIN_FILE', __FILE__);
define('EEA_SQUARE_GATEWAY_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Register this add-on with EE.
function loadEEASquareGateway()
{
    if (class_exists('EE_Addon')) {
        require_once(EEA_SQUARE_GATEWAY_PLUGIN_PATH . 'EE_SquareGateway.class.php');
        EE_SquareGateway::registerAddon();
    }
}
add_action('AHEE__EE_System__load_espresso_addons', 'loadEEASquareGateway');
// End of file eea-square-gateway.php
