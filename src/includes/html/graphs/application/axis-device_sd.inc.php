<?php
// Axis Device app graph: SD card usage
// Install path: /opt/librenms/includes/html/graphs/application/axis-device_sd.inc.php
require 'includes/html/graphs/common.inc.php';

$axd_app_id = is_object($app) ? $app->app_id : $app['app_id'];
$rrd_filename = Rrd::name($device['hostname'], ['app', 'axis-device', $axd_app_id]);

$colours = 'mixed';
$unit_text = 'Percent';
$nototal = 1;
$scale_min = 0;
$scale_max = 100;

$rrd_list = [
    ['filename' => $rrd_filename, 'descr' => 'SD used', 'ds' => 'sd_used'],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
