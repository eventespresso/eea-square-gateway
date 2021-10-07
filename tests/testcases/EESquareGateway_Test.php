<?php

if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
}

/**
 * EE_Square_Gateway_Test
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 * @version        $VID:$
 */
class EE_Square_Gateway_Test extends EE_UnitTestCase
{
    /**
     * Tests the loading of the main add-on file.
     */
    function test_loading_Square_Gateway()
    {
        $this->assertEquals(has_action('AHEE__EE_System__load_espresso_addons', 'load_eea_square_gateway'), 15);
        $this->assertTrue(class_exists('EE_Square_Gateway'));
        $this->assertEquals(has_action('plugin_action_links', ['EE_Square_Gateway', 'plugin_actions']), 15);
    }
}