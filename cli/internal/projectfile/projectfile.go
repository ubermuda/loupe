// Package projectfile reads .loupe.yaml, the file that names the Loupe project
// a repository belongs to.
//
// The reader accepts keys it does not know, so a later version that adds a
// projects list or a path map does not break a file written today.
package projectfile

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"go.yaml.in/yaml/v3"
)

// Name is the file name, in the working directory.
const Name = ".loupe.yaml"

// ErrNoFile reports that the directory holds no .loupe.yaml. It is not a
// failure: a user with one project needs no file, because the server falls
// back to the single project the grant covers.
var ErrNoFile = errors.New("no " + Name + " in this directory")

// uuidPattern matches the RFC 4122 text a project id is written in.
var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// maxSize caps the file the reader buffers. A project file is one short line,
// so anything larger is a mistake rather than a file to parse.
const maxSize = 64 << 10

// File is what .loupe.yaml says. Only Project is read today.
type File struct {
	// Project is the project id, lower-case RFC 4122, or "" when the file
	// names none.
	Project string `yaml:"project"`
}

// Load reads .loupe.yaml from dir. It returns ErrNoFile when the file is
// absent, and an error that names the file for anything it cannot read.
func Load(dir string) (File, error) {
	path := filepath.Join(dir, Name)

	info, err := os.Stat(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return File{}, ErrNoFile
		}

		return File{}, fmt.Errorf("%s: %w", path, err)
	}
	if info.Size() > maxSize {
		return File{}, fmt.Errorf("%s: larger than %d bytes, which is not a project file", path, maxSize)
	}

	b, err := os.ReadFile(path)
	if err != nil {
		return File{}, fmt.Errorf("%s: %w", path, err)
	}

	var f File
	if err := yaml.Unmarshal(b, &f); err != nil {
		return File{}, fmt.Errorf("%s: %w", path, err)
	}

	f.Project = strings.TrimSpace(f.Project)
	if f.Project != "" && !uuidPattern.MatchString(strings.ToLower(f.Project)) {
		return File{}, fmt.Errorf("%s: project must be a project id such as 0192f3c4-5d6e-7f80-9123-456789abcdef, got %q", path, f.Project)
	}
	f.Project = strings.ToLower(f.Project)

	return f, nil
}

// Write replaces .loupe.yaml in dir with one naming projectID. It writes
// through a temporary file and a rename, so an interrupted write leaves the
// previous file intact.
func Write(dir, projectID string) error {
	if !uuidPattern.MatchString(strings.ToLower(projectID)) {
		return fmt.Errorf("project id must be a project id such as 0192f3c4-5d6e-7f80-9123-456789abcdef, got %q", projectID)
	}

	body := "# The Loupe project this repository belongs to. `loupe mcp` sends it with\n" +
		"# every request, so an agent acts on this project and no other.\n" +
		"project: " + strings.ToLower(projectID) + "\n"

	f, err := os.CreateTemp(dir, Name+".*")
	if err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)

	if _, err := f.WriteString(body); err != nil {
		f.Close()

		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := os.Chmod(tmp, 0o644); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := os.Rename(tmp, filepath.Join(dir, Name)); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}

	return nil
}
