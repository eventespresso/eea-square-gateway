<?php
/**
 * Bootstrap for eea-square-gateway tests
 */

use EETests\bootstrap\AddonLoader;

$core_tests_dir = dirname(dirname(dirname(__FILE__))) . '/event-espresso-core/tests/';
require $core_tests_dir . 'includes/CoreLoader.php';
require $core_tests_dir . 'includes/AddonLoader.php';

$addon_path = dirname(dirname(__FILE__)) . '/';

$addon_loader = new AddonLoader(
    $addon_path . 'tests',
    $addon_path,
    'eea-square-gateway.php',
);
$addon_loader->init();
