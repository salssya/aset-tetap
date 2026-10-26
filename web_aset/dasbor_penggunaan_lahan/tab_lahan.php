<?php

$LAHAN_TABS = require __DIR__ . '/lahan_tabs_config.php';
$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (!isset($LAHAN_TABS[$t])) {
    http_response_code(404);
    die('Tab tidak dikenal.');
}
$PAGE = $LAHAN_TABS[$t]['page'];
$_GET['embed'] = '1';
require __DIR__ . '/lahan_template.php';