<?php
/*
 * Axis Device application page for LibreNMS
 * ----------------------------------------
 * Per-device health view: status badges, snapshot, video, storage, ACAPs, system and history.
 * Data is collected by includes/polling/applications/axis-device.inc.php
 *
 * Install path: /opt/librenms/includes/html/pages/device/apps/axis-device.inc.php
 * Requires:     /opt/librenms/includes/html/axis-device-common.inc.php
 */

require_once __DIR__ . '/../../../axis-device-common.inc.php';

$d = axd_decode(is_object($app) ? $app->data : ($app['data'] ?? null));
$app_data = $d; // backwards compatibility
$app_id = is_object($app) ? $app->app_id : $app['app_id'];

if (empty($d)) {
    echo '<div class="alert alert-info">No VAPIX data yet. It will appear after the next poll.</div>';

    return;
}

$h = 'axd_h';
$small = fn (string $cls, string $text, string $title = ''): string => axd_label($cls, $text, $title);
$muted = fn (string $t): string => '<span class="text-muted">' . axd_h($t) . '</span>';
$panel = fn (string $title, string $body): string => '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">'
    . $title . '</h3></div><div class="panel-body">' . $body . '</div></div>';

// $rows values are pre-escaped HTML; empty values are skipped
$kv = function (array $rows): string {
    $o = '<table class="table table-condensed table-striped" style="margin-bottom:0">';
    foreach ($rows as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $o .= '<tr><th style="width:40%">' . axd_h($k) . '</th><td>' . $v . '</td></tr>';
    }

    return $o . '</table>';
};
// $rows cells are pre-escaped HTML
$table = function (array $headers, array $rows): string {
    $o = '<table class="table table-condensed table-striped table-hover" style="margin-bottom:0"><thead><tr>';
    foreach ($headers as $hd) {
        $o .= '<th>' . axd_h($hd) . '</th>';
    }
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $o .= '<tr>';
        foreach ($r as $c) {
            $o .= '<td>' . $c . '</td>';
        }
        $o .= '</tr>';
    }

    return $o . '</tbody></table>';
};
$details = fn (string $summary, string $body): string => '<details style="margin-top:10px"><summary style="cursor:pointer">'
    . $summary . '</summary><div style="margin-top:6px;overflow-x:auto">' . $body . '</div></details>';
$temp_cls = fn ($c): string => $c > 70 ? 'text-danger' : ($c > 60 ? 'text-warning' : 'text-success');

// ---------------------------------------------------------------------------
// Context from the LibreNMS DB
// ---------------------------------------------------------------------------
$device_id = (int) ($device['device_id'] ?? 0);
$model = (string) ($d['ProdShortName'] ?? '');

$link_row = null;
try {
    $link_row = dbFetchRow(
        "SELECT ifName, ifSpeed, ifDuplex, ifOperStatus FROM ports WHERE device_id = ? AND deleted = 0 AND ifType = 'ethernetCsmacd' ORDER BY (ifOperStatus = 'up') DESC, ifSpeed DESC LIMIT 1",
        [$device_id]
    );
} catch (\Throwable $e) {
}
$link = $link_row ? ['up' => ($link_row['ifOperStatus'] ?? '') === 'up', 'speed' => $link_row['ifSpeed'] ?? 0, 'half' => ($link_row['ifDuplex'] ?? '') === 'halfDuplex'] : null;

$fleet = [];
if ($model !== '') {
    try {
        $fleet = dbFetchRows(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(data, \'$.Version\')) AS v, COUNT(*) AS c FROM applications '
            . 'WHERE app_type = \'axis-device\' AND deleted_at IS NULL AND JSON_VALID(data) AND JSON_UNQUOTE(JSON_EXTRACT(data, \'$.ProdShortName\')) = ? '
            . 'GROUP BY v ORDER BY c DESC',
            [$model]
        ) ?: [];
    } catch (\Throwable $e) {
    }
}
$fleet = array_values(array_filter($fleet, fn ($f) => ! empty($f['v']) && $f['v'] !== 'null'));
$fleet_total = array_sum(array_map(fn ($f) => (int) $f['c'], $fleet));
$fleet_newest = axd_fleet_newest(array_map(fn ($f) => ['ProdShortName' => $model, 'Version' => $f['v']], $fleet))[$model] ?? null;

$client_name = function (string $ip): string {
    try {
        $n = dbFetchCell(
            'SELECT d.sysName FROM ipv4_addresses a JOIN ports p ON p.port_id = a.port_id JOIN devices d ON d.device_id = p.device_id WHERE a.ipv4_address = ? LIMIT 1',
            [$ip]
        );
        if (! $n) {
            $n = dbFetchCell("SELECT COALESCE(NULLIF(sysName, ''), hostname) FROM devices WHERE hostname = ? LIMIT 1", [$ip]);
        }

        return (string) $n;
    } catch (\Throwable $e) {
        return '';
    }
};

$alerts = [];
try {
    $alerts = dbFetchRows(
        'SELECT a.state, a.timestamp, r.name, r.severity FROM alerts a JOIN alert_rules r ON r.id = a.rule_id WHERE a.device_id = ? AND a.state IN (1,2,3,4) ORDER BY a.timestamp DESC',
        [$device_id]
    ) ?: [];
} catch (\Throwable $e) {
}

$events = [];
try {
    $events = dbFetchRows(
        "SELECT datetime, message, severity FROM eventlog WHERE device_id = ? AND message LIKE 'Axis Device:%' ORDER BY datetime DESC LIMIT 15",
        [$device_id]
    ) ?: [];
} catch (\Throwable $e) {
}

// ---------------------------------------------------------------------------
// Status banner + health badges
// ---------------------------------------------------------------------------
$vs = $d['VapixStatus'] ?? 'ok';
$vmsg = [
    'auth_failed' => ['danger', 'Authentication failed (HTTP %s). VAPIX user <strong>%s</strong> is missing or has the wrong password on this device.'],
    'not_supported' => ['warning', 'Device info API not supported (HTTP %s). AXIS OS may be too old.'],
    'unreachable' => ['warning', 'Could not reach the device over HTTPS or HTTP.'],
    'error' => ['warning', 'VAPIX request failed (HTTP %s).'],
];
if (isset($vmsg[$vs])) {
    [$cls, $fmt] = $vmsg[$vs];
    $vuser = axd_h((string) axd_cfg('username', '(not configured)'));
    $stale = ! empty($d['CollectedAt']) ? ' Showing last known data from ' . axd_h(axd_ago($d['CollectedAt'])) . '.' : '';
    echo '<div class="alert alert-' . $cls . '">' . sprintf($fmt, (int) ($d['VapixHttpCode'] ?? 0), $vuser) . $stale . '</div>';
}

$health = axd_health($d, $link, $fleet_newest);
echo '<div style="margin-bottom:10px">';
foreach ($health as $item) {
    echo axd_label($item['level'], $item['text'], $item['title'], true);
}
echo '</div>';

// Open LibreNMS alerts + device notes
$top = '';
if ($alerts) {
    $state_txt = [1 => 'active', 2 => 'acknowledged', 3 => 'worse', 4 => 'better'];
    $items = array_map(fn ($a) => axd_label(($a['severity'] ?? '') === 'critical' ? 'danger' : 'warning', ($a['name'] ?? 'Alert') . ' (' . ($state_txt[(int) $a['state']] ?? $a['state']) . ')', 'Since ' . ($a['timestamp'] ?? '')), $alerts);
    $top .= '<div class="alert alert-warning" style="padding:8px 12px;margin-bottom:10px"><strong><i class="fa fa-bell"></i> Open alerts:</strong> '
        . implode(' ', $items) . ' <a href="' . axd_h(axd_device_url($device, 'alerts')) . '">view</a></div>';
}
$notes = trim((string) ($device['notes'] ?? ''));
if ($notes !== '') {
    $top .= '<div class="well well-sm" style="margin-bottom:10px"><strong><i class="fa fa-sticky-note-o"></i> Device notes</strong> '
        . '<a href="' . axd_h(axd_device_url($device, 'notes')) . '" class="small">edit</a>'
        . '<div style="white-space:pre-wrap;margin-top:4px">' . axd_h(mb_substr($notes, 0, 1500)) . '</div></div>';
}
echo $top;

// ---------------------------------------------------------------------------
// TAB: Overview
// ---------------------------------------------------------------------------
$host = (string) ($device['hostname'] ?? '');
if (! empty($d['Snapshot']['b64'])) {
    $src = 'data:image/jpeg;base64,' . axd_h($d['Snapshot']['b64']);
    $snap = '<a href="#" data-toggle="modal" data-target="#axdSnapModal" title="Click to enlarge">'
        . '<img src="' . $src . '" alt="Snapshot" class="img-responsive img-rounded" style="width:100%"></a>'
        . '<div class="text-muted small" style="margin-top:6px">Captured ' . axd_h(axd_ago($d['Snapshot']['at'] ?? 0))
        . (! empty($d['Snapshot']['res']) ? ' · ' . axd_h($d['Snapshot']['res']) : '') . ' · click to enlarge</div>';
    $snap_modal = '<div class="modal fade" id="axdSnapModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">'
        . '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">'
        . axd_h(($device['sysName'] ?? '') ?: $host) . ' · ' . axd_h(axd_ago($d['Snapshot']['at'] ?? 0)) . '</h4></div>'
        . '<div class="modal-body"><img src="' . $src . '" alt="Snapshot" class="img-responsive" style="width:100%;image-rendering:auto"></div>'
        . '<div class="modal-footer"><a href="https://' . axd_h($host) . '/" target="_blank" rel="noopener" class="btn btn-primary">Open live view on device</a></div>'
        . '</div></div></div>';
} else {
    $snap = $muted('No snapshot (disabled, unsupported, or not yet captured).');
    $snap_modal = '';
}
$snap .= '<div style="margin-top:8px"><a href="https://' . axd_h($host) . '/" target="_blank" rel="noopener" class="btn btn-default btn-sm">'
    . '<i class="fa fa-external-link"></i> Open device web interface</a></div>';

$model_txt = axd_h($d['ProdFullName'] ?? '') . (($d['ProdShortName'] ?? '') !== '' ? ' <span class="text-muted">(' . axd_h($d['ProdShortName']) . ')</span>' : '');
$info = $kv([
    'Model' => $model_txt,
    'Product type' => axd_h($d['ProdType'] ?? ''),
    'Serial number' => axd_h($d['SerialNumber'] ?? ''),
    'AXIS OS' => axd_h($d['Version'] ?? '') . (axd_fw_track((string) ($d['Version'] ?? '')) ? ' ' . $small('info', axd_fw_track((string) $d['Version'])) : ''),
    'Build date' => axd_h($d['BuildDate'] ?? ''),
    'SoC / Architecture' => trim(axd_h($d['Soc'] ?? '') . ' / ' . axd_h($d['Architecture'] ?? ''), ' /'),
    'Hardware ID' => axd_h($d['HardwareID'] ?? ''),
    'Uptime' => isset($d['Uptime']) ? axd_h(axd_uptime($d['Uptime'])) : '',
    'System ready' => isset($d['SystemReady']) ? $small($d['SystemReady'] === 'yes' ? 'success' : 'warning', $d['SystemReady']) : '',
    'Needs setup' => (($d['NeedSetup'] ?? '') === 'yes') ? $small('danger', 'yes') : '',
    'Max resolution' => axd_h($d['Caps']['max_resolution'] ?? ''),
    'Capabilities' => axd_h(implode(', ', array_filter([
        (($d['Caps']['ptz'] ?? '') === 'yes') ? 'PTZ' : '',
        (($d['Caps']['audio'] ?? '') === 'yes') ? 'Audio' : '',
    ]))),
]);

$sevmap = [1 => 'success', 2 => 'info', 3 => 'primary', 4 => 'warning', 5 => 'danger'];
if ($events) {
    $rows = array_map(fn ($e) => [
        '<span class="text-muted small" style="white-space:nowrap">' . axd_h($e['datetime']) . '</span>',
        axd_label($sevmap[(int) $e['severity']] ?? 'default', ' ') . ' ' . axd_h(preg_replace('/^Axis Device:\s*/', '', $e['message'])),
    ], $events);
    $events_html = $table(['When', 'Change'], $rows) . '<p class="small" style="margin:8px 0 0"><a href="' . axd_h(axd_device_url($device, 'logs')) . '">All device logs</a></p>';
} else {
    $events_html = $muted('No changes recorded yet. AXIS OS, ACAP, SD card, user, MQTT and clock changes appear here.');
}

$tab_overview = '<div class="row">'
    . '<div class="col-md-4">' . $panel('Live view', $snap) . '</div>'
    . '<div class="col-md-8">' . $panel('Axis device information', $info) . '</div>'
    . '</div>'
    . '<div class="row"><div class="col-md-12">' . $panel('Recent changes', $events_html) . '</div></div>';

// ---------------------------------------------------------------------------
// TAB: Video
// ---------------------------------------------------------------------------
$str = $kv([
    'Active streams' => '<span class="badge">' . (int) ($d['StreamCount'] ?? 0) . '</span> <small class="text-muted">' . (int) ($d['VideoStreams'] ?? 0) . ' video, ' . (int) ($d['AudioStreams'] ?? 0) . ' audio</small>',
    'Active clients' => '<span class="badge">' . (int) ($d['ActiveClients'] ?? 0) . '</span>',
]);
if (! empty($d['Clients'])) {
    $rows = [];
    foreach ($d['Clients'] as $ip => $cnt) {
        $nm = $client_name((string) $ip);
        $rows[] = [axd_h($ip), $nm !== '' ? '<strong>' . axd_h($nm) . '</strong>' : $muted('unknown'), (int) $cnt];
    }
    $str .= '<h5 style="margin-top:12px">Connected clients</h5>' . $table(['Client IP', 'Known as', 'Streams'], $rows);
}
if (! empty($d['Streams'])) {
    $cols = [];
    foreach ($d['Streams'] as $s) {
        foreach (array_keys($s) as $k) {
            $cols[$k] = true;
        }
    }
    $cols = array_slice(array_keys($cols), 0, 8);
    $rows = [];
    foreach ($d['Streams'] as $s) {
        $rows[] = array_map(fn ($c) => axd_h($s[$c] ?? ''), $cols);
    }
    $str .= $details('Stream details', $table($cols, $rows));
}
$prof = $muted('No stream profiles reported.');
if (! empty($d['StreamProfiles'])) {
    $rows = array_map(fn ($p) => ['<strong>' . axd_h($p['name']) . '</strong>', axd_h($p['description']), '<small>' . axd_h($p['parameters']) . '</small>'], $d['StreamProfiles']);
    $prof = $table(['Name', 'Description', 'Parameters'], $rows);
}

$video_extra = [];
if (! empty($d['PtzPosition']) || ! empty($d['PtzPresets'])) {
    $ptz = $kv(array_map('axd_h', $d['PtzPosition'] ?? []));
    if (! empty($d['PtzPresets'])) {
        $ptz .= '<h5 style="margin-top:12px">Presets</h5><p>' . implode(' ', array_map(fn ($n, $nm) => $small('default', $n . ': ' . $nm), array_keys($d['PtzPresets']), $d['PtzPresets'])) . '</p>';
    }
    $video_extra[] = $panel('PTZ', $ptz);
}
if (! empty($d['Lights'])) {
    $rows = array_map(fn ($l) => [
        axd_h($l['id']),
        axd_h($l['type']),
        $small($l['enabled'] === 'yes' ? 'success' : 'default', $l['enabled'] ?: '?'),
        $small($l['state'] === 'yes' ? 'warning' : 'default', $l['state'] === 'yes' ? 'on' : ($l['state'] === 'no' ? 'off' : ($l['state'] ?: '?'))),
    ], $d['Lights']);
    $video_extra[] = $panel('Lights (IR / illumination)', $table(['ID', 'Type', 'Enabled', 'State'], $rows));
}

$tab_video = '<div class="row">'
    . '<div class="col-md-6">' . $panel('Streams &amp; clients', $str) . '</div>'
    . '<div class="col-md-6">' . $panel('Stream profiles', $prof) . implode('', $video_extra) . '</div>'
    . '</div>';

// ---------------------------------------------------------------------------
// TAB: Storage & ACAPs
// ---------------------------------------------------------------------------
$storage = $muted('Storage information not available.');
if (isset($d['Disks'])) {
    $rows = [];
    foreach ($d['Disks'] as $dk) {
        $st = strtolower($dk['status'] ?? '');
        if ($st === 'disconnected' && ($dk['id'] ?? '') !== 'SD_DISK') {
            continue; // unused network share slots
        }
        $st_cls = in_array($st, ['ok', 'connected'], true) ? 'success' : ($st === 'disconnected' ? 'default' : 'danger');
        $used = $dk['used_pct'] ?? null;
        $bar = '';
        if ($used !== null && ($dk['total_kb'] ?? 0) > 0) {
            $bcls = $used >= 95 ? 'danger' : ($used >= 85 ? 'warning' : 'success');
            $bar = '<div class="progress" style="margin:0;min-width:90px"><div class="progress-bar progress-bar-' . $bcls . '" style="width:' . (float) $used . '%">' . axd_h($used) . '%</div></div>';
        }
        $flags = [];
        foreach (['full' => 'full', 'readonly' => 'read-only', 'locked' => 'locked', 'encrypted' => 'encrypted'] as $k => $lbl) {
            if (in_array($dk[$k] ?? '', ['yes', 'true'], true)) {
                $flags[] = $small($k === 'encrypted' ? 'info' : 'warning', $lbl);
            }
        }
        $kbfmt = function ($kb): string {
            $b = (float) $kb * 1024;
            $u = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            while ($b >= 1024 && $i < 4) {
                $b /= 1024;
                $i++;
            }

            return round($b, $i >= 3 ? 1 : 0) . ' ' . $u[$i];
        };
        $rows[] = [
            axd_h($dk['id'] ?? '') . (($dk['name'] ?? '') !== '' ? '<br><small class="text-muted">' . axd_h($dk['name']) . '</small>' : ''),
            $small($st_cls, $dk['status'] ?: 'unknown'),
            $bar,
            ($dk['total_kb'] ?? 0) > 0 ? axd_h($kbfmt($dk['free_kb']) . ' free of ' . $kbfmt($dk['total_kb'])) : '',
            axd_h($dk['fs'] ?? ''),
            implode(' ', $flags),
        ];
    }
    $storage = $rows ? $table(['Disk', 'Status', 'Used', 'Capacity', 'FS', 'Flags'], $rows) : $muted('No storage present.');
}

$acap_html = $muted('ACAP information not available.');
if (isset($d['Acaps'])) {
    $rows = [];
    foreach ($d['Acaps'] as $a) {
        $s = strtolower($a['status'] ?? '');
        $l = strtolower($a['license'] ?? '');
        $lcls = match (true) {
            $l === 'valid' => 'success',
            in_array($l, ['invalid', 'expired', 'missing'], true) => 'danger',
            $l === 'custom' => 'info',
            default => 'default',
        };
        $rows[] = [
            '<strong>' . axd_h($a['nice'] ?? '') . '</strong>' . (($a['name'] ?? '') !== ($a['nice'] ?? '') ? '<br><small class="text-muted">' . axd_h($a['name']) . '</small>' : ''),
            axd_h($a['vendor'] ?? ''),
            axd_h($a['version'] ?? ''),
            $small($s === 'running' ? 'success' : ($s === 'stopped' && axd_acap_recently_stopped($d, $a['name'] ?? '') ? 'warning' : 'default'), $a['status'] ?: 'unknown')
                . ($s === 'stopped' && ! empty($d['AcapState'][$a['name'] ?? '']['stopped_since'])
                    ? ' <small class="text-muted">stopped ' . axd_h(axd_ago($d['AcapState'][$a['name']]['stopped_since'])) . '</small>'
                    : ($s === 'stopped' && ! empty($d['AcapState'][$a['name'] ?? '']['last_running'])
                        ? ' <small class="text-muted">last ran ' . axd_h(axd_ago($d['AcapState'][$a['name']]['last_running'])) . '</small>' : '')),
            $small($lcls, $a['license'] ?: 'n/a'),
        ];
    }
    $acap_html = $rows ? $table(['Application', 'Vendor', 'Version', 'Status', 'License'], $rows) : $muted('No ACAP applications installed.');
}

$tab_storage = '<div class="row">'
    . '<div class="col-md-5">' . $panel('Storage', $storage) . '</div>'
    . '<div class="col-md-7">' . $panel('ACAP applications', $acap_html) . '</div>'
    . '</div>';

// ---------------------------------------------------------------------------
// TAB: System
// ---------------------------------------------------------------------------
$time_rows = [];
if (isset($d['Time'])) {
    $off = $d['Time']['offset_s'];
    $time_rows['Device time (UTC)'] = axd_h($d['Time']['device_utc'] ?? '');
    $time_rows['Offset vs LibreNMS'] = $off === null ? '' : $small(abs($off) <= 2 ? 'success' : (abs($off) <= 30 ? 'warning' : 'danger'), ($off >= 0 ? '+' : '') . $off . ' s');
    $time_rows['Time zone'] = axd_h(trim(($d['Time']['tz'] ?? '') . (($d['Time']['tz_source'] ?? '') !== '' ? ' (' . $d['Time']['tz_source'] . ')' : '')));
}
$ntp_i = 0;
foreach (($d['Ntp'] ?? []) as $k => $v) {
    if ($ntp_i++ >= 12) {
        break;
    }
    $time_rows['NTP ' . $k] = axd_h($v);
}
$time_html = $time_rows ? $kv($time_rows) : $muted('Time information not available.');

// AXIS OS with ftp.axis.com links
$fw_ver = (string) (($d['Version'] ?? '') !== '' ? $d['Version'] : ($d['Firmware']['active'] ?? ''));
$fwi = axd_fw_info($model, $fw_ver);
$fw_rows = [];
$fw_rows['Installed'] = axd_h($fw_ver) . (($fwi['track'] ?? '') !== '' ? ' ' . $small('info', $fwi['track']) : '');
if (isset($d['Firmware']) && ($d['Firmware']['active'] ?? '') !== '' && $d['Firmware']['active'] !== $fw_ver) {
    $fw_rows['Status'] = $muted('AXIS OS changed since the last status check; rollback and commit details refresh on the next poll.');
} elseif (isset($d['Firmware'])) {
    $fw = $d['Firmware'];
    $fw_rows['Inactive (rollback)'] = axd_h($fw['inactive'] ?? '');
    $fw_rows['Committed'] = ($fw['committed'] ?? null) === null ? '' : $small($fw['committed'] ? 'success' : 'warning', $fw['committed'] ? 'yes' : 'no');
    $fw_rows['Last upgrade'] = axd_h($fw['last_upgrade'] ?? '');
}
if ($fleet) {
    $fw_rows['Fleet (' . $model . ')'] = implode(' ', array_map(
        fn ($f) => $small($f['v'] === $fw_ver ? 'primary' : 'default', $f['v'] . ' × ' . (int) $f['c']),
        $fleet
    )) . ' <small class="text-muted">' . $fleet_total . ' devices</small>';
}
if ($fwi) {
    $btns = [];
    if ($fwi['current_url']) {
        $btns[] = '<a class="btn btn-default btn-xs" href="' . axd_h($fwi['current_url']) . '" rel="noopener"><i class="fa fa-download"></i> ' . axd_h($fw_ver) . '</a>';
    }
    if ($fleet_newest && $fleet_newest !== $fw_ver) {
        $nf = axd_fw_info($model, $fleet_newest);
        if (! empty($nf['current_url'])) {
            $btns[] = '<a class="btn btn-warning btn-xs" href="' . axd_h($nf['current_url']) . '" rel="noopener"><i class="fa fa-download"></i> ' . axd_h($fleet_newest) . ' (fleet newest)</a>';
        }
    }
    $btns[] = '<a class="btn btn-primary btn-xs" href="' . axd_h($fwi['latest_url']) . '" rel="noopener"><i class="fa fa-download"></i> Latest</a>';
    $btns[] = '<a class="btn btn-link btn-xs" href="' . axd_h($fwi['index_url']) . '" target="_blank" rel="noopener">All versions (' . axd_h($fwi['folder']) . ')</a>';
    $fw_rows['Download'] = implode(' ', $btns);
}

$net_rows = [];
if (isset($d['Network'])) {
    $net_rows['Hostname'] = axd_h($d['Network']['hostname'] ?? '');
    $net_rows['DNS servers'] = axd_h($d['Network']['dns'] ?? '');
    foreach (($d['Network']['interfaces'] ?? []) as $if) {
        $mode = strtolower($if['mode'] ?? '');
        $is_dhcp = in_array($mode, ['dhcp', 'auto'], true);
        $mode_lbl = $mode === '' ? '' : ' ' . $small($is_dhcp ? 'info' : 'default', $is_dhcp ? 'DHCP' : (in_array($mode, ['none', 'static'], true) ? 'static' : $mode));
        $net_rows['Interface ' . ($if['name'] ?? '')] = axd_h($if['ipv4'] ?? '') . $mode_lbl
            . (($if['gateway'] ?? '') !== '' ? '<br><small class="text-muted">gateway ' . axd_h($if['gateway']) . '</small>' : '')
            . (($if['mac'] ?? '') !== '' ? '<br><small class="text-muted">MAC ' . axd_h($if['mac']) . '</small>' : '')
            . (($if['ipv6'] ?? '') !== '' ? '<br><small class="text-muted">IPv6 ' . axd_h($if['ipv6']) . '</small>' : '');
    }
}
if ($link_row) {
    $net_rows['Link (SNMP)'] = axd_h(($link_row['ifName'] ?? '') . ' · ' . axd_speed($link_row['ifSpeed'] ?? 0) . ' · ' . ($link_row['ifDuplex'] ?? '?') . ' · ' . ($link_row['ifOperStatus'] ?? '?'));
}
$net_html = $net_rows ? $kv($net_rows) : $muted('Network information not available.');

$sys_extra = [];
$env_rows = [];
foreach (($d['Sensors'] ?? []) as $nm => $c) {
    if ($c !== null) {
        $env_rows[$nm] = '<strong class="' . $temp_cls($c) . '">' . number_format((float) $c, 1) . '°C</strong>';
    }
}
foreach (($d['HeaterFan'] ?? []) as $nm => $stt) {
    $env_rows[$nm] = axd_h($stt);
}
if ($env_rows) {
    $sys_extra[] = $panel('Temperature &amp; environment', $kv($env_rows));
}
if (isset($d['Mqtt'])) {
    $m = $d['Mqtt'];
    $conn = strtolower($m['connection'] ?? '');
    $sys_extra[] = $panel('MQTT client', $kv([
        'State' => axd_h($m['state'] ?? ''),
        'Connection' => ($m['connection'] ?? '') !== '' ? $small($conn === 'connected' ? 'success' : 'danger', $m['connection']) : '',
        'Broker' => axd_h(trim(($m['protocol'] ?? '') . ' ' . ($m['host'] ?? '') . (($m['port'] ?? '') !== '' ? ':' . $m['port'] : ''))),
        'Client ID' => axd_h($m['client_id'] ?? ''),
    ]));
}
if (! empty($d['IoPorts'])) {
    $rows = array_map(fn ($p) => [
        axd_h($p['port']),
        axd_h($p['name']),
        axd_h(trim($p['usage'] . ' ' . $p['direction'])),
        $small(($p['state'] !== '' && $p['normal'] !== '' && $p['state'] !== $p['normal']) ? 'warning' : 'default', $p['state'] ?: '?'),
    ], $d['IoPorts']);
    $sys_extra[] = $panel('I/O ports', $table(['Port', 'Name', 'Usage', 'State'], $rows));
}

$log_html = $muted('System log not collected yet (refreshed hourly).');
if (isset($d['Syslog'])) {
    $e = (int) ($d['Syslog']['errors_24h'] ?? 0);
    $w = (int) ($d['Syslog']['warnings_24h'] ?? 0);
    $log_html = '<p>' . $small($e ? 'danger' : 'success', $e . ' errors') . ' ' . $small($w ? 'warning' : 'default', $w . ' warnings') . ' <span class="text-muted">in the last 24 h</span></p>';
    if (! empty($d['Syslog']['last_errors'])) {
        $log_html .= '<pre style="max-height:220px;overflow:auto;font-size:11px;white-space:pre-wrap">' . axd_h(implode("\n", $d['Syslog']['last_errors'])) . '</pre>';
    }
}
$users_html = $muted('User list not collected yet (refreshed hourly).');
if (! empty($d['Users'])) {
    $rows = [];
    foreach ($d['Users'] as $u => $role) {
        $rows[] = [axd_h($u), $small(match ($role) {
            'Administrator' => 'danger',
            'Operator' => 'warning',
            'Viewer' => 'info',
            default => 'default',
        }, $role)];
    }
    $users_html = $table(['Username', 'Highest role'], $rows);
}

$cert_html = '';
if (! empty($d['Cert']['valid_to'])) {
    $c = $d['Cert'];
    $cert_html = $panel('HTTPS certificate', $kv([
        'Subject' => axd_h($c['subject'] ?? ''),
        'Issuer' => axd_h($c['issuer'] ?? '') . (! empty($c['self_signed']) ? ' ' . $small('default', 'self-signed') : ''),
        'Valid to' => axd_h($c['valid_to']) . (isset($c['days_left']) ? ' <small class="text-muted">(' . (int) $c['days_left'] . ' days)</small>' : ''),
    ]));
}

$tab_system = '<div class="row">'
    . '<div class="col-md-6">' . $panel('AXIS OS', $kv($fw_rows)) . $panel('Time &amp; NTP', $time_html) . $cert_html . '</div>'
    . '<div class="col-md-6">' . $panel('Network', $net_html) . implode('', $sys_extra) . '</div>'
    . '</div>'
    . '<div class="row">'
    . '<div class="col-md-8">' . $panel('System log', $log_html) . '</div>'
    . '<div class="col-md-4">' . $panel('User accounts', $users_html) . '</div>'
    . '</div>';

// ---------------------------------------------------------------------------
// TAB: History (RRD graphs written by the poller)
// ---------------------------------------------------------------------------
$graphs = [
    'axis-device_clients' => 'Streams &amp; clients',
    'axis-device_temp' => 'CPU temperature',
    'axis-device_sd' => 'SD card usage',
    'axis-device_acaps' => 'ACAP applications',
    'axis-device_clock' => 'Clock offset vs LibreNMS',
];
ob_start();
foreach ($graphs as $key => $text) {
    $graph_array = [
        'height' => '100',
        'width' => '215',
        'to' => \LibreNMS\Config::get('time.now'),
        'id' => $app_id,
        'type' => 'application_' . $key,
    ];
    echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">' . $text . '</h3></div><div class="panel-body"><div class="row">';
    try {
        include \LibreNMS\Config::get('install_dir') . '/includes/html/print-graphrow.inc.php';
    } catch (\Throwable $e) {
        echo '<div class="col-md-12 text-muted">Graph unavailable: ' . axd_h($e->getMessage()) . '</div>';
    }
    echo '</div></div></div>';
}
$tab_history = '<p class="text-muted small">History starts from the first poll with this version of the app.</p>' . ob_get_clean();

// ---------------------------------------------------------------------------
// TAB: APIs
// ---------------------------------------------------------------------------
$tab_apis = $muted('API list not collected yet (refreshed every 6 hours).');
if (! empty($d['Apis'])) {
    $rows = array_map(fn ($a) => [axd_h($a['id']), axd_h($a['name']), axd_h($a['version']), axd_h($a['status'])], $d['Apis']);
    $tab_apis = $panel('Supported VAPIX APIs (' . count($d['Apis']) . ')', $table(['ID', 'Name', 'Version', 'Status'], $rows));
}

// ---------------------------------------------------------------------------
// Render tabs
// ---------------------------------------------------------------------------
$count_badge = fn (int $n, string $cls = 'default') => $n > 0 ? ' <span class="label label-' . $cls . '">' . $n . '</span>' : '';
$storage_issues = count(array_filter($health, fn ($i) => in_array($i['key'], ['sd', 'acaps'], true) && in_array($i['level'], ['warning', 'danger'], true)));
$system_issues = count(array_filter($health, fn ($i) => in_array($i['key'], ['fw', 'clock', 'mqtt', 'temp', 'log', 'cert', 'link'], true) && in_array($i['level'], ['warning', 'danger'], true)));

$tabs = [
    'overview' => ['Overview', $tab_overview],
    'video' => ['Video' . $count_badge((int) ($d['ActiveClients'] ?? 0), 'primary'), $tab_video],
    'storage' => ['Storage &amp; ACAPs' . $count_badge($storage_issues, 'warning'), $tab_storage],
    'system' => ['System' . $count_badge($system_issues, 'warning'), $tab_system],
    'history' => ['History', $tab_history],
    'apis' => ['APIs', $tab_apis],
];

echo '<ul class="nav nav-tabs" id="axd-tabs" role="tablist" style="margin-bottom:12px">';
$first = true;
foreach ($tabs as $id => [$title, $_]) {
    echo '<li role="presentation"' . ($first ? ' class="active"' : '') . '><a href="#axd-' . $id . '" aria-controls="axd-' . $id . '" role="tab" data-toggle="tab">' . $title . '</a></li>';
    $first = false;
}
echo '</ul><div class="tab-content">';
$first = true;
foreach ($tabs as $id => [$_, $body]) {
    echo '<div role="tabpanel" class="tab-pane' . ($first ? ' active' : '') . '" id="axd-' . $id . '">' . $body . '</div>';
    $first = false;
}
echo '</div>';

echo $snap_modal;

echo '<p class="text-muted small">Data collected ' . axd_h(axd_ago($d['CollectedAt'] ?? 0)) . ' · checked ' . axd_h(axd_ago($d['CheckedAt'] ?? 0))
    . ' · via ' . axd_h(strtoupper($d['Proto'] ?? 'https')) . ' · Axis Device app v' . axd_h(axd_version()) . '</p>';

// Remember the last tab per browser
echo <<<'JS'
<script>
(function () {
    var key = 'axd-active-tab';
    try {
        var saved = localStorage.getItem(key);
        if (saved && window.jQuery && jQuery('#axd-tabs a[href="' + saved + '"]').length) {
            jQuery('#axd-tabs a[href="' + saved + '"]').tab('show');
        }
    } catch (e) {}
    if (window.jQuery) {
        jQuery('#axd-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            try { localStorage.setItem(key, jQuery(e.target).attr('href')); } catch (err) {}
        });
    }
})();
</script>
JS;
