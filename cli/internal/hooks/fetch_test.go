package hooks

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

// entry is one member of a test tarball. A link sets typ and target.
type entry struct {
	name   string
	body   string
	mode   int64
	typ    byte
	target string
}

func tarball(t *testing.T, entries ...entry) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	// codeload writes a pax global header with the commit id first.
	if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeXGlobalHeader, Name: "pax_global_header", PAXRecords: map[string]string{"comment": sha}}); err != nil {
		t.Fatal(err)
	}
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Mode: e.mode, Typeflag: e.typ, Linkname: e.target}
		switch {
		case e.typ != 0:
		case strings.HasSuffix(e.name, "/"):
			h.Typeflag = tar.TypeDir
		default:
			h.Typeflag = tar.TypeReg
			h.Size = int64(len(e.body))
		}
		if h.Mode == 0 {
			h.Mode = 0o644
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if h.Typeflag == tar.TypeReg {
			if _, err := tw.Write([]byte(e.body)); err != nil {
				t.Fatal(err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}

	return buf.Bytes()
}

// serve answers the codeload tarball URL of acme/tool at sha.
func serve(t *testing.T, body []byte) *Fetcher {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/acme/tool/tar.gz/"+sha {
			http.NotFound(w, r)

			return
		}
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	return &Fetcher{CodeloadBase: srv.URL, Client: srv.Client()}
}

func dest(t *testing.T) string {
	t.Helper()

	return filepath.Join(t.TempDir(), "packages", "acme", "tool", sha)
}

func read(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	return string(data)
}

func TestResolveSHA(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Accept") != "application/vnd.github.sha" {
			http.Error(w, "accept", http.StatusBadRequest)

			return
		}
		switch r.URL.Path {
		case "/repos/acme/tool/commits/v1", "/repos/acme/tool/commits/feature/x":
			_, _ = w.Write([]byte(sha))
		case "/repos/acme/tool/commits/garbage":
			_, _ = w.Write([]byte("<html>"))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	f := &Fetcher{APIBase: srv.URL, Client: srv.Client()}

	for _, ref := range []string{"v1", "feature/x"} {
		got, err := f.ResolveSHA(context.Background(), "acme", "tool", ref)
		if err != nil || got != sha {
			t.Fatalf("%s: sha = %q, %v", ref, got, err)
		}
	}
	if _, err := f.ResolveSHA(context.Background(), "acme", "tool", "nope"); err == nil || !strings.Contains(err.Error(), `acme/tool has no ref "nope"`) {
		t.Fatalf("err = %v", err)
	}
	if _, err := f.ResolveSHA(context.Background(), "acme", "tool", "garbage"); err == nil || !strings.Contains(err.Error(), "is not 40 lowercase hex characters") {
		t.Fatalf("err = %v", err)
	}
}

func TestTheFetcherDefaults(t *testing.T) {
	f := &Fetcher{}
	if f.apiBase() != "https://api.github.com" || f.codeloadBase() != "https://codeload.github.com" || f.client().Timeout <= 0 {
		t.Fatalf("defaults = %s, %s, %v", f.apiBase(), f.codeloadBase(), f.client().Timeout)
	}
}

func TestFetchExtractsTheRepository(t *testing.T) {
	f := serve(t, tarball(t,
		entry{name: "tool-0123456/"},
		entry{name: "tool-0123456/loupe-hook.yaml", body: "name: tool\n"},
		entry{name: "tool-0123456/hook", body: "#!/bin/sh\n", mode: 0o775},
		entry{name: "tool-0123456/lib/util.sh", body: "util\n", mode: 0o600},
		entry{name: "tool-0123456/run", typ: tar.TypeSymlink, target: "hook"},
	))
	d := dest(t)

	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, sha, d); err != nil {
		t.Fatal(err)
	}

	if got := read(t, filepath.Join(d, ManifestFile)); got != "name: tool\n" {
		t.Fatalf("manifest = %q", got)
	}
	if got := read(t, filepath.Join(d, "lib", "util.sh")); got != "util\n" {
		t.Fatalf("util = %q", got)
	}
	if runtime.GOOS != "windows" {
		for name, want := range map[string]os.FileMode{"hook": 0o755, "lib/util.sh": 0o644, "lib": 0o755, ".": 0o755} {
			info, err := os.Stat(filepath.Join(d, filepath.FromSlash(name)))
			if err != nil || info.Mode().Perm() != want {
				t.Fatalf("%s: mode = %v, %v, want %v", name, info.Mode(), err, want)
			}
		}
		if target, err := os.Readlink(filepath.Join(d, "run")); err != nil || target != "hook" {
			t.Fatalf("link = %q, %v", target, err)
		}
	}
	siblings, err := os.ReadDir(filepath.Dir(d))
	if err != nil || len(siblings) != 1 {
		t.Fatalf("siblings = %v, %v", siblings, err)
	}
}

func TestFetchKeepsOnlyThePath(t *testing.T) {
	f := serve(t, tarball(t,
		entry{name: "tool-0123456/loupe-hook.yaml", body: "root\n"},
		entry{name: "tool-0123456/README.md", body: "readme\n"},
		entry{name: "tool-0123456/hooks/amphetamine/loupe-hook.yaml", body: "name: amphetamine\n"},
		entry{name: "tool-0123456/hooks/amphetamine/hook", body: "#!/bin/sh\n", mode: 0o755},
		entry{name: "tool-0123456/hooks/amphetamine-extra/x", body: "x\n"},
	))
	d := dest(t)

	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Path: "hooks/amphetamine", Ref: "v1"}, sha, d); err != nil {
		t.Fatal(err)
	}

	if got := read(t, filepath.Join(d, ManifestFile)); got != "name: amphetamine\n" {
		t.Fatalf("manifest = %q", got)
	}
	entries, err := os.ReadDir(d)
	if err != nil || len(entries) != 2 {
		t.Fatalf("entries = %v, %v", entries, err)
	}
}

func TestFetchReplacesAnEarlierInstall(t *testing.T) {
	f := serve(t, tarball(t, entry{name: "tool-0123456/loupe-hook.yaml", body: "new\n"}))
	d := dest(t)
	if err := os.MkdirAll(d, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(d, "stale"), []byte("old"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, sha, d); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(d, "stale")); !os.IsNotExist(err) {
		t.Fatalf("stale file: %v", err)
	}
	if got := read(t, filepath.Join(d, ManifestFile)); got != "new\n" {
		t.Fatalf("manifest = %q", got)
	}
	siblings, err := os.ReadDir(filepath.Dir(d))
	if err != nil || len(siblings) != 1 {
		t.Fatalf("siblings = %v, %v", siblings, err)
	}
}

func TestFetchRefuses(t *testing.T) {
	manifest := entry{name: "tool-0123456/loupe-hook.yaml", body: "name: tool\n"}
	for name, tc := range map[string]struct {
		entries []entry
		want    string
	}{
		"parent segment":   {[]entry{manifest, {name: "tool-0123456/../evil", body: "x"}}, `"tool-0123456/../evil" is not a clean relative name`},
		"absolute":         {[]entry{manifest, {name: "/etc/evil", body: "x"}}, `"/etc/evil" is not a clean relative name`},
		"two top dirs":     {[]entry{manifest, {name: "other/x", body: "x"}}, "more than one top directory"},
		"escaping link":    {[]entry{manifest, {name: "tool-0123456/l", typ: tar.TypeSymlink, target: "../../evil"}}, "leaves the package"},
		"absolute link":    {[]entry{manifest, {name: "tool-0123456/l", typ: tar.TypeSymlink, target: "/etc/passwd"}}, "leaves the package"},
		"link through dot": {[]entry{manifest, {name: "tool-0123456/m", typ: tar.TypeSymlink, target: "."}, {name: "tool-0123456/l", typ: tar.TypeSymlink, target: "m/.."}}, "leaves the package"},
		"write via link":   {[]entry{manifest, {name: "tool-0123456/m", typ: tar.TypeSymlink, target: "sub"}, {name: "tool-0123456/sub/"}, {name: "tool-0123456/m/x", body: "x"}}, "goes through a link"},
		"hard link out":    {[]entry{manifest, {name: "tool-0123456/h", typ: tar.TypeLink, target: "tool-0123456/../x"}}, "leaves the package"},
		"no manifest":      {[]entry{{name: "tool-0123456/hook", body: "x"}}, "has no loupe-hook.yaml"},
		"manifest dir":     {[]entry{{name: "tool-0123456/loupe-hook.yaml/"}}, "has no loupe-hook.yaml"},
	} {
		t.Run(name, func(t *testing.T) {
			f := serve(t, tarball(t, tc.entries...))
			d := dest(t)

			err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, sha, d)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			siblings, err := os.ReadDir(filepath.Dir(d))
			if err != nil || len(siblings) != 0 {
				t.Fatalf("left behind = %v, %v", siblings, err)
			}
			if _, err := os.Lstat(filepath.Join(filepath.Dir(d), "evil")); !os.IsNotExist(err) {
				t.Fatalf("evil written: %v", err)
			}
		})
	}
}

func TestFetchRefusesAMissingPath(t *testing.T) {
	f := serve(t, tarball(t, entry{name: "tool-0123456/loupe-hook.yaml", body: "name: tool\n"}))

	err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Path: "hooks/none", Ref: "v1"}, sha, dest(t))
	if err == nil || !strings.Contains(err.Error(), "acme/tool/hooks/none has no loupe-hook.yaml") {
		t.Fatalf("err = %v", err)
	}
}

func TestFetchCapsTheArchive(t *testing.T) {
	big := entry{name: "tool-0123456/big", body: strings.Repeat("x", 100)}
	manifest := entry{name: "tool-0123456/loupe-hook.yaml", body: "name: tool\n"}

	f := serve(t, tarball(t, manifest, big))
	f.maxBytes = 50
	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, sha, dest(t)); err == nil || !strings.Contains(err.Error(), "larger than") {
		t.Fatalf("err = %v", err)
	}

	f = serve(t, tarball(t, manifest, entry{name: "tool-0123456/a"}, entry{name: "tool-0123456/b"}))
	f.maxEntries = 2
	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, sha, dest(t)); err == nil || !strings.Contains(err.Error(), "more than 2 entries") {
		t.Fatalf("err = %v", err)
	}
}

func TestFetchNamesAFailedDownload(t *testing.T) {
	f := serve(t, nil)

	err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "other", Ref: "v1"}, sha, dest(t))
	if err == nil || !strings.Contains(err.Error(), "404") {
		t.Fatalf("err = %v", err)
	}
	if err := f.Fetch(context.Background(), Spec{Owner: "acme", Repo: "tool", Ref: "v1"}, "bad", dest(t)); err == nil {
		t.Fatal("Fetch took a bad sha")
	}
}
