package cmd

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"maps"
	"os"
	"runtime"
	"slices"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/rules"
)

// hookFetcher downloads hook packages. Tests point it at a fake GitHub.
var hookFetcher = &hooks.Fetcher{}

const reloadHint = "Run `loupe bridge reload` to apply it to a running bridge."

func newBridgeHooksCmd() *cobra.Command {
	var rulesPath string

	cmd := &cobra.Command{
		Use:   "hooks",
		Short: "Install and manage the hook packages that the bridge runs",
		Long: "A hook package is a directory of a GitHub repository with a loupe-hook.yaml " +
			"manifest. The bridge runs its commands when the bridge starts, when it stops, " +
			"when its first worker starts (busy) and when its last worker ends (idle).\n\n" +
			"The hooks list of the rule file names each package and the commit it runs. " +
			"These commands edit that list, and keep the rest of the file and its comments. " +
			"The packages live in the hooks directory of your config directory.",
	}
	cmd.PersistentFlags().StringVar(&rulesPath, "rules", "", "edit the rule file at this `path`; empty uses rules.yaml in your config directory")
	cmd.AddCommand(
		newHooksInstallCmd(&rulesPath),
		newHooksListCmd(&rulesPath),
		newHooksRemoveCmd(&rulesPath),
		newHooksSetCmd(&rulesPath),
		newHooksRunCmd(&rulesPath),
	)

	return cmd
}

func newHooksInstallCmd(rulesPath *string) *cobra.Command {
	var yes bool

	cmd := &cobra.Command{
		Use:   "install <owner>/<repo>[/<path>]@<ref>",
		Short: "Install or update a hook package from GitHub",
		Long: "Resolves the ref, a tag, a branch or a sha, to a commit, and downloads the " +
			"package at that commit. Then it shows what the package runs and asks you to " +
			"confirm. The hook runs with your rights and no sandbox, so read the package " +
			"before you install it.\n\n" +
			"When the package is already installed, this command updates it to the new " +
			"commit. It keeps the settings that the new manifest takes, and names the " +
			"settings it drops.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			spec, err := hooks.ParseSpec(args[0])
			if err != nil {
				return err
			}
			path, root, entries, err := loadHooks(*rulesPath)
			if err != nil {
				return err
			}
			id := spec.ID()
			if i := findHook(entries, id); i >= 0 {
				id = entries[i].ID()
			}

			sha, err := hookFetcher.ResolveSHA(cmd.Context(), spec.Owner, spec.Repo, spec.Ref)
			if err != nil {
				return err
			}
			dir := hooks.PackageDir(root, id, sha)
			m, err := hooks.LoadManifest(dir)
			fetched := false
			if err != nil {
				if err := hookFetcher.Fetch(cmd.Context(), spec, sha, dir); err != nil {
					return err
				}
				fetched = true
				m, err = hooks.LoadManifest(dir)
			}
			installed := false
			defer func() {
				if fetched && !installed {
					_ = os.RemoveAll(dir)
				}
			}()
			if err != nil {
				return fmt.Errorf("%s at commit %s: %w", id, sha, err)
			}
			if len(m.OS) > 0 && !slices.Contains(m.OS, runtime.GOOS) {
				return fmt.Errorf("%s runs on %s, and this machine runs %s", id, strings.Join(m.OS, ", "), runtime.GOOS)
			}

			out := cmd.OutOrStdout()
			describeHook(out, spec, sha, m)
			if !yes && !confirmInstall(cmd.InOrStdin(), out) {
				return errors.New("the hook package is not installed")
			}

			var prev *rules.HookEntry
			var dropped []string
			err = rules.EditHooks(path, func(list []rules.HookEntry) ([]rules.HookEntry, error) {
				i := findHook(list, id)
				if i < 0 {
					return append(list, rules.HookEntry{Package: spec.Package(), Path: spec.Path, Ref: spec.Ref, SHA: sha}), nil
				}
				old := list[i]
				prev = &old
				kept := map[string]string{}
				for name, value := range old.Settings {
					if s, ok := m.Settings[name]; ok && s.Check(value) == nil {
						kept[name] = value
					} else {
						dropped = append(dropped, name)
					}
				}
				list[i].Ref, list[i].SHA, list[i].Settings = spec.Ref, sha, kept

				return list, nil
			})
			if err != nil {
				return fmt.Errorf("rule file %s: %w", path, err)
			}
			installed = true

			if prev == nil {
				fmt.Fprintf(out, "Installed %s at %s (%s).\n", id, spec.Ref, sha[:7])
			} else {
				fmt.Fprintf(out, "Updated %s from %s (%s) to %s (%s).\n", id, prev.Ref, prev.SHA[:7], spec.Ref, sha[:7])
			}
			if len(dropped) > 0 {
				slices.Sort(dropped)
				fmt.Fprintf(out, "Dropped these settings, because the new manifest does not take their values: %s.\n", strings.Join(dropped, ", "))
			}
			fmt.Fprintln(out, reloadHint)

			return nil
		},
	}
	cmd.Flags().BoolVar(&yes, "yes", false, "install without the prompt")

	return cmd
}

// describeHook shows what a package runs, before the operator confirms.
func describeHook(w io.Writer, spec hooks.Spec, sha string, m hooks.Manifest) {
	systems := "any"
	if len(m.OS) > 0 {
		systems = strings.Join(m.OS, ", ")
	}
	path := spec.Path
	if path == "" {
		path = "the repository root"
	}
	fmt.Fprintf(w, "Name:       %s\n", m.Name)
	fmt.Fprintf(w, "Repository: https://github.com/%s\n", spec.Package())
	fmt.Fprintf(w, "Path:       %s\n", path)
	fmt.Fprintf(w, "Ref:        %s\n", spec.Ref)
	fmt.Fprintf(w, "Commit:     %s\n", sha)
	fmt.Fprintf(w, "Systems:    %s\n", systems)
	fmt.Fprintf(w, "Time limit: %d seconds\n", int(m.Timeout/time.Second))
	fmt.Fprintln(w, "Events:")
	for _, e := range hooks.Events {
		if argv, ok := m.Events[e]; ok {
			fmt.Fprintf(w, "  %s: %q\n", e, argv)
		}
	}
	if len(m.Settings) == 0 {
		fmt.Fprintln(w, "Settings:   none")
	} else {
		fmt.Fprintln(w, "Settings:")
		for _, name := range slices.Sorted(maps.Keys(m.Settings)) {
			s := m.Settings[name]
			fmt.Fprintf(w, "  %s (%s, default %s): %s\n", name, s.Type, s.Default, s.Description)
		}
	}
	fmt.Fprintln(w, "The hook runs with your rights and no sandbox. Install it only when you trust its code.")
}

// confirmInstall asks on in, and takes y or yes alone as consent.
func confirmInstall(in io.Reader, out io.Writer) bool {
	fmt.Fprint(out, "Install? [y/N] ")
	answer, _ := bufio.NewReader(in).ReadString('\n')
	answer = strings.ToLower(strings.TrimSpace(answer))

	return answer == "y" || answer == "yes"
}

func newHooksListCmd(rulesPath *string) *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List the installed hook packages",
		Long: "Shows one line for each package in the rule file: the package, its ref, its " +
			"commit and its settings. A setting that the rule file does not set shows its default.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			_, root, entries, err := loadHooks(*rulesPath)
			if err != nil && !errors.Is(err, rules.ErrMissing) {
				return err
			}
			out := cmd.OutOrStdout()
			if len(entries) == 0 {
				fmt.Fprintln(out, "No hook is installed.")

				return nil
			}

			w := tabwriter.NewWriter(out, 0, 0, 2, ' ', 0)
			for _, e := range entries {
				settings := e.Settings
				if list, err := hooks.Resolve(root, []rules.HookEntry{e}, runtime.GOOS); err == nil {
					settings = list[0].Settings
				} else {
					fmt.Fprintln(cmd.ErrOrStderr(), err)
				}
				fmt.Fprintf(w, "%s\t%s\t%s", e.ID(), e.Ref, e.SHA[:7])
				if len(settings) > 0 {
					pairs := []string{}
					for _, name := range slices.Sorted(maps.Keys(settings)) {
						pairs = append(pairs, name+"="+settings[name])
					}
					fmt.Fprintf(w, "\t%s", strings.Join(pairs, " "))
				}
				fmt.Fprintln(w)
			}

			return w.Flush()
		},
	}
}

func newHooksRemoveCmd(rulesPath *string) *cobra.Command {
	return &cobra.Command{
		Use:   "remove <owner>/<repo>[/<path>]",
		Short: "Remove a hook package from the rule file",
		Long: "Removes the package from the hooks list of the rule file. Its state " +
			"directory stays, so a later install of the package finds its state again.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := hookID(args[0])
			if err != nil {
				return err
			}
			path, root, _, err := loadHooks(*rulesPath)
			if err != nil {
				return err
			}
			var removed rules.HookEntry
			err = rules.EditHooks(path, func(list []rules.HookEntry) ([]rules.HookEntry, error) {
				i := findHook(list, id)
				if i < 0 {
					return nil, notInstalled(id)
				}
				removed = list[i]

				return slices.Delete(list, i, i+1), nil
			})
			if err != nil {
				return err
			}

			out := cmd.OutOrStdout()
			fmt.Fprintf(out, "Removed %s. Its state stays in %s.\n", removed.ID(), hooks.StateDir(root, removed.ID()))
			fmt.Fprintln(out, reloadHint)

			return nil
		},
	}
}

func newHooksSetCmd(rulesPath *string) *cobra.Command {
	return &cobra.Command{
		Use:   "set <owner>/<repo>[/<path>] <name>=<value>",
		Short: "Set a setting of an installed hook package",
		Long: "Writes the setting into the rule file. The manifest of the package must " +
			"define the setting, and a bool setting takes true or false only.",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := hookID(args[0])
			if err != nil {
				return err
			}
			name, value, ok := strings.Cut(args[1], "=")
			if !ok || name == "" {
				return fmt.Errorf("%q is not name=value, such as takeover=true", args[1])
			}
			path, root, _, err := loadHooks(*rulesPath)
			if err != nil {
				return err
			}
			err = rules.EditHooks(path, func(list []rules.HookEntry) ([]rules.HookEntry, error) {
				i := findHook(list, id)
				if i < 0 {
					return nil, notInstalled(id)
				}
				e := list[i]
				e.Settings = maps.Clone(e.Settings)
				if e.Settings == nil {
					e.Settings = map[string]string{}
				}
				e.Settings[name] = value
				if _, err := hooks.Resolve(root, []rules.HookEntry{e}, runtime.GOOS); err != nil {
					return nil, err
				}
				list[i] = e

				return list, nil
			})
			if err != nil {
				return err
			}

			out := cmd.OutOrStdout()
			fmt.Fprintf(out, "Set %s=%s for %s.\n", name, value, id)
			fmt.Fprintln(out, reloadHint)

			return nil
		},
	}
}

func newHooksRunCmd(rulesPath *string) *cobra.Command {
	return &cobra.Command{
		Use:   "run <owner>/<repo>[/<path>] <event>",
		Short: "Run one hook of an installed package now",
		Long: "Runs the hook of the event, start, stop, busy or idle, from this terminal. It " +
			"uses the environment and the time limit that the bridge uses, and prints the " +
			"output of the hook. It exits with status 1 when the hook fails.\n\n" +
			"On macOS, a hook can cause a permission prompt the first time it runs. A bridge " +
			"under launchd cannot answer that prompt. Run the hook here once and grant the " +
			"permission before the bridge relies on it.",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := hookID(args[0])
			if err != nil {
				return err
			}
			event := args[1]
			_, root, entries, err := loadHooks(*rulesPath)
			if err != nil {
				return err
			}
			i := findHook(entries, id)
			if i < 0 {
				return notInstalled(id)
			}
			list, err := hooks.Resolve(root, entries[i:i+1], runtime.GOOS)
			if err != nil {
				return err
			}
			h := list[0]
			argv, ok := h.Events[event]
			if !ok {
				defined := slices.DeleteFunc(slices.Clone(hooks.Events), func(e string) bool { _, has := h.Events[e]; return !has })

				return fmt.Errorf("%s has no %s hook; it has %s", h.ID, event, strings.Join(defined, ", "))
			}
			cfg, err := config.Load()
			if err != nil && !errors.Is(err, config.ErrNotLoggedIn) {
				return err
			}

			out := cmd.OutOrStdout()
			limit := hookLimit(h, 0)
			timedOut, err := execHook(h, event, argv, cfg.BridgeID, limit, out)
			switch {
			case err == nil:
				fmt.Fprintf(out, "The %s hook of %s ran.\n", event, h.ID)

				return nil
			case timedOut:
				return fmt.Errorf("the %s hook of %s ran past its time limit of %d seconds and was stopped", event, h.ID, int(limit/time.Second))
			default:
				return fmt.Errorf("the %s hook of %s failed: %w", event, h.ID, err)
			}
		},
	}
}

// loadHooks reads the hooks list of the rule file, and finds the config
// directory that holds the packages.
func loadHooks(rulesPath string) (path, root string, entries []rules.HookEntry, err error) {
	path, err = rulesPathOr(rulesPath)
	if err != nil {
		return "", "", nil, err
	}
	set, err := rules.Load(path, rules.Defaults{})
	if errors.Is(err, rules.ErrMissing) {
		return "", "", nil, fmt.Errorf("rule file %s: %w", path, err)
	}
	if err != nil {
		return "", "", nil, fmt.Errorf("rule file %s: %w\nFix the file by hand, then run the command again", path, err)
	}
	root, err = config.Dir()
	if err != nil {
		return "", "", nil, err
	}

	return path, root, set.Hooks(), nil
}

// findHook finds a package by its id. GitHub names ignore case, and so does
// the check of the rule file.
func findHook(entries []rules.HookEntry, id string) int {
	return slices.IndexFunc(entries, func(e rules.HookEntry) bool { return strings.EqualFold(e.ID(), id) })
}

// hookID reads a package name that names no ref.
func hookID(arg string) (string, error) {
	if name, _, ok := strings.Cut(arg, "@"); ok {
		return "", fmt.Errorf("name the package without a ref, such as %s", name)
	}

	return arg, nil
}

func notInstalled(id string) error {
	return fmt.Errorf("hook package %s is not installed; run `loupe bridge hooks list` to see the installed ones", id)
}
