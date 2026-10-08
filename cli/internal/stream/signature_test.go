package stream

import (
	"reflect"
	"strings"
	"testing"
)

func TestCommandSignatures(t *testing.T) {
	for _, tc := range []struct {
		command string
		want    []string
	}{
		{"git status | grep x", []string{"git status", "grep"}},
		{"cd /a && just phpunit tests/X", []string{"cd", "just phpunit"}},
		{`for f in *.php; do grep -n foo "$f"; done`, []string{"for", "grep"}},
		{"VAR=1 npm test", []string{"npm test"}},
		{`echo "a && b"`, []string{"echo"}},
		{"/usr/bin/git -C x log", []string{"git"}},
		{"grep secret file", []string{"grep"}},
		{"$(git rev-parse HEAD)", []string{"git rev-parse"}},
		{"git status; git status && git log", []string{"git status", "git log"}},
		{"git diff 2>&1 | head -5", []string{"git diff", "head"}},
		{"ls > out.txt; cat < in.txt", []string{"ls", "cat"}},
		{"(cd x && make build) || true", []string{"cd", "make build", "true"}},
		{"{ go test ./...; }", []string{"go test"}},
		{"echo `docker ps`", []string{"echo", "docker ps"}},
		{"a='x;y' b | c", []string{"b", "c"}},
		{`echo a\;b`, []string{"echo"}},
		{"git \\\n  status", []string{"git status"}},
		{"if git diff --quiet; then echo same; else echo diff; fi", []string{"git diff", "echo"}},
		{"while true; do sleep 1; done", []string{"while", "sleep"}},
		{"! time npm ci", []string{"npm ci"}},
		{"cat > f <<'EOF'\nsecret words here\nEOF\ngit add f", []string{"cat", "git add"}},
		{"cat <<-END\n\tsecret\n\tEND\nls", []string{"cat", "ls"}},
		{`git commit -m "$(cat <<'EOF'` + "\nsecret message\nEOF\n)\"", []string{"git commit"}},
		{"npm Test", []string{"npm"}},
		{"git " + strings.Repeat("a", 32), []string{"git"}},
		{"ls # a comment; rm -rf x", []string{"ls"}},
		{"echo ${HOME}/x {a,b}", []string{"echo"}},
		{"cargo build &", []string{"cargo build"}},
		{"", nil},
	} {
		t.Run(tc.command, func(t *testing.T) {
			if got := commandSignatures(tc.command, DefaultPrograms); !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("commandSignatures(%q) = %q, want %q", tc.command, got, tc.want)
			}
		})
	}
}

// A project's list replaces the default one, and an empty list keeps no
// second word.
func TestTheProgramListIsTheProjects(t *testing.T) {
	if got := commandSignatures("kubectl get pods; git status", []string{"kubectl"}); !reflect.DeepEqual(got, []string{"kubectl get", "git"}) {
		t.Fatalf("got %q", got)
	}
	if got := commandSignatures("git status", []string{}); !reflect.DeepEqual(got, []string{"git"}) {
		t.Fatalf("got %q", got)
	}
}

func TestSignaturesAreBoundedAndDistinct(t *testing.T) {
	var parts []string
	for i := range 30 {
		parts = append(parts, "p"+strings.Repeat("x", i))
	}
	got := commandSignatures(strings.Join(parts, "; "), nil)
	if len(got) != maxSignatures || got[0] != "p" {
		t.Fatalf("got %d signatures: %q", len(got), got)
	}

	long := strings.Repeat("é", 100)
	got = commandSignatures(long, nil)
	if len(got) != 1 || len(got[0]) > maxSignature || !strings.HasPrefix(long, got[0]) {
		t.Fatalf("got %q", got)
	}
}

func TestSignaturesOfACall(t *testing.T) {
	if got := Signatures(Call{Tool: "Bash", Kind: KindShell, Commands: Commands("git status | grep x")}, nil); !reflect.DeepEqual(got, []string{"git status", "grep"}) {
		t.Fatalf("Bash: %q", got)
	}
	if got := Signatures(Call{Tool: "exec", Kind: KindShell, Commands: Commands("go test ./...")}, nil); !reflect.DeepEqual(got, []string{"go test"}) {
		t.Fatalf("exec: %q", got)
	}
	if got := Signatures(Call{Tool: "Bash", Kind: KindTool, Commands: Commands("git status")}, nil); !reflect.DeepEqual(got, []string{"Bash"}) {
		t.Fatalf("Bash of the kind tool: %q", got)
	}
	if got := Signatures(Call{Tool: "Read", FullText: `{"file_path":"/secret"}`}, nil); !reflect.DeepEqual(got, []string{"Read"}) {
		t.Fatalf("Read: %q", got)
	}
	if got := Signatures(Call{Tool: "Bash", Kind: KindShell}, nil); got == nil || len(got) != 0 {
		t.Fatalf("Bash with no command: %#v", got)
	}
}
