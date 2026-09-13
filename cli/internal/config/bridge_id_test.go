package config

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/zalando/go-keyring"
)

const seededID = "3f2504e0-4f89-41d3-9a0c-0305e82c3301"

// useTempConfigHome points the config dir at a temp dir. HOME matters as well
// as XDG_CONFIG_HOME, because os.UserConfigDir reads HOME on macOS.
func useTempConfigHome(t *testing.T) {
	t.Helper()

	dir := t.TempDir()
	t.Setenv("XDG_CONFIG_HOME", dir)
	t.Setenv("HOME", dir)
}

func storedConfigPath(t *testing.T) string {
	t.Helper()

	d, err := Dir()
	if err != nil {
		t.Fatalf("Dir: %v", err)
	}

	return filepath.Join(d, "config.json")
}

func seedConfigFile(t *testing.T, content string) {
	t.Helper()

	d, err := Dir()
	if err != nil {
		t.Fatalf("Dir: %v", err)
	}
	if err := os.MkdirAll(d, 0o700); err != nil {
		t.Fatalf("create config dir: %v", err)
	}
	if err := os.WriteFile(storedConfigPath(t), []byte(content), 0o600); err != nil {
		t.Fatalf("seed config: %v", err)
	}
}

func readStoredForTest(t *testing.T) Config {
	t.Helper()

	b, err := os.ReadFile(storedConfigPath(t))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		t.Fatalf("parse config: %v", err)
	}

	return c
}

// TestEnsureBridgeIDHealsWhatItReads covers the states a config file reaches: a
// first run, an older login, and a file an operator edited by hand. None of
// them may stop the bridge.
func TestEnsureBridgeIDHealsWhatItReads(t *testing.T) {
	cases := []struct {
		name    string
		file    string
		absent  bool
		keepsID bool
	}{
		{name: "no config file", absent: true},
		{name: "logged in with no id", file: `{"baseUrl":"https://example.test"}`},
		{name: "empty id", file: `{"baseUrl":"https://example.test","bridgeId":""}`},
		{name: "id that is not a uuid", file: `{"baseUrl":"https://example.test","bridgeId":"not-a-uuid"}`},
		{name: "id of the wrong length", file: `{"bridgeId":"0123456789abcdef"}`},
		{name: "id with a bad separator", file: `{"bridgeId":"3f2504e04f89-41d3-9a0c-0305e82c3301"}`},
		{name: "id with a non-hex digit", file: `{"bridgeId":"3f2504e0-4f89-41d3-9a0c-0305e82c330z"}`},
		{name: "blank file", file: "  \n"},
		{name: "valid id", file: `{"bridgeId":"` + seededID + `"}`, keepsID: true},
		{name: "valid id in upper case", file: `{"bridgeId":"` + strings.ToUpper(seededID) + `"}`, keepsID: true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			keyring.MockInit()
			useTempConfigHome(t)
			if !tc.absent {
				seedConfigFile(t, tc.file)
			}

			got, err := EnsureBridgeID()
			if err != nil {
				t.Fatalf("EnsureBridgeID: %v", err)
			}
			if !isUUID(got) {
				t.Fatalf("EnsureBridgeID returned %q, want a uuid", got)
			}

			if tc.keepsID {
				var want Config
				if err := json.Unmarshal([]byte(tc.file), &want); err != nil {
					t.Fatalf("parse case file: %v", err)
				}
				if got != want.BridgeID {
					t.Fatalf("EnsureBridgeID replaced a valid id: got %q want %q", got, want.BridgeID)
				}
			} else if strings.EqualFold(got, seededID) {
				t.Fatalf("EnsureBridgeID returned the seeded id %q for a case that must generate one", got)
			}

			if onDisk := readStoredForTest(t).BridgeID; onDisk != got {
				t.Fatalf("config file holds %q, want the returned id %q", onDisk, got)
			}
		})
	}
}

// TestEnsureBridgeIDKeepsTheBaseURL pins that the heal writes the whole config
// back. A heal that dropped the base URL would log the operator out.
func TestEnsureBridgeIDKeepsTheBaseURL(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	seedConfigFile(t, `{"baseUrl":"https://example.test"}`)

	if _, err := EnsureBridgeID(); err != nil {
		t.Fatalf("EnsureBridgeID: %v", err)
	}

	if got := readStoredForTest(t).BaseURL; got != "https://example.test" {
		t.Fatalf("base URL is %q after the heal, want it untouched", got)
	}
}

// TestEnsureBridgeIDKeepsAFileToken covers the headless host, where the config
// file holds the token itself.
func TestEnsureBridgeIDKeepsAFileToken(t *testing.T) {
	keyring.MockInitWithError(errors.New("no keychain here"))
	t.Cleanup(keyring.MockInit)
	useTempConfigHome(t)
	seedConfigFile(t, `{"baseUrl":"https://example.test","token":"sk-fallback"}`)

	if _, err := EnsureBridgeID(); err != nil {
		t.Fatalf("EnsureBridgeID: %v", err)
	}

	if got := readStoredForTest(t).Token; got != "sk-fallback" {
		t.Fatalf("token is %q after the heal, want it untouched", got)
	}
}

// TestEnsureBridgeIDSurvivesAReload is the point of the file: the id must name
// the same bridge across runs, so the server sees one reporter per machine.
func TestEnsureBridgeIDSurvivesAReload(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	first, err := EnsureBridgeID()
	if err != nil {
		t.Fatalf("first EnsureBridgeID: %v", err)
	}
	second, err := EnsureBridgeID()
	if err != nil {
		t.Fatalf("second EnsureBridgeID: %v", err)
	}
	if first != second {
		t.Fatalf("id changed between calls: %q then %q", first, second)
	}

	b, err := os.ReadFile(storedConfigPath(t))
	if err != nil {
		t.Fatalf("read config: %v", err)
	}
	t.Logf("config.json holds:\n%s", b)

	cfg, err := Load()
	if !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("Load with no credentials: want ErrNotLoggedIn, got %v", err)
	}
	if cfg.BridgeID != first {
		t.Fatalf("Load read id %q, want %q", cfg.BridgeID, first)
	}
}

// TestEnsureBridgeIDGeneratesAVersion4UUID checks the bits the format alone
// does not: the version nibble and the RFC 4122 variant.
func TestEnsureBridgeIDGeneratesAVersion4UUID(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	got, err := EnsureBridgeID()
	if err != nil {
		t.Fatalf("EnsureBridgeID: %v", err)
	}
	if !isUUID(got) {
		t.Fatalf("id %q is not in the canonical form", got)
	}
	if got[14] != '4' {
		t.Fatalf("id %q has version %c, want 4", got, got[14])
	}
	if !strings.ContainsRune("89ab", rune(got[19])) {
		t.Fatalf("id %q has variant %c, want one of 8, 9, a or b", got, got[19])
	}
}

// TestNewUUIDReturnsADifferentValueEachTime guards against an id that looks
// valid and names every machine the same.
func TestNewUUIDReturnsADifferentValueEachTime(t *testing.T) {
	seen := map[string]bool{}
	for range 100 {
		id, err := newUUID()
		if err != nil {
			t.Fatalf("newUUID: %v", err)
		}
		if seen[id] {
			t.Fatalf("newUUID repeated %q", id)
		}
		seen[id] = true
	}
}

// TestEnsureBridgeIDIsSafeForConcurrentCallers pins that callers in one process
// agree. Run it with -race.
func TestEnsureBridgeIDIsSafeForConcurrentCallers(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	const callers = 16
	ids := make([]string, callers)
	errs := make([]error, callers)

	var wg sync.WaitGroup
	wg.Add(callers)
	for i := range callers {
		go func() {
			defer wg.Done()
			ids[i], errs[i] = EnsureBridgeID()
		}()
	}
	wg.Wait()

	for i, err := range errs {
		if err != nil {
			t.Fatalf("caller %d: %v", i, err)
		}
	}
	for i, id := range ids {
		if id != ids[0] {
			t.Fatalf("caller %d got %q, caller 0 got %q", i, id, ids[0])
		}
	}
	if onDisk := readStoredForTest(t).BridgeID; onDisk != ids[0] {
		t.Fatalf("config file holds %q, want %q", onDisk, ids[0])
	}
}

// TestLoadTreatsABlankFileAsNoCredentials pins the reader the bridge id shares
// with Load. A blank file used to give a JSON parse error.
func TestLoadTreatsABlankFileAsNoCredentials(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	seedConfigFile(t, "\n")

	if _, err := Load(); !errors.Is(err, ErrNotLoggedIn) {
		t.Fatalf("want ErrNotLoggedIn, got %v", err)
	}
}

// TestEnsureBridgeIDReportsAnUnparseableConfig draws the line on healing. A
// file that is not JSON already stops Load, so overwriting it here would throw
// the operator's token away.
func TestEnsureBridgeIDReportsAnUnparseableConfig(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	seedConfigFile(t, "{ this is not json")

	if _, err := EnsureBridgeID(); err == nil {
		t.Fatal("EnsureBridgeID accepted an unparseable config")
	}
}

// TestSaveKeepsAnExistingBridgeID covers a second `loupe login`, which writes a
// Config that carries no id. The machine must keep the bridge it already is.
func TestSaveKeepsAnExistingBridgeID(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)

	want, err := EnsureBridgeID()
	if err != nil {
		t.Fatalf("EnsureBridgeID: %v", err)
	}

	if err := Save(Config{BaseURL: "https://example.test", Token: "sk-tok"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	if onDisk := readStoredForTest(t).BridgeID; onDisk != want {
		t.Fatalf("Save left id %q, want %q", onDisk, want)
	}
	again, err := EnsureBridgeID()
	if err != nil {
		t.Fatalf("second EnsureBridgeID: %v", err)
	}
	if again != want {
		t.Fatalf("EnsureBridgeID returned %q after a login, want %q", again, want)
	}
}

// TestSaveTakesTheBridgeIDItIsGiven keeps the carry-forward from overriding an
// explicit value.
func TestSaveTakesTheBridgeIDItIsGiven(t *testing.T) {
	keyring.MockInit()
	useTempConfigHome(t)
	seedConfigFile(t, `{"baseUrl":"https://example.test","bridgeId":"`+seededID+`"}`)

	replacement := "7c9e6679-7425-40de-944b-e07fc1f90ae7"
	if err := Save(Config{BaseURL: "https://example.test", Token: "sk-tok", BridgeID: replacement}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	if onDisk := readStoredForTest(t).BridgeID; onDisk != replacement {
		t.Fatalf("Save stored id %q, want %q", onDisk, replacement)
	}
}
