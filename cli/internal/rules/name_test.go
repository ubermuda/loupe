package rules

import (
	"errors"
	"strings"
	"testing"
)

func stubHostname(t *testing.T, name string, err error) {
	t.Helper()
	old := hostname
	hostname = func() (string, error) { return name, err }
	t.Cleanup(func() { hostname = old })
}

func TestAnAbsentNameTakesTheFirstLabelOfTheHostName(t *testing.T) {
	stubHostname(t, "Geoffreys-MacBook-Pro.local", nil)
	if got := parse(t, oneRule).Name(); got != "Geoffreys-MacBook-Pro" {
		t.Fatalf("name = %q", got)
	}
}

func TestAnAbsentNameCutsALongHostNameTo40Characters(t *testing.T) {
	stubHostname(t, " "+strings.Repeat("é", 45)+"\x01.example.com", nil)
	if got := parse(t, oneRule).Name(); got != strings.Repeat("é", 40) {
		t.Fatalf("name = %q", got)
	}
}

func TestAnAbsentNameIsEmptyWhenTheHostNameFails(t *testing.T) {
	stubHostname(t, "", errors.New("no host name"))
	if got := parse(t, oneRule).Name(); got != "" {
		t.Fatalf("name = %q", got)
	}
}

func TestABlankNameOptsOut(t *testing.T) {
	stubHostname(t, "host", nil)
	for _, value := range []string{`""`, `"   "`} {
		if got := parse(t, "name: "+value+"\n"+oneRule).Name(); got != "" {
			t.Fatalf("name: %s gave %q", value, got)
		}
	}
}

func TestASetNameIsTrimmed(t *testing.T) {
	stubHostname(t, "host", nil)
	if got := parse(t, "name: \"  studio mac  \"\n"+oneRule).Name(); got != "studio mac" {
		t.Fatalf("name = %q", got)
	}
}

func TestANameTheServerRefusesFailsTheLoad(t *testing.T) {
	for label, value := range map[string]string{
		"41 characters":       `"` + strings.Repeat("é", 41) + `"`,
		"a control character": `"studio\tmac"`,
	} {
		text, _ := file(t, "name: "+value+"\n"+oneRule)
		if _, err := Parse([]byte(text), Defaults{}); err == nil || !strings.Contains(err.Error(), "name") {
			t.Fatalf("%s: err = %v", label, err)
		}
	}
}

func TestA40CharacterNameLoads(t *testing.T) {
	if got := parse(t, "name: \""+strings.Repeat("é", 40)+"\"\n"+oneRule).Name(); got != strings.Repeat("é", 40) {
		t.Fatalf("name = %q", got)
	}
}
