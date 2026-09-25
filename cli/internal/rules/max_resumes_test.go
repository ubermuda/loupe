package rules

import (
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/event"
)

func TestMaxResumesDefaultsToTwo(t *testing.T) {
	s := checked(t, oneRule)

	if m := s.Match(moved("backlog", "ready", event.ActorHuman)); m.MaxResumes != DefaultMaxResumes || DefaultMaxResumes != 2 {
		t.Fatalf("MaxResumes = %d, want %d", m.MaxResumes, DefaultMaxResumes)
	}
}

// Zero turns the resume off, and the rule still loads.
func TestMaxResumesTakesZero(t *testing.T) {
	s := checked(t, strings.Replace(oneRule, "    to: ready\n", "    to: ready\n    maxResumes: 0\n", 1))

	if m := s.Match(moved("backlog", "ready", event.ActorHuman)); m.Skip != Run || m.MaxResumes != 0 {
		t.Fatalf("Match = %+v", m)
	}
}

func TestMaxResumesRefusesANegativeValue(t *testing.T) {
	text, _ := file(t, strings.Replace(oneRule, "    to: ready\n", "    to: ready\n    maxResumes: -1\n", 1))

	_, err := Parse([]byte(text), Defaults{})
	if err == nil || !strings.Contains(err.Error(), "maxResumes must be at least 0, got -1") {
		t.Fatalf("err = %v", err)
	}
}

// The run report endpoint takes a resume cap of at most 32767.
func TestMaxResumesRefusesWhatTheReportEndpointRejects(t *testing.T) {
	text, _ := file(t, strings.Replace(oneRule, "    to: ready\n", "    to: ready\n    maxResumes: 32768\n", 1))

	_, err := Parse([]byte(text), Defaults{})
	if err == nil || !strings.Contains(err.Error(), "maxResumes is 32768, and the server takes at most 32767") {
		t.Fatalf("err = %v", err)
	}
}
