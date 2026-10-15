<?php
/*
 * Axis Device app for LibreNMS: fleet overview
 * -------------------------------------------
 * Every Axis device with the axis-device app in one table, problems first.
 * Reached from Apps > Axis Device  (URL: /apps/app=axis-device/)
 *
 * Install path: /opt/librenms/includes/html/pages/apps/axis-device.inc.php
 * Requires:     /opt/librenms/includes/html/axis-device-common.inc.php
 */

require_once __DIR__ . '/../../axis-device-common.inc.php';

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$rows = [];
try {
    $rows = dbFetchRows(
        "SELECT a.app_id, d.device_id, d.hostname, d.sysName, d.poller_group, d.status AS dev_status, d.disabled, d.`ignore`,
                CASE WHEN JSON_VALID(a.data) THEN JSON_REMOVE(a.data, '$.Snapshot', '$.Streams', '$.Apis', '$.StreamProfiles', '$.Syslog.last_errors', '$._base') END AS data
         FROM applications a JOIN devices d ON d.device_id = a.device_id
         WHERE a.app_type = 'axis-device' AND a.deleted_at IS NULL"
    ) ?: [];
} catch (\Throwable $e) {
    echo '<div class="alert alert-danger">Could not load Axis devices: ' . axd_h($e->getMessage()) . '</div>';

    return;
}

$groups = [0 => 'Default (0)'];
try {
    foreach (dbFetchRows('SELECT id, group_name FROM poller_groups') ?: [] as $g) {
        $groups[(int) $g['id']] = $g['group_name'];
    }
} catch (\Throwable $e) {
}

$links = [];
$ids = array_map(fn ($r) => (int) $r['device_id'], $rows);
if ($ids) {
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (dbFetchRows(
            "SELECT device_id, MAX(ifOperStatus = 'up') AS up,
                    MAX(CASE WHEN ifOperStatus = 'up' THEN ifSpeed END) AS speed,
                    MAX(ifOperStatus = 'up' AND ifDuplex = 'halfDuplex') AS half
             FROM ports WHERE deleted = 0 AND ifType = 'ethernetCsmacd' AND device_id IN ($in) GROUP BY device_id",
            $ids
        ) ?: [] as $l) {
            $links[(int) $l['device_id']] = ['up' => (bool) $l['up'], 'speed' => $l['speed'], 'half' => (bool) $l['half']];
        }
    } catch (\Throwable $e) {
    }
}

foreach ($rows as &$r) {
    $r['d'] = axd_decode($r['data']);
    unset($r['data']);
}
unset($r);

$newest = axd_fleet_newest(array_column($rows, 'd'));

// ---------------------------------------------------------------------------
// Evaluate each device
// ---------------------------------------------------------------------------
$sum = ['total' => 0, 'ok' => 0, 'warning' => 0, 'danger' => 0, 'auth' => 0, 'fw_behind' => 0, 'down' => 0];
$models = [];
foreach ($rows as &$r) {
    $d = $r['d'];
    $model = (string) ($d['ProdShortName'] ?? '');
    $health = $d ? axd_health($d, $links[(int) $r['device_id']] ?? null, $newest[$model] ?? null) : [];
    $issues = array_values(array_filter($health, fn ($i) => in_array($i['level'], ['warning', 'danger'], true)));

    if ((int) $r['disabled'] || (int) $r['ignore']) {
        $level = 'default';
    } elseif ((int) $r['dev_status'] === 0) {
        array_unshift($issues, ['key' => 'down', 'level' => 'danger', 'text' => 'Device down', 'title' => 'LibreNMS reports the device as down']);
        $level = 'danger';
        $sum['down']++;
    } elseif (! $d) {
        $issues[] = ['key' => 'nodata', 'level' => 'warning', 'text' => 'No data yet', 'title' => ''];
        $level = 'warning';
    } else {
        $level = axd_worst($health);
    }

    $r['level'] = $level;
    $r['issues'] = $issues;
    $r['model'] = $model;
    $r['site'] = $groups[(int) $r['poller_group']] ?? ('Group ' . (int) $r['poller_group']);

    $sum['total']++;
    $sum[$level === 'success' ? 'ok' : ($level === 'default' ? 'ok' : $level)]++;
    if (($d['VapixStatus'] ?? 'ok') === 'auth_failed') {
        $sum['auth']++;
    }
    foreach ($issues as $i) {
        if ($i['key'] === 'fw' && str_contains($i['text'], 'behind fleet')) {
            $sum['fw_behind']++;
        }
    }

    if ($model !== '') {
        $ver = (string) ($d['Version'] ?? '?');
        $models[$model][$ver] = ($models[$model][$ver] ?? 0) + 1;
    }
}
unset($r);

usort($rows, function ($a, $b) {
    $c = axd_rank($b['level']) <=> axd_rank($a['level']);

    return $c !== 0 ? $c : strnatcasecmp(($a['sysName'] ?: $a['hostname']), ($b['sysName'] ?: $b['hostname']));
});

// ---------------------------------------------------------------------------
// Summary tiles
// ---------------------------------------------------------------------------
$tile = fn (string $cls, int $n, string $label, string $filter) => '<div class="col-xs-6 col-sm-4 col-md-2"><a href="#" class="axd-tile" data-filter="' . $filter . '" style="text-decoration:none">'
    . '<div class="panel panel-' . $cls . '" style="margin-bottom:10px"><div class="panel-body text-center" style="padding:10px">'
    . '<div style="font-size:26px;font-weight:bold;line-height:1.1">' . $n . '</div><div class="small">' . axd_h($label) . '</div></div></div></a></div>';

echo '<div class="row" style="margin-top:10px">'
    . $tile('default', $sum['total'], 'Axis devices', 'all')
    . $tile('success', $sum['ok'], 'Healthy', 'success')
    . $tile('warning', $sum['warning'], 'Warnings', 'warning')
    . $tile('danger', $sum['danger'], 'Critical', 'danger')
    . $tile('danger', $sum['auth'], 'VAPIX auth failed', 'auth')
    . $tile('warning', $sum['fw_behind'], 'AXIS OS behind fleet', 'fw')
    . '</div>';

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
echo '<div class="form-inline" style="margin-bottom:10px">'
    . '<input type="search" id="axd-q" class="form-control input-sm" placeholder="Search name, IP, model, AXIS OS…" style="width:280px;margin-right:8px">'
    . '<select id="axd-site" class="form-control input-sm" style="margin-right:8px"><option value="">All sites</option>';
foreach (array_unique(array_column($rows, 'site')) as $s) {
    echo '<option value="' . axd_h($s) . '">' . axd_h($s) . '</option>';
}
echo '</select>'
    . '<label class="checkbox-inline" style="margin-right:8px"><input type="checkbox" id="axd-problems"> Problems only</label>'
    . '<span id="axd-count" class="text-muted small"></span>'
    . '</div>';

// ---------------------------------------------------------------------------
// Device table
// ---------------------------------------------------------------------------
$dot = ['danger' => 'text-danger', 'warning' => 'text-warning', 'success' => 'text-success', 'default' => 'text-muted'];
echo '<div class="table-responsive"><table class="table table-condensed table-hover" id="axd-fleet"><thead><tr>'
    . '<th data-sort="level" style="cursor:pointer;width:30px"></th>'
    . '<th data-sort="name" style="cursor:pointer">Device</th>'
    . '<th data-sort="site" style="cursor:pointer">Site</th>'
    . '<th data-sort="model" style="cursor:pointer">Model</th>'
    . '<th data-sort="fw" style="cursor:pointer">AXIS OS</th>'
    . '<th>Issues</th>'
    . '<th data-sort="clients" style="cursor:pointer" class="text-right">Clients</th>'
    . '<th data-sort="uptime" style="cursor:pointer" class="text-right">Uptime</th>'
    . '<th data-sort="checked" style="cursor:pointer" class="text-right">Checked</th>'
    . '</tr></thead><tbody>';

foreach ($rows as $r) {
    $d = $r['d'];
    $name = $r['sysName'] ?: $r['hostname'];
    $ver = (string) ($d['Version'] ?? '');
    $track = axd_fw_track($ver);
    $issue_html = $r['issues']
        ? implode(' ', array_map(fn ($i) => axd_label($i['level'], $i['text'], $i['title']), $r['issues']))
        : '<span class="text-muted small">OK</span>';
    $search = strtolower(implode(' ', [$name, $r['hostname'], $r['model'], $ver, $d['SerialNumber'] ?? '', $r['site']]));
    $is_auth = ($d['VapixStatus'] ?? 'ok') === 'auth_failed' ? '1' : '0';
    $is_fw = str_contains($issue_html, 'behind fleet') ? '1' : '0';

    echo '<tr data-level="' . axd_h($r['level']) . '" data-rank="' . axd_rank($r['level']) . '" data-site="' . axd_h($r['site']) . '"'
        . ' data-search="' . axd_h($search) . '" data-auth="' . $is_auth . '" data-fw="' . $is_fw . '"'
        . ' data-name="' . axd_h(strtolower($name)) . '" data-model="' . axd_h($r['model']) . '" data-fwv="' . axd_h($ver) . '"'
        . ' data-clients="' . (int) ($d['ActiveClients'] ?? 0) . '" data-uptime="' . (int) ($d['Uptime'] ?? 0) . '" data-checked="' . (int) ($d['CheckedAt'] ?? 0) . '">'
        . '<td><i class="fa fa-circle ' . ($dot[$r['level']] ?? 'text-muted') . '"></i></td>'
        . '<td><a href="' . axd_h(axd_device_url($r)) . '"><strong>' . axd_h($name) . '</strong></a>'
        . ($name !== $r['hostname'] ? '<br><small class="text-muted">' . axd_h($r['hostname']) . '</small>' : '') . '</td>'
        . '<td>' . axd_h($r['site']) . '</td>'
        . '<td>' . axd_h(preg_replace('/^AXIS\s+/i', '', $r['model'])) . '</td>'
        . '<td>' . axd_h($ver) . ($track ? ' ' . axd_label('info', $track) : '') . '</td>'
        . '<td>' . $issue_html . '</td>'
        . '<td class="text-right">' . (int) ($d['ActiveClients'] ?? 0) . '</td>'
        . '<td class="text-right">' . (isset($d['Uptime']) ? axd_h(axd_uptime($d['Uptime'])) : '') . '</td>'
        . '<td class="text-right small text-muted">' . axd_h(axd_ago($d['CheckedAt'] ?? 0)) . '</td>'
        . '</tr>';
}
echo '</tbody></table></div>';

// ---------------------------------------------------------------------------
// AXIS OS by model
// ---------------------------------------------------------------------------
ksort($models, SORT_NATURAL | SORT_FLAG_CASE);
$fw_rows = '';
foreach ($models as $model => $vers) {
    uksort($vers, fn ($a, $b) => version_compare($b, $a));
    $top = $newest[$model] ?? '';
    $info = axd_fw_info($model, $top);
    $chips = implode(' ', array_map(
        fn ($v, $c) => axd_label($v === $top ? 'success' : 'warning', $v . ' × ' . $c),
        array_keys($vers), $vers
    ));
    $dl = $info
        ? (($info['current_url'] ? '<a class="btn btn-default btn-xs" href="' . axd_h($info['current_url']) . '" rel="noopener"><i class="fa fa-download"></i> ' . axd_h($top) . '</a> ' : '')
            . '<a class="btn btn-primary btn-xs" href="' . axd_h($info['latest_url']) . '" rel="noopener"><i class="fa fa-download"></i> Latest</a> '
            . '<a class="btn btn-link btn-xs" href="' . axd_h($info['index_url']) . '" target="_blank" rel="noopener">All versions</a>')
        : '';
    $fw_rows .= '<tr><td><strong>' . axd_h(preg_replace('/^AXIS\s+/i', '', $model)) . '</strong></td><td class="text-right">' . array_sum($vers) . '</td><td>' . $chips . '</td><td>' . $dl . '</td></tr>';
}
echo '<details style="margin:10px 0 20px"><summary style="cursor:pointer"><strong>AXIS OS by model (' . count($models) . ' models)</strong></summary>'
    . '<div class="table-responsive" style="margin-top:8px"><table class="table table-condensed table-striped"><thead><tr><th>Model</th><th class="text-right">Devices</th><th>Versions in fleet</th><th>Download (ftp.axis.com)</th></tr></thead><tbody>'
    . $fw_rows . '</tbody></table></div></details>';

// ---------------------------------------------------------------------------
// Filtering / sorting (vanilla JS)
// ---------------------------------------------------------------------------
echo <<<'JS'
<script>
(function () {
    var table = document.getElementById('axd-fleet');
    if (!table) return;
    var tbody = table.tBodies[0];
    var q = document.getElementById('axd-q'), site = document.getElementById('axd-site'),
        problems = document.getElementById('axd-problems'), count = document.getElementById('axd-count');
    var tileFilter = 'all';

    function apply() {
        var term = (q.value || '').toLowerCase(), s = site.value, shown = 0;
        Array.prototype.forEach.call(tbody.rows, function (tr) {
            var lvl = tr.getAttribute('data-level'), ok = true;
            if (term && tr.getAttribute('data-search').indexOf(term) === -1) ok = false;
            if (s && tr.getAttribute('data-site') !== s) ok = false;
            if (problems.checked && lvl !== 'warning' && lvl !== 'danger') ok = false;
            if (tileFilter === 'success' && lvl !== 'success' && lvl !== 'default') ok = false;
            if ((tileFilter === 'warning' || tileFilter === 'danger') && lvl !== tileFilter) ok = false;
            if (tileFilter === 'auth' && tr.getAttribute('data-auth') !== '1') ok = false;
            if (tileFilter === 'fw' && tr.getAttribute('data-fw') !== '1') ok = false;
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        count.textContent = shown + ' of ' + tbody.rows.length + ' shown';
    }
    [q, site, problems].forEach(function (el) { el.addEventListener('input', apply); el.addEventListener('change', apply); });
    Array.prototype.forEach.call(document.querySelectorAll('.axd-tile'), function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); tileFilter = a.getAttribute('data-filter'); apply(); });
    });

    var dir = {};
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th) {
        var key = th.getAttribute('data-sort');
        if (!key) return;
        th.addEventListener('click', function () {
            dir[key] = !dir[key];
            var num = ['level', 'clients', 'uptime', 'checked'].indexOf(key) !== -1;
            var attr = key === 'level' ? 'data-rank' : (key === 'fw' ? 'data-fwv' : 'data-' + key);
            var rows = Array.prototype.slice.call(tbody.rows);
            rows.sort(function (a, b) {
                var x = a.getAttribute(attr) || '', y = b.getAttribute(attr) || '', c;
                if (num) c = (+x) - (+y);
                else c = x.localeCompare(y, undefined, {numeric: true, sensitivity: 'base'});
                return dir[key] ? c : -c;
            });
            rows.forEach(function (r) { tbody.appendChild(r); });
        });
    });
    apply();
})();
</script>
JS;
