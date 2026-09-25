package cmd

import (
	"fmt"
	"runtime"

	"github.com/spf13/cobra"
)

// commit and dirty are injected at link time with `-ldflags -X`. The build
// container mounts cli/ alone, so it has no .git and no git binary to read.
// dirty is "true" when the working tree held uncommitted changes, which means
// the binary corresponds to no commit at all. version is set by a release
// build only, so a dev build has none.
var (
	commit  = "unknown"
	dirty   = ""
	version = ""
)

// versionString is the one source both `loupe version` and `loupe --version`
// read, so the two can never disagree.
func versionString() string {
	id := buildID()
	if version != "" {
		id = fmt.Sprintf("%s (%s)", version, commit)
	}

	return fmt.Sprintf("loupe %s\n%s %s/%s", id, runtime.Version(), runtime.GOOS, runtime.GOARCH)
}

// buildID is the commit, marked when the tree was dirty.
func buildID() string {
	if dirty == "true" {
		return commit + " (dirty)"
	}

	return commit
}

// cliVersion is what the heartbeat reports: the release, else the build.
func cliVersion() string {
	if version != "" {
		return version
	}

	return buildID()
}

func newVersionCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "version",
		Short: "Print the version and the commit this binary was built from",
		Args:  cobra.NoArgs,
		Run: func(cmd *cobra.Command, _ []string) {
			fmt.Fprintln(cmd.OutOrStdout(), versionString())
		},
	}
}
