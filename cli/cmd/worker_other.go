//go:build !unix

package cmd

import "os/exec"

// setProcessGroup does nothing off unix. Cancelling the bridge there kills
// claude alone, so a tool it started can outlive the bridge.
func setProcessGroup(_ *exec.Cmd) {}
