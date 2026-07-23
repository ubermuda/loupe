// Command loupe is the Loupe command-line tool. Today it bridges
// submitted site reviews into a local Claude Code session; more subcommands will
// be added over time.
package main

import (
	"fmt"
	"os"

	"github.com/ubermuda/loupe/cli/cmd"
)

func main() {
	if err := cmd.Execute(); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}
