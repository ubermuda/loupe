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
)

// experimentNamePattern is the shape the server takes for an experiment or a
// variant name.
var experimentNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,63}$`)

// modelPattern refuses the control characters the server refuses in a model.
var modelPattern = regexp.MustCompile(`^[^\p{C}]+$`)

// Experiment splits the runs of the rules that join it between models.
type Experiment struct {
	Name     string    `yaml:"name"`
	Variants []Variant `yaml:"variants"`
}

// Variant is one model of an experiment, and its share of the cards.
type Variant struct {
	Name   string `yaml:"name"`
	Weight int    `yaml:"weight"`
	Model  string `yaml:"model"`
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

// clone copies the variants, so a caller cannot change the set's experiment.
func (e Experiment) clone() *Experiment {
	e.Variants = slices.Clone(e.Variants)

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

	return errors.Join(errs...)
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
	case v.Model == "":
		errs = append(errs, errors.New("model is required"))
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
