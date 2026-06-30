package inject

import (
	"strings"
	"testing"
)

func TestParse(t *testing.T) {
	e, err := Parse([]byte(`{"type":"site_review.submitted","batchId":"abc","commentCount":2,"urls":["https://x/a","https://x/b"]}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if e.BatchID != "abc" || e.CommentCount != 2 || len(e.URLs) != 2 {
		t.Fatalf("unexpected event: %+v", e)
	}
}

func TestDirectiveSelfContainedIncludesUrlsAndCount(t *testing.T) {
	e := Event{BatchID: "abc", CommentCount: 2, URLs: []string{"https://x/a"}}
	got := Directive(e, SelfContained)
	for _, want := range []string{"abc", "2 comment", "https://x/a"} {
		if !strings.Contains(got, want) {
			t.Fatalf("directive %q missing %q", got, want)
		}
	}
}

func TestDirectiveIDOnlyMentionsMcpTool(t *testing.T) {
	got := Directive(Event{BatchID: "abc", CommentCount: 2, URLs: []string{"https://x/a"}}, IDOnly)
	if !strings.Contains(got, "get_site_review") {
		t.Fatalf("id-only directive should reference the MCP tool: %q", got)
	}
	if strings.Contains(got, "https://x/a") {
		t.Fatalf("id-only directive should not inline URLs: %q", got)
	}
}
