<?php
// Axis Device app graph: Clock offset
// Install path: /opt/librenms/includes/html/graphs/application/axis-device_clock.inc.php
require 'includes/html/graphs/common.inc.php';

$axd_app_id = is_object($app) ? $app->app_id : $app['app_id'];
$rrd_filename = Rrd::name($device['hostname'], ['app', 'axis-device', $axd_app_id]);

$colours = 'mixed';
$unit_text = 'Seconds';
$nototal = 1;

$rrd_list = [
    ['filename' => $rrd_filename, 'descr' => 'Offset', 'ds' => 'clock_off'],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
