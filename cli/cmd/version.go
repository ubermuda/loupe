package cmd

import (
	"fmt"
	"runtime"

	"github.com/spf13/cobra"
)

// commit and dirty are injected at link time with `-ldflags -X`. The build
// container mounts cli/ alone, so it has no .git and no git binary to read.
// dirty is "true" when the working tree held uncommitted changes, which means
// the binary corresponds to no commit at all.
var (
	commit = "unknown"
	dirty  = ""
)

// versionString is the one source both `loupe version` and `loupe --version`
// read, so the two can never disagree.
func versionString() string {
	build := commit
	if dirty == "true" {
		build += " (dirty)"
	}

	return fmt.Sprintf("loupe %s\n%s %s/%s", build, runtime.Version(), runtime.GOOS, runtime.GOARCH)
}

func newVersionCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "version",
		Short: "Print the commit this binary was built from",
		Args:  cobra.NoArgs,
		Run: func(cmd *cobra.Command, _ []string) {
			fmt.Fprintln(cmd.OutOrStdout(), versionString())
		},
	}
}
