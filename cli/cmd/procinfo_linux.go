package cmd

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
)

// processInfo reads the start time and the state of pid from /proc. The start
// time is field 22 of the stat line, in clock ticks since boot.
func processInfo(pid int) (procInfo, error) {
	b, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return procInfo{}, err
	}
	// The command name sits in parentheses and may hold spaces or parentheses
	// itself, so the fields start after the last one.
	i := strings.LastIndexByte(string(b), ')')
	if i < 0 {
		return procInfo{}, errors.New("unreadable /proc stat line")
	}
	fields := strings.Fields(string(b[i+1:]))
	if len(fields) < 20 {
		return procInfo{}, fmt.Errorf("short /proc stat line: %d fields", len(fields))
	}

	return procInfo{start: fields[19], zombie: fields[0] == "Z" || fields[0] == "X"}, nil
}
