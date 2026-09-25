package cmd

import (
	"os"
	"strconv"

	"golang.org/x/sys/unix"
)

// sZomb is SZOMB in sys/proc.h, the state of an exited process nobody reaped.
const sZomb = 5

// processInfo reads the start time and the state of pid from the kern.proc.pid
// sysctl. The start time keeps its microseconds, so two processes rarely share it.
func processInfo(pid int) (procInfo, error) {
	procs, err := unix.SysctlKinfoProcSlice("kern.proc.pid", pid)
	if err != nil {
		return procInfo{}, err
	}
	if len(procs) == 0 {
		return procInfo{}, os.ErrNotExist
	}
	p := procs[0].Proc
	start := strconv.FormatInt(p.P_starttime.Sec, 10) + "." + strconv.FormatInt(int64(p.P_starttime.Usec), 10)

	return procInfo{start: start, zombie: p.P_stat == sZomb}, nil
}
