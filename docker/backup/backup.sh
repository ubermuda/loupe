#!/bin/sh
#
# Dump the database, upload the dump to S3-compatible storage, prune dumps older
# than the retention window, sleep, repeat. Every failure is fatal: the container
# exits non-zero and the restart policy brings it back, so a broken backup job is
# a restarting container with an error in its logs rather than a quiet one that
# has stopped producing dumps.  @comment-budget-ignore

set -eu

SCRATCH=/scratch
PATTERN='loupe-*.dump'

log() {
    echo "[backup] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*"
}

fail() {
    log "FATAL: $*" >&2
    exit 1
}

bucket=${BACKUP_S3_BUCKET:-}
prefix=${BACKUP_S3_PREFIX:-}
retention=${BACKUP_RETENTION_DAYS:-14}
interval=${BACKUP_INTERVAL:-24h}
provider=${RCLONE_CONFIG_S3_PROVIDER:-Other}

# The compose file cannot guard these the way it guards the application's own
# settings: Compose interpolates the whole file whatever profiles are selected,
# so an abort-on-empty guard there would break every command for operators who
# never enable this profile.
[ -n "$bucket" ] ||
    fail 'BACKUP_S3_BUCKET is empty — the backup profile is enabled but has nowhere to upload to.'
[ -n "${RCLONE_CONFIG_S3_ACCESS_KEY_ID:-}" ] ||
    fail 'BACKUP_S3_KEY is empty.'
[ -n "${RCLONE_CONFIG_S3_SECRET_ACCESS_KEY:-}" ] ||
    fail 'BACKUP_S3_SECRET is empty.'
[ "$provider" = 'AWS' ] || [ -n "${RCLONE_CONFIG_S3_ENDPOINT:-}" ] ||
    fail "BACKUP_S3_ENDPOINT is empty and BACKUP_S3_PROVIDER is '$provider' — only AWS S3 has an endpoint that can be inferred."

case "$retention" in
    '' | *[!0-9]*) fail "BACKUP_RETENTION_DAYS must be a whole number of days, got '$retention'." ;;
esac
[ "$retention" -ge 1 ] || fail 'BACKUP_RETENTION_DAYS must be at least 1.'

interval_count=${interval%[smhd]}
case "$interval_count" in
    '' | *[!0-9]*) fail "BACKUP_INTERVAL must be a number, optionally suffixed s, m, h or d, got '$interval'." ;;
esac
# Zero would make `sleep` return at once, turning the schedule into a continuous
# loop of dumps and uploads.
[ "$interval_count" -ge 1 ] || fail "BACKUP_INTERVAL must be at least 1, got '$interval'."

if [ -n "$prefix" ]; then
    remote="s3:$bucket/${prefix%/}"
else
    remote="s3:$bucket"
fi

log "target $remote, every $interval, keeping ${retention}d"

# Put, list and delete are exactly what a dump, a prune and nothing else need,
# so proving all three now turns a key with the wrong grants into a startup
# error instead of a first upload that fails hours later.
preflight() {
    printf 'loupe backup preflight\n' | rclone rcat "$remote/.preflight" &&
        rclone lsf --max-depth 1 "$remote/" >/dev/null &&
        rclone deletefile "$remote/.preflight"
}

if ! preflight; then
    # Whichever step failed, the marker may already be in the bucket, and the
    # prune below never looks at anything but its own dumps.
    rclone deletefile "$remote/.preflight" >/dev/null 2>&1 || true
    fail "cannot put, list and delete under $remote — check the bucket name, endpoint, region, credentials and the key's grants."
fi

while true; do
    name="loupe-$(date -u '+%Y%m%dT%H%M%SZ').dump"

    # A dump left behind by a previous crash would otherwise fill the volume.
    rm -f "$SCRATCH"/$PATTERN

    log "dumping $PGDATABASE"
    pg_dump --format=custom --file="$SCRATCH/$name"

    log "uploading $name ($(du -h "$SCRATCH/$name" | cut -f1))"
    rclone copyto "$SCRATCH/$name" "$remote/$name"
    rm -f "$SCRATCH/$name"

    # Scoped to this job's own filenames so a bucket shared with anything else
    # keeps its other objects.
    log "pruning dumps older than ${retention}d"
    rclone delete --min-age "${retention}d" --include "$PATTERN" "$remote/"

    log "next dump in $interval"
    sleep "$interval"
done
