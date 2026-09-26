package cmd

import "github.com/spf13/cobra"

// Execute runs the root command. main() is the only caller.
func Execute() error {
	return newRootCmd().Execute()
}

func newRootCmd() *cobra.Command {
	root := &cobra.Command{
		Use:           "loupe",
		Short:         "Loupe command-line tools",
		Version:       versionString(),
		SilenceUsage:  true,
		SilenceErrors: true,
	}
	// Cobra's default template prefixes "loupe version", which the string
	// already carries, and it spans two lines.
	root.SetVersionTemplate("{{.Version}}\n")
	root.AddCommand(newLoginCmd(), newInitCmd(), newMcpCmd(), newBridgeCmd(), newUsageCmd(), newUpdateCmd(), newVersionCmd())

	return root
}
