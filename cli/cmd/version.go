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
	return fmt.Sprintf("loupe %s\n%s %s/%s", buildID(), runtime.Version(), runtime.GOOS, runtime.GOARCH)
}

// buildID is the commit, marked when the tree was dirty. The heartbeat sends it
// as the CLI version.
func buildID() string {
	if dirty == "true" {
		return commit + " (dirty)"
	}

	return commit
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
