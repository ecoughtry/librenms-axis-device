<?php
// Axis Device app graph: CPU temperature
// Install path: /opt/librenms/includes/html/graphs/application/axis-device_temp.inc.php
require 'includes/html/graphs/common.inc.php';

$axd_app_id = is_object($app) ? $app->app_id : $app['app_id'];
$rrd_filename = Rrd::name($device['hostname'], ['app', 'axis-device', $axd_app_id]);

$colours = 'mixed';
$unit_text = 'Celsius';
$nototal = 1;

$rrd_list = [
    ['filename' => $rrd_filename, 'descr' => 'CPU', 'ds' => 'temp_cpu'],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
