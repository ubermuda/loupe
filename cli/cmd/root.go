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
		SilenceUsage:  true,
		SilenceErrors: true,
	}
	root.AddCommand(newLoginCmd(), newBridgeCmd())

	return root
}
