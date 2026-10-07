package hostsample

import (
	"context"
	"os/exec"
	"time"
)

func battery(ctx context.Context) (*float64, *bool) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	out, err := exec.CommandContext(ctx, "/usr/bin/pmset", "-g", "batt").Output()
	if err != nil {
		return nil, nil
	}

	return parsePmset(string(out))
}
