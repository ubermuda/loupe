// Package update decides whether a newer CLI release should replace the
// running binary, and checks the downloaded archive before it does.
package update

import (
	"strconv"
	"strings"
)

// Version is a semantic version. Pre holds the prerelease part, without its dash.
type Version struct {
	Major, Minor, Patch int
	Pre                 string
}

func (v Version) String() string {
	s := strconv.Itoa(v.Major) + "." + strconv.Itoa(v.Minor) + "." + strconv.Itoa(v.Patch)
	if v.Pre != "" {
		s += "-" + v.Pre
	}

	return s
}

// ParseVersion reads X.Y.Z, with an optional leading v and an optional
// -prerelease suffix. A commit sha or a partial version does not parse.
func ParseVersion(s string) (Version, bool) {
	s = strings.TrimPrefix(s, "v")
	core, pre, hasPre := strings.Cut(s, "-")
	if hasPre && pre == "" {
		return Version{}, false
	}
	nums, ok := parseNumbers(core, 3)
	if !ok {
		return Version{}, false
	}

	return Version{Major: nums[0], Minor: nums[1], Patch: nums[2], Pre: pre}, true
}

func parseNumbers(s string, count int) ([]int, bool) {
	parts := strings.Split(s, ".")
	if len(parts) != count {
		return nil, false
	}
	nums := make([]int, count)
	for i, p := range parts {
		if p == "" || strings.Trim(p, "0123456789") != "" {
			return nil, false
		}
		n, err := strconv.Atoi(p)
		if err != nil {
			return nil, false
		}
		nums[i] = n
	}

	return nums, true
}

// Compare returns -1, 0 or 1. A prerelease sorts below its release, and two
// prereleases of one version compare as plain strings.
func Compare(a, b Version) int {
	for _, d := range [3]int{a.Major - b.Major, a.Minor - b.Minor, a.Patch - b.Patch} {
		if d != 0 {
			return sign(d)
		}
	}
	switch {
	case a.Pre == b.Pre:
		return 0
	case a.Pre == "":
		return 1
	case b.Pre == "":
		return -1
	}

	return strings.Compare(a.Pre, b.Pre)
}

func sign(d int) int {
	if d < 0 {
		return -1
	}

	return 1
}

// Satisfies reports whether version falls in a caret range, ^X.Y or ^X.Y.Z.
// Like Composer and npm, the caret keeps the leftmost non-zero part fixed. A
// prerelease never satisfies, so a release candidate is never auto-installed.
func Satisfies(version, rangeStr string) bool {
	v, ok := ParseVersion(version)
	if !ok || v.Pre != "" {
		return false
	}
	low, high, ok := caretBounds(rangeStr)
	if !ok {
		return false
	}

	return Compare(v, low) >= 0 && Compare(v, high) < 0
}

func caretBounds(rangeStr string) (Version, Version, bool) {
	body, ok := strings.CutPrefix(rangeStr, "^")
	if !ok {
		return Version{}, Version{}, false
	}
	nums, ok := parseNumbers(body, 2)
	hasPatch := false
	if !ok {
		if nums, ok = parseNumbers(body, 3); !ok {
			return Version{}, Version{}, false
		}
		hasPatch = true
	} else {
		nums = append(nums, 0)
	}
	low := Version{Major: nums[0], Minor: nums[1], Patch: nums[2]}
	switch {
	case low.Major > 0:
		return low, Version{Major: low.Major + 1}, true
	case low.Minor > 0 || !hasPatch:
		return low, Version{Minor: low.Minor + 1}, true
	}

	return low, Version{Patch: low.Patch + 1}, true
}
