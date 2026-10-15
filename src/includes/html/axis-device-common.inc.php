<?php
/*
 * Axis Device app for LibreNMS: shared helpers (device page + fleet overview)
 * Install path: /opt/librenms/includes/html/axis-device-common.inc.php
 */

if (! function_exists('axd_decode')) {
    /** App version (also shown in the page footer). */
    function axd_version(): string
    {
        return '1.0.2';
    }

    /** Setting: axis_device.<key>. */
    function axd_cfg(string $key, $default = null)
    {
        return \LibreNMS\Config::get('axis_device.' . $key, $default);
    }

    /** Decode applications.data (array, JSON string, or double-encoded JSON). */
    function axd_decode($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    function axd_h($v): string
    {
        return htmlspecialchars(is_scalar($v) || $v === null ? (string) $v : json_encode($v), ENT_QUOTES);
    }

    function axd_ago($ts): string
    {
        if (! is_numeric($ts) || $ts <= 0) {
            return 'never';
        }
        $s = max(0, time() - (int) $ts);
        if ($s < 90) {
            return $s . 's ago';
        }
        if ($s < 5400) {
            return round($s / 60) . 'm ago';
        }
        if ($s < 172800) {
            return round($s / 3600) . 'h ago';
        }

        return round($s / 86400) . 'd ago';
    }

    function axd_uptime($sec): string
    {
        if (! is_numeric($sec)) {
            return 'N/A';
        }
        $sec = (int) $sec;
        $parts = [];
        if ($sec >= 86400) {
            $parts[] = floor($sec / 86400) . 'd';
        }
        if ($sec % 86400 >= 3600) {
            $parts[] = floor(($sec % 86400) / 3600) . 'h';
        }
        if ($sec < 86400 && $sec % 3600 >= 60) {
            $parts[] = floor(($sec % 3600) / 60) . 'm';
        }

        return $parts ? implode(' ', $parts) : '0m';
    }

    function axd_speed($bps): string
    {
        $bps = (float) $bps;
        if ($bps >= 1e9) {
            return round($bps / 1e9, 1) . ' Gbps';
        }
        if ($bps >= 1e6) {
            return round($bps / 1e6) . ' Mbps';
        }

        return $bps > 0 ? round($bps / 1e3) . ' Kbps' : 'n/a';
    }

    function axd_label(string $cls, string $text, string $title = '', bool $big = false): string
    {
        $style = $big ? ' style="display:inline-block;font-size:13px;padding:6px 10px;margin:0 6px 6px 0;"' : '';

        return '<span class="label label-' . $cls . '"' . $style . ($title !== '' ? ' title="' . axd_h($title) . '"' : '') . '>' . axd_h($text) . '</span>';
    }

    /** Severity rank for sorting / worst-of. */
    function axd_rank(string $level): int
    {
        return ['danger' => 3, 'warning' => 2, 'info' => 1, 'primary' => 1, 'success' => 0, 'default' => 0][$level] ?? 0;
    }

    function axd_device_url(array $dev, string $tab = 'apps'): string
    {
        $vars = $tab === 'apps' ? ['tab' => 'apps', 'app' => 'axis-device'] : ['tab' => $tab];
        if (function_exists('generate_device_url')) {
            try {
                return generate_device_url($dev, $vars);
            } catch (\Throwable $e) {
            }
        }
        $path = 'device/device=' . (int) $dev['device_id'] . '/';
        foreach ($vars as $k => $v) {
            $path .= $k . '=' . $v . '/';
        }

        return '/' . $path;
    }

    // -----------------------------------------------------------------------
    // Firmware
    // -----------------------------------------------------------------------

    /** "AXIS P3287-LVE" -> "P3287-LVE" */
    function axd_fw_model(string $short): string
    {
        $m = trim(preg_replace('/^AXIS\s+/i', '', $short));

        return $m === '' ? '' : preg_split('/\s+/', $m)[0];
    }

    /**
     * ftp.axis.com folder for a model.
     * Access control / door stations (A), audio & speakers (C) and intercoms (I) live under PACS,
     * everything else under MPQT. Per-model overrides:
     *   lnms config:set axis_device.fw_folder_overrides '{"D2110-VE":"MPQT"}'
     */
    function axd_fw_folder(string $model): string
    {
        $overrides = (array) axd_cfg('fw_folder_overrides', []);
        if (isset($overrides[$model])) {
            return (string) $overrides[$model];
        }

        return in_array(strtoupper(substr($model, 0, 1)), ['A', 'C', 'I'], true) ? 'PACS' : 'MPQT';
    }

    /** AXIS OS LTS tracks (major.minor => label). Update when a new LTS track is released. */
    function axd_fw_track(string $version): string
    {
        $lts = ['11.11' => 'LTS 2024', '10.12' => 'LTS 2022', '9.80' => 'LTS 2020', '8.40' => 'LTS 2018', '6.50' => 'LTS 2016'];
        if (preg_match('/^(\d+\.\d+)\./', $version, $m) && isset($lts[$m[1]])) {
            return $lts[$m[1]];
        }

        return '';
    }

    /** Download links on ftp.axis.com for a model + version. */
    function axd_fw_info(string $short, string $version): array
    {
        $model = axd_fw_model($short);
        if ($model === '') {
            return [];
        }
        $folder = axd_fw_folder($model);
        $m = rawurlencode($model);
        $base = 'https://ftp.axis.com/pub_soft/' . $folder . '/' . $m . '/';
        $info = [
            'model' => $model,
            'folder' => $folder,
            'index_url' => $base,
            'latest_url' => $base . 'latest/' . $m . '.bin',
            'current_url' => null,
            'track' => axd_fw_track($version),
        ];
        if (preg_match('/^\d+(\.\d+)+$/', $version)) {
            $v = str_replace('.', '_', $version);
            $info['current_url'] = $base . $v . '/' . $m . '_' . $v . '.bin';
        }

        return $info;
    }

    /** Newest version per model across a list of decoded data arrays. */
    function axd_fleet_newest(array $datas): array
    {
        $newest = [];
        foreach ($datas as $d) {
            $model = (string) ($d['ProdShortName'] ?? '');
            $ver = (string) ($d['Version'] ?? '');
            if ($model === '' || $ver === '') {
                continue;
            }
            if (! isset($newest[$model]) || version_compare($ver, $newest[$model], '>')) {
                $newest[$model] = $ver;
            }
        }

        return $newest;
    }

    // -----------------------------------------------------------------------
    // Health evaluation (shared by device page badges and fleet overview)
    // Returns a list of ['key', 'level', 'text', 'title'].
    // -----------------------------------------------------------------------
    function axd_health(array $d, ?array $link = null, ?string $fleet_newest = null): array
    {
        $out = [];
        $add = function (string $key, string $level, string $text, string $title = '') use (&$out) {
            $out[] = ['key' => $key, 'level' => $level, 'text' => $text, 'title' => $title];
        };

        $vs = $d['VapixStatus'] ?? 'ok';
        $vs === 'ok'
            ? $add('vapix', 'success', 'VAPIX OK')
            : $add('vapix', 'danger', 'VAPIX ' . str_replace('_', ' ', $vs), 'HTTP ' . (int) ($d['VapixHttpCode'] ?? 0));

        // SD card
        if (isset($d['Disks'])) {
            $sd = null;
            foreach ($d['Disks'] as $dk) {
                if (($dk['id'] ?? '') === 'SD_DISK') {
                    $sd = $dk;
                }
            }
            $st = strtolower($sd['status'] ?? '');
            if (! $sd || in_array($st, ['', 'disconnected'], true)) {
                $add('sd', 'default', 'No SD card');
            } else {
                $used = $sd['used_pct'] ?? null;
                if (! in_array($st, ['ok', 'connected'], true) || ($sd['readonly'] ?? '') === 'yes') {
                    $add('sd', 'danger', 'SD ' . $sd['status'] . (($sd['readonly'] ?? '') === 'yes' ? ' (read-only)' : ''));
                } elseif (($sd['full'] ?? '') === 'yes' || ($used !== null && $used >= 95)) {
                    $add('sd', 'warning', 'SD ' . $used . '% used');
                } else {
                    $add('sd', 'success', 'SD ' . ($used ?? '?') . '% used');
                }
            }
        }

        // ACAPs
        if (isset($d['Acaps'])) {
            $tot = count($d['Acaps']);
            $run = $stop = $bad = 0;
            $stopped = [];
            foreach ($d['Acaps'] as $a) {
                $s = strtolower($a['status'] ?? '');
                if ($s === 'running') {
                    $run++;
                } elseif ($s === 'stopped') {
                    $stop++;
                    $stopped[] = $a['nice'] ?? $a['name'] ?? '';
                }
                if (in_array(strtolower($a['license'] ?? ''), ['invalid', 'expired', 'missing'], true)) {
                    $bad++;
                }
            }
            if ($tot === 0) {
                $add('acaps', 'default', 'No ACAPs');
            } else {
                $add('acaps', ($stop || $bad) ? 'warning' : 'success',
                    "ACAPs {$run}/{$tot} running" . ($bad ? ", {$bad} license issue" : ''),
                    $stopped ? 'Stopped: ' . implode(', ', $stopped) : '');
            }
        }

        // Clock
        $off = $d['Time']['offset_s'] ?? null;
        if ($off !== null) {
            $off = (int) $off;
            $abs = abs($off);
            $add('clock', $abs <= 2 ? 'success' : ($abs <= 30 ? 'warning' : 'danger'), 'Clock ' . ($off >= 0 ? '+' : '') . $off . 's', 'Device clock vs LibreNMS poller');
        }

        // Firmware
        $fw = (string) (($d['Version'] ?? '') !== '' ? $d['Version'] : ($d['Firmware']['active'] ?? ''));
        $fw_status_current = ($d['Firmware']['active'] ?? '') === $fw;
        if ($fw !== '') {
            $track = axd_fw_track($fw);
            $txt = 'AXIS OS ' . $fw . ($track ? " ({$track})" : '');
            if ($fw_status_current && ($d['Firmware']['committed'] ?? null) === false) {
                $add('fw', 'warning', $txt . ' uncommitted', 'AXIS OS upgrade not committed; device may roll back on reboot');
            } elseif ($fleet_newest && version_compare($fw, $fleet_newest, '<')) {
                $add('fw', 'warning', $txt . ' behind fleet', 'Newest AXIS OS for this model in fleet: ' . $fleet_newest);
            } else {
                $add('fw', 'info', $txt);
            }
        }

        // Link (LibreNMS SNMP port data)
        if ($link) {
            if (empty($link['up'])) {
                $add('link', 'danger', 'Link down');
            } else {
                $sp = (float) ($link['speed'] ?? 0);
                $half = ! empty($link['half']);
                $add('link', ($half || ($sp > 0 && $sp < 1e8)) ? 'warning' : ($sp >= 1e9 ? 'success' : 'info'), 'Link ' . axd_speed($sp) . ($half ? ' half-duplex' : ''));
            }
        }

        // MQTT
        if (isset($d['Mqtt'])) {
            $conn = strtolower($d['Mqtt']['connection'] ?? '');
            if ($conn !== 'connected' && strtolower($d['Mqtt']['state'] ?? '') === 'inactive') {
                $add('mqtt', 'default', 'MQTT off');
            } else {
                $add('mqtt', $conn === 'connected' ? 'success' : 'danger', 'MQTT ' . ($conn !== '' ? $conn : 'unknown'), (string) ($d['Mqtt']['host'] ?? ''));
            }
        }

        // Temperature
        if (isset($d['CPUTemp'])) {
            $t = (float) $d['CPUTemp'];
            $add('temp', $t > 70 ? 'danger' : ($t > 60 ? 'warning' : 'success'), 'CPU ' . number_format($t, 1) . '°C');
        }

        // System log
        if (isset($d['Syslog'])) {
            $e = (int) ($d['Syslog']['errors_24h'] ?? 0);
            $add('log', $e === 0 ? 'success' : 'warning', $e === 0 ? 'Log clean (24h)' : "{$e} log errors (24h)");
        }

        // Certificate
        if (isset($d['Cert']['days_left']) && $d['Cert']['days_left'] !== null) {
            $dl = (int) $d['Cert']['days_left'];
            $add('cert', $dl < 0 ? 'danger' : ($dl < 30 ? 'warning' : 'default'), $dl < 0 ? 'Cert expired' : "Cert {$dl}d",
                ! empty($d['Cert']['self_signed']) ? 'Self-signed certificate' : (string) ($d['Cert']['issuer'] ?? ''));
        }

        // Clients
        $n = (int) ($d['ActiveClients'] ?? 0);
        $add('clients', $n > 0 ? 'primary' : 'default', $n . ' client' . ($n === 1 ? '' : 's'));

        return $out;
    }

    /** Worst level from axd_health() items. */
    function axd_worst(array $items): string
    {
        $worst = 'success';
        foreach ($items as $i) {
            if (axd_rank($i['level']) > axd_rank($worst) && in_array($i['level'], ['warning', 'danger'], true)) {
                $worst = $i['level'];
            }
        }

        return $worst;
    }
}
