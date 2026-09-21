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
	"github.com/ubermuda/loupe/cli/internal/claudecode"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/mcpjson"
	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

func newInitCmd() *cobra.Command {
	var projectID string
	var force bool
	var writeMcp, skipMcp bool

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Write " + projectfile.Name + ", the file that names this repository's project",
		Long: "Writes " + projectfile.Name + " in the current directory. `loupe mcp` reads it and " +
			"sends that project with every request, so an agent in this repository acts on this " +
			"project and no other.\n\n" +
			"With no --project, it lists the projects your login covers and asks which one. " +
			"A login that covers exactly one project needs no answer.\n\n" +
			"It refuses to replace an existing file unless you pass --force.\n\n" +
			"It then offers to name `loupe mcp` in " + mcpjson.Name + ", which is what makes an " +
			"agent in this repository start it. Every other server in that file is kept. " +
			"Use --mcp or --no-mcp to answer without being asked, which a script must do.\n\n" +
			"--mcp on a repository that already names its project writes " + mcpjson.Name + " alone.\n\n" +
			"Claude Code prefers its own entry of the same name over " + mcpjson.Name + ". When it has " +
			"one, init offers to remove it. That offer is always asked, so --mcp in a script never " +
			"changes Claude Code's configuration.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			dir, err := os.Getwd()
			if err != nil {
				return err
			}

			// Whether the file parses is beside the point. Replacing one
			// somebody wrote is the thing that needs saying yes to.
			if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !force {
				if !writeMcp {
					return fmt.Errorf("%s already exists: pass --force to replace it", projectfile.Name)
				}

				// --mcp on a repository that already names its project asks for
				// the second half alone. Refusing here would make the message
				// printed when somebody declines the offer a dead end.
				fmt.Fprintf(cmd.OutOrStdout(), "%s already exists, so it is kept.\n", projectfile.Name)

				return offerMcpEntry(cmd, dir, writeMcp, skipMcp)
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

			return offerMcpEntry(cmd, dir, writeMcp, skipMcp)
		},
	}
	cmd.Flags().StringVar(&projectID, "project", "", "project id to write, instead of choosing from a list")
	cmd.Flags().BoolVar(&force, "force", false, "replace an existing "+projectfile.Name)
	cmd.Flags().BoolVar(&writeMcp, "mcp", false, "name `loupe mcp` in "+mcpjson.Name+" without asking")
	cmd.Flags().BoolVar(&skipMcp, "no-mcp", false, "leave "+mcpjson.Name+" alone without asking")
	cmd.MarkFlagsMutuallyExclusive("mcp", "no-mcp")

	return cmd
}

// offerMcpEntry names `loupe mcp` in .mcp.json, asking first unless a flag
// already answered. Writing the file is the step that makes an agent in this
// repository start the shim, and leaving it out is why somebody installs the
// CLI and sees nothing change.
func offerMcpEntry(cmd *cobra.Command, dir string, write, skip bool) error {
	if skip {
		return nil
	}

	state, found, err := mcpjson.Read(dir)
	if err != nil {
		return err
	}
	if mcpjson.Current == state {
		fmt.Fprintf(cmd.OutOrStdout(), "%s already starts `loupe mcp`.\n", mcpjson.Name)

		return nil
	}

	out := cmd.OutOrStdout()
	if !write {
		if mcpjson.Different == state {
			fmt.Fprintf(out, "%s starts %q for loupe, not `loupe mcp`.\n", mcpjson.Name, strings.TrimSpace(found.Command+" "+strings.Join(found.Args, " ")))
			write = confirm(out, cmd.InOrStdin(), "Replace it?")
		} else {
			fmt.Fprintf(out, "An agent starts `loupe mcp` when %s names it, and every other server in that file is kept.\n", mcpjson.Name)
			write = confirm(out, cmd.InOrStdin(), fmt.Sprintf("Add it to %s?", mcpjson.Name))
		}
	}
	if !write {
		fmt.Fprintf(out, "Left %s alone. Run `loupe init --mcp` later, or add it by hand.\n", mcpjson.Name)

		return nil
	}

	if err := mcpjson.Write(dir); err != nil {
		return err
	}
	// A .mcp.json names programs to run and travels with the repository, so an
	// agent gates it behind an approval the first time rather than on trust.
	fmt.Fprintf(out, "Wrote %s. Restart your agent, which asks you to approve the server once.\n", mcpjson.Name)

	return offerToUnshadow(cmd, dir)
}

// offerToUnshadow deals with a Claude Code entry that hides the file we just
// wrote. Claude Code prefers its own entry over .mcp.json, in local scope and
// in user scope alike, so the new file has no effect while one exists and
// neither of them says so.
func offerToUnshadow(cmd *cobra.Command, dir string) error {
	out := cmd.OutOrStdout()

	// Local beats user, so removing one can uncover the other. Two scopes hide
	// the file, so two passes see all of it. The bound matters more than the
	// count: a removal that reports success and changes nothing must not loop.
	for range 2 {
		shadow, err := claudecode.Shadowing(dir, mcpjson.ServerKey)
		if err != nil {
			// Claude Code owns this file. Failing to read it says nothing
			// about whether loupe init did its job, so it is a note.
			fmt.Fprintf(out, "Could not check Claude Code's own configuration: %v\n", err)

			return nil
		}
		if nil == shadow {
			return nil
		}

		if !removeShadow(cmd, dir, shadow) {
			return nil
		}
	}

	return nil
}

// removeShadow shows one entry and offers to remove it, and reports whether it
// went, so the caller knows whether to look for another behind it.
func removeShadow(cmd *cobra.Command, dir string, shadow *claudecode.Shadow) bool {
	out := cmd.OutOrStdout()
	remove := claudecode.RemoveCommand(mcpjson.ServerKey, shadow.Scope)

	fmt.Fprintf(out, "\nClaude Code has its own %q server for %s, pointing at %s.\n", mcpjson.ServerKey, shadow.Where(), shadow.Summary)
	fmt.Fprintf(out, "That entry wins over %s, so the file just written has no effect until it goes.\n", mcpjson.Name)
	if claudecode.ScopeUser == shadow.Scope {
		fmt.Fprintln(out, "It covers every project, so removing it affects your other repositories too.")
	}

	if !confirm(out, cmd.InOrStdin(), "Ask Claude Code to remove it?") {
		fmt.Fprintf(out, "Left it. Run `%s` when you want %s to take effect.\n", remove, mcpjson.Name)

		return false
	}

	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()
	if err := claudecode.Remove(ctx, dir, mcpjson.ServerKey, shadow.Scope); err != nil {
		fmt.Fprintf(out, "Could not remove it: %v\n", err)
		fmt.Fprintf(out, "Run `%s` by hand.\n", remove)

		return false
	}
	fmt.Fprintf(out, "Removed the %s entry.\n", shadow.Scope)

	return true
}

// confirm asks a yes or no question. Enter means yes, and every answer that is
// not an answer means no: a stdin that is not a terminal, a closed stdin, or a
// read that fails. A script that passed no flag therefore leaves the file alone
// rather than hanging on a prompt nobody can see.
func confirm(out io.Writer, in io.Reader, question string) bool {
	if f, ok := in.(*os.File); ok {
		if info, err := f.Stat(); err != nil || 0 == info.Mode()&os.ModeCharDevice {
			return false
		}
	}

	fmt.Fprintf(out, "%s [Y/n]: ", question)
	line, err := bufio.NewReader(in).ReadString('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return false
	}
	// Ctrl-D gives EOF with nothing typed. Reading that as the Enter default
	// would write a file the person was in the middle of declining.
	if errors.Is(err, io.EOF) && "" == line {
		return false
	}

	switch strings.ToLower(strings.TrimSpace(line)) {
	case "", "y", "yes":
		return true
	default:
		return false
	}
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
