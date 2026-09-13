package config

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
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
	if err := os.MkdirAll(d, 0o700); err != nil {
		return "", fmt.Errorf("create config dir: %w", err)
	}
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

// isUUID reports whether s is a usable UUID. It accepts any version and either
// letter case, so it heals a malformed id without discarding a valid one an
// operator wrote by hand. It rejects the nil UUID, which a disk image or a
// hand-edited file can carry onto every machine that copies it.
func isUUID(s string) bool {
	if len(s) != 36 {
		return false
	}

	allZero := true
	for i := range len(s) {
		c := s[i]
		if i == 8 || i == 13 || i == 18 || i == 23 {
			if c != '-' {
				return false
			}

			continue
		}
		if !isHexDigit(c) {
			return false
		}
		if c != '0' {
			allZero = false
		}
	}

	return !allZero
}

func isHexDigit(c byte) bool {
	return c >= '0' && c <= '9' || c >= 'a' && c <= 'f' || c >= 'A' && c <= 'F'
}
