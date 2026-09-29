<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * Finds the numbered definitions of a rendered version, such as "R3: Title.",
 * and marks each later mention of one so the page can remind the reader of it.
 *
 * The marks must add NO text: comment anchors are measured against the pane's
 * textContent, so only tags and attributes may change here.
 */
final readonly class ReferenceReminderInjector
{
    private const string ID = '[A-Z]{1,3}[0-9]{1,3}';
    private const string GLUE = '\p{L}\p{N}-';
    private const int MAX_LENGTH = 200;
    private const array SKIPPED = ['code', 'pre', 'a', 'label', 'button', 'textarea', 'script', 'style'];

    /**
     * @return array<string, string> the first sentence of each definition, keyed by ID
     */
    public function definitions(string $html): array
    {
        $definitions = [];
        foreach ($this->scan($this->tokens($html)) as $definition) {
            $definitions[$definition['id']] ??= $this->firstSentence($definition['text']);
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definitions keyed by ID; only the keys are read
     */
    public function inject(string $html, array $definitions): string
    {
        if ([] === $definitions) {
            return $html;
        }

        $tokens = $this->tokens($html);
        $lead = [];
        $marked = [];
        foreach ($this->scan($tokens) as $definition) {
            $id = $definition['id'];
            if (!isset($definitions[$id])) {
                continue;
            }

            for ($i = $definition['leadStart']; $i <= $definition['leadEnd']; ++$i) {
                $lead[$i] = true;
            }

            $item = $tokens[$definition['item']];
            if (!isset($marked[$id]) && 1 !== preg_match('~\sid=~', $item)) {
                $tokens[$definition['item']] = '<li id="ref-'.$id.'"'.substr($item, 3);
            }
            $marked[$id] = true;
        }

        $skipDepth = 0;
        foreach ($tokens as $i => $token) {
            if (1 === $i % 2) {
                [$name, $closing] = $this->tag($token);
                if (in_array($name, self::SKIPPED, true)) {
                    $skipDepth = max(0, $skipDepth + ($closing ? -1 : 1));
                }
            } elseif (0 === $skipDepth && !isset($lead[$i])) {
                $tokens[$i] = $this->wrap($token, $definitions);
            }
        }

        return implode('', $tokens);
    }

    /**
     * Text tokens sit at even indexes and tags at odd ones. The sanitizer
     * encodes every "<" and ">" in text and attributes, so the split is exact.
     *
     * @return list<string>
     */
    private function tokens(string $html): array
    {
        return preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE)
            ?: throw new \RuntimeException('Reference tokenizing failed: '.preg_last_error_msg().'.');
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<array{id: string, text: string, item: int, leadStart: int, leadEnd: int}> in document order
     */
    private function scan(array $tokens): array
    {
        $open = [];
        $found = [];
        foreach ($tokens as $i => $token) {
            if (0 === $i % 2) {
                foreach ($open as $k => $item) {
                    if (0 === $item['lists']) {
                        $open[$k]['text'] .= $token;
                    }
                }
                $top = array_key_last($open);
                if (null !== $top) {
                    if ('start' === $open[$top]['state'] && '' !== trim($token)) {
                        $open[$top]['state'] = 'none';
                    } elseif ('lead' === $open[$top]['state']) {
                        $open[$top]['lead'] .= $token;
                    }
                }

                continue;
            }

            [$name, $closing] = $this->tag($token);
            if ('li' === $name) {
                if (!$closing) {
                    $open[] = ['item' => $i, 'text' => '', 'lead' => '', 'lists' => 0, 'state' => 'start', 'leadStart' => 0, 'leadEnd' => 0];
                } elseif (null !== ($item = array_pop($open))) {
                    $definition = $this->definition($item);
                    if (null !== $definition) {
                        $found[$item['item']] = $definition;
                    }
                }

                continue;
            }

            foreach ($open as $k => $item) {
                if ('ul' === $name || 'ol' === $name) {
                    $open[$k]['lists'] += $closing ? -1 : 1;
                } elseif (0 === $item['lists'] && ('p' === $name || 'br' === $name)) {
                    $open[$k]['text'] .= ' ';
                }
            }

            $top = array_key_last($open);
            if (null === $top) {
                continue;
            }
            if ('start' === $open[$top]['state']) {
                if (!$closing && 'strong' === $name) {
                    $open[$top]['state'] = 'lead';
                    $open[$top]['leadStart'] = $i;
                } elseif ($closing || 'p' !== $name) {
                    $open[$top]['state'] = 'none';
                }
            } elseif ('lead' === $open[$top]['state'] && $closing && 'strong' === $name) {
                $open[$top]['state'] = 'done';
                $open[$top]['leadEnd'] = $i;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * @param array{item: int, text: string, lead: string, lists: int, state: string, leadStart: int, leadEnd: int} $item
     *
     * @return array{id: string, text: string, item: int, leadStart: int, leadEnd: int}|null
     */
    private function definition(array $item): ?array
    {
        if ('done' !== $item['state']
            || 1 !== preg_match('~^('.self::ID.')(?!['.self::GLUE.'])~u', $this->plain($item['lead']), $id)
            || 1 !== preg_match('~^'.$id[1].'(?::| —| -|\.)(?=\s|$)(.*)$~su', $this->plain($item['text']), $text)
        ) {
            return null;
        }

        return ['id' => $id[1], 'text' => trim($text[1]), 'item' => $item['item'], 'leadStart' => $item['leadStart'], 'leadEnd' => $item['leadEnd']];
    }

    private function plain(string $html): string
    {
        $plain = preg_replace('~\s+~u', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ?? throw new \RuntimeException('Reference reading failed: '.preg_last_error_msg().'.');

        return trim($plain);
    }

    private function firstSentence(string $text): string
    {
        $sentence = 1 === preg_match('~^.*?[.!?](?=\s|$)~su', $text, $match) ? $match[0] : $text;

        return mb_strlen($sentence) > self::MAX_LENGTH
            ? rtrim(mb_substr($sentence, 0, self::MAX_LENGTH - 1)).'…'
            : $sentence;
    }

    /**
     * @return array{string, bool} the lower-case tag name, empty for a comment, and whether it closes
     */
    private function tag(string $token): array
    {
        return 1 === preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $token, $match)
            ? [strtolower($match[2]), '/' === $match[1]]
            : ['', false];
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private function wrap(string $text, array $definitions): string
    {
        // Entities are left whole, so "&#X1;" never reads as the ID X1.
        $parts = preg_split('~(&[#A-Za-z0-9]+;)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE)
            ?: throw new \RuntimeException('Reference injection failed: '.preg_last_error_msg().'.');

        foreach ($parts as $i => $part) {
            if (1 === $i % 2) {
                continue;
            }

            $parts[$i] = preg_replace_callback(
                '~(?<!['.self::GLUE.'])'.self::ID.'(?!['.self::GLUE.'])~u',
                static fn (array $m): string => isset($definitions[$m[0]])
                    ? '<span class="lp-ref" data-ref="'.$m[0].'" tabindex="0">'.$m[0].'</span>'
                    : $m[0],
                $part,
            ) ?? throw new \RuntimeException('Reference injection failed: '.preg_last_error_msg().'.');
        }

        return implode('', $parts);
    }
}
