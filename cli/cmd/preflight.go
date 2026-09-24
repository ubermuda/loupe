package cmd

import (
	"fmt"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// newBridgePreflightCmd checks that this binary can take over a running
// bridge. The running bridge runs it on a staged release before it hands over.
func newBridgePreflightCmd() *cobra.Command {
	var rulesPath, handover string

	cmd := &cobra.Command{
		Use:    "preflight",
		Short:  "Check that this binary can take over the bridge that reads the rule file",
		Hidden: true,
		Args:   cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := rulesPathOr(rulesPath)
			if err != nil {
				return err
			}
			set, err := rules.Load(path, rules.Defaults{})
			if err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			if handover != "" {
				if _, err := readHandover(handover); err != nil {
					return err
				}
			}
			cfg, err := config.Load()
			if err != nil {
				return err
			}
			if err := set.Check(cmd.Context(), apiClient(cfg)); err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			_, err = startEvents(cmd.Context(), cfg, set)

			return err
		},
	}
	cmd.Flags().StringVar(&rulesPath, "rules", "", "read rules from this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&handover, "handover", "", "read this handover `file` as the new image would")

	return cmd
}
