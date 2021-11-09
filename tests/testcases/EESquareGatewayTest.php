<?php
defined('EVENT_ESPRESSO_VERSION') || exit('No direct script access allowed');

/**
 * EESquareGatewayTest
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 * @version        $VID:$
 */
class EESquareGatewayTest extends EE_UnitTestCase
{
    /**
     * Do setup.
     *
     * @throws EE_Error
     */
    public function setUp()
    {
        parent::setUp();
        $admin_user = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_user);
        set_current_screen('plugins.php');
    }


    /**
     * Test loading the main add-on file.
     */
    function testLoadingSquareGateway()
    {
        // plugin registration with EE
        $this->assertEquals(10, has_action('AHEE__EE_System__load_espresso_addons', 'loadEEASquareGateway'));
        $this->assertTrue(class_exists('EE_SquareGateway'));
        // plugin action links
        $this->assertTrue(is_admin());
    }
}
