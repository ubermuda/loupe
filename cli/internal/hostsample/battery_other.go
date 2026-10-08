//go:build !darwin && !linux

package hostsample

import "context"

// battery has no source on this system.
func battery(context.Context) (*float64, *bool) {
	return nil, nil
}
