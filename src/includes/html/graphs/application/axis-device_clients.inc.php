<?php
// Axis Device app graph: Streams and clients
// Install path: /opt/librenms/includes/html/graphs/application/axis-device_clients.inc.php
require 'includes/html/graphs/common.inc.php';

$axd_app_id = is_object($app) ? $app->app_id : $app['app_id'];
$rrd_filename = Rrd::name($device['hostname'], ['app', 'axis-device', $axd_app_id]);

$colours = 'mixed';
$unit_text = 'Count';
$nototal = 1;
$scale_min = 0;

$rrd_list = [
    ['filename' => $rrd_filename, 'descr' => 'Streams', 'ds' => 'streams'],
    ['filename' => $rrd_filename, 'descr' => 'Clients', 'ds' => 'clients'],
];

require 'includes/html/graphs/generic_multi_line.inc.php';
