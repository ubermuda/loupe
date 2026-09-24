#!/bin/sh
# Runs ./hook against a fake osascript, so it needs no Mac and no Amphetamine.
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
    'session time remaining') if [ "$(cat "$FAKE/active")" = true ]; then cat "$FAKE/remaining"; else echo -3; fi ;;
    'closed display mode enabled') cat "$FAKE/closed" ;;
    'start new session with options {duration:0, interval:0, displaySleepAllowed:true}')
        echo true >"$FAKE/active"
        echo 0 >"$FAKE/remaining"
        ;;
    'end session') echo false >"$FAKE/active" ;;
    'enable closed display mode') cat "$FAKE/closed_after_enable" >"$FAKE/closed" ;;
    *) echo "unknown command: $cmd" >&2; exit 64 ;;
esac
EOF
chmod +x "$work/bin/osascript"

any_failed=0
case_failed=0
name=''

# setup ACTIVE REMAINING MARKER TAKEOVER [CLOSED_AFTER_ENABLE]
setup() {
    rm -rf "$work/fake" "$work/state"
    mkdir -p "$work/fake" "$work/state"
    echo "$1" >"$work/fake/active"
    echo "$2" >"$work/fake/remaining"
    echo false >"$work/fake/closed"
    echo "${5:-true}" >"$work/fake/closed_after_enable"
    : >"$work/fake/log"
    [ "$3" = marker ] && : >"$work/state/session"
    takeover=$4
}

run() {
    (cd "$pkg" && PATH="$work/bin:$PATH" FAKE="$work/fake" LOUPE_HOOK_EVENT="$1" \
        LOUPE_HOOK_PACKAGE=amphetamine LOUPE_BRIDGE_ID=test \
        LOUPE_HOOK_STATE_DIR="$work/state" LOUPE_HOOK_SETTING_TAKEOVER="$takeover" \
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
expect_marker() { [ -f "$work/state/session" ] || fail "no marker"; }
expect_no_marker() { [ ! -f "$work/state/session" ] || fail "marker present"; }

START='start new session with options {duration:0, interval:0, displaySleepAllowed:true}'

check() {
    name=$1
    case_failed=0
}
finish() {
    [ "$case_failed" -eq 1 ] || echo "PASS $name"
}

check 'busy with no session starts one'
setup false 0 none false
run busy
expect_code 0
expect_called "$START"
expect_called 'enable closed display mode'
expect_called 'closed display mode enabled'
expect_marker
finish

check 'busy leaves a foreign session alone when takeover is off'
setup true 3600 none false
run busy
expect_code 0
expect_not_called "$START"
expect_not_called 'enable closed display mode'
expect_no_marker
finish

check 'busy takes over a foreign session when takeover is on'
setup true 3600 none true
run busy
expect_code 0
expect_called "$START"
expect_marker
finish

check 'busy exits 3 when closed-display mode stays off'
setup false 0 none false false
run busy
expect_code 3
expect_called "$START"
expect_not_called 'end session'
expect_marker
grep -q 'closed-display mode is off' "$work/stderr" || fail 'no closed-display message'
finish

check 'idle ends its own infinite session'
setup true 0 marker false
run idle
expect_code 0
expect_called 'end session'
expect_no_marker
finish

check 'idle without a marker ends nothing'
setup true 0 none false
run idle
expect_code 0
expect_not_called 'end session'
expect_no_marker
finish

check 'idle leaves a finite session alone and deletes the marker'
setup true 3600 marker false
run idle
expect_code 0
expect_not_called 'end session'
expect_no_marker
finish

check 'idle with no active session deletes the marker'
setup false 0 marker false
run idle
expect_code 0
expect_not_called 'end session'
expect_no_marker
finish

check 'idle ends any session when takeover is on'
setup true 3600 none true
run idle
expect_code 0
expect_called 'end session'
expect_no_marker
finish

check 'stop ends its own infinite session'
setup true 0 marker false
run stop
expect_code 0
expect_called 'end session'
expect_no_marker
finish

check 'start cleans up a session left by a crash'
setup true 0 marker false
run start
expect_code 0
expect_called 'end session'
expect_no_marker
finish

check 'an osascript failure passes its exit code on'
setup false 0 none false
echo 7 >"$work/fake/fail"
run busy
expect_code 7
grep -q 'fake osascript failure' "$work/stderr" || fail 'no osascript message'
expect_no_marker
finish

check 'an unknown event exits 2'
setup false 0 none false
run nap
expect_code 2
[ ! -s "$work/fake/log" ] || fail 'osascript was called'
finish

exit "$any_failed"
