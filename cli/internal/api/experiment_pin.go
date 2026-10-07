package api

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
)

// ErrExperimentPinsUnsupported marks a 404 with no error code, which is the
// answer of a server with no pin endpoint, or with agent push switched off.
var ErrExperimentPinsUnsupported = errors.New("the server has no experiment pin endpoint, or agent push is switched off")

// ResolveExperimentPin asks the server which variant of an experiment a card
// runs with. candidate is the variant the bridge drew, and variants are the
// ones the rule offers now. weights holds the weight of each variant, in the
// order of variants. metrics are the metric keys the experiment declares,
// which the server shows on its Comparison tab. It answers the variant, and
// the variant the pin moved from, or "" when the pin did not move.
func (c *Client) ResolveExperimentPin(ctx context.Context, handle, experiment, cardID, candidate string, variants []string, weights []int, metrics []string) (string, string, error) {
	if variants == nil {
		variants = []string{}
	}
	if weights == nil {
		weights = []int{}
	}
	if metrics == nil {
		metrics = []string{}
	}
	body, err := json.Marshal(struct {
		Candidate string   `json:"candidate"`
		Variants  []string `json:"variants"`
		Weights   []int    `json:"weights"`
		Metrics   []string `json:"metrics"`
	}{candidate, variants, weights, metrics})
	if err != nil {
		return "", "", fmt.Errorf("encode the experiment pin: %w", err)
	}

	status, detail, err := c.putReport(ctx, "/api/projects/"+url.PathEscape(handle)+
		"/experiments/"+url.PathEscape(experiment)+"/pins/"+url.PathEscape(cardID), body)
	if err != nil {
		return "", "", fmt.Errorf("resolve the experiment pin: %w", err)
	}

	switch {
	case status == http.StatusNotFound && !hasErrorCode(detail):
		return "", "", ErrExperimentPinsUnsupported
	case status != http.StatusOK:
		return "", "", fmt.Errorf("the server refused the experiment pin (HTTP %d): %s", status, strings.TrimSpace(string(detail)))
	}

	var answer struct {
		Variant      string  `json:"variant"`
		SwitchedFrom *string `json:"switchedFrom"`
	}
	if err := json.Unmarshal(detail, &answer); err != nil {
		return "", "", fmt.Errorf("read the experiment pin: %w", err)
	}
	if answer.Variant == "" {
		return "", "", errors.New("read the experiment pin: the answer names no variant")
	}
	switchedFrom := ""
	if answer.SwitchedFrom != nil {
		switchedFrom = *answer.SwitchedFrom
	}

	return answer.Variant, switchedFrom, nil
}

// hasErrorCode reports whether an answer is a JSON object with an error code.
func hasErrorCode(detail []byte) bool {
	var payload struct {
		Error string `json:"error"`
	}

	return json.Unmarshal(detail, &payload) == nil && payload.Error != ""
}
