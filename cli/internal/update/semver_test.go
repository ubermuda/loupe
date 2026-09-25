package update

import "testing"

func TestParseVersion(t *testing.T) {
	cases := []struct {
		in   string
		want Version
		ok   bool
	}{
		{"1.2.3", Version{1, 2, 3, ""}, true},
		{"v1.2.3", Version{1, 2, 3, ""}, true},
		{"1.2.3-rc.1", Version{1, 2, 3, "rc.1"}, true},
		{"v0.10.0", Version{0, 10, 0, ""}, true},
		{"1.2", Version{}, false},
		{"a1b2c3d", Version{}, false},
		{"", Version{}, false},
		{"1.2.3.4", Version{}, false},
		{"1.2.x", Version{}, false},
		{"1.2.3-", Version{}, false},
		{"-1.2.3", Version{}, false},
		{"1.+2.3", Version{}, false},
		{"vv1.2.3", Version{}, false},
	}
	for _, c := range cases {
		got, ok := ParseVersion(c.in)
		if ok != c.ok || got != c.want {
			t.Errorf("ParseVersion(%q) = %+v, %v; want %+v, %v", c.in, got, ok, c.want, c.ok)
		}
	}
}

func TestCompare(t *testing.T) {
	cases := []struct {
		a, b string
		want int
	}{
		{"1.0.0", "1.0.0", 0},
		{"1.0.0", "1.0.1", -1},
		{"1.1.0", "1.0.9", 1},
		{"2.0.0", "1.99.99", 1},
		{"1.0.0-rc.1", "1.0.0", -1},
		{"1.0.0", "1.0.0-rc.1", 1},
		{"1.0.0-rc.1", "1.0.0-rc.2", -1},
	}
	for _, c := range cases {
		a, _ := ParseVersion(c.a)
		b, _ := ParseVersion(c.b)
		if got := Compare(a, b); got != c.want {
			t.Errorf("Compare(%s, %s) = %d, want %d", c.a, c.b, got, c.want)
		}
	}
}

func TestSatisfies(t *testing.T) {
	cases := []struct {
		version, rng string
		want         bool
	}{
		{"1.0.0", "^1.0", true},
		{"1.9.3", "^1.0", true},
		{"v1.9.3", "^1.0", true},
		{"2.0.0", "^1.0", false},
		{"0.9.9", "^1.0", false},
		{"a1b2c3d", "^1.0", false},
		{"1.1.0-rc.1", "^1.0", false},
		{"0.4.0", "^0.4", true},
		{"0.4.9", "^0.4", true},
		{"0.5.0", "^0.4", false},
		{"0.3.9", "^0.4", false},
		{"4.5.1", "^4.5.2", false},
		{"4.5.2", "^4.5.2", true},
		{"4.9.0", "^4.5.2", true},
		{"0.0.3", "^0.0.3", true},
		{"0.0.4", "^0.0.3", false},
		{"0.0.9", "^0.0", true},
		{"0.1.0", "^0.0", false},
		{"1.0.0", "1.0", false},
		{"1.0.0", "^1", false},
		{"1.0.0", "^1.0-rc", false},
		{"1.0.0", "", false},
	}
	for _, c := range cases {
		if got := Satisfies(c.version, c.rng); got != c.want {
			t.Errorf("Satisfies(%q, %q) = %v, want %v", c.version, c.rng, got, c.want)
		}
	}
}
