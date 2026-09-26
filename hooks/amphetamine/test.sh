#!/bin/sh
# Runs ./hook against a fake osascript, date and ioreg, so it needs no Mac and
# no Amphetamine.
set -u

pkg=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"

cat >"$work/bin/osascript" <<'EOF'
#!/bin/sh
cmd=${2#tell application \"Amphetamine\" to }
echo "$cmd" >>"$FAKE/log"
if [ -f "$FAKE/fail" ]; then
    echo "fake osascript failure" >&2
    exit "$(cat "$FAKE/fail")"
fi
case $cmd in
    'session is active') cat "$FAKE/active" ;;
    'closed display mode enabled') cat "$FAKE/closed" ;;
    'start new session with options '*) echo true >"$FAKE/active" ;;
    'end session') echo false >"$FAKE/active" ;;
    'enable closed display mode') cat "$FAKE/closed_after_enable" >"$FAKE/closed" ;;
    *) echo "unknown command: $cmd" >&2; exit 64 ;;
esac
EOF

cat >"$work/bin/date" <<'EOF'
#!/bin/sh
cat "$FAKE/now"
EOF

cat >"$work/bin/ioreg" <<'EOF'
#!/bin/sh
echo '    | |   "HIDIdleTime" = '"$(cat "$FAKE/idle")"'000000000'
EOF
chmod +x "$work/bin/osascript" "$work/bin/date" "$work/bin/ioreg"

any_failed=0
case_failed=0
name=''

# setup ACTIVE NOW IDLE_SECONDS [CLOSED_AFTER_ENABLE]
setup() {
    rm -rf "$work/fake" "$work/state"
    mkdir -p "$work/fake" "$work/state"
    echo "$1" >"$work/fake/active"
    echo "$2" >"$work/fake/now"
    echo "$3" >"$work/fake/idle"
    echo false >"$work/fake/closed"
    echo "${4:-true}" >"$work/fake/closed_after_enable"
    : >"$work/fake/log"
    quiet='23:00-06:00'
    away=30
}

run() {
    (cd "$pkg" && PATH="$work/bin:$PATH" FAKE="$work/fake" LOUPE_HOOK_EVENT="$1" \
        LOUPE_HOOK_PACKAGE=amphetamine LOUPE_BRIDGE_ID=test \
        LOUPE_HOOK_STATE_DIR="$work/state" LOUPE_HOOK_SETTING_QUIET_HOURS="$quiet" \
        LOUPE_HOOK_SETTING_AWAY_MINUTES="$away" \
        ./hook "$1" 2>"$work/stderr")
    code=$?
}

fail() {
    echo "FAIL $name: $1"
    case_failed=1
    any_failed=1
}

called() { grep -qxF "$1" "$work/fake/log"; }

expect_code() { [ "$code" -eq "$1" ] || fail "exit code $code, want $1"; }
expect_called() { called "$1" || fail "no call to '$1'"; }
expect_not_called() { ! called "$1" || fail "unexpected call to '$1'"; }

START='start new session with options {duration:0, interval:0, displaySleepAllowed:true}'
timed() { echo "start new session with options {duration:$1, interval:minutes, displaySleepAllowed:true}"; }

check() {
    name=$1
    case_failed=0
}
finish() {
    [ "$case_failed" -eq 1 ] || echo "PASS $name"
}

check 'busy starts an infinite session'
setup false 14:00 0
run busy
expect_code 0
expect_called "$START"
expect_called 'enable closed display mode'
expect_called 'closed display mode enabled'
finish

check 'busy replaces any active session'
setup true 14:00 0
run busy
expect_code 0
expect_called "$START"
finish

check 'busy exits 3 when closed-display mode stays off'
setup false 14:00 0 false
run busy
expect_code 3
expect_called "$START"
grep -q 'closed-display mode is off' "$work/stderr" || fail 'no closed-display message'
finish

check 'idle in quiet hours ends the session'
setup true 23:30 0
run idle
expect_code 0
expect_called 'end session'
finish

check 'idle after midnight in quiet hours ends the session'
setup true 05:59 0
run idle
expect_code 0
expect_called 'end session'
finish

check 'idle at the end of quiet hours makes the session timed'
setup true 06:00 0
run idle
expect_code 0
expect_not_called 'end session'
expect_called "$(timed 1020)"
finish

check 'idle by day with a person at the Mac makes the session end at quiet hours'
setup true 21:00 60
run idle
expect_code 0
expect_not_called 'end session'
expect_called "$(timed 120)"
finish

check 'idle by day with nobody at the Mac ends the session'
setup true 14:00 1800
run idle
expect_code 0
expect_called 'end session'
finish

check 'idle with away_minutes 0 ignores the idle time'
setup true 14:00 99999
away=0
run idle
expect_code 0
expect_not_called 'end session'
expect_called "$(timed 540)"
finish

check 'idle with empty quiet_hours ends the session'
setup true 14:00 0
quiet=''
run idle
expect_code 0
expect_called 'end session'
finish

check 'idle reads hours with a leading zero'
setup true 12:00 0
quiet='08:09-09:08'
run idle
expect_code 0
expect_called "$(timed 1209)"
finish

check 'idle with a same-day window'
setup true 13:00 0
quiet='12:00-14:00'
run idle
expect_code 0
expect_called 'end session'
finish

check 'idle with no active session does nothing'
setup false 23:30 0
run idle
expect_code 0
[ "$(cat "$work/fake/log")" = 'session is active' ] || fail 'called more than session is active'
finish

check 'stop and start follow the idle rules'
setup true 23:30 0
run stop
expect_called 'end session'
setup true 23:30 0
run start
expect_code 0
expect_called 'end session'
finish

check 'a bad quiet_hours exits 5'
setup true 14:00 0
quiet='25:00-06:00'
run idle
expect_code 5
[ ! -s "$work/fake/log" ] || fail 'osascript was called'
grep -q 'quiet_hours' "$work/stderr" || fail 'no quiet_hours message'
finish

check 'a bad away_minutes exits 5'
setup true 14:00 0
away=ten
run idle
expect_code 5
grep -q 'away_minutes' "$work/stderr" || fail 'no away_minutes message'
finish

check 'an osascript failure passes its exit code on'
setup false 14:00 0
echo 7 >"$work/fake/fail"
run busy
expect_code 7
grep -q 'fake osascript failure' "$work/stderr" || fail 'no osascript message'
finish

check 'an unknown event exits 2'
setup false 14:00 0
run nap
expect_code 2
[ ! -s "$work/fake/log" ] || fail 'osascript was called'
finish

exit "$any_failed"
