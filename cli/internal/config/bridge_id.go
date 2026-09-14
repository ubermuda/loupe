package config

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sync"
)

// configMu serialises every read-modify-write of config.json, so two goroutines
// in one process cannot write two different ids, and a login cannot land
// between another goroutine's read and its write. Two first runs in two
// processes still race, and the last write wins. Every call after that reads
// the winner, so the machine settles on one id.
var configMu sync.Mutex

// EnsureBridgeID returns the id that names this machine's bridge to the server.
// The first call generates one and stores it in config.json. An id that is
// absent, empty or malformed is replaced rather than reported, so a hand-edited
// file cannot stop the bridge from starting.
//
// A config.json that does not exist returns ErrNotLoggedIn by design: a bridge
// with no credentials reports nothing, so an unauthenticated command must not
// create a config directory as a side effect.
//
// The id identifies a bridge and grants nothing, so it is not a secret and the
// keychain does not hold it.
func EnsureBridgeID() (string, error) {
	configMu.Lock()
	defer configMu.Unlock()

	d, err := Dir()
	if err != nil {
		return "", err
	}
	c, err := readStoredConfig(d)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return "", ErrNotLoggedIn
		}

		return "", err
	}
	if isUUID(c.BridgeID) {
		return c.BridgeID, nil
	}

	id, err := newUUID()
	if err != nil {
		return "", err
	}

	c.BridgeID = id
	if err := writeConfig(d, c); err != nil {
		return "", err
	}

	return id, nil
}

// newUUID returns a version 4 UUID in the canonical 8-4-4-4-12 form.
func newUUID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", fmt.Errorf("generate bridge id: %w", err)
	}
	// Version 4 in the high nibble of byte 6, RFC 4122 variant in byte 8.
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80

	var out [36]byte
	hex.Encode(out[0:8], b[0:4])
	out[8] = '-'
	hex.Encode(out[9:13], b[4:6])
	out[13] = '-'
	hex.Encode(out[14:18], b[6:8])
	out[18] = '-'
	hex.Encode(out[19:23], b[8:10])
	out[23] = '-'
	hex.Encode(out[24:36], b[10:16])

	return string(out[:]), nil
}

// bridgeIDPattern is what the server accepts on its bridge routes, which is
// Symfony's Requirement::UUID. It is lower case only, its version nibble is 1 or
// 3 to 8, and its variant nibble is 8, 9, a or b. An id outside it answers 404
// with no error code, so the heal has to replace it here.
var bridgeIDPattern = regexp.MustCompile(`\A[0-9a-f]{8}-[0-9a-f]{4}-[13-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`)

// isUUID reports whether the server takes s as a bridge id. An upper-case id is
// replaced rather than lowered, because the heal replaces every other malformed
// shape and rewriting what an operator typed would surprise them.
func isUUID(s string) bool {
	return bridgeIDPattern.MatchString(s)
}
