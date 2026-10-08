package rules

import (
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"regexp"
	"slices"
	"unicode/utf8"
)

// The limits the server puts on an experiment. MaxWeight is the bridge's own,
// so the sum of the weights never overflows.
const (
	MaxVariants    = 32
	MaxModelLength = 100
	MaxWeight      = 1_000_000
	MaxMetrics     = 16
)

// experimentNamePattern is the shape the server takes for an experiment or a
// variant name.
var experimentNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,63}$`)

// metricKeyPattern is the shape the server takes for a metric key. The bridge
// does not know the server's metrics, so it checks the shape only.
var metricKeyPattern = regexp.MustCompile(`^[a-z][a-z0-9:-]{0,63}$`)

// modelPattern refuses the control characters the server refuses in a model.
var modelPattern = regexp.MustCompile(`^[^\p{C}]+$`)

// Experiment splits the runs of the rules that join it between models.
type Experiment struct {
	Name     string    `yaml:"name"`
	Variants []Variant `yaml:"variants"`
	Metrics  []string  `yaml:"metrics"`

	// settings holds the run settings of each variant, in variant order.
	settings []RunSettings
}

// Variant is one model or account of an experiment, and its share of the
// cards.
type Variant struct {
	Name        string `yaml:"name"`
	Weight      int    `yaml:"weight"`
	Model       string `yaml:"model"`
	Account     string `yaml:"account"`
	Permissions string `yaml:"permissions"`
}

// Settings are the run settings of a variant. A variant the experiment does
// not hold runs with its own model alone.
func (e Experiment) Settings(v Variant) RunSettings {
	i := slices.IndexFunc(e.Variants, func(o Variant) bool { return o.Name == v.Name })
	if i < 0 || i >= len(e.settings) {
		return RunSettings{Model: v.Model}
	}

	return e.settings[i].clone()
}

// Pick is the variant the bridge proposes for a card. The same experiment and
// card key always give the same variant, and each variant takes its weight's
// share of the cards. It returns the zero Variant for an experiment with no
// weight.
func (e Experiment) Pick(cardKey string) Variant {
	total := 0
	for _, v := range e.Variants {
		total += v.Weight
	}
	if total <= 0 {
		return Variant{}
	}
	sum := sha256.Sum256([]byte(e.Name + ":" + cardKey))
	n := int(binary.BigEndian.Uint64(sum[:8]) % uint64(total))
	for _, v := range e.Variants {
		if n < v.Weight {
			return v
		}
		n -= v.Weight
	}

	return Variant{}
}

// clone copies the variants and the metrics, so a caller cannot change the
// set's experiment.
func (e Experiment) clone() *Experiment {
	e.Variants = slices.Clone(e.Variants)
	e.Metrics = slices.Clone(e.Metrics)
	e.settings = slices.Clone(e.settings)
	for i := range e.settings {
		e.settings[i] = e.settings[i].clone()
	}

	return &e
}

func checkExperiment(e Experiment) error {
	var errs []error
	if !experimentNamePattern.MatchString(e.Name) {
		errs = append(errs, errNamePattern)
	}
	switch {
	case len(e.Variants) == 0:
		errs = append(errs, errors.New("it has no variants"))
	case len(e.Variants) > MaxVariants:
		errs = append(errs, fmt.Errorf("it has %d variants, and the server takes at most %d", len(e.Variants), MaxVariants))
	}
	names := map[string]bool{}
	for _, v := range e.Variants {
		if names[v.Name] {
			errs = append(errs, fmt.Errorf("two variants are named %q", v.Name))

			continue
		}
		names[v.Name] = true
		if err := checkVariant(v); err != nil {
			errs = append(errs, fmt.Errorf("variant %q: %w", v.Name, err))
		}
	}

	errs = append(errs, checkMetrics(e.Metrics)...)

	return errors.Join(errs...)
}

func checkMetrics(metrics []string) []error {
	var errs []error
	if len(metrics) > MaxMetrics {
		errs = append(errs, fmt.Errorf("it has %d metrics, and the server takes at most %d", len(metrics), MaxMetrics))
	}
	seen := map[string]bool{}
	for _, key := range metrics {
		switch {
		case seen[key]:
			errs = append(errs, fmt.Errorf("metric %q is listed twice", key))
		case !metricKeyPattern.MatchString(key):
			errs = append(errs, fmt.Errorf("metric %q: a metric key is 1 to 64 lowercase letters, digits, colons and hyphens, and starts with a letter, such as merge-rate", key))
		}
		seen[key] = true
	}

	return errs
}

func checkVariant(v Variant) error {
	var errs []error
	if !experimentNamePattern.MatchString(v.Name) {
		errs = append(errs, errNamePattern)
	}
	switch {
	case v.Weight < 1:
		errs = append(errs, fmt.Errorf("weight must be at least 1, got %d", v.Weight))
	case v.Weight > MaxWeight:
		errs = append(errs, fmt.Errorf("weight must be at most %d, got %d", MaxWeight, v.Weight))
	}
	switch n := utf8.RuneCountInString(v.Model); {
	case v.Model == "" && v.Account == "":
		errs = append(errs, errors.New("model or account is required"))
	case v.Model == "":
	case n > MaxModelLength:
		errs = append(errs, fmt.Errorf("model is %d characters, and the server takes at most %d", n, MaxModelLength))
	case !modelPattern.MatchString(v.Model):
		errs = append(errs, fmt.Errorf("model %q holds a control character", v.Model))
	default:
		if err := checkWord("model", v.Model); err != nil {
			errs = append(errs, err)
		}
	}

	return errors.Join(errs...)
}

var errNamePattern = errors.New("a name is 1 to 64 lowercase letters, digits, hyphens and underscores, and starts with a letter or a digit, such as impl-model")
