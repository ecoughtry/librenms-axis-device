# Changelog

## 1.0.2 - 2026-10-14
- AXIS OS version on the System tab and badges now comes from live data (every poll)
- AXIS OS status (rollback, committed) refreshes immediately after an upgrade
- Removed the "Active part" row
- A stopped ACAP is only flagged when it was running and stopped recently (default 24 h,
  setting `acap_stop_window_hours`); ACAPs that are intentionally left stopped stay neutral
- ACAP table shows when an app stopped or last ran
- New metric `acaps_recently_stopped` for alert rules
- "ACAP stopped" change events are logged as notices instead of warnings

## 1.0.1 - 2026-10-14
- Fix `install.sh status` summary query on MariaDB

## 1.0.0 - 2026-10-14
Initial release.
- Tabbed device page: Overview, Video, Storage & ACAPs, System, History, APIs
- Fleet overview page under Apps
- RRD history graphs, eventlog change events, alert metrics
- Snapshot with click-to-enlarge, device notes and open alerts on the page
- AXIS OS download links to ftp.axis.com (MPQT / PACS) and LTS track labels
- ACAP and SD card changes compared against a settled baseline, so reboots and
  AXIS OS upgrades log one "updated X -> Y" event instead of removed + installed
- Digest auth support, per-tier collection schedule, graceful handling of unsupported APIs
- Installer: install / enable / status / uninstall, multi-node deploy over SSH
