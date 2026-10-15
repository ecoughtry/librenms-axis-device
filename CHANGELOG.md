# Changelog

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
