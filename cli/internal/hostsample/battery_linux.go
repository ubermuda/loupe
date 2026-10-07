package hostsample

import "context"

func battery(context.Context) (*float64, *bool) {
	return readPowerSupply("/sys/class/power_supply")
}
