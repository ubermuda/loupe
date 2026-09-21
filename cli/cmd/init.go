package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

func newInitCmd() *cobra.Command {
	var projectID string
	var force bool

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Write " + projectfile.Name + ", the file that names this repository's project",
		Long: "Writes " + projectfile.Name + " in the current directory. `loupe mcp` reads it and " +
			"sends that project with every request, so an agent in this repository acts on this " +
			"project and no other.\n\n" +
			"With no --project, it lists the projects your login covers and asks which one. " +
			"A login that covers exactly one project needs no answer.\n\n" +
			"It refuses to replace an existing file unless you pass --force.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			dir, err := os.Getwd()
			if err != nil {
				return err
			}

			// Whether the file parses is beside the point. Replacing one
			// somebody wrote is the thing that needs saying yes to.
			if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !force {
				return fmt.Errorf("%s already exists: pass --force to replace it", projectfile.Name)
			}

			if projectID == "" {
				cfg, err := config.Load()
				if err != nil {
					return err
				}

				ctx, cancel := context.WithTimeout(cmd.Context(), 10*time.Second)
				defer cancel()
				sites, err := apiClient(cfg).Sites(ctx)
				if err != nil {
					return err
				}

				if projectID, err = chooseProject(cmd.OutOrStdout(), cmd.InOrStdin(), sites); err != nil {
					return err
				}
			}

			if err := projectfile.Write(dir, projectID); err != nil {
				return err
			}
			fmt.Fprintf(cmd.OutOrStdout(), "Wrote %s for project %s.\n", projectfile.Name, projectID)

			return nil
		},
	}
	cmd.Flags().StringVar(&projectID, "project", "", "project id to write, instead of choosing from a list")
	cmd.Flags().BoolVar(&force, "force", false, "replace an existing "+projectfile.Name)

	return cmd
}

// chooseProject asks which project the file should name. One project needs no
// question, and no project is an error rather than an empty file.
func chooseProject(out io.Writer, in io.Reader, sites []api.Site) (string, error) {
	switch len(sites) {
	case 0:
		return "", errors.New("your login covers no project: create one in Loupe first")
	case 1:
		fmt.Fprintf(out, "Your login covers one project, %s.\n", describe(sites[0]))

		return sites[0].ID, nil
	}

	fmt.Fprintln(out, "Which project does this repository belong to?")
	for i, site := range sites {
		fmt.Fprintf(out, "  %d) %s\n", i+1, describe(site))
	}
	fmt.Fprintf(out, "Choose 1 to %d: ", len(sites))

	line, err := bufio.NewReader(in).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return "", err
	}

	choice, err := strconv.Atoi(strings.TrimSpace(line))
	if err != nil || choice < 1 || choice > len(sites) {
		return "", fmt.Errorf("choose a number between 1 and %d, or pass --project", len(sites))
	}

	return sites[choice-1].ID, nil
}

// describe names a project the way the chooser shows it. A project with no slug
// yet shows its id, which is what the file will hold.
func describe(site api.Site) string {
	if site.Slug == "" {
		return fmt.Sprintf("%s (%s)", site.Name, site.ID)
	}

	return fmt.Sprintf("%s (%s, %s)", site.Name, site.Slug, site.ID)
}
