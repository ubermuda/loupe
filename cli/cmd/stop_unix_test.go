//go:build unix

package cmd

import (
	"errors"
	"os/exec"
	"syscall"
	"testing"
	"time"
)

// A pid below 2 would reach the bridge's own group or every process, so the
// signaller refuses it.
func TestTheSignallerRefusesAPidThatLeadsNoWorker(t *testing.T) {
	for _, pid := range []int{-1, 0, 1} {
		if err := signalGroup(pid, stopInt); err == nil {
			t.Fatalf("signalGroup(%d) sent a signal", pid)
		}
	}
}

// SIGINT reaches every process of the group, and the probe then finds the
// group gone.
func TestTheSignallerStopsTheWholeGroup(t *testing.T) {
	cmd := exec.Command("sh", "-c", "sleep 30; sleep 30")
	newProcessGroup(cmd)
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	pid := cmd.Process.Pid
	if err := signalGroup(pid, stopProbe); err != nil {
		t.Fatalf("probe of a running group = %v", err)
	}

	if err := signalGroup(pid, stopInt); err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		t.Fatal("the group ignored SIGINT")
	}
	for deadline := time.Now().Add(5 * time.Second); !errors.Is(signalGroup(pid, stopProbe), errGroupGone); {
		if time.Now().After(deadline) {
			_ = syscall.Kill(-pid, syscall.SIGKILL)
			t.Fatal("the sleep of the group still runs")
		}
		time.Sleep(10 * time.Millisecond)
	}
}
