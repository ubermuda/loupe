package cmd

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"syscall"
	"time"

	"github.com/spf13/cobra"
	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/config"
	"github.com/ubermuda/loupe/cli/internal/hooks"
	"github.com/ubermuda/loupe/cli/internal/outbound"
	"github.com/ubermuda/loupe/cli/internal/rules"
	"github.com/ubermuda/loupe/cli/internal/transport"
	"github.com/ubermuda/loupe/cli/internal/update"
)

// refreshTimeout bounds a credentials fetch. http.DefaultClient has none at
// all, so a single unanswered request would stall reconnection for good — the
// bridge would sit there looking healthy and never receive anything again.
const refreshTimeout = 15 * time.Second

// lookPath resolves the worker binary. Tests replace it.
var lookPath = exec.LookPath

// apiClient is for short request/response calls only. The SSE subscription must
// keep its own timeout-free client: a stream is meant to stay open.
func apiClient(cfg config.Config) *api.Client {
	hc := &http.Client{Timeout: refreshTimeout}

	return api.NewWithSource(cfg.BaseURL, tokenSource(cfg, hc), hc)
}

func newBridgeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "bridge",
		Short: "Bridge Loupe events into local workers",
	}
	cmd.AddCommand(newBridgeRunCmd(), newBridgeReloadCmd(), newBridgePreflightCmd(), newBridgeHooksCmd())

	return cmd
}

// bridgeRunOptions are the flags of `loupe bridge run`.
type bridgeRunOptions struct {
	rulesPath, permissionMode, model, logFile string
	// resumeFile and rolledBackFrom carry a handover from a former image.
	resumeFile, rolledBackFrom string
}

func newBridgeRunCmd() *cobra.Command {
	var o bridgeRunOptions

	cmd := &cobra.Command{
		Use:   "run",
		Short: "Watch a Loupe board and run a Claude Code worker for each rule an event matches",
		Long: "Reads the rule file, rules.yaml in your config directory, and runs " +
			"`claude -p --session-id <uuid> -- <prompt>` for every event a rule matches. Each rule names an event, " +
			"a project and a column, and the prompt its worker runs. The projects map in " +
			"the file gives each project the directory its workers run in.\n\n" +
			"The bridge refuses to start without the file, and checks every project and " +
			"column slug against the server first. It follows every project you own on " +
			"one connection, and ignores the events of a project the file does not map.\n\n" +
			"Run `loupe bridge reload` to apply a changed rule file without a restart. " +
			"A second bridge on the same rule file refuses to start.\n\n" +
			"The defaults block of the rule file and each rule win over --permission-mode " +
			"and --model. The flags fill a value that both leave empty. A worker has no " +
			"terminal, so it cannot answer a permission prompt: with no mode, claude " +
			"denies every tool call that needs approval.\n\n" +
			"Set maxWorkers in the rule file to bound the workers that run at once. Events past the " +
			"bound wait in a queue and start in arrival order, except that an event waits " +
			"while its card has a worker. The bridge writes one JSON " +
			"object per line to stdout and to --log-file.",
		RunE: func(cmd *cobra.Command, _ []string) error {
			return startBridge(cmd, o)
		},
	}
	cmd.Flags().StringVar(&o.rulesPath, "rules", "", "read rules from this `path`; empty uses rules.yaml in your config directory")
	cmd.Flags().StringVar(&o.permissionMode, "permission-mode", "", "pass this `mode` to every `claude` when neither its rule nor the defaults block of the rule file sets one; empty passes no flag, and a worker cannot answer a prompt")
	cmd.Flags().StringVar(&o.model, "model", "", "pass this `model` to every `claude` when neither its rule nor the defaults block of the rule file sets one; empty passes no flag")
	cmd.Flags().Int(maxWorkersFlag, rules.DefaultMaxWorkers, "does nothing; set maxWorkers in rules.yaml")
	cmd.Flags().MarkDeprecated(maxWorkersFlag, "it does nothing; set maxWorkers in rules.yaml instead")
	cmd.Flags().StringVar(&o.logFile, "log-file", "", "append the JSON log to this `path`; empty uses bridge.log in your config directory")
	cmd.Flags().StringVar(&o.resumeFile, resumeHandoverFlag, "", "take over the bridge that a former image handed over in this `file`")
	cmd.Flags().StringVar(&o.rolledBackFrom, rolledBackFromFlag, "", "the `version` that handed the bridge back")
	cmd.Flags().MarkHidden(resumeHandoverFlag)
	cmd.Flags().MarkHidden(rolledBackFromFlag)

	return cmd
}

// bridgeLog is the log of a bridge and the file it appends to.
type bridgeLog struct {
	path string
	file *os.File
	log  *slog.Logger
}

func openBridgeLog(cmd *cobra.Command, logFile string) (*bridgeLog, error) {
	path := logFile
	if path == "" {
		path = defaultLogPath()
	}
	f, err := openLogFile(path)
	if err != nil {
		return nil, err
	}

	return &bridgeLog{path: path, file: f, log: newBridgeLogger(bridgeLogWriter(f, cmd.OutOrStdout()))}, nil
}

func startBridge(cmd *cobra.Command, o bridgeRunOptions) error {
	defaults := rules.Defaults{PermissionMode: o.permissionMode, Model: o.model}
	if err := defaults.Check(); err != nil {
		return err
	}
	path, err := rulesPathOr(o.rulesPath)
	if err != nil {
		return err
	}
	if o.resumeFile == "" {
		return runBridgeOn(cmd, o, defaults, path, nil, nil, nil)
	}

	// A resumed image logs from the start, because a failure before the
	// health hands the bridge back, and the operator reads why in the log.
	bl, err := openBridgeLog(cmd, o.logFile)
	if err != nil {
		return err
	}
	defer bl.file.Close()
	sock, err := socketPath(path)
	if err != nil {
		return err
	}
	b, control, err := resumeBridge(bl.log, path, sock, o.resumeFile, o.rolledBackFrom)
	if err != nil {
		bl.log.Error("update_resume_failed", "file", o.resumeFile, "error", err.Error())

		return err
	}
	err = runBridgeOn(cmd, o, defaults, path, bl, b, control)
	if err != nil {
		b.rollbackAtStart(err)
	}
	b.dropResumeFile()
	control.Close()
	b.close()
	b.lock.Close()

	return err
}

// runBridgeOn starts the bridge. A resumed image passes its log, its handover
// and the control socket it took over. Otherwise it takes the lock and the
// socket itself.
func runBridgeOn(cmd *cobra.Command, o bridgeRunOptions, defaults rules.Defaults, path string, bl *bridgeLog, b *bridgeUpdate, control net.Listener) error {
	migration := migrateAccounts(path)
	set, err := rules.Load(path, defaults)
	if err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	hookList, err := resolveHooks(set)
	if err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	claude, err := resolveClaude()
	if err != nil {
		return err
	}

	cfg, err := config.Load()
	if err != nil {
		return err
	}
	if err := set.Check(cmd.Context(), apiClient(cfg)); err != nil {
		return fmt.Errorf("rule file %s: %w", path, err)
	}
	// A bridge with no stored credentials reaches neither the stream nor
	// the reporting endpoints, so it stops here rather than starting a
	// worker whose run it can report nothing about.
	bridgeID, err := config.EnsureBridgeID()
	if errors.Is(err, config.ErrNotLoggedIn) {
		return config.ErrNotLoggedIn
	}
	if err != nil {
		return fmt.Errorf("bridge id: %w", err)
	}
	cfg.BridgeID = bridgeID

	if bl == nil {
		if bl, err = openBridgeLog(cmd, o.logFile); err != nil {
			return err
		}
		defer bl.file.Close()
	}
	migration.log(bl.log, path)

	if b == nil {
		sock, err := socketPath(path)
		if err != nil {
			return err
		}
		// The listener closes first, because a deferred call runs in reverse order.
		lock, err := lockBridge(path, sock)
		if err != nil {
			return err
		}
		defer lock.Close()
		if control, err = listenControl(sock); err != nil {
			return err
		}
		defer control.Close()
		if b, err = newBridgeUpdate(bl.log, path, lock, control); err != nil {
			return err
		}
		defer b.close()
		b.recovered = leftoverHandover(b.file, bl.log)
	}
	cursorFile, err := cursorPath(path)
	if err != nil {
		return err
	}

	r := &router{
		log:        bl.log,
		worker:     defaultWorkerOps(),
		bridgeID:   bridgeID,
		control:    control,
		source:     newReloadSource(path, defaults, cfg, b.lock),
		update:     b,
		hookRunner: newHookRunner(hookList, bridgeID, bl.log),
		claude:     claude,
		cursorFile: cursorFile,
		agent:      cfg.AgentAccount,
		pushLogin:  checkAgentAccount(cmd.Context(), cfg.AgentAccount, bl.log),
	}
	if dir, err := config.Dir(); err == nil {
		r.assumeAutoUpdate = migrateAutoUpdate(bl.log, dir, path, o.resumeFile != "")
	}
	r.set.Store(set)
	// The cache is read before subscribe, whose adopt dispatches.
	r.baseURL = cfg.BaseURL
	r.usePauseCache(pausePath())
	cleanLaunchScripts(defaultScriptDir(), time.Now(), r.log)
	logBridgeStart(r.log, cmd, set, path, bl.path, bridgeID)
	warnUnknownModes(r.log, set)
	warnAgentsOff(r.log, set)

	return subscribe(cmd, cfg, r)
}

// maxWorkersFlag stays declared, so a service that still passes it starts.
const maxWorkersFlag = "max-workers"

// logBridgeStart warns when --max-workers is set, then logs bridge_started.
func logBridgeStart(log *slog.Logger, cmd *cobra.Command, set *rules.Set, path, logPath, bridgeID string) {
	if cmd.Flags().Changed(maxWorkersFlag) {
		flag, _ := cmd.Flags().GetInt(maxWorkersFlag)
		message := fmt.Sprintf("--max-workers does nothing. The bridge runs at most %d workers, the maxWorkers of rules.yaml. Set maxWorkers in rules.yaml and remove the flag.", set.MaxWorkers())
		if flag != set.MaxWorkers() {
			message = fmt.Sprintf("--max-workers %d does nothing. The bridge runs at most %d workers, the maxWorkers of rules.yaml, not %d. Set maxWorkers in rules.yaml and remove the flag.", flag, set.MaxWorkers(), flag)
		}
		log.Warn("max_workers_flag_ignored", "flag_value", flag, "max_workers", set.MaxWorkers(), "message", message)
	}
	log.Info("bridge_started", "rules", path, "projects", set.Projects(), "work_kinds", set.WorkKinds(),
		"max_workers", set.MaxWorkers(), "worker_pools", poolSizes(set), "log_file", logPath, "bridge_id", bridgeID)
}

// poolSizes names each worker pool and its size, in name order, such as
// default=3,quick=1.
func poolSizes(set *rules.Set) string {
	pools := set.Pools()
	parts := make([]string, 0, len(pools))
	for _, name := range slices.Sorted(maps.Keys(pools)) {
		parts = append(parts, fmt.Sprintf("%s=%d", name, pools[name]))
	}

	return strings.Join(parts, ",")
}

// warnUnknownModes names each permission mode this build does not know. A newer
// claude may accept it, so the bridge starts, and a typo shows in the log.
func warnUnknownModes(log *slog.Logger, set *rules.Set) {
	for _, mode := range set.UnknownPermissionModes() {
		log.Warn("permission_mode_unknown", "mode", mode, "known", rules.PermissionModes)
	}
}

// resolveHooks loads the installed hook packages the rule file lists.
func resolveHooks(set *rules.Set) ([]hooks.Hook, error) {
	root, err := config.Dir()
	if err != nil {
		return nil, err
	}

	return hooks.Resolve(root, set.Hooks(), runtime.GOOS)
}

// defaultRulesPath puts the rule file beside config.json.
func defaultRulesPath() (string, error) {
	dir, err := config.Dir()
	if err != nil {
		return "", err
	}

	return filepath.Join(dir, rules.FileName), nil
}

// bridgeLogWriter fans one line out to the log file and to out.
//
// The file comes first. io.MultiWriter stops at the first writer that fails,
// and a reader piped to stdout can leave mid-run, which breaks that pipe. The
// history must survive that.
func bridgeLogWriter(file, out io.Writer) io.Writer {
	return io.MultiWriter(file, out)
}

// newBridgeLogger writes one JSON object per line to w.
//
// slog names the message "msg". The bridge names it "event", because a reader
// selects lines by what happened rather than by a prose message.
func newBridgeLogger(w io.Writer) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{
		ReplaceAttr: func(_ []string, a slog.Attr) slog.Attr {
			if a.Key == slog.MessageKey {
				a.Key = "event"
			}

			return a
		},
	}))
}

// defaultLogPath names the log the bridge appends to. The temp directory is the
// fallback, because a host with no config directory must still keep a history.
func defaultLogPath() string {
	base, err := os.UserConfigDir()
	if err != nil {
		base = os.TempDir()
	}

	return filepath.Join(base, "loupe", "bridge.log")
}

// openLogFile appends to path and creates its directory. A supervisor's log is
// a history, so the file is never truncated.
func openLogFile(path string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, fmt.Errorf("create log directory: %w", err)
	}

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open log file: %w", err)
	}

	return f, nil
}

// subscribe blocks in the foreground until Ctrl-C or SIGTERM. Cancelling also
// kills every worker in flight, so the bridge leaves no unattended claude
// behind. It then waits for their reports before it returns.
//
// The shutdown drops the queue before it waits. Subscribe calls the handler on
// this goroutine, so no event can arrive after it returns.
//
// The server publishes every event of a project on its owner's topic too, so
// one topic follows every project, including one created after the start. The
// router ignores a project the file does not map.
func subscribe(cmd *cobra.Command, cfg config.Config, r *router) error {
	// stop runs last, after every worker has ended, on an early return too.
	r.hookRunner.start()
	defer r.hookRunner.stop()

	ctx, stop := signal.NotifyContext(cmd.Context(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	r.ctx = ctx

	// The queue closes after the workers, so it sees every report a dying worker
	// still makes, and its grace window can send them.
	queue := outbound.New(ctx, r.log)
	r.reports = queue
	r.runs = newRunReports(apiClient(cfg), r.log)
	defer queue.Close()

	set := r.rules()
	if r.bridgeID != "" {
		announce(ctx, apiClient(cfg), r.bridgeID, set, r.pushLogin, r.log)
	}
	events, err := startEvents(ctx, cfg, r.bridgeID, set)
	if err != nil {
		logUpgradeRequired(r.log, err)

		return err
	}
	r.projects, r.topic = set.Projects(), events.Topic
	if r.readCard == nil {
		r.readCard = apiClient(cfg).ReadCard
	}
	if r.resolvePin == nil {
		r.resolvePin = apiClient(cfg).ResolveExperimentPin
	}
	if r.ackCommand == nil {
		r.ackCommand = apiClient(cfg).AckCommand
	}
	if r.workAPI == nil {
		r.workAPI = apiClient(cfg)
	}
	if r.replay == nil {
		r.replay = func(ctx context.Context, after int64) (api.Replay, error) {
			return apiClient(cfg).Replay(ctx, r.bridgeID, after)
		}
	}
	if r.readHolds == nil {
		r.readHolds = apiClient(cfg).CardHolds
	}
	r.applyFlags(events)
	r.restoreState(events.Head)
	var updates *updater
	var watched <-chan struct{}
	if r.bridgeID != "" {
		hb := newHeartbeater(ctx, queue, apiClient(cfg), r.bridgeID, heartbeatBody(set, r.pushLogin), heartbeatInterval(events), r.log)
		if dir, err := config.Dir(); err != nil {
			r.log.Warn("update_skipped", "reason", err.Error())
		} else {
			hook := logStaged(r.log, version)
			if r.update != nil {
				hook = r.update.handover(r, version)
			}
			updates = newUpdater(r.log, version, dir, func() bool { return autoUpdateOn(r.rules(), r.assumeAutoUpdate) }, hook)
			if r.update != nil {
				updates.executable = r.update.installed
				if r.update.crashedFrom != "" {
					updates.markRolledBack(r.update.crashedFrom)
				}
			}
			updates.install = installMethod(updates.executable)
			hb.onRange, hb.update = updates.setRange, updates.state
		}
		if r.update != nil {
			hb.onSent = r.update.markBeat
		}
		hb.onReply, hb.onLost = r.onHeartbeatReply, r.loseClaims
		// Adopted runs can end on their own goroutines, and read heartbeat
		// under mu.
		r.mu.Lock()
		hb.paused = r.personPaused
		r.heartbeat = hb
		r.notePoolsLocked()
		r.noteClaimsLocked()
		r.mu.Unlock()
		r.hookRunner.attach(r.heartbeat)
		r.heartbeat.start()
	}
	if r.update != nil && r.update.resumed != nil {
		watched = r.update.watchHealth(ctx, r, updates)
	} else if updates != nil {
		updates.start(ctx)
	}
	// The control socket starts last, so no reload races the writes above.
	var served <-chan struct{}
	if r.control != nil {
		served = serveControl(ctx, r.control, controlOps{
			reload: func(ctx context.Context) reloadResult { return r.reload(ctx, r.source) },
			update: func(ctx context.Context, reply func(updateResult)) updateResult {
				if updates == nil {
					return updateResult{From: version, Outcome: outcomeFailed, Problem: "this bridge runs no update checks"}
				}

				return updates.checkNow(ctx, func(to string) {
					reply(updateResult{OK: true, From: version, To: to, Outcome: outcomeHandingOver})
				})
			},
		})
		r.log.Info("control_listening", "socket", r.control.Addr().String())
	}

	err = transport.Subscribe(ctx, &http.Client{}, events.HubURL, []string{events.Topic}, jwtRefresher(cfg, r.bridgeID, events.JWT, r.onRefresh), r.handler())
	failed := err != nil && ctx.Err() == nil
	if failed {
		logUpgradeRequired(r.log, err)
	}
	r.shutdown()
	r.wg.Wait()
	// The reports stay on the server, so a pending one is dropped rather than
	// sent. Its goroutines return once the context ends.
	stop()
	if r.heartbeat != nil {
		r.heartbeat.wait()
	}
	if watched != nil {
		<-watched
	}
	if updates != nil {
		updates.stop()
	}
	if served != nil {
		<-served
	}
	if failed {
		return err
	}

	return nil
}

// logStaged is the hook of a staged release in a bridge that cannot hand over.
func logStaged(log *slog.Logger, from string) stagedHook {
	return func(_ context.Context, c update.Candidate, path string) stagedOutcome {
		log.Info("update_staged", "from", from, "to", c.Version.String(), "path", path)

		return stagedDeferred
	}
}

// announce sends one heartbeat before the first GET /api/events, because the
// server serves the events only to a bridge whose heartbeat names the
// work-requests capability. A failure leaves GET /api/events to answer.
func announce(ctx context.Context, client heartbeatSender, bridgeID string, set *rules.Set, pushLogin string, log *slog.Logger) {
	body := heartbeatBody(set, pushLogin)
	body.Capabilities = slices.Concat(bridgeCapabilities, body.Capabilities)
	if _, err := client.Heartbeat(ctx, bridgeID, body); err != nil {
		log.Warn("start_heartbeat_failed", "error", err.Error())
	}
}

// logUpgradeRequired names the refusal of a server that serves no bridge
// without work requests, because the bridge then stops rather than retry.
func logUpgradeRequired(log *slog.Logger, err error) {
	if errors.Is(err, api.ErrUpgradeRequired) {
		log.Error("bridge_upgrade_required", "error", err.Error(),
			"message", "The server serves events only to a bridge that runs work requests. Upgrade the loupe CLI and map the work kinds under work: in rules.yaml. The bridge stops.")
	}
}

// startEvents reads GET /api/events, and fails when it does not list a mapped
// project.
func startEvents(ctx context.Context, cfg config.Config, bridgeID string, set *rules.Set) (api.Events, error) {
	events, err := apiClient(cfg).Events(ctx, bridgeID)
	if err != nil {
		return events, err
	}
	if missing := missingProjects(set, events); len(missing) > 0 {
		return events, fmt.Errorf("GET /api/events does not list %s, so no event of theirs can reach the bridge", strings.Join(missing, ", "))
	}

	return events, nil
}

// heartbeatBody names the projects the rule file maps, by id, the CLI
// version, the capabilities of the work map, the bridge name and the login
// that workers push as.
func heartbeatBody(set *rules.Set, pushLogin string) api.Heartbeat {
	ids := []string{}
	for _, slug := range set.Projects() {
		ids = append(ids, set.ProjectID(slug))
	}

	name := set.Name()

	return api.Heartbeat{Projects: ids, CLIVersion: cliVersion(), Capabilities: set.Capabilities(), Name: &name, PushLogin: &pushLogin}
}

// missingProjects names the mapped projects that GET /api/events does not list:
// deleted, or no longer the user's. Their rules can never fire.
func missingProjects(set *rules.Set, events api.Events) []string {
	listed := map[string]bool{}
	for _, p := range events.Projects {
		listed[strings.ToLower(p.ID)] = true
	}
	var missing []string
	for _, slug := range set.Projects() {
		if !listed[set.ProjectID(slug)] {
			missing = append(missing, slug)
		}
	}

	return missing
}

// jwtRefresher hands out first for the first connection, then mints a fresh
// subscriber JWT per attempt and gives each fresh answer to onRefresh.
// Subscriber JWTs are short-lived, so a bridge left running would otherwise
// reconnect with an expired token forever once the first one lapsed. Subscribe
// calls it from one goroutine. A refusal to upgrade ends the subscription.
func jwtRefresher(cfg config.Config, bridgeID, first string, onRefresh func(api.Events)) transport.TokenFunc {
	return func(ctx context.Context) (string, error) {
		if first != "" {
			jwt := first
			first = ""

			return jwt, nil
		}
		events, err := apiClient(cfg).Events(ctx, bridgeID)
		if errors.Is(err, api.ErrUpgradeRequired) {
			return "", transport.Fatal(err)
		}
		if err != nil {
			return "", err
		}
		onRefresh(events)

		return events.JWT, nil
	}
}
