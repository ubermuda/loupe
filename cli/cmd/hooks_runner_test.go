package cmd

import (
	"slices"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/hooks"
)

// testHook is an installed package in dir that listens to events. Each event
// runs ./hook.sh with the event name as its argument.
func testHook(id, dir string, events ...string) hooks.Hook {
	cmds := map[string][]string{}
	for _, e := range events {
		cmds[e] = []string{"./hook.sh", e}
	}

	return hooks.Hook{ID: id, Ref: "v1", SHA: "sha-" + id, Dir: dir, StateDir: dir + "/state", Timeout: hooks.DefaultTimeout, Events: cmds}
}

// A row stands for each event the manifest defines, in install order and in
// the order the events fire. A package that has not run reads as never.
func TestHookRowsListEachDefinedEventAsNever(t *testing.T) {
	hr := newHookRunner([]hooks.Hook{
		testHook("acme/b", t.TempDir(), "idle", "busy"),
		testHook("acme/a", t.TempDir(), "stop"),
	}, testBridgeID, newBridgeLogger(&syncBuffer{}))

	want := []api.HookReport{
		{Package: "acme/b", Ref: "v1", Event: "busy", Outcome: hookNever},
		{Package: "acme/b", Ref: "v1", Event: "idle", Outcome: hookNever},
		{Package: "acme/a", Ref: "v1", Event: "stop", Outcome: hookNever},
	}
	if got := hr.rows(); !slices.Equal(got, want) {
		t.Fatalf("rows = %+v", got)
	}
}

// A runner with no hooks reports an empty list rather than none, so the server
// clears the rows of an earlier run.
func TestARunnerWithNoHooksReportsAnEmptyList(t *testing.T) {
	hr := newHookRunner(nil, testBridgeID, newBridgeLogger(&syncBuffer{}))

	if got := hr.rows(); got == nil || len(got) != 0 {
		t.Fatalf("rows = %#v", got)
	}
}

// A reload drops the rows of a removed package and keeps the last run of a
// package it keeps. A package at a new commit starts again as never.
func TestSetHooksReplacesTheRows(t *testing.T) {
	kept, updated := testHook("acme/kept", t.TempDir(), "busy"), testHook("acme/updated", t.TempDir(), "busy")
	hr := newHookRunner([]hooks.Hook{kept, updated, testHook("acme/removed", t.TempDir(), "busy")}, testBridgeID, newBridgeLogger(&syncBuffer{}))
	at := time.Date(2026, 9, 24, 10, 0, 0, 0, time.UTC)
	for _, h := range hr.hooks {
		hr.record(h, "busy", hookRun{at: at, outcome: hookOK})
	}

	updated.SHA, updated.Ref = "sha-new", "v2"
	added := testHook("acme/added", t.TempDir(), "idle")
	hr.setHooks([]hooks.Hook{kept, updated, added})

	want := []api.HookReport{
		{Package: "acme/kept", Ref: "v1", Event: "busy", LastRunAt: "2026-09-24T10:00:00Z", Outcome: hookOK},
		{Package: "acme/updated", Ref: "v2", Event: "busy", Outcome: hookNever},
		{Package: "acme/added", Ref: "v1", Event: "idle", Outcome: hookNever},
	}
	if got := hr.rows(); !slices.Equal(got, want) {
		t.Fatalf("rows = %+v", got)
	}
}

// A nil runner, which most router tests have, takes every call.
func TestANilHookRunnerDoesNothing(t *testing.T) {
	var hr *hookRunner
	hr.start()
	hr.fire(hookBusy)
	hr.setHooks(nil)
	hr.attach(nil)
	hr.stop()
}
