package stream

import (
	"regexp"
	"slices"
	"strings"
)

// DefaultPrograms are the programs whose second word names a subcommand, for a
// project that sets no list of its own.
var DefaultPrograms = []string{"git", "just", "npm", "pnpm", "yarn", "cargo", "go", "docker", "gh", "composer", "make", "pip", "uv"}

const (
	maxSignatures = 20
	maxSignature  = 120
)

var (
	subcommandPattern = regexp.MustCompile(`^[a-z][a-z0-9:_-]{0,30}$`)
	assignPattern     = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*=`)
	digitsPattern     = regexp.MustCompile(`^[0-9]+$`)
)

// keywords are the shell words that lead a simple command and name no program.
var keywords = []string{"do", "then", "else", "elif", "if", "!", "time", "done", "fi", "esac"}

// Command is one simple command of a shell call. Sub is its second word when
// that word looks like a subcommand, and "" otherwise.
type Command struct {
	Program string
	Sub     string
}

// Signatures names what a call ran, with no argument a person typed. A shell
// call gets a signature for each program its commands run. Any other call
// gets its tool name. programs are the programs that keep a subcommand, and
// nil means DefaultPrograms.
func Signatures(c Call, programs []string) []string {
	if c.Kind != KindShell {
		if c.Tool == "" {
			return []string{}
		}

		return []string{c.Tool}
	}

	return signaturesOf(c.Commands, programs)
}

// signaturesOf keeps a subcommand only for a program on the list.
func signaturesOf(commands []Command, programs []string) []string {
	if programs == nil {
		programs = DefaultPrograms
	}
	sigs := []string{}
	for _, c := range commands {
		sig := c.Program
		if c.Sub != "" && slices.Contains(programs, c.Program) {
			sig += " " + c.Sub
		}
		sig = Cut(sig, maxSignature)
		if len(sigs) < maxSignatures && !slices.Contains(sigs, sig) {
			sigs = append(sigs, sig)
		}
	}

	return sigs
}

// heredoc is a here-document whose body the tokenizer must skip.
type heredoc struct {
	delimiter string
	stripTabs bool
}

// tokenizer splits a shell command into simple commands. It reads no
// substitution inside double quotes, so a quoted text never names a program.
type tokenizer struct {
	s        string
	commands []Command

	words  []string
	word   strings.Builder
	inWord bool
	quoted bool
	// target says the next word is the target of a redirection, and delimiter
	// that it ends a here-document.
	target    bool
	delimiter bool
	stripTabs bool
	heredocs  []heredoc
}

// Commands are the distinct simple commands of a shell command, at most
// maxSignatures of them, in order.
func Commands(command string) []Command {
	t := &tokenizer{s: command}
	t.run()

	return t.commands
}

func commandSignatures(command string, programs []string) []string {
	sigs := signaturesOf(Commands(command), programs)
	if len(sigs) == 0 {
		return nil
	}

	return sigs
}

func (t *tokenizer) run() {
	s := t.s
	for i := 0; i < len(s); {
		c := s[i]
		switch {
		case c == '\\':
			if i+1 < len(s) && s[i+1] != '\n' {
				t.add(s[i+1 : i+2])
			}
			i += 2
		case c == '\'':
			end := strings.IndexByte(s[i+1:], '\'')
			if end < 0 {
				end = len(s) - i - 1
			}
			t.add(s[i+1 : i+1+end])
			t.quoted = true
			i += end + 2
		case c == '"':
			i = t.doubleQuoted(i + 1)
		case c == '#' && !t.inWord:
			for i < len(s) && s[i] != '\n' {
				i++
			}
		case c == ' ' || c == '\t':
			t.endWord()
			i++
		case c == '\n':
			t.endCommand()
			i = t.skipHeredocs(i + 1)
		case c == '&' && i+1 < len(s) && s[i+1] == '>':
			i = t.redirect(i + 1)
		case c == ';' || c == '&' || c == '|' || c == '(' || c == ')' || c == '`':
			t.endCommand()
			i++
		case c == '$' && i+1 < len(s) && s[i+1] == '(':
			t.endCommand()
			i += 2
		case c == '$' && i+1 < len(s) && s[i+1] == '{':
			end := strings.IndexByte(s[i:], '}')
			if end < 0 {
				end = len(s) - i - 1
			}
			t.add(s[i : i+end+1])
			i += end + 1
		case c == '<' || c == '>':
			if t.inWord && !t.quoted && digitsPattern.MatchString(t.word.String()) {
				t.resetWord()
			}
			i = t.redirect(i)
		default:
			t.add(s[i : i+1])
			i++
		}
	}
	t.endCommand()
}

// doubleQuoted adds the text up to the closing quote as is, and returns the
// index after it.
func (t *tokenizer) doubleQuoted(i int) int {
	s := t.s
	var b strings.Builder
	for ; i < len(s) && s[i] != '"'; i++ {
		if s[i] == '\\' && i+1 < len(s) && strings.IndexByte("\"\\$`", s[i+1]) >= 0 {
			i++
		}
		b.WriteByte(s[i])
	}
	t.add(b.String())
	t.quoted = true

	return i + 1
}

// redirect reads the operator at i, and marks the word after it as its target.
func (t *tokenizer) redirect(i int) int {
	t.endWord()
	s := t.s
	if strings.HasPrefix(s[i:], "<<<") {
		t.target = true

		return i + 3
	}
	if strings.HasPrefix(s[i:], "<<") {
		i += 2
		t.stripTabs = i < len(s) && s[i] == '-'
		if t.stripTabs {
			i++
		}
		t.delimiter = true

		return i
	}
	for i < len(s) && strings.IndexByte("<>&|", s[i]) >= 0 {
		i++
	}
	// A process substitution such as <(cmd) runs a command, so its first word
	// is a program.
	t.target = i >= len(s) || s[i] != '('

	return i
}

// skipHeredocs skips the bodies of the here-documents the line opened.
func (t *tokenizer) skipHeredocs(i int) int {
	s := t.s
	for _, h := range t.heredocs {
		for i < len(s) {
			end := strings.IndexByte(s[i:], '\n')
			if end < 0 {
				end = len(s) - i
			}
			line := s[i : i+end]
			i += end + 1
			if h.stripTabs {
				line = strings.TrimLeft(line, "\t")
			}
			if line == h.delimiter {
				break
			}
		}
	}
	t.heredocs = nil

	return min(i, len(s))
}

func (t *tokenizer) add(text string) {
	t.word.WriteString(text)
	t.inWord = true
}

func (t *tokenizer) resetWord() {
	t.word.Reset()
	t.inWord, t.quoted = false, false
}

func (t *tokenizer) endWord() {
	if !t.inWord {
		return
	}
	w, quoted := t.word.String(), t.quoted
	t.resetWord()
	switch {
	case t.delimiter:
		t.heredocs = append(t.heredocs, heredoc{delimiter: w, stripTabs: t.stripTabs})
		t.delimiter = false
	case t.target:
		t.target = false
	case !quoted && (w == "{" || w == "}"):
		t.endCommand()
	default:
		t.words = append(t.words, w)
	}
}

// endCommand ends the simple command and keeps its program and subcommand.
func (t *tokenizer) endCommand() {
	t.endWord()
	words := t.words
	t.words, t.target = nil, false
	for len(words) > 0 && (assignPattern.MatchString(words[0]) || slices.Contains(keywords, words[0])) {
		words = words[1:]
	}
	if len(words) == 0 {
		return
	}
	program := words[0][strings.LastIndexByte(words[0], '/')+1:]
	if program == "" {
		return
	}
	c := Command{Program: Cut(program, maxSignature)}
	if len(words) > 1 && subcommandPattern.MatchString(words[1]) {
		c.Sub = words[1]
	}
	if len(t.commands) < maxSignatures && !slices.Contains(t.commands, c) {
		t.commands = append(t.commands, c)
	}
}
