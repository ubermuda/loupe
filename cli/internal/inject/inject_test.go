package inject

import (
	"strings"
	"testing"
)

func TestParse(t *testing.T) {
	e, err := Parse([]byte(`{"type":"site_review.submitted","siteId":"site-1","siteName":"acme","reviewId":"rev-42","commentCount":2,"urls":["https://x/a","https://x/b"],"submittedAt":"2026-07-01T00:00:00Z"}`))
	if err != nil {
		t.Fatalf("Parse: %v", err)
	}
	if e.SiteID != "site-1" || e.SiteName != "acme" || e.ReviewID != "rev-42" || e.CommentCount != 2 || len(e.URLs) != 2 {
		t.Fatalf("unexpected event: %+v", e)
	}
}

func TestParseMalformedJSON(t *testing.T) {
	_, err := Parse([]byte(`not json`))
	if err == nil {
		t.Fatal("expected error for malformed JSON, got nil")
	}
}

func TestDirectiveSelfContainedIncludesUrlsAndCount(t *testing.T) {
	e := Event{SiteName: "acme", CommentCount: 2, URLs: []string{"https://x/a"}}
	got := Directive(e, SelfContained)
	for _, want := range []string{"acme", "2 comment", "https://x/a"} {
		if !strings.Contains(got, want) {
			t.Fatalf("directive %q missing %q", got, want)
		}
	}
}

func TestDirectiveIDOnlyExactString(t *testing.T) {
	e := Event{SiteName: "acme", CommentCount: 2, URLs: []string{"https://x/a"}}
	got := Directive(e, IDOnly)
	want := `A site review for site "acme" was just submitted (2 comment(s)). Fetch the pending comments with the get_site_review MCP tool (site "acme"), address them, and mark each one with address_site_review_comments.`
	if got != want {
		t.Fatalf("IDOnly directive mismatch\ngot:  %q\nwant: %q", got, want)
	}
}

func TestDirectiveIDOnlyDoesNotInlineURLs(t *testing.T) {
	got := Directive(Event{SiteName: "acme", CommentCount: 2, URLs: []string{"https://x/a"}}, IDOnly)
	if strings.Contains(got, "https://x/a") {
		t.Fatalf("id-only directive should not inline URLs: %q", got)
	}
}
