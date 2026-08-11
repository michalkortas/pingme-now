#!/usr/bin/env bash
set -u

if [[ -z "${PINGME_URL:-}" || -z "${PINGME_TOKEN:-}" ]]; then
    echo "PINGME_URL and PINGME_TOKEN are required" >&2
    exit 2
fi

mode="${1:-scan}"
name="${2:-backup}"
backup_path="${3:-.}"

uuid() {
    if command -v uuidgen >/dev/null 2>&1; then uuidgen | tr '[:upper:]' '[:lower:]'; else cat /proc/sys/kernel/random/uuid; fi
}

post() {
    local payload="$1"
    curl --silent --show-error --fail --config - \
        --header 'Content-Type: application/json' \
        --data "$payload" \
        "${PINGME_URL%/}/api/v1/ingest/backups" <<EOF
header = "Authorization: Bearer ${PINGME_TOKEN}"
EOF
}

scan_payload() {
    if [[ ! -d "$backup_path" ]]; then
        echo "Backup directory does not exist: $backup_path" >&2
        return 2
    fi

    local count total oldest newest newest_size oldest_iso newest_iso safe_name
    count=$(find "$backup_path" -type f -printf '.' | wc -c | tr -d ' ')
    total=$(find "$backup_path" -type f -printf '%s\n' | awk '{sum += $1} END {print sum + 0}')
    oldest=$(find "$backup_path" -type f -printf '%T@\n' | sort -n | head -1 | cut -d. -f1)
    newest=$(find "$backup_path" -type f -printf '%T@ %s\n' | sort -nr | head -1)
    newest_size=$(awk '{print $2 + 0}' <<<"$newest")
    newest=$(awk '{print int($1)}' <<<"$newest")
    oldest_iso=""; newest_iso=""
    [[ -n "$oldest" ]] && oldest_iso=$(date -u -d "@$oldest" '+%Y-%m-%dT%H:%M:%SZ')
    [[ -n "$newest" ]] && newest_iso=$(date -u -d "@$newest" '+%Y-%m-%dT%H:%M:%SZ')
    safe_name=${name//\"/\\\"}

    printf '{"event_uuid":"%s","name":"%s","state":"scan","files_count":%s,"total_size_bytes":%s,"newest_size_bytes":%s,"oldest_backup_at":%s,"newest_backup_at":%s,"recorded_at":"%s"}' \
        "$(uuid)" "$safe_name" "$count" "$total" "$newest_size" \
        "$([[ -n "$oldest_iso" ]] && printf '"%s"' "$oldest_iso" || printf 'null')" \
        "$([[ -n "$newest_iso" ]] && printf '"%s"' "$newest_iso" || printf 'null')" \
        "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
}

if [[ "$mode" == "scan" ]]; then
    post "$(scan_payload)"
    exit $?
fi

if [[ "$mode" != "run" || "${4:-}" != "--" ]]; then
    echo "Usage: $0 scan NAME PATH | $0 run NAME PATH -- COMMAND..." >&2
    exit 2
fi

shift 4
started=$(date +%s)
post "{\"event_uuid\":\"$(uuid)\",\"name\":\"${name//\"/\\\"}\",\"state\":\"started\",\"recorded_at\":\"$(date -u '+%Y-%m-%dT%H:%M:%SZ')\"}"
"$@"
exit_code=$?
duration=$(($(date +%s) - started))

if [[ $exit_code -eq 0 ]]; then
    payload=$(scan_payload)
    payload=${payload/\"state\":\"scan\"/\"state\":\"success\"}
    payload=${payload%\}},\"duration_seconds\":$duration}
    post "$payload" || exit 3
else
    post "{\"event_uuid\":\"$(uuid)\",\"name\":\"${name//\"/\\\"}\",\"state\":\"failure\",\"duration_seconds\":$duration,\"message\":\"Exit code $exit_code\",\"recorded_at\":\"$(date -u '+%Y-%m-%dT%H:%M:%SZ')\"}" || true
fi

exit "$exit_code"
