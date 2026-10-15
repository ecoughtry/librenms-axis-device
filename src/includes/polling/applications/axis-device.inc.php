<?php
/*
 * Axis Device application poller for LibreNMS
 * -------------------------------------------
 * Collects device health over VAPIX: device info, storage, ACAPs, time/NTP, AXIS OS,
 * network, streams, MQTT, I/O, lights, PTZ, system log, users and a snapshot.
 *
 * Install path: /opt/librenms/includes/polling/applications/axis-device.inc.php
 *
 * Config (config.php or `lnms config:set`):
 *   axis_device.username / axis_device.password   VAPIX account (digest or basic auth)
 *   axis_device.snapshot  (default: true)           store a small JPEG thumbnail each poll
 *   axis_device.snapshot_resolution (default: 480x270)
 *
 * Also writes an RRD (history graphs) and LibreNMS eventlog entries when
 * something changes (AXIS OS, ACAPs, SD card, users, MQTT, clock, VAPIX access).
 *
 * Collection tiers:
 *   every poll : device info, streams, temps, uptime, storage, ACAPs, time/NTP,
 *                MQTT, I/O, lights, PTZ position, snapshot
 *   hourly     : users, system log, HTTPS certificate
 *   every 6 h  : API discovery, AXIS OS status, network settings,
 *                stream profiles, capabilities, PTZ presets
 *
 * Force every tier on a manual poll:
 *   sudo -u librenms env AXD_FORCE=1 ./lnms device:poll <device_id> -m applications -vv
 */

use LibreNMS\Config;
use LibreNMS\RRD\RrdDefinition;

$name = 'axis-device';

// Settings: axis_device.*
$axd_cfg = fn (string $key, $default = null) => Config::get('axis_device.' . $key, $default);
$axd_user = $axd_cfg('username');
$axd_pass = $axd_cfg('password');
$axd_snapshot = (bool) $axd_cfg('snapshot', true);

if (empty($axd_user) || empty($axd_pass)) {
    echo "VAPIX credentials not configured (axis_device.username / axis_device.password)\n";

    return;
}

$app_id = is_object($app) ? $app->app_id : $app['app_id'];

// ---------------------------------------------------------------------------
// Previous data: slow-tier values are carried over between polls
// ---------------------------------------------------------------------------
$axd_prev = is_object($app) ? $app->data : ($app['data'] ?? null);
if (is_string($axd_prev)) {
    $axd_prev = json_decode($axd_prev, true);
}
if (is_string($axd_prev)) {
    $axd_prev = json_decode($axd_prev, true);
}
$axd_prev = is_array($axd_prev) ? $axd_prev : [];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
if (! function_exists('axd_request')) {
    /**
     * VAPIX HTTP request with digest/basic auth, HTTPS first then HTTP.
     * Returns ['code' => int, 'body' => ?string]; code 0 means no HTTP response.
     */
    function axd_request(array $ctx, string $endpoint, ?string $json = null, int $timeout = 8): array
    {
        $pref = $GLOBALS['axd_proto'] ?? $ctx['proto'];
        $protocols = $pref === 'http' ? ['http', 'https'] : ['https', 'http'];
        $result = ['code' => 0, 'body' => null];

        foreach ($protocols as $proto) {
            $ch = curl_init("{$proto}://{$ctx['host']}{$endpoint}");
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $ctx['user'] . ':' . $ctx['pass'],
                CURLOPT_HTTPAUTH => CURLAUTH_ANY,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => $timeout,
            ];
            if ($json !== null) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $json;
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            if ($code === 200 && is_string($body) && $body !== '') {
                $GLOBALS['axd_proto'] = $proto;

                return ['code' => 200, 'body' => $body];
            }
            if ($code !== 0 || $result['code'] === 0) {
                $result = ['code' => $code, 'body' => null];
            }
            // Auth / not-found answers won't change on the other protocol
            if (in_array($code, [401, 403, 404], true)) {
                break;
            }
        }

        return $result;
    }

    /** JSON-style VAPIX call. Returns the "data" object, or null on failure. */
    function axd_json(array $ctx, string $endpoint, string $method, ?array $params = null, string $version = '1.0'): ?array
    {
        $req = ['apiVersion' => $version, 'context' => 'LibreNMS', 'method' => $method];
        if ($params !== null) {
            $req['params'] = $params;
        }
        $r = axd_request($ctx, $endpoint, json_encode($req));
        if ($r['code'] !== 200) {
            return null;
        }
        $d = json_decode($r['body'], true);
        if (! is_array($d) || isset($d['error'])) {
            return null;
        }

        return is_array($d['data'] ?? null) ? $d['data'] : [];
    }

    /** param.cgi list. Returns [http_code, ['root.X.Y' => value, ...]]. */
    function axd_params(array $ctx, string $groups): array
    {
        $r = axd_request($ctx, '/axis-cgi/param.cgi?action=list&group=' . $groups);
        $map = [];
        if ($r['code'] === 200) {
            $map = axd_kv($r['body']);
        }

        return [$r['code'], $map];
    }

    /** Parse key=value lines. */
    function axd_kv(?string $body): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $body) as $line) {
            if (str_contains($line, '=') && ! str_starts_with($line, '#')) {
                [$k, $v] = explode('=', $line, 2);
                $out[trim($k)] = trim($v);
            }
        }

        return $out;
    }

    /** Attributes of every XML element named $tag (namespace-agnostic). */
    function axd_xml_nodes(?string $body, string $tag): array
    {
        if (! $body) {
            return [];
        }
        $old = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (($xml->xpath("//*[local-name()='" . $tag . "']") ?: []) as $node) {
            $row = [];
            foreach ($node->attributes() as $k => $v) {
                $row[(string) $k] = (string) $v;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Safe string conversion for API values. */
    function axd_str($v): string
    {
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            return implode(', ', array_map('strval', array_filter($v, 'is_scalar')));
        }

        return '';
    }

    /** Flatten a nested array into ['a.b' => 'value'] (max 40 entries). */
    function axd_flatten(array $a, string $prefix = '', int $depth = 0): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && array_is_list($v) && count(array_filter($v, 'is_scalar')) === count($v)) {
                $out[$key] = implode(', ', array_map('axd_str', $v));
            } elseif (is_array($v)) {
                if ($depth < 3) {
                    $out += axd_flatten($v, $key, $depth + 1);
                }
            } elseif ($v !== null) {
                $out[$key] = axd_str($v);
            }
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------
$axd_ctx = [
    'host' => $device['hostname'],
    'user' => $axd_user,
    'pass' => $axd_pass,
    'proto' => $axd_prev['Proto'] ?? 'https',
];
$GLOBALS['axd_proto'] = null;

$axd_now = time();
$axd_force = (bool) getenv('AXD_FORCE');
$axd_ts = is_array($axd_prev['_ts'] ?? null) ? $axd_prev['_ts'] : [];
$axd = [];

$axd_due = function (string $key, int $interval) use (&$axd_ts, $axd_now, $axd_force): bool {
    if ($axd_force || ! isset($axd_ts[$key]) || ($axd_now - (int) $axd_ts[$key]) >= $interval) {
        $axd_ts[$key] = $axd_now;

        return true;
    }

    return false;
};
// Copy keys from the previous poll if they weren't collected this time
$axd_carry = function (array $keys) use (&$axd, $axd_prev): void {
    foreach ($keys as $k) {
        if (! array_key_exists($k, $axd) && array_key_exists($k, $axd_prev)) {
            $axd[$k] = $axd_prev[$k];
        }
    }
};

// ---------------------------------------------------------------------------
// System ready / uptime (no auth required on most AXIS OS versions)
// ---------------------------------------------------------------------------
$sr = axd_json($axd_ctx, '/axis-cgi/systemready.cgi', 'systemready');
if (is_array($sr)) {
    if (isset($sr['uptime'])) {
        $axd['Uptime'] = (int) $sr['uptime'];
    }
    if (isset($sr['systemready'])) {
        $axd['SystemReady'] = axd_str($sr['systemready']);
    }
    if (isset($sr['needsetup'])) {
        $axd['NeedSetup'] = axd_str($sr['needsetup']);
    }
}

// ---------------------------------------------------------------------------
// Basic device info (also decides auth status)
// ---------------------------------------------------------------------------
$axd_info_fields = ['SerialNumber', 'Version', 'ProdShortName', 'ProdFullName', 'ProdType', 'Soc', 'Architecture', 'BuildDate', 'HardwareID'];
$r = axd_request($axd_ctx, '/axis-cgi/basicdeviceinfo.cgi', json_encode(['apiVersion' => '1.0', 'context' => 'LibreNMS', 'method' => 'getAllProperties']));
$axd_code = $r['code'];
$props = null;
if ($r['code'] === 200) {
    $j = json_decode($r['body'], true);
    $props = $j['data']['propertyList'] ?? null;
}

if (is_array($props)) {
    foreach ($axd_info_fields as $f) {
        $axd[$f] = axd_str($props[$f] ?? '');
    }
} else {
    // Older AXIS OS: param.cgi fallback
    [$pc, $pm] = axd_params($axd_ctx, 'root.Brand,root.Properties.Firmware,root.Properties.System');
    if ($pc === 200 && $pm) {
        $map = [
            'ProdShortName' => 'root.Brand.ProdShortName',
            'ProdFullName' => 'root.Brand.ProdFullName',
            'ProdType' => 'root.Brand.ProdType',
            'SerialNumber' => 'root.Properties.System.SerialNumber',
            'Version' => 'root.Properties.Firmware.Version',
            'Soc' => 'root.Properties.System.Soc',
            'Architecture' => 'root.Properties.System.Architecture',
            'BuildDate' => 'root.Properties.Firmware.BuildDate',
            'HardwareID' => 'root.Properties.System.HardwareID',
        ];
        foreach ($map as $f => $k) {
            $axd[$f] = $pm[$k] ?? '';
        }
        $axd_code = 200;
    } elseif ($pc !== 0 && $axd_code !== 401) {
        $axd_code = $pc;
    }
}

if (! empty($axd['SerialNumber']) || ! empty($axd['ProdShortName'])) {
    $axd_status = 'ok';
} elseif (in_array($axd_code, [401, 403], true)) {
    $axd_status = 'auth_failed';
} elseif ($axd_code === 404) {
    $axd_status = 'not_supported';
} elseif ($axd_code === 0) {
    $axd_status = 'unreachable';
} else {
    $axd_status = 'error';
}

if ($axd_status !== 'ok') {
    // Keep the last good data so the page can still show what the device was (marked stale)
    $fresh = array_filter($axd, fn ($v) => $v !== '' && $v !== null);
    $axd = array_merge($axd_prev, $fresh);
    unset($axd['_ts']);
} else {
    // =======================================================================
    // FAST TIER: every poll
    // =======================================================================

    // --- Streams & clients -------------------------------------------------
    $s = axd_json($axd_ctx, '/axis-cgi/streamstatus.cgi', 'getAllStreams');
    $streams = (is_array($s) && is_array($s['streams'] ?? null)) ? $s['streams'] : [];
    $axd['StreamCount'] = count($streams);
    $axd['VideoStreams'] = 0;
    $axd['AudioStreams'] = 0;
    $clients = [];
    $stream_rows = [];
    foreach ($streams as $st) {
        if (! is_array($st)) {
            continue;
        }
        $media = strtolower(axd_str($st['media'] ?? ''));
        if ($media === 'video') {
            $axd['VideoStreams']++;
        } elseif ($media === 'audio') {
            $axd['AudioStreams']++;
        }
        $ip = axd_str($st['destination_address'] ?? '');
        if ($ip !== '') {
            $clients[$ip] = ($clients[$ip] ?? 0) + 1;
        }
        if (count($stream_rows) < 15) {
            $stream_rows[] = array_filter($st, 'is_scalar');
        }
    }
    $axd['ActiveClients'] = count($clients);
    $axd['Clients'] = array_slice($clients, 0, 20, true);
    $axd['Streams'] = $stream_rows;

    // --- Temperatures, heaters, fans ---------------------------------------
    $r = axd_request($axd_ctx, '/axis-cgi/temperaturecontrol.cgi?action=statusall');
    if ($r['code'] === 200) {
        $sensors = [];
        if (preg_match_all('/^Sensor\.(S\d+)\.(Name|Celsius)=(.*)$/m', $r['body'], $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $sensors[$row[1]][$row[2]] = trim($row[3]);
            }
        }
        $axd['Sensors'] = [];
        foreach ($sensors as $id => $sv) {
            $nm = $sv['Name'] ?? $id;
            $c = (isset($sv['Celsius']) && is_numeric($sv['Celsius'])) ? (float) $sv['Celsius'] : null;
            $axd['Sensors'][$nm] = $c;
            if ($c !== null && stripos($nm, 'cpu') !== false) {
                $axd['CPUTemp'] = $c;
            }
            if ($c !== null && stripos($nm, 'main') !== false) {
                $axd['MainTemp'] = $c;
            }
        }
        $hf = [];
        if (preg_match_all('/^(Heater|Fan)\.(\w+)\.Status=(.*)$/m', $r['body'], $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $hf[$row[1] . ' ' . $row[2]] = trim($row[3]);
            }
        }
        $axd['HeaterFan'] = $hf;
    }

    // --- Storage (SD card, network shares) --------------------------------
    $r = axd_request($axd_ctx, '/axis-cgi/disks/list.cgi?diskid=all');
    if ($r['code'] === 200) {
        $disks = [];
        foreach (axd_xml_nodes($r['body'], 'disk') as $a) {
            $total = (float) ($a['totalsize'] ?? 0);
            $free = (float) ($a['freesize'] ?? 0);
            $disks[] = [
                'id' => $a['diskid'] ?? '',
                'name' => $a['name'] ?? '',
                'status' => $a['status'] ?? '',
                'total_kb' => $total,
                'free_kb' => $free,
                'used_pct' => $total > 0 ? round(100 * ($total - $free) / $total, 1) : null,
                'fs' => $a['filesystem'] ?? '',
                'full' => $a['full'] ?? '',
                'readonly' => $a['readonly'] ?? '',
                'locked' => $a['locked'] ?? '',
                'encrypted' => $a['diskencrypted'] ?? '',
            ];
        }
        $axd['Disks'] = $disks;
    }

    // --- ACAP applications -------------------------------------------------
    $r = axd_request($axd_ctx, '/axis-cgi/applications/list.cgi');
    if ($r['code'] === 200) {
        $axd['Acaps'] = [];
        foreach (axd_xml_nodes($r['body'], 'application') as $a) {
            $axd['Acaps'][] = [
                'name' => $a['Name'] ?? '',
                'nice' => ($a['NiceName'] ?? '') !== '' ? $a['NiceName'] : ($a['Name'] ?? ''),
                'vendor' => $a['Vendor'] ?? '',
                'version' => $a['Version'] ?? '',
                'status' => $a['Status'] ?? '',
                'license' => $a['License'] ?? '',
            ];
        }
    }

    // --- Time & NTP --------------------------------------------------------
    $t = axd_json($axd_ctx, '/axis-cgi/time.cgi', 'getDateTimeInfo');
    if (is_array($t) && ! empty($t['dateTime'])) {
        $dev_ts = strtotime(axd_str($t['dateTime']));
        $axd['Time'] = [
            'device_utc' => axd_str($t['dateTime']),
            'offset_s' => $dev_ts !== false ? $dev_ts - time() : null,
            'tz' => axd_str($t['timeZone'] ?? ($t['posixTimeZone'] ?? '')),
            'tz_source' => axd_str($t['timeZoneSource'] ?? ''),
        ];
    }
    $n = axd_json($axd_ctx, '/axis-cgi/ntp.cgi', 'getNTPInfo');
    if (is_array($n) && $n) {
        $axd['Ntp'] = axd_flatten($n);
    }

    // --- MQTT client -------------------------------------------------------
    $mq = axd_json($axd_ctx, '/axis-cgi/mqtt/client.cgi', 'getClientStatus');
    if (is_array($mq) && $mq) {
        $axd['Mqtt'] = [
            'state' => axd_str($mq['status']['state'] ?? ''),
            'connection' => axd_str($mq['status']['connectionStatus'] ?? ''),
            'host' => axd_str($mq['config']['server']['host'] ?? ''),
            'port' => axd_str($mq['config']['server']['port'] ?? ''),
            'protocol' => axd_str($mq['config']['server']['protocol'] ?? ''),
            'client_id' => axd_str($mq['config']['clientId'] ?? ''),
        ];
    }

    // --- I/O ports ---------------------------------------------------------
    $io = axd_json($axd_ctx, '/axis-cgi/io/portmanagement.cgi', 'getPorts');
    if (is_array($io) && ! empty($io['items']) && is_array($io['items'])) {
        $axd['IoPorts'] = [];
        foreach ($io['items'] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $axd['IoPorts'][] = [
                'port' => axd_str($p['port'] ?? ''),
                'name' => axd_str($p['name'] ?? ''),
                'usage' => axd_str($p['usage'] ?? ''),
                'direction' => axd_str($p['direction'] ?? ''),
                'state' => axd_str($p['state'] ?? ''),
                'normal' => axd_str($p['normalState'] ?? ''),
            ];
        }
    }

    // --- Lights (IR / white light) ----------------------------------------
    $li = axd_json($axd_ctx, '/axis-cgi/lightcontrol.cgi', 'getLightInformation');
    if (is_array($li) && ! empty($li['items']) && is_array($li['items'])) {
        $axd['Lights'] = [];
        foreach ($li['items'] as $l) {
            if (! is_array($l)) {
                continue;
            }
            $axd['Lights'][] = [
                'id' => axd_str($l['lightID'] ?? ''),
                'type' => axd_str($l['lightType'] ?? ''),
                'enabled' => axd_str($l['enabled'] ?? ''),
                'state' => axd_str($l['lightState'] ?? ''),
            ];
        }
    }

    // --- Snapshot thumbnail ------------------------------------------------
    if ($axd_snapshot) {
        $res_list = array_unique([(string) $axd_cfg('snapshot_resolution', '480x270'), '480x270', '480x360', '320x180', '320x240']);
        foreach ($res_list as $res) {
            $r = axd_request($axd_ctx, '/axis-cgi/jpg/image.cgi?resolution=' . rawurlencode($res) . '&compression=50', null, 10);
            if ($r['code'] === 200 && str_starts_with($r['body'], "\xFF\xD8") && strlen($r['body']) < 120000) {
                $axd['Snapshot'] = ['b64' => base64_encode($r['body']), 'at' => $axd_now, 'res' => $res];
                break;
            }
        }
        $axd_carry(['Snapshot']);
    }

    // =======================================================================
    // HOURLY TIER
    // =======================================================================
    if ($axd_due('hourly', 3600)) {
        // --- Users ---------------------------------------------------------
        $r = axd_request($axd_ctx, '/axis-cgi/pwdgrp.cgi?action=get');
        if ($r['code'] === 200) {
            $users = [];
            foreach (['admin' => 'Administrator', 'operator' => 'Operator', 'viewer' => 'Viewer', 'ptz' => 'PTZ'] as $grp => $role) {
                if (preg_match('/^' . $grp . '="([^"]*)"/m', $r['body'], $mm)) {
                    foreach (array_filter(array_map('trim', explode(',', $mm[1]))) as $u) {
                        $users[$u] ??= $role;
                    }
                }
            }
            $axd['Users'] = $users;
            $axd['UserCount'] = count($users);
        }

        // --- System log (last 24 h) ---------------------------------------
        $r = axd_request($axd_ctx, '/axis-cgi/systemlog.cgi', null, 20);
        if ($r['code'] === 200) {
            $err = 0;
            $warn = 0;
            $last = [];
            $cut = $axd_now - 86400;
            foreach (preg_split('/\r?\n/', $r['body']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $lt = false;
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)/', $line, $mm)) {
                    $lt = strtotime($mm[1]);
                } elseif (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s\d{2}:\d{2}:\d{2})/', $line, $mm)) {
                    $lt = strtotime($mm[1]);
                }
                if ($lt !== false && $lt < $cut) {
                    continue;
                }
                if (preg_match('/\b(ERR|ERROR|CRIT|CRITICAL|ALERT|EMERG)\b/i', $line)) {
                    $err++;
                    $last[] = mb_substr($line, 0, 220);
                } elseif (preg_match('/\bWARN(ING)?\b/i', $line)) {
                    $warn++;
                }
            }
            $axd['Syslog'] = ['errors_24h' => $err, 'warnings_24h' => $warn, 'last_errors' => array_slice($last, -8)];
        }

        // --- HTTPS certificate --------------------------------------------
        if (function_exists('openssl_x509_parse')) {
            $sctx = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]]);
            $fp = @stream_socket_client('ssl://' . $device['hostname'] . ':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $sctx);
            if ($fp) {
                $params = stream_context_get_params($fp);
                fclose($fp);
                $cert = $params['options']['ssl']['peer_certificate'] ?? null;
                $x = $cert ? openssl_x509_parse($cert) : false;
                if (is_array($x)) {
                    $to = (int) ($x['validTo_time_t'] ?? 0);
                    $axd['Cert'] = [
                        'subject' => axd_str($x['subject']['CN'] ?? ''),
                        'issuer' => axd_str($x['issuer']['CN'] ?? ($x['issuer']['O'] ?? '')),
                        'valid_to' => $to ? gmdate('Y-m-d', $to) : '',
                        'days_left' => $to ? (int) floor(($to - $axd_now) / 86400) : null,
                        'self_signed' => ($x['subject'] ?? null) == ($x['issuer'] ?? null),
                    ];
                }
            }
        }
    }
    $axd_carry(['Users', 'UserCount', 'Syslog', 'Cert']);

    // =======================================================================
    // SLOW TIER: every 6 hours
    // =======================================================================
    if ($axd_due('slow', 21600)) {
        // --- Capabilities --------------------------------------------------
        [$pc, $pm] = axd_params($axd_ctx, 'root.Properties.Image,root.Properties.PTZ,root.Properties.Audio');
        if ($pc === 200 && $pm) {
            $best = '';
            $best_px = 0;
            foreach (explode(',', $pm['root.Properties.Image.Resolution'] ?? '') as $res) {
                if (preg_match('/^(\d+)x(\d+)$/', trim($res), $mm) && ($mm[1] * $mm[2]) > $best_px) {
                    $best_px = $mm[1] * $mm[2];
                    $best = trim($res);
                }
            }
            $axd['Caps'] = [
                'max_resolution' => $best,
                'formats' => $pm['root.Properties.Image.Format'] ?? '',
                'ptz' => $pm['root.Properties.PTZ.PTZ'] ?? 'no',
                'audio' => $pm['root.Properties.Audio.Audio'] ?? 'no',
            ];
        }

        // --- API discovery -------------------------------------------------
        $ad = axd_json($axd_ctx, '/axis-cgi/apidiscovery.cgi', 'getApiList');
        if (is_array($ad) && is_array($ad['apiList'] ?? null)) {
            $axd['Apis'] = [];
            foreach ($ad['apiList'] as $api) {
                if (is_array($api)) {
                    $axd['Apis'][] = [
                        'id' => axd_str($api['id'] ?? ''),
                        'version' => axd_str($api['version'] ?? ''),
                        'name' => axd_str($api['name'] ?? ''),
                        'status' => axd_str($api['status'] ?? ''),
                    ];
                }
            }
        }

        // --- AXIS OS status --------------------------------------------------
        $fw = axd_json($axd_ctx, '/axis-cgi/firmwaremanagement.cgi', 'status');
        if (is_array($fw) && $fw) {
            $axd['Firmware'] = [
                'active' => axd_str($fw['activeFirmwareVersion'] ?? ''),
                'active_part' => axd_str($fw['activeFirmwarePart'] ?? ''),
                'inactive' => axd_str($fw['inactiveFirmwareVersion'] ?? ''),
                'committed' => isset($fw['isCommitted']) ? (bool) $fw['isCommitted'] : null,
                'last_upgrade' => axd_str($fw['lastUpgradeAt'] ?? ''),
            ];
        }

        // --- Network settings (JSON API, param.cgi fallback) --------------
        $nw = axd_json($axd_ctx, '/axis-cgi/network_settings.cgi', 'getNetworkInfo');
        if (is_array($nw) && $nw) {
            $net = [
                'hostname' => axd_str($nw['system']['hostname']['hostname'] ?? ($nw['system']['hostname']['staticHostname'] ?? '')),
                'dns' => axd_str($nw['system']['resolver']['nameServers'] ?? ''),
                'interfaces' => [],
            ];
            foreach (($nw['devices'] ?? []) as $dev) {
                if (! is_array($dev)) {
                    continue;
                }
                $v4 = is_array($dev['IPv4'] ?? null) ? $dev['IPv4'] : [];
                $addrs = [];
                foreach (($v4['addresses'] ?? []) as $ad4) {
                    if (is_array($ad4) && ! empty($ad4['address'])) {
                        $addrs[] = axd_str($ad4['address']) . (isset($ad4['prefixLength']) ? '/' . axd_str($ad4['prefixLength']) : '');
                    }
                }
                $net['interfaces'][] = [
                    'name' => axd_str($dev['name'] ?? ''),
                    'mac' => axd_str($dev['macAddress'] ?? ''),
                    'mode' => axd_str($v4['configurationMode'] ?? ''),
                    'ipv4' => implode(', ', $addrs),
                    'gateway' => axd_str($v4['defaultRouter'] ?? ''),
                    'ipv6' => isset($dev['IPv6']['enabled']) ? axd_str($dev['IPv6']['enabled']) : '',
                ];
            }
            $axd['Network'] = $net;
        } else {
            [$pc, $pm] = axd_params($axd_ctx, 'root.Network');
            if ($pc === 200 && $pm) {
                $axd['Network'] = [
                    'hostname' => $pm['root.Network.HostName'] ?? '',
                    'dns' => trim(($pm['root.Network.Resolver.NameServer1'] ?? '') . ' ' . ($pm['root.Network.Resolver.NameServer2'] ?? '')),
                    'interfaces' => [[
                        'name' => 'eth0',
                        'mac' => $pm['root.Network.eth0.MACAddress'] ?? '',
                        'mode' => $pm['root.Network.BootProto'] ?? '',
                        'ipv4' => trim(($pm['root.Network.eth0.IPAddress'] ?? '') . ' ' . ($pm['root.Network.eth0.SubnetMask'] ?? '')),
                        'gateway' => $pm['root.Network.DefaultRouter'] ?? '',
                        'ipv6' => '',
                    ]],
                ];
            }
        }

        // --- Stream profiles -----------------------------------------------
        $sp = axd_json($axd_ctx, '/axis-cgi/streamprofile.cgi', 'list', ['streamProfileName' => []]);
        if (is_array($sp) && is_array($sp['streamProfile'] ?? null)) {
            $axd['StreamProfiles'] = [];
            foreach ($sp['streamProfile'] as $p) {
                if (is_array($p)) {
                    $axd['StreamProfiles'][] = [
                        'name' => axd_str($p['name'] ?? ''),
                        'description' => axd_str($p['description'] ?? ''),
                        'parameters' => mb_substr(axd_str($p['parameters'] ?? ''), 0, 300),
                    ];
                }
            }
        }

        // --- PTZ presets ---------------------------------------------------
        $axd_carry(['Caps']);
        if (($axd['Caps']['ptz'] ?? 'no') === 'yes') {
            $r = axd_request($axd_ctx, '/axis-cgi/com/ptz.cgi?query=presetposall');
            if ($r['code'] === 200) {
                $presets = [];
                foreach (axd_kv($r['body']) as $k => $v) {
                    if (preg_match('/^presetposno(\d+)$/', $k, $mm)) {
                        $presets[(int) $mm[1]] = $v;
                    }
                }
                ksort($presets);
                $axd['PtzPresets'] = $presets;
            }
        }
    }
    $axd_carry(['Caps', 'Apis', 'Firmware', 'Network', 'StreamProfiles', 'PtzPresets']);

    // --- PTZ position (fast, only on PTZ devices) -------------------------
    if (($axd['Caps']['ptz'] ?? 'no') === 'yes') {
        $r = axd_request($axd_ctx, '/axis-cgi/com/ptz.cgi?query=position');
        if ($r['code'] === 200) {
            $axd['PtzPosition'] = array_intersect_key(axd_kv($r['body']), array_flip(['pan', 'tilt', 'zoom', 'focus', 'iris', 'autofocus', 'autoiris']));
        }
    }

    // --- Update device table fields shown on the overview page ------------
    $dev_update = [];
    if (! empty($axd['SerialNumber'])) {
        $dev_update['serial'] = $axd['SerialNumber'];
    }
    if (! empty($axd['Version'])) {
        $dev_update['version'] = $axd['Version'];
    }
    if (! empty($axd['ProdShortName'])) {
        $dev_update['hardware'] = $axd['ProdShortName'];
    }
    if ($dev_update) {
        dbUpdate($dev_update, 'devices', 'device_id = ?', [$device['device_id']]);
    }

    $axd['CollectedAt'] = $axd_now;
}

// ---------------------------------------------------------------------------
// Status, metrics and save
// ---------------------------------------------------------------------------
$axd['VapixStatus'] = $axd_status;
$axd['VapixHttpCode'] = $axd_code;
$axd['CheckedAt'] = $axd_now;
$axd['Proto'] = $GLOBALS['axd_proto'] ?? $axd_ctx['proto'];
$axd['_ts'] = $axd_ts;

// Numeric metrics: graphable and usable in LibreNMS alert rules
$metrics = [
    'auth_ok' => $axd_status === 'ok' ? 1 : 0,
    'uptime' => (int) ($axd['Uptime'] ?? 0),
];
if ($axd_status === 'ok') {
    $metrics['streams'] = (int) ($axd['StreamCount'] ?? 0);
    $metrics['clients'] = (int) ($axd['ActiveClients'] ?? 0);
    if (isset($axd['CPUTemp'])) {
        $metrics['temp_cpu'] = (float) $axd['CPUTemp'];
    }
    foreach (($axd['Disks'] ?? []) as $dk) {
        if (($dk['id'] ?? '') === 'SD_DISK' && strtolower($dk['status'] ?? '') !== 'disconnected') {
            $sd_good = in_array(strtolower($dk['status'] ?? ''), ['ok', 'connected'], true)
                && ($dk['readonly'] ?? '') !== 'yes' && ($dk['full'] ?? '') !== 'yes';
            $metrics['sd_ok'] = $sd_good ? 1 : 0;
            $metrics['sd_used_pct'] = (float) ($dk['used_pct'] ?? 0);
        }
    }
    if (isset($axd['Acaps'])) {
        $st = array_map(fn ($a) => strtolower($a['status'] ?? ''), $axd['Acaps']);
        $lic = array_map(fn ($a) => strtolower($a['license'] ?? ''), $axd['Acaps']);
        $metrics['acaps_total'] = count($axd['Acaps']);
        $metrics['acaps_running'] = count(array_keys($st, 'running'));
        $metrics['acaps_stopped'] = count(array_keys($st, 'stopped'));
        $metrics['acaps_license_bad'] = count(array_filter($lic, fn ($l) => in_array($l, ['invalid', 'expired', 'missing'], true)));
    }
    if (isset($axd['Time']['offset_s']) && $axd['Time']['offset_s'] !== null) {
        $metrics['time_offset_s'] = abs((int) $axd['Time']['offset_s']);
    }
    if (isset($axd['Mqtt'])) {
        $metrics['mqtt_connected'] = strtolower($axd['Mqtt']['connection']) === 'connected' ? 1 : 0;
    }
    if (isset($axd['Syslog']['errors_24h'])) {
        $metrics['syslog_errors_24h'] = (int) $axd['Syslog']['errors_24h'];
    }
    if (isset($axd['Cert']['days_left']) && $axd['Cert']['days_left'] !== null) {
        $metrics['cert_days_left'] = (int) $axd['Cert']['days_left'];
    }
}

// ---------------------------------------------------------------------------
// History graphs (RRD): rrd/<host>/app-axis-device-<app_id>.rrd
// ---------------------------------------------------------------------------
if ($axd_status === 'ok') {
    try {
        $rrd_def = RrdDefinition::make()
            ->addDataset('temp_cpu', 'GAUGE', -60, 150)
            ->addDataset('streams', 'GAUGE', 0)
            ->addDataset('clients', 'GAUGE', 0)
            ->addDataset('sd_used', 'GAUGE', 0, 100)
            ->addDataset('clock_off', 'GAUGE', -100000000, 100000000)
            ->addDataset('acaps_run', 'GAUGE', 0)
            ->addDataset('acaps_total', 'GAUGE', 0);
        $rrd_fields = [
            'temp_cpu' => $metrics['temp_cpu'] ?? null,
            'streams' => $metrics['streams'] ?? null,
            'clients' => $metrics['clients'] ?? null,
            'sd_used' => $metrics['sd_used_pct'] ?? null,
            'clock_off' => isset($axd['Time']['offset_s']) ? (int) $axd['Time']['offset_s'] : null,
            'acaps_run' => $metrics['acaps_running'] ?? null,
            'acaps_total' => $metrics['acaps_total'] ?? null,
        ];
        $rrd_tags = ['name' => $name, 'app_id' => $app_id, 'rrd_name' => ['app', $name, $app_id], 'rrd_def' => $rrd_def];
        try {
            app('Datastore')->put($device, 'app', $rrd_tags, $rrd_fields);
        } catch (\Throwable $e) {
            if (function_exists('data_update')) {
                data_update($device, 'app', $rrd_tags, $rrd_fields);
            } else {
                throw $e;
            }
        }
    } catch (\Throwable $e) {
        echo '  RRD update failed: ' . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// Change events -> LibreNMS eventlog (device Logs tab)
// ---------------------------------------------------------------------------
$axd_events = [];
$axd_ev = function (string $msg, string $sev = 'info') use (&$axd_events): void {
    $axd_events[] = [$msg, $sev];
};

if ($axd_prev) {
    // VAPIX access
    $pst = $axd_prev['VapixStatus'] ?? null;
    if ($pst !== null && $pst !== $axd_status) {
        $axd_status === 'ok'
            ? $axd_ev("VAPIX access restored (was {$pst})", 'ok')
            : $axd_ev("VAPIX access lost: {$axd_status} (HTTP {$axd_code})", 'error');
    }

    if ($axd_status === 'ok') {
        // AXIS OS
        $pv = (string) ($axd_prev['Version'] ?? '');
        $cv = (string) ($axd['Version'] ?? '');
        if ($pv !== '' && $cv !== '' && $pv !== $cv) {
            $axd_ev("AXIS OS changed {$pv} -> {$cv}", 'notice');
        }
        if (($axd['Firmware']['committed'] ?? null) === false && ($axd_prev['Firmware']['committed'] ?? null) !== false) {
            $axd_ev('AXIS OS upgrade not committed (device may roll back on reboot)', 'warning');
        }

        // ACAPs and SD card are compared against a settled baseline, not just the previous poll.
        // While a device is booting (e.g. after an AXIS OS upgrade) the app list can come back
        // empty and the SD card can briefly show as disconnected. Those polls are skipped, so an
        // ACAP that disappears and returns with a new version is reported once as "updated".
        $base = is_array($axd_prev['_base'] ?? null) ? $axd_prev['_base'] : [];
        $booting = (isset($axd['Uptime']) && (int) $axd['Uptime'] < 600)
            || (isset($axd['SystemReady']) && $axd['SystemReady'] !== 'yes');

        // ACAPs
        if (isset($axd['Acaps'])) {
            $base_list = $base['Acaps'] ?? ($axd_prev['Acaps'] ?? null);
            $empty_polls = empty($axd['Acaps']) && ! empty($base_list) ? (int) ($base['acaps_empty'] ?? 0) + 1 : 0;
            $transient = $booting || ($empty_polls > 0 && $empty_polls < 3);

            if (is_array($base_list) && ! $transient) {
                $prev_apps = [];
                foreach ($base_list as $a) {
                    $prev_apps[$a['name'] ?? ''] = $a;
                }
                $cur_apps = [];
                foreach ($axd['Acaps'] as $a) {
                    $cur_apps[$a['name'] ?? ''] = $a;
                }
                foreach ($cur_apps as $n => $a) {
                    $label = $a['nice'] ?? $n;
                    if (! isset($prev_apps[$n])) {
                        $axd_ev("ACAP installed: {$label} {$a['version']}", 'notice');
                        continue;
                    }
                    $pa = $prev_apps[$n];
                    if (($pa['version'] ?? '') !== ($a['version'] ?? '')) {
                        $dir = version_compare((string) $a['version'], (string) $pa['version'], '>=') ? 'updated' : 'downgraded';
                        $axd_ev("ACAP {$label} {$dir} {$pa['version']} -> {$a['version']}", 'notice');
                    }
                    $ps = $pa['status'] ?? '';
                    if ($ps !== ($a['status'] ?? '')) {
                        $sev = strtolower($a['status']) === 'running' ? 'ok' : (strtolower($a['status']) === 'stopped' ? 'warning' : 'info');
                        $axd_ev("ACAP {$label}: {$ps} -> {$a['status']}", $sev);
                    }
                    if (strtolower($pa['license'] ?? '') !== strtolower($a['license'] ?? '')) {
                        $bad = in_array(strtolower($a['license'] ?? ''), ['invalid', 'expired', 'missing'], true);
                        $axd_ev("ACAP {$label} license: {$pa['license']} -> {$a['license']}", $bad ? 'warning' : 'info');
                    }
                }
                foreach ($prev_apps as $n => $a) {
                    if (! isset($cur_apps[$n])) {
                        $axd_ev('ACAP removed: ' . ($a['nice'] ?? $n) . ' ' . ($a['version'] ?? ''), 'notice');
                    }
                }
            }
            if ($transient && is_array($base_list)) {
                $base['Acaps'] = $base_list;     // keep the pre-boot baseline
                $base['acaps_empty'] = $empty_polls;
            } else {
                $base['Acaps'] = $axd['Acaps'];
                $base['acaps_empty'] = 0;
            }
        }

        // SD card
        if (isset($axd['Disks'])) {
            $find_sd = function (?array $disks): ?array {
                foreach ($disks ?? [] as $dk) {
                    if (($dk['id'] ?? '') === 'SD_DISK') {
                        return $dk;
                    }
                }

                return null;
            };
            $base_disks = $base['Disks'] ?? ($axd_prev['Disks'] ?? null);
            if (is_array($base_disks) && ! $booting) {
                $psd = $find_sd($base_disks);
                $csd = $find_sd($axd['Disks']);
                $pst_sd = strtolower($psd['status'] ?? 'disconnected');
                $cst_sd = strtolower($csd['status'] ?? 'disconnected');
                if ($pst_sd !== $cst_sd) {
                    if ($cst_sd === 'disconnected') {
                        $axd_ev('SD card removed or disconnected', 'warning');
                    } elseif ($pst_sd === 'disconnected') {
                        $axd_ev('SD card inserted (' . ($csd['status'] ?? '') . ')', 'ok');
                    } else {
                        $axd_ev("SD card status {$psd['status']} -> {$csd['status']}", in_array($cst_sd, ['ok', 'connected'], true) ? 'ok' : 'error');
                    }
                }
                if ($csd && ($csd['readonly'] ?? '') === 'yes' && ($psd['readonly'] ?? '') !== 'yes') {
                    $axd_ev('SD card became read-only', 'error');
                }
                if ($csd && ($csd['full'] ?? '') === 'yes' && ($psd['full'] ?? '') !== 'yes') {
                    $axd_ev('SD card full', 'warning');
                }
            }
            $base['Disks'] = ($booting && is_array($base_disks)) ? $base_disks : $axd['Disks'];
        }
        $axd['_base'] = $base;

        // Users (only when freshly collected this poll)
        if (($axd_ts['hourly'] ?? 0) === $axd_now && isset($axd['Users'], $axd_prev['Users'])) {
            foreach ($axd['Users'] as $u => $role) {
                if (! isset($axd_prev['Users'][$u])) {
                    $axd_ev("User account added: {$u} ({$role})", 'warning');
                } elseif ($axd_prev['Users'][$u] !== $role) {
                    $axd_ev("User {$u} role changed {$axd_prev['Users'][$u]} -> {$role}", 'notice');
                }
            }
            foreach ($axd_prev['Users'] as $u => $role) {
                if (! isset($axd['Users'][$u])) {
                    $axd_ev("User account removed: {$u}", 'notice');
                }
            }
        }

        // MQTT
        if (isset($axd['Mqtt'], $axd_prev['Mqtt'])) {
            $pc = strtolower($axd_prev['Mqtt']['connection'] ?? '');
            $cc = strtolower($axd['Mqtt']['connection'] ?? '');
            if ($pc !== $cc && $cc !== '') {
                $axd_ev("MQTT {$pc} -> {$cc} (" . ($axd['Mqtt']['host'] ?? '') . ')', $cc === 'connected' ? 'ok' : 'warning');
            }
        }

        // Clock drift
        if (isset($axd['Time']['offset_s'], $axd_prev['Time']['offset_s'])) {
            $po = abs((int) $axd_prev['Time']['offset_s']);
            $co = abs((int) $axd['Time']['offset_s']);
            if ($co > 30 && $po <= 30) {
                $axd_ev("Clock drift detected: {$axd['Time']['offset_s']}s vs LibreNMS", 'warning');
            } elseif ($co <= 2 && $po > 30) {
                $axd_ev('Clock back in sync', 'ok');
            }
        }

        // Certificate renewed / replaced
        $pvt = $axd_prev['Cert']['valid_to'] ?? '';
        $cvt = $axd['Cert']['valid_to'] ?? '';
        if ($pvt !== '' && $cvt !== '' && $pvt !== $cvt) {
            $axd_ev("HTTPS certificate changed (now valid to {$cvt})", 'notice');
        }

        // Network address / mode (only when freshly collected)
        if (($axd_ts['slow'] ?? 0) === $axd_now && isset($axd['Network'], $axd_prev['Network'])) {
            $sig = fn ($n) => implode('; ', array_map(fn ($i) => ($i['name'] ?? '') . ' ' . ($i['ipv4'] ?? '') . ' ' . ($i['mode'] ?? ''), $n['interfaces'] ?? []));
            if ($sig($axd['Network']) !== $sig($axd_prev['Network'])) {
                $axd_ev('Network settings changed: ' . $sig($axd['Network']), 'notice');
            }
        }
    }
}

foreach ($axd_events as [$msg, $sev]) {
    $text = 'Axis Device: ' . $msg;
    echo "  Event: {$text}\n";
    try {
        $severity = match ($sev) {
            'ok' => \LibreNMS\Enum\Severity::Ok,
            'notice' => \LibreNMS\Enum\Severity::Notice,
            'warning' => \LibreNMS\Enum\Severity::Warning,
            'error' => \LibreNMS\Enum\Severity::Error,
            default => \LibreNMS\Enum\Severity::Info,
        };
        \App\Models\Eventlog::log($text, (int) $device['device_id'], 'application', $severity);
    } catch (\Throwable $e) {
        if (function_exists('log_event')) {
            log_event($text, $device, 'application', ['ok' => 1, 'info' => 2, 'notice' => 3, 'warning' => 4, 'error' => 5][$sev] ?? 2);
        }
    }
}

$axd_json_data = json_encode($axd, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

if (is_object($app)) {
    $app->data = $axd;
}
if (function_exists('update_application')) {
    update_application($app, $axd_status === 'ok' ? 'OK' : 'ERROR', $metrics, $axd_status === 'ok' ? '' : $axd_status);
}
dbUpdate(['data' => $axd_json_data], 'applications', '`app_id` = ?', [$app_id]);

// ---------------------------------------------------------------------------
// Console summary
// ---------------------------------------------------------------------------
echo "Successfully collected VAPIX data:\n";
echo "  VAPIX status: {$axd_status} (HTTP {$axd_code})\n";
if ($axd_status === 'ok') {
    echo '  Model: ' . ($axd['ProdShortName'] ?? 'N/A') . '  Serial: ' . ($axd['SerialNumber'] ?? 'N/A') . '  FW: ' . ($axd['Version'] ?? 'N/A') . "\n";
    echo '  Streams: ' . ($axd['StreamCount'] ?? 0) . ' (' . ($axd['VideoStreams'] ?? 0) . ' video, ' . ($axd['AudioStreams'] ?? 0) . ' audio), clients: ' . ($axd['ActiveClients'] ?? 0) . "\n";
    if (isset($metrics['sd_used_pct'])) {
        echo '  SD card: ' . ($metrics['sd_ok'] ? 'OK' : 'PROBLEM') . ", {$metrics['sd_used_pct']}% used\n";
    }
    if (isset($metrics['acaps_total'])) {
        echo "  ACAPs: {$metrics['acaps_running']}/{$metrics['acaps_total']} running\n";
    }
    if (isset($metrics['time_offset_s'])) {
        echo "  Clock offset: {$axd['Time']['offset_s']}s\n";
    }
    if (isset($axd['CPUTemp'])) {
        echo "  CPU Temperature: {$axd['CPUTemp']}°C\n";
    }
    if (isset($axd['Uptime'])) {
        echo '  Uptime: ' . floor($axd['Uptime'] / 86400) . 'd ' . floor(($axd['Uptime'] % 86400) / 3600) . "h\n";
    }
    echo '  Snapshot: ' . (isset($axd['Snapshot']['at']) && $axd['Snapshot']['at'] === $axd_now ? 'captured' : 'not captured') . "\n";
}
