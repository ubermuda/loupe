//go:build windows

package cmd

import (
	"fmt"
	"testing"
)

func TestConnRefusedMatchesTheWinsockErrno(t *testing.T) {
	if !connRefused(fmt.Errorf("dial: %w", wsaeconnrefused)) {
		t.Fatal("a refused dial on Windows must count as refused")
	}
}
