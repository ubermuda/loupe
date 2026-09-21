// Package mcpjson reads and writes .mcp.json, the file an agent reads to learn
// which MCP servers to start for a repository.
//
// The file belongs to whoever wrote it. Every other server in it survives a
// write, because a repository commonly declares several and losing one to a
// Loupe command would be worse than never offering to write the file at all.
package mcpjson

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// Name is the file, in the working directory.
const Name = ".mcp.json"

// ServerKey is the entry a Loupe agent connects through.
const ServerKey = "loupe"

// maxSize caps the file the reader buffers. A server list is short, so anything
// larger is a mistake rather than a file to parse.
const maxSize = 1 << 20

// Entry is one server declaration. Only the fields this command writes are
// named, and any other key in the file travels as raw JSON.
type Entry struct {
	Command string   `json:"command"`
	Args    []string `json:"args"`
}

// Desired is the entry `loupe mcp` needs.
func Desired() Entry {
	return Entry{Command: "loupe", Args: []string{"mcp"}}
}

// State says what the file holds for the Loupe server.
type State int

const (
	// Absent means the file has no Loupe entry, or no file exists.
	Absent State = iota
	// Current means the Loupe entry already says what it should.
	Current
	// Different means a Loupe entry exists and says something else.
	Different
)

// Read reports what dir's file says about the Loupe server, and gives back the
// entry it found so a caller can show what it would replace.
func Read(dir string) (State, Entry, error) {
	servers, _, err := load(dir)
	if err != nil {
		return Absent, Entry{}, err
	}

	raw, ok := servers[ServerKey]
	if !ok {
		return Absent, Entry{}, nil
	}

	var found Entry
	if err := json.Unmarshal(raw, &found); err != nil {
		return Different, Entry{}, nil
	}
	if found.Command == Desired().Command && equal(found.Args, Desired().Args) {
		return Current, found, nil
	}

	return Different, found, nil
}

// Write puts the Loupe entry into dir's file, keeping every other server and
// every other top-level key as they were.
func Write(dir string) error {
	servers, doc, err := load(dir)
	if err != nil {
		return err
	}

	entry, err := json.Marshal(Desired())
	if err != nil {
		return err
	}
	servers[ServerKey] = entry

	rewritten, err := json.Marshal(servers)
	if err != nil {
		return err
	}
	doc["mcpServers"] = rewritten

	out, err := json.MarshalIndent(doc, "", "  ")
	if err != nil {
		return err
	}

	return replace(filepath.Join(dir, Name), append(out, '\n'))
}

// load gives the server map and the whole document, both as raw JSON so that a
// key this package does not model survives being written back.
func load(dir string) (map[string]json.RawMessage, map[string]json.RawMessage, error) {
	doc := map[string]json.RawMessage{}
	servers := map[string]json.RawMessage{}

	path := filepath.Join(dir, Name)
	info, err := os.Stat(path)
	if errors.Is(err, os.ErrNotExist) {
		return servers, doc, nil
	}
	if err != nil {
		return nil, nil, fmt.Errorf("%s: %w", path, err)
	}
	if info.Size() > maxSize {
		return nil, nil, fmt.Errorf("%s: larger than %d bytes, which is not a server list", path, maxSize)
	}

	b, err := os.ReadFile(path)
	if err != nil {
		return nil, nil, fmt.Errorf("%s: %w", path, err)
	}
	if len(bytes.TrimSpace(b)) == 0 {
		return servers, doc, nil
	}
	if err := json.Unmarshal(b, &doc); err != nil {
		return nil, nil, fmt.Errorf("%s: %w", path, err)
	}
	if raw, ok := doc["mcpServers"]; ok {
		if err := json.Unmarshal(raw, &servers); err != nil {
			return nil, nil, fmt.Errorf("%s: mcpServers is not an object: %w", path, err)
		}
	}

	return servers, doc, nil
}

func equal(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}

	return true
}

// replace writes through a temporary file and a rename, so an interrupted write
// leaves the previous file intact rather than a half-written one an agent reads.
func replace(path string, body []byte) error {
	f, err := os.CreateTemp(filepath.Dir(path), Name+".*")
	if err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)

	if _, err := f.Write(body); err != nil {
		f.Close()

		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := os.Chmod(tmp, 0o644); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}

	return os.Rename(tmp, path)
}
