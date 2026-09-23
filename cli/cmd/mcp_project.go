package cmd

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sync"

	"github.com/ubermuda/loupe/cli/internal/projectfile"
)

// mcpProject gives the project each request of `loupe mcp` names. The flag
// wins and the file is never read. Otherwise the project file in the working
// directory decides, and a malformed file at start is an error.
func mcpProject(flag string, notes io.Writer) (func() string, error) {
	if flag != "" {
		return func() string { return flag }, nil
	}

	dir, err := os.Getwd()
	if err != nil {
		return nil, err
	}
	src := &projectSource{dir: dir, notes: notes}
	if err := src.start(); err != nil {
		return nil, fmt.Errorf("%w\n\nWrite the file with `loupe init`", err)
	}

	return src.project, nil
}

// projectSource follows the project file in dir. It runs os.Stat on each call,
// and reads the file again only when the stat changed.
type projectSource struct {
	dir   string
	notes io.Writer

	mu     sync.Mutex
	seen   os.FileInfo
	last   string
	failed string
}

func (p *projectSource) start() error {
	p.seen, _ = os.Stat(filepath.Join(p.dir, projectfile.Name))
	file, err := projectfile.Load(p.dir)
	if errors.Is(err, projectfile.ErrNoFile) {
		return nil
	}
	if err != nil {
		return err
	}
	p.last = file.Project

	return nil
}

// project gives the last good project. A file that fails to load keeps it, and
// a removed file names none. Each change writes one note.
func (p *projectSource) project() string {
	p.mu.Lock()
	defer p.mu.Unlock()

	info, err := os.Stat(filepath.Join(p.dir, projectfile.Name))
	if errors.Is(err, os.ErrNotExist) {
		if p.seen != nil {
			p.seen = nil
			fmt.Fprintf(p.notes, "loupe mcp: %s is gone, so no project is sent (was %s)\n", projectfile.Name, orNone(p.last))
			p.last = ""
		}

		return p.last
	}
	if err != nil {
		if err.Error() != p.failed {
			p.failed = err.Error()
			fmt.Fprintf(p.notes, "loupe mcp: %v; the project stays %s\n", err, orNone(p.last))
		}

		return p.last
	}
	p.failed = ""
	if unchanged(p.seen, info) {
		return p.last
	}
	p.seen = info

	file, err := projectfile.Load(p.dir)
	if err != nil {
		fmt.Fprintf(p.notes, "loupe mcp: %v; the project stays %s\n", err, orNone(p.last))

		return p.last
	}
	if file.Project != p.last {
		fmt.Fprintf(p.notes, "loupe mcp: %s now names project %s (was %s)\n", projectfile.Name, orNone(file.Project), orNone(p.last))
		p.last = file.Project
	}

	return p.last
}

// unchanged reports whether two stats show the same file with the same
// contents. A rename replaces the file, which the time and size can miss.
func unchanged(before, after os.FileInfo) bool {
	return before != nil && os.SameFile(before, after) &&
		before.ModTime().Equal(after.ModTime()) && before.Size() == after.Size()
}

func orNone(project string) string {
	if project == "" {
		return "none"
	}

	return project
}
