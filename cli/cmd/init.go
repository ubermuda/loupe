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
	var writeMcp, skipMcp, writeMcpJSON bool

	cmd := &cobra.Command{
		Use:   "init",
		Short: "Write " + projectfile.Name + ", the file that names this repository's project",
		Long: "Writes " + projectfile.Name + " in the current directory. `loupe mcp` reads it and " +
			"sends that project with every request, so an agent in this repository acts on this " +
			"project and no other.\n\n" +
			"With no --project, it lists the projects your login covers and asks which one. " +
			"A login that covers exactly one project needs no answer.\n\n" +
			"It refuses to replace an existing file unless you pass --force.\n\n" +
			"It then checks how Claude Code starts the `" + mcpjson.ServerKey + "` MCP server, and " +
			"offers to declare `loupe mcp` for every project when nothing does. One declaration " +
			"serves every repository, because the working directory chooses the project.\n\n" +
			"Use --mcp or --no-mcp to answer without being asked. --mcp declares a missing server " +
			"and never replaces one that exists, so a script cannot drop a declaration you made " +
			"by hand.\n\n" +
			"Pass --mcp-json to write " + mcpjson.Name + " instead, which commits the server to the " +
			"repository for everyone who clones it. Claude Code prefers its own declaration over " +
			"that file, and asks you to approve the file once.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			dir, err := os.Getwd()
			if err != nil {
				return err
			}

			// Whether the file parses is beside the point. Replacing one
			// somebody wrote is the thing that needs saying yes to.
			if _, err := os.Stat(filepath.Join(dir, projectfile.Name)); err == nil && !force {
				if !writeMcp && !writeMcpJSON {
					return fmt.Errorf("%s already exists: pass --force to replace it", projectfile.Name)
				}

				// --mcp on a repository that already names its project asks for
				// the second half alone. Refusing here would make the message
				// printed when somebody declines the offer a dead end.
				fmt.Fprintf(cmd.OutOrStdout(), "%s already exists, so it is kept.\n", projectfile.Name)

				return offerServer(cmd, dir, writeMcp, skipMcp, writeMcpJSON)
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

			return offerServer(cmd, dir, writeMcp, skipMcp, writeMcpJSON)
		},
	}
	cmd.Flags().StringVar(&projectID, "project", "", "project id to write, instead of choosing from a list")
	cmd.Flags().BoolVar(&force, "force", false, "replace an existing "+projectfile.Name)
	cmd.Flags().BoolVar(&writeMcp, "mcp", false, "declare `loupe mcp` to Claude Code without asking")
	cmd.Flags().BoolVar(&skipMcp, "no-mcp", false, "leave the MCP server alone without asking")
	cmd.Flags().BoolVar(&writeMcpJSON, "mcp-json", false, "write "+mcpjson.Name+" instead, to commit the server to the repository")
	cmd.MarkFlagsMutuallyExclusive("mcp", "no-mcp")
	cmd.MarkFlagsMutuallyExclusive("mcp-json", "no-mcp")

	return cmd
}

// offerServer makes sure something starts `loupe mcp` for this repository.
// Writing .loupe.yaml alone changes nothing an agent can see, and leaving this
// out is why somebody installs the CLI and finds their agent unchanged.
func offerServer(cmd *cobra.Command, dir string, write, skip, useFile bool) error {
	if skip {
		return nil
	}
	if useFile {
		return writeRepositoryFile(cmd, dir, write)
	}

	out := cmd.OutOrStdout()

	// Three scopes can declare the server, and a higher one hides a lower. So
	// one pass can fix a layer and uncover another. The bound matters more than
	// the count: `claude mcp` reports success for a call that changed nothing,
	// so a loop that trusts it must still end.
	for range 3 {
		got, err := claudecode.Effective(dir, mcpjson.ServerKey)
		if err != nil {
			// Claude Code owns its configuration. Failing to read it says
			// nothing about whether loupe init did its job, so it is a note.
			fmt.Fprintf(out, "Could not check how Claude Code starts %q: %v\n", mcpjson.ServerKey, err)

			return nil
		}

		if got.Correct() {
			fmt.Fprintf(out, "Claude Code already starts `loupe mcp` for %s. Nothing to change.\n", got.Where())

			return nil
		}
		if !got.Declared() {
			return declareServer(cmd, dir, write)
		}
		if !replaceServer(cmd, dir, got) {
			return nil
		}
	}

	return nil
}

// declareServer offers the missing declaration. It asks unless --mcp already
// answered, because a script that passed no flag must change nothing.
func declareServer(cmd *cobra.Command, dir string, write bool) error {
	out := cmd.OutOrStdout()
	if !write {
		fmt.Fprintf(out, "\nNothing starts the %q MCP server, so your agent has no Loupe tools.\n", mcpjson.ServerKey)
		fmt.Fprintln(out, "One declaration covers every project, because the working directory chooses the project.")
		if !confirm(out, cmd.InOrStdin(), "Declare `loupe mcp` to Claude Code?") {
			fmt.Fprintf(out, "Left it. Run `%s` when you want the tools.\n", claudecode.AddCommand(mcpjson.ServerKey, claudecode.ScopeUser))

			return nil
		}
	}

	return apply(cmd, func(ctx context.Context) error {
		return claudecode.Add(ctx, dir, mcpjson.ServerKey, claudecode.ScopeUser)
	}, "Declared `loupe mcp`. Restart your agent to pick it up.", claudecode.AddCommand(mcpjson.ServerKey, claudecode.ScopeUser))
}

// replaceServer offers to drop a declaration that starts something else, and
// reports whether it went, so the caller knows to look for another behind it.
//
// Replacing always asks, whatever the flags say. A script must not remove a
// declaration somebody made by hand.
func replaceServer(cmd *cobra.Command, dir string, got claudecode.Resolution) bool {
	out := cmd.OutOrStdout()
	remove := claudecode.RemoveCommand(mcpjson.ServerKey, got.Scope)

	fmt.Fprintf(out, "\nClaude Code starts %q from %s, as %s.\n", mcpjson.ServerKey, got.Where(), got.Entry.Summary())
	fmt.Fprintln(out, "That is not `loupe mcp`, so this repository's project file does not reach it.")
	if claudecode.ScopeUser == got.Scope {
		fmt.Fprintln(out, "It covers every project, so removing it affects your other repositories too.")
	}

	if !confirm(out, cmd.InOrStdin(), "Remove it?") {
		fmt.Fprintf(out, "Left it. Run `%s` to remove it yourself.\n", remove)

		return false
	}

	if err := apply(cmd, func(ctx context.Context) error {
		return claudecode.Remove(ctx, dir, mcpjson.ServerKey, got.Scope)
	}, fmt.Sprintf("Removed the %s declaration.", got.Scope), remove); err != nil {
		return false
	}

	return true
}

// writeRepositoryFile puts the server in .mcp.json, which commits it to the
// repository. Claude Code prefers its own declaration, so the file can be
// correct and inert, and offerServer reports that on the next run.
func writeRepositoryFile(cmd *cobra.Command, dir string, write bool) error {
	out := cmd.OutOrStdout()

	state, _, err := mcpjson.Read(dir)
	if err != nil {
		return err
	}
	if mcpjson.Current == state {
		fmt.Fprintf(out, "%s already starts `loupe mcp`.\n", mcpjson.Name)

		return nil
	}
	if !write && !confirm(out, cmd.InOrStdin(), fmt.Sprintf("Write `loupe mcp` into %s?", mcpjson.Name)) {
		fmt.Fprintf(out, "Left %s alone.\n", mcpjson.Name)

		return nil
	}

	if err := mcpjson.Write(dir); err != nil {
		return err
	}
	// A .mcp.json names programs to run and travels with the repository, so an
	// agent gates it behind an approval the first time rather than on trust.
	fmt.Fprintf(out, "Wrote %s. Restart your agent, which asks you to approve the server once.\n", mcpjson.Name)

	higher, err := claudecode.Effective(dir, mcpjson.ServerKey)
	if err == nil && higher.Declared() && claudecode.ScopeProject != higher.Scope {
		fmt.Fprintf(out, "Claude Code starts %q from %s instead, so the file has no effect yet.\n", mcpjson.ServerKey, higher.Where())
		fmt.Fprintf(out, "Run `%s` to let the file win.\n", claudecode.RemoveCommand(mcpjson.ServerKey, higher.Scope))
	}

	return nil
}

// apply runs one change against Claude Code and reports what happened. A
// failure names the command to run by hand, and is never fatal: the project
// file is written either way.
func apply(cmd *cobra.Command, change func(context.Context) error, done, byHand string) error {
	out := cmd.OutOrStdout()
	ctx, cancel := context.WithTimeout(cmd.Context(), 30*time.Second)
	defer cancel()

	if err := change(ctx); err != nil {
		fmt.Fprintf(out, "Could not do it: %v\n", err)
		fmt.Fprintf(out, "Run `%s` by hand.\n", byHand)

		return err
	}
	fmt.Fprintln(out, done)

	return nil
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
