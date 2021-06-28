<?php

if (! defined('EVENT_ESPRESSO_VERSION')) {
    exit('No direct script access allowed');
}

/**
 * EEPMTSquareOnsite_Test
 *
 * @package        Event Espresso
 * @subpackage     eea-square-gateway
 * @author         Nazar Kolivoshka
 * @version        $VID:$
 */
class EEPMTSquareOnsite_Test extends EE_UnitTestCase
{
    public function setUp()
    {
        parent::setUp();
        EE_Payment_Method_Manager::reset();
    }
}