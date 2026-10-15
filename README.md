# Axis Device app for LibreNMS

A LibreNMS application that polls Axis devices over **VAPIX** and shows their health on a single page per device, plus a fleet overview across all of them.

Works with Axis cameras, intercoms, speakers, audio, sensors and access control devices running AXIS OS.

## What you get

**Device page** (Device → Apps → Axis Device), in tabs:

| Tab | Contents |
|---|---|
| Overview | Health badges, live snapshot (click to enlarge), model/serial/AXIS OS, open LibreNMS alerts, device notes, recent changes |
| Video | Active streams, connected clients (resolved to LibreNMS device names), stream profiles, PTZ position/presets, IR/illumination |
| Storage & ACAPs | SD card / network share status and usage, ACAP apps with run state and license |
| System | AXIS OS (active, rollback, committed, fleet versions, ftp.axis.com downloads), time/NTP, HTTPS cert, network, temperatures/heaters/fans, MQTT, I/O ports, system log errors, user accounts |
| History | RRD graphs: streams/clients, CPU temperature, SD usage, ACAPs running, clock offset |
| APIs | VAPIX APIs the device reports via API discovery |

**Fleet overview** (Apps → Axis Device): every Axis device in one table, problems first, with search, site filter, sortable columns, summary tiles and an AXIS OS-by-model table with download links.

**Change events** written to the LibreNMS eventlog (device Logs tab): AXIS OS changes, ACAP installed/removed/started/stopped/updated/license, SD card inserted/removed/read-only/full, user accounts added/removed/role changed, MQTT connect/disconnect, clock drift, certificate replaced, network changes, VAPIX access lost/restored.

**Metrics** in `application_metrics` for LibreNMS alert rules: `auth_ok`, `sd_ok`, `sd_used_pct`, `acaps_running`, `acaps_stopped`, `acaps_license_bad`, `time_offset_s`, `mqtt_connected`, `temp_cpu`, `syslog_errors_24h`, `cert_days_left`, `streams`, `clients`, `uptime`.

## Requirements

- LibreNMS with PHP 8.1+ (tested on 26.x, PHP 8.5) and the PHP `curl` and `openssl` extensions
- A VAPIX account on each device (Administrator recommended; digest or basic auth)
- Pollers must reach devices on HTTPS (443), falling back to HTTP (80)

## Install

```bash
git clone <this-repo-url> librenms-axis-device
cd librenms-axis-device
sudo ./install.sh install
```

Set the VAPIX credentials once (stored in the shared LibreNMS database):

```bash
cd /opt/librenms
sudo -u librenms ./lnms config:set axis_device.username 'vapix-user'
sudo -u librenms ./lnms config:set axis_device.password 'your-password'
```

Add the app to your Axis devices (preview first):

```bash
sudo ./install.sh enable --dry-run
sudo ./install.sh enable
```

The default filter matches devices whose OS, hardware or sysDescr mentions Axis. Use your own with `--where`, e.g. `--where "d.hostname LIKE '192.0.2.%'"`.

Run a first poll with every collection tier forced:

```bash
cd /opt/librenms
sudo -u librenms env AXD_FORCE=1 ./lnms device:poll <device_id> -m applications -vv
```

### Distributed LibreNMS (multiple pollers / web nodes)

The files must exist on **every** node that polls Axis devices or serves the web UI. From one node:

```bash
sudo ./install.sh install --nodes "poller2.example.com poller3.example.com"
```

This installs locally, then copies the package to each node over SSH and runs the installer there (you may be asked for SSH and sudo passwords). `SSH_USER` overrides the SSH user.

Database steps (`enable`, `uninstall --purge`) only need to run once, because the database is shared.

## Update

```bash
cd librenms-axis-device
git pull
sudo ./install.sh install --nodes "poller2.example.com poller3.example.com"
```

Each install backs up the files it replaces to `/opt/librenms/axis-device-backup-<timestamp>.tgz`. Roll back with `sudo tar -xzf <backup> -C /opt/librenms`.


## Settings

| Setting | Default | Purpose |
|---|---|---|
| `axis_device.username` / `axis_device.password` | — | VAPIX account |
| `axis_device.snapshot` | `true` | Store a JPEG thumbnail each poll (in the database) |
| `axis_device.snapshot_resolution` | `480x270` | Thumbnail size |
| `axis_device.fw_folder_overrides` | `{}` | ftp.axis.com folder per model, e.g. `{"D2110-VE":"MPQT"}` |

AXIS OS download links assume models starting with A, C or I live under `pub_soft/PACS/` and everything else under `pub_soft/MPQT/`.

## Collection schedule

| Every poll | Hourly | Every 6 hours |
|---|---|---|
| Device info, streams, temperatures, uptime, storage, ACAPs, time/NTP, MQTT, I/O, lights, PTZ position, snapshot | Users, system log, HTTPS certificate | API discovery, AXIS OS status, network settings, stream profiles, capabilities, PTZ presets |

Each endpoint is optional: anything a device or AXIS OS version doesn't support is simply left off its page.

## Troubleshooting

| Symptom | Check |
|---|---|
| Red "Authentication failed" banner | The VAPIX user is missing or has a different password on that device |
| Page empty after install | Run the forced poll above and read the output |
| No graphs | Graphs start from the first poll after install; look for `RRD update failed` in poll output |
| Fleet page missing under Apps | Your LibreNMS version may not load `includes/html/pages/apps/<app>.inc.php`; the installer warns about this |
| A node shows old behaviour | `sudo ./install.sh status` on each node and compare versions |

## Files installed

```
includes/polling/applications/axis-device.inc.php        poller
includes/html/axis-device-common.inc.php                 shared helpers
includes/html/pages/device/apps/axis-device.inc.php      device page
includes/html/pages/apps/axis-device.inc.php             fleet overview
includes/html/graphs/application/axis-device_*.inc.php   graph definitions
```

These are untracked files inside the LibreNMS tree, so LibreNMS's own `git pull` updates leave them alone. A major LibreNMS change to how applications work could still require an update here.

## Uninstall

```bash
sudo ./install.sh uninstall --nodes "..."          # remove files
sudo ./install.sh uninstall --purge --nodes "..."  # also remove the app from all devices
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
