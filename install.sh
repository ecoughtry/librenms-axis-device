#!/usr/bin/env bash
#
# Axis Device app for LibreNMS: installer
#
#   sudo ./install.sh install [--nodes "host1 host2"]   install/update files (this node, plus pollers/web nodes)
#   sudo ./install.sh enable [--dry-run] [--where SQL]   add the app to Axis devices (run once, on any node)
#   sudo ./install.sh credentials                       set the VAPIX username/password (run once, on any node)
#   sudo ./install.sh config [key] [value|--unset]      show or change axis_device.* settings
#   sudo ./install.sh status                            show installed version and device counts
#   sudo ./install.sh uninstall [--purge] [--nodes "..."]
#
# Environment:
#   LIBRENMS_DIR   LibreNMS install directory (default /opt/librenms)
#   SSH_USER       user for --nodes (default: current sudo user)
#
set -euo pipefail

APP=axis-device
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$REPO_DIR/src"
LNMS="${LIBRENMS_DIR:-/opt/librenms}"
SSH_USER="${SSH_USER:-${SUDO_USER:-$(id -un)}}"
VERSION="$(sed -n "s/.*return '\([0-9.]*\)';.*/\1/p" "$SRC/includes/html/axis-device-common.inc.php" | head -1)"

FILES=(
  includes/polling/applications/axis-device.inc.php
  includes/html/axis-device-common.inc.php
  includes/html/pages/device/apps/axis-device.inc.php
  includes/html/pages/apps/axis-device.inc.php
  includes/html/graphs/application/axis-device_temp.inc.php
  includes/html/graphs/application/axis-device_clients.inc.php
  includes/html/graphs/application/axis-device_sd.inc.php
  includes/html/graphs/application/axis-device_clock.inc.php
  includes/html/graphs/application/axis-device_acaps.inc.php
)

c_bold=$'\e[1m'; c_red=$'\e[31m'; c_grn=$'\e[32m'; c_yel=$'\e[33m'; c_off=$'\e[0m'
say()  { echo "${c_bold}==>${c_off} $*"; }
ok()   { echo "    ${c_grn}✓${c_off} $*"; }
warn() { echo "    ${c_yel}!${c_off} $*"; }
die()  { echo "${c_red}ERROR:${c_off} $*" >&2; exit 1; }

need_root() { [ "$(id -u)" -eq 0 ] || die "run with sudo"; }
need_lnms() { [ -f "$LNMS/lnms" ] || die "LibreNMS not found in $LNMS (set LIBRENMS_DIR)"; }
owner()     { stat -c %U "$LNMS"; }
as_lnms()   { sudo -u "$(owner)" "$@"; }

# --- DB access using LibreNMS .env (password never on the command line) ----
DB_CNF=""
db_init() {
  [ -n "$DB_CNF" ] && return
  local env="$LNMS/.env"; [ -f "$env" ] || die "$env not found"
  get() { grep -E "^$1=" "$env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
  DB_CNF="$(mktemp)"; chmod 600 "$DB_CNF"
  trap 'rm -f "$DB_CNF"' EXIT
  {
    echo "[client]"
    echo "host=$(get DB_HOST || true)"
    p="$(get DB_PORT || true)"; [ -n "$p" ] && echo "port=$p"
    s="$(get DB_SOCKET || true)"; [ -n "$s" ] && echo "socket=$s"
    echo "user=$(get DB_USERNAME)"
    echo "password=$(get DB_PASSWORD)"
  } > "$DB_CNF"
  DB_NAME="$(get DB_DATABASE)"
}
sql() { db_init; mysql --defaults-extra-file="$DB_CNF" -N -B "$DB_NAME" -e "$1"; }

restart_fpm() {
  for s in php8.5-fpm php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    systemctl is-active --quiet "$s" 2>/dev/null && systemctl restart "$s" && ok "restarted $s"
  done
  return 0
}

# --- run this script on other nodes -------------------------------------------
push_nodes() { # $1 = subcommand, $2 = space-separated hosts
  local cmd="$1" hosts="$2" tgz
  [ -z "$hosts" ] && return
  tgz="$(mktemp --suffix=.tgz)"
  tar -C "$REPO_DIR" -czf "$tgz" install.sh src
  for h in $hosts; do
    say "Node $h"
    scp -q "$tgz" "$SSH_USER@$h:/tmp/axis-device-$VERSION.tgz"
    ssh -t "$SSH_USER@$h" "rm -rf /tmp/axis-device && mkdir -p /tmp/axis-device && tar -xzf /tmp/axis-device-$VERSION.tgz -C /tmp/axis-device && rm -f /tmp/axis-device-$VERSION.tgz && sudo LIBRENMS_DIR='$LNMS' bash /tmp/axis-device/install.sh $cmd && rm -rf /tmp/axis-device"
  done
  rm -f "$tgz"
}

# --- commands ---------------------------------------------------------------
cmd_install() {
  need_root; need_lnms
  say "Installing Axis Device app v$VERSION into $LNMS ($(hostname))"
  for f in "${FILES[@]}"; do
    [ -f "$SRC/$f" ] || die "missing $SRC/$f"
    php -l "$SRC/$f" >/dev/null || die "syntax error in $f"
  done
  local backup=""
  local existing=(); for f in "${FILES[@]}"; do [ -f "$LNMS/$f" ] && existing+=("$f"); done
  if [ ${#existing[@]} -gt 0 ]; then
    backup="$LNMS/axis-device-backup-$(date +%Y%m%d-%H%M%S).tgz"
    tar -C "$LNMS" -czf "$backup" "${existing[@]}"
    ok "backup: $backup"
  fi
  local own; own="$(owner)"
  for f in "${FILES[@]}"; do
    install -D -o "$own" -g "$own" -m 644 "$SRC/$f" "$LNMS/$f"
  done
  ok "installed ${#FILES[@]} files (owner $own)"

  if ! grep -q 'pages/apps/' "$LNMS/includes/html/pages/apps.inc.php" 2>/dev/null; then
    warn "this LibreNMS version may not load per-app overview pages; the fleet page (/apps/app=$APP/) might not appear"
  fi
  restart_fpm
  ok "done. Backups can be restored with: sudo tar -xzf <backup> -C $LNMS"
  push_nodes install "${NODES:-}"
}

cmd_enable() {
  need_root; need_lnms
  # Axis OS detection, plus hardware/sysDescr for Axis devices LibreNMS sees as "generic".
  # Excludes server management controllers (Axis S-series recorders report as AXIS via iDRAC).
  local where="${WHERE:-(d.os LIKE 'axis%' OR d.hardware LIKE 'AXIS%' OR d.sysDescr LIKE '%AXIS%') AND d.os NOT IN ('drac', 'windows', 'linux', 'ipmi')}"
  say "Axis devices without the $APP app (filter: $where)"
  local q="FROM devices d WHERE ($where) AND NOT EXISTS (SELECT 1 FROM applications a WHERE a.device_id = d.device_id AND a.app_type = '$APP')"
  sql "SELECT d.device_id, d.hostname, COALESCE(d.sysName,''), d.hardware $q ORDER BY d.hostname" | column -t -s $'\t' || true
  local n; n="$(sql "SELECT COUNT(*) $q")"
  if [ "${DRY_RUN:-0}" = 1 ]; then ok "dry run: $n device(s) would be enabled"; return; fi
  [ "$n" = 0 ] && { ok "nothing to do"; return; }
  sql "INSERT INTO applications (device_id, app_type, app_state, discovered, app_status, app_instance) SELECT d.device_id, '$APP', 'UNKNOWN', 1, '', '' $q"
  ok "enabled on $n device(s); data appears after the next poll"
}

# --- settings (axis_device.*) live in LibreNMS's shared config table, so every node sees them ---
sql_str() { printf "%s" "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g"; }

set_setting() { # $1 = key without prefix, $2 = value (JSON literals stored as-is, anything else as a string)
  local key="axis_device.$1" val="$2" json
  if [[ "$val" =~ ^(true|false|null|-?[0-9]+(\.[0-9]+)?)$ ]] || [[ "$val" =~ ^[\{\[] ]]; then
    json="'$(sql_str "$val")'"
  else
    json="JSON_QUOTE('$(sql_str "$val")')"
  fi
  sql "INSERT INTO config (config_name, config_value) VALUES ('$(sql_str "$key")', $json) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
}

clear_config_cache() {
  as_lnms php "$LNMS/artisan" config:clear >/dev/null 2>&1 || true
  ok "settings saved; pollers pick them up on their next poll"
}

cmd_credentials() {
  need_root; need_lnms
  say "VAPIX credentials (stored in the shared LibreNMS database)"
  local cur user pass
  cur="$(sql "SELECT JSON_UNQUOTE(config_value) FROM config WHERE config_name = 'axis_device.username'" || true)"
  read -rp "    Username${cur:+ [$cur]}: " user; user="${user:-$cur}"
  [ -n "$user" ] || die "username is required"
  read -rsp "    Password: " pass; echo
  [ -n "$pass" ] || die "password is required"
  set_setting username "$user"
  set_setting password "$pass"
  clear_config_cache
}

cmd_config() {
  need_root; need_lnms
  local key="${ARGS[0]:-}" val="${ARGS[1]:-}"
  if [ -z "$key" ]; then
    say "axis_device settings"
    sql "SELECT CONCAT('    ', config_name, ' = ', IF(config_name LIKE '%password%', '********', config_value)) FROM config WHERE config_name LIKE 'axis\\_device.%' ORDER BY config_name" || true
    return
  fi
  key="${key#axis_device.}"
  if [ "$val" = "--unset" ] || [ "${UNSET:-0}" = 1 ]; then
    sql "DELETE FROM config WHERE config_name = 'axis_device.$(sql_str "$key")'"
    ok "removed axis_device.$key"
  elif [ -z "$val" ]; then
    sql "SELECT IF(config_name LIKE '%password%', '********', config_value) FROM config WHERE config_name = 'axis_device.$(sql_str "$key")'" || true
    return
  else
    set_setting "$key" "$val"
    ok "axis_device.$key set"
  fi
  clear_config_cache
}

cmd_status() {
  need_lnms
  say "Axis Device app on $(hostname)"
  if [ -f "$LNMS/includes/html/axis-device-common.inc.php" ]; then
    ok "installed: v$(sed -n "s/.*return '\([0-9.]*\)';.*/\1/p" "$LNMS/includes/html/axis-device-common.inc.php" | head -1) (repo: v$VERSION)"
  else
    warn "not installed (repo: v$VERSION)"
  fi
  local missing=0; for f in "${FILES[@]}"; do [ -f "$LNMS/$f" ] || { warn "missing $f"; missing=1; }; done
  [ $missing = 0 ] && ok "all ${#FILES[@]} files present"
  if [ "$(id -u)" -eq 0 ] && [ -r "$LNMS/.env" ]; then
    sql "SELECT CONCAT('    ', s, ': ', n) FROM (SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data,'$.VapixStatus')),'no data') AS s, COUNT(*) AS n FROM applications WHERE app_type = '$APP' GROUP BY s) t" || true
  fi
}

cmd_uninstall() {
  need_root; need_lnms
  say "Uninstalling Axis Device app from $(hostname)"
  for f in "${FILES[@]}"; do rm -f "$LNMS/$f"; done
  ok "files removed"
  if [ "${PURGE:-0}" = 1 ]; then
    sql "DELETE FROM applications WHERE app_type = '$APP'"
    ok "application rows removed (RRD files kept in the rrd directory)"
  fi
  restart_fpm
  push_nodes uninstall "${NODES:-}"
}

# --- args ------------------------------------------------------------------
CMD="${1:-}"; shift || true
NODES=""; DRY_RUN=0; PURGE=0; WHERE=""; UNSET=0; ARGS=()
while [ $# -gt 0 ]; do
  case "$1" in
    --nodes) NODES="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --purge) PURGE=1; shift ;;
    --where) WHERE="$2"; shift 2 ;;
    --unset) UNSET=1; shift ;;
    --*) die "unknown option $1" ;;
    *) ARGS+=("$1"); shift ;;
  esac
done

case "$CMD" in
  install) cmd_install ;;
  enable) cmd_enable ;;
  credentials) cmd_credentials ;;
  config) cmd_config ;;
  status) cmd_status ;;
  uninstall) cmd_uninstall ;;
  *) sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
