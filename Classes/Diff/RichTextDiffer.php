<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Diff;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use Neos\Diff\SequenceMatcher;
use Neos\Flow\Annotations as Flow;

/**
 * Compares two rich-text values on the markup level and reports the changes a
 * word-level text diff cannot show, because it strips all tags before diffing:
 *
 * - a link that now points somewhere else, or opens differently
 * - words that were linked or unlinked while the words themselves stayed
 * - character formatting such as bold, italic or the heading level
 *
 * Findings are returned as plain arrays; turning them into labels and
 * translations is the caller's job, so this class needs no dependencies.
 *
 * Findings are deliberately conservative: they are only stated when the
 * surrounding words prove what happened. As soon as the anchor text itself was
 * edited or the structure around it changed, nothing is reported here and the
 * caller's word-level text diff (or its "technical change" note) owns the
 * story - a silent finding is better than a wrong one.
 *
 * Note on the parser: libxml silently truncates markup nested deeper than ~254
 * levels, which cannot occur in rich-text editor content.
 *
 * @Flow\Scope("singleton")
 */
class RichTextDiffer
{
    /**
     * Elements that contribute a human-readable formatting mark. Links are
     * reported by the link comparison, so an <a> deliberately has no mark.
     */
    protected const MARK_BY_TAG_NAME = [
        'strong' => 'bold',
        'b' => 'bold',
        'em' => 'italic',
        'i' => 'italic',
        'u' => 'underline',
        's' => 'strikethrough',
        'del' => 'strikethrough',
        'strike' => 'strikethrough',
        'sub' => 'subscript',
        'sup' => 'superscript',
        'code' => 'code',
        'mark' => 'highlight',
        'h1' => 'heading1',
        'h2' => 'heading2',
        'h3' => 'heading3',
        'h4' => 'heading4',
        'h5' => 'heading5',
        'h6' => 'heading6',
    ];

    /**
     * Elements that end a word. Every other element is inline and may sit
     * inside a word or between a word and its punctuation, so its boundary
     * must not separate what stands left and right of it. Public because the
     * word-level text diff of the review module has to split words the same
     * way to stay comparable with the findings of this class.
     *
     * @var string[]
     */
    public const BLOCK_LEVEL_TAG_NAMES = [
        'br', 'p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'section',
        'article', 'header', 'footer', 'figure', 'figcaption', 'hr', 'pre',
    ];

    /**
     * Whitespace of the raw text, including the non-breaking space, at the
     * edge where a text node meets the markup around it.
     */
    protected const LEADING_WHITESPACE_PATTERN = '/^[\x{00A0}\s]/u';
    protected const TRAILING_WHITESPACE_PATTERN = '/[\x{00A0}\s]$/u';

    /**
     * Link attributes that change how a link behaves and are therefore worth
     * reporting on their own.
     */
    protected const COMPARED_LINK_ATTRIBUTES = ['target', 'title'];

    /**
     * Formatting excerpts longer than this are shortened in the middle.
     */
    protected const EXCERPT_WORD_LIMIT = 12;
    protected const EXCERPT_WORDS_KEPT = 6;

    /**
     * Compares two rich-text values and returns what changed in the markup.
     *
     * @return array<int, array<string, mixed>> list of findings, all link findings first, then formatting
     */
    public function compare(string $original, string $changed): array
    {
        if ($original === $changed || trim($original) === '' || trim($changed) === '') {
            // With one side empty there is nothing to align: every difference
            // is a plain addition or removal the text diff already shows.
            return [];
        }

        $originalDocument = $this->extract($original);
        $changedDocument = $this->extract($changed);

        return array_merge(
            $this->compareLinks($originalDocument, $changedDocument),
            $this->compareFormatting($originalDocument['segments'], $changedDocument['segments'])
        );
    }

    /**
     * Reads an HTML fragment into the two lists the comparison works on:
     * text segments carrying their formatting marks, and links carrying their
     * label and attributes. "text" holds the full normalized plain text.
     *
     * @return array{segments: array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}>, links: array<int, array<string, ?string>>, text: string}
     */
    protected function extract(string $html): array
    {
        $segments = [];
        $links = [];
        $fragment = $this->parseFragment($html);
        if ($fragment !== null) {
            $boundaryPending = false;
            $this->collect($fragment, [], null, $segments, $links, $boundaryPending);
        }

        $segments = $this->mergeSegments($segments);
        // The plain text is built from the very words the formatting
        // comparison aligns, so both stay comparable between the two sides.
        [$words] = $this->flattenSegments($segments);

        return [
            'segments' => $segments,
            // Links without a label or without a target cannot be described in
            // a review sentence, so they never become a finding.
            'links' => array_values(array_filter(
                $links,
                static fn(array $link): bool => $link['text'] !== '' && $link['href'] !== ''
            )),
            'text' => implode(' ', $words),
        ];
    }

    /**
     * Parses a rich-text value, which may be a partial or broken HTML
     * fragment. The processing instruction makes the HTML parser read the
     * fragment as UTF-8 (so umlauts survive), the wrapping element keeps
     * libxml from inventing a document structure around it.
     */
    protected function parseFragment(string $html): ?\DOMNode
    {
        $document = new \DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        return $document->getElementsByTagName('body')->item(0);
    }

    /**
     * Walks the parsed fragment in document order, carrying the formatting
     * marks of all ancestors and the innermost surrounding link. Every segment
     * also records whether it is separated from its neighbours, so a word split
     * by an inline tag can be put back together later.
     *
     * @param string[] $marks
     * @param int|null $linkIndex index in $links the current text belongs to
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $segments
     * @param array<int, array<string, ?string>> $links
     * @param bool $boundaryPending whether the text still to come starts after a separation
     */
    protected function collect(
        \DOMNode $node,
        array $marks,
        ?int $linkIndex,
        array &$segments,
        array &$links,
        bool &$boundaryPending
    ): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $value = (string)$child->nodeValue;
                $text = $this->normalizeText($value);
                if ($text === '') {
                    // Whitespace between two tags carries no words of its own,
                    // but it does separate the text on both sides of it.
                    if ($value !== '') {
                        $this->markBoundary($segments, $boundaryPending);
                    }
                    continue;
                }
                $previousSegment = $segments === [] ? null : $segments[array_key_last($segments)];
                $segment = [
                    'text' => $text,
                    'marks' => $this->normalizeMarks($marks),
                    // Only whitespace of the raw value - or a boundary the
                    // markup forces - tells a word ending here from one that
                    // continues outside this text node.
                    'spaceBefore' => $boundaryPending || preg_match(self::LEADING_WHITESPACE_PATTERN, $value) === 1,
                    'spaceAfter' => preg_match(self::TRAILING_WHITESPACE_PATTERN, $value) === 1,
                ];
                $segments[] = $segment;
                $boundaryPending = false;
                if ($linkIndex !== null) {
                    // A label is assembled by the same rule as the words are:
                    // markup inside it must not insert a space that the other
                    // side has no reason to show, or two labels naming the same
                    // link stop matching and the link change goes unreported.
                    $label = $links[$linkIndex]['text'];
                    $continuesLabel = $previousSegment !== null && $this->isContiguous($previousSegment, $segment);
                    $links[$linkIndex]['text'] = $label === '' || $continuesLabel
                        ? $label . $text
                        : $label . ' ' . $text;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            $isBlockLevel = in_array($tagName, self::BLOCK_LEVEL_TAG_NAMES, true);
            if ($isBlockLevel) {
                $this->markBoundary($segments, $boundaryPending);
            }
            $childMarks = $marks;
            if (isset(self::MARK_BY_TAG_NAME[$tagName])) {
                $childMarks[] = self::MARK_BY_TAG_NAME[$tagName];
            }
            $childLinkIndex = $linkIndex;
            if ($tagName === 'a') {
                $links[] = [
                    'text' => '',
                    'href' => (string)$this->readAttribute($child, 'href'),
                    'target' => $this->readAttribute($child, 'target'),
                    'title' => $this->readAttribute($child, 'title'),
                ];
                $childLinkIndex = array_key_last($links);
            }

            $this->collect($child, $childMarks, $childLinkIndex, $segments, $links, $boundaryPending);

            if ($isBlockLevel) {
                $this->markBoundary($segments, $boundaryPending);
            }
        }
    }

    /**
     * Records that the text collected so far and the text still to come belong
     * to different words, although no whitespace of their own says so - the
     * case of two blocks written without a line break between them.
     *
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $segments
     */
    protected function markBoundary(array &$segments, bool &$boundaryPending): void
    {
        $lastIndex = array_key_last($segments);
        if ($lastIndex !== null) {
            $segments[$lastIndex]['spaceAfter'] = true;
        }
        $boundaryPending = true;
    }

    /**
     * An attribute that is absent and one that is empty describe the same
     * behaviour, so both become null.
     */
    protected function readAttribute(\DOMElement $element, string $name): ?string
    {
        $value = trim($element->getAttribute($name));
        return $value === '' ? null : $value;
    }

    /**
     * Joins neighbouring text with the same formatting into one segment. Two
     * separated segments are joined by a single space, so neither a block
     * boundary nor a <br> can glue the last word of one block to the first of
     * the next; contiguous ones are joined without one, so a word split across
     * inline tags stays a single word.
     *
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $segments
     * @return array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}>
     */
    protected function mergeSegments(array $segments): array
    {
        $merged = [];
        foreach ($segments as $segment) {
            $lastIndex = array_key_last($merged);
            if ($lastIndex !== null && $merged[$lastIndex]['marks'] === $segment['marks']) {
                $isContiguous = $this->isContiguous($merged[$lastIndex], $segment);
                $merged[$lastIndex]['text'] .= ($isContiguous ? '' : ' ') . $segment['text'];
                // The joined segment ends where the segment just added ends.
                $merged[$lastIndex]['spaceAfter'] = $segment['spaceAfter'];
                continue;
            }
            $merged[] = $segment;
        }
        return $merged;
    }

    /**
     * Whether a segment continues the word of its predecessor, which is the
     * case when neither side of the join carries whitespace.
     *
     * @param array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool} $previous
     * @param array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool} $segment
     */
    protected function isContiguous(array $previous, array $segment): bool
    {
        return !$previous['spaceAfter'] && !$segment['spaceBefore'];
    }

    /**
     * Normalizes text the same way the word-level text diff does: entities are
     * already decoded by the parser, remaining whitespace and non-breaking
     * spaces collapse into a single space.
     */
    protected function normalizeText(string $text): string
    {
        return trim((string)preg_replace('/[\x{00A0}\s]+/u', ' ', $text));
    }

    /**
     * @param string[] $marks
     * @return string[]
     */
    protected function normalizeMarks(array $marks): array
    {
        $marks = array_values(array_unique($marks));
        sort($marks);
        return $marks;
    }

    /**
     * Pairs up the links of both sides in three passes of decreasing certainty
     * and reports what changed about the pairs that were found.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function compareLinks(array $originalDocument, array $changedDocument): array
    {
        $originalLinks = $originalDocument['links'];
        $changedLinks = $changedDocument['links'];
        if ($originalLinks === [] && $changedLinks === []) {
            return [];
        }

        // First pass: a link that kept both its label and its target is the
        // same link beyond doubt, so only its attributes can have changed.
        [$identityFindings, $originalRest, $changedRest] = $this->matchLinks(
            $originalLinks,
            $changedLinks,
            static fn(array $link): string => $link['text'] . "\x00" . (string)$link['href']
        );

        // Second pass: among the links left over, a shared label still
        // identifies the same link - the target below it was edited.
        [$labelFindings, $originalRest, $changedRest] = $this->matchLinks(
            $originalRest,
            $changedRest,
            static fn(array $link): string => $link['text']
        );

        return array_merge(
            $identityFindings,
            $labelFindings,
            $this->compareUnmatchedLinks($originalRest, $changedRest, $originalDocument, $changedDocument)
        );
    }

    /**
     * Aligns two link lists by the given key and compares the pairs the
     * alignment found, so a link keeps its identity even when links around it
     * were added or removed. Everything left unpaired is handed back for the
     * next, less certain pass.
     *
     * @param array<int, array<string, ?string>> $originalLinks
     * @param array<int, array<string, ?string>> $changedLinks
     * @param callable(array<string, ?string>): string $key
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, ?string>>, 2: array<int, array<string, ?string>>}
     */
    protected function matchLinks(array $originalLinks, array $changedLinks, callable $key): array
    {
        if ($originalLinks === [] || $changedLinks === []) {
            return [[], $originalLinks, $changedLinks];
        }

        $findings = [];
        $originalRest = [];
        $changedRest = [];
        $matcher = new SequenceMatcher(array_map($key, $originalLinks), array_map($key, $changedLinks));
        foreach ($matcher->getOpCodes() as [$tag, $i1, $i2, $j1, $j2]) {
            if ($tag === 'equal') {
                for ($offset = 0, $length = $i2 - $i1; $offset < $length; $offset++) {
                    $findings = array_merge(
                        $findings,
                        $this->compareLinkPair($originalLinks[$i1 + $offset], $changedLinks[$j1 + $offset])
                    );
                }
                continue;
            }
            for ($index = $i1; $index < $i2; $index++) {
                $originalRest[] = $originalLinks[$index];
            }
            for ($index = $j1; $index < $j2; $index++) {
                $changedRest[] = $changedLinks[$index];
            }
        }
        return [$findings, $originalRest, $changedRest];
    }

    /**
     * Reports links without a partner as added or removed - but only when the
     * plain text of both sides is word for word the same, which is the one case
     * that proves the words stayed and merely the linking around them changed.
     * As soon as a single word was edited, the word-level text diff shows the
     * passage anyway and a guess about the link would contradict it.
     *
     * @param array<int, array<string, ?string>> $originalRest
     * @param array<int, array<string, ?string>> $changedRest
     * @return array<int, array<string, mixed>>
     */
    protected function compareUnmatchedLinks(
        array $originalRest,
        array $changedRest,
        array $originalDocument,
        array $changedDocument
    ): array {
        if ($originalRest === [] && $changedRest === []) {
            return [];
        }
        if ($originalDocument['text'] !== $changedDocument['text']) {
            return [];
        }

        $findings = [];
        $originalHrefs = array_column($originalRest, 'href');
        $changedHrefs = array_column($changedRest, 'href');
        foreach ($originalRest as $link) {
            // The same target still present on the other side means the link
            // survived under a different label - a rewrite, not a removal.
            if (!in_array($link['href'], $changedHrefs, true)) {
                $findings[] = ['kind' => 'linkRemoved', 'linkText' => $link['text'], 'href' => $link['href']];
            }
        }
        foreach ($changedRest as $link) {
            if (!in_array($link['href'], $originalHrefs, true)) {
                $findings[] = ['kind' => 'linkAdded', 'linkText' => $link['text'], 'href' => $link['href']];
            }
        }
        return $findings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function compareLinkPair(array $original, array $changed): array
    {
        if ($original['href'] !== $changed['href']) {
            // A new target is the whole story of this link; attributes that
            // merely repeat the target would only add noise.
            return [[
                'kind' => 'linkTarget',
                'linkText' => $changed['text'],
                'original' => $original['href'],
                'changed' => $changed['href'],
            ]];
        }

        $findings = [];
        foreach (self::COMPARED_LINK_ATTRIBUTES as $attribute) {
            if ($this->linkAttributeValuesAreEqual($attribute, $original[$attribute], $changed[$attribute])) {
                continue;
            }
            $findings[] = [
                'kind' => 'linkAttribute',
                'linkText' => $changed['text'],
                'attribute' => $attribute,
                'original' => $original[$attribute],
                'changed' => $changed[$attribute],
            ];
        }
        return $findings;
    }

    /**
     * Browsers read the target keyword case-insensitively, so "_BLANK" and
     * "_blank" describe the same behaviour. A title is shown to the reader and
     * therefore compares literally.
     */
    protected function linkAttributeValuesAreEqual(string $attribute, ?string $original, ?string $changed): bool
    {
        if ($attribute === 'target') {
            return mb_strtolower((string)$original) === mb_strtolower((string)$changed);
        }
        return $original === $changed;
    }

    /**
     * Aligns both texts word by word and reports words that survived the edit
     * but are formatted differently now.
     *
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $originalSegments
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $changedSegments
     * @return array<int, array<string, mixed>>
     */
    protected function compareFormatting(array $originalSegments, array $changedSegments): array
    {
        [$originalWords, $originalMarks] = $this->flattenSegments($originalSegments);
        [$changedWords, $changedMarks] = $this->flattenSegments($changedSegments);
        if ($originalWords === [] || $changedWords === []) {
            return [];
        }

        $findings = [];
        $matcher = new SequenceMatcher($originalWords, $changedWords);
        foreach ($matcher->getOpCodes() as [$tag, $i1, $i2, $j1, $j2]) {
            if ($tag !== 'equal') {
                // Inserted, deleted and rewritten words are shown by the
                // word-level text diff already.
                continue;
            }

            // Neighbouring words with the same formatting delta describe one
            // edit and are reported as a single finding.
            $groupWords = [];
            $groupMarks = null;
            for ($offset = 0, $length = $i2 - $i1; $offset < $length; $offset++) {
                $marks = [$originalMarks[$i1 + $offset], $changedMarks[$j1 + $offset]];
                $isChanged = $marks[0] !== $marks[1];
                if ($groupWords !== [] && (!$isChanged || $marks !== $groupMarks)) {
                    $findings[] = $this->renderFormattingFinding($groupWords, $groupMarks[0], $groupMarks[1]);
                    $groupWords = [];
                }
                if (!$isChanged) {
                    continue;
                }
                $groupMarks = $marks;
                $groupWords[] = $originalWords[$i1 + $offset];
            }
            if ($groupWords !== []) {
                $findings[] = $this->renderFormattingFinding($groupWords, $groupMarks[0], $groupMarks[1]);
            }
        }
        return $findings;
    }

    /**
     * Splits the segments into one entry per word plus the marks that word
     * carries, so both sides can be aligned word by word.
     *
     * A contiguous segment continues the last word instead of starting a new
     * one, and that word keeps the marks of the segment it started in: the
     * comma behind a bolded "Uhr" belongs to the word "Uhr," and is therefore
     * reported as part of the passage that became bold.
     *
     * @param array<int, array{text: string, marks: string[], spaceBefore: bool, spaceAfter: bool}> $segments
     * @return array{0: string[], 1: array<int, string[]>}
     */
    protected function flattenSegments(array $segments): array
    {
        $words = [];
        $marks = [];
        $previous = null;
        foreach ($segments as $segment) {
            $continuesWord = $words !== [] && $previous !== null && $this->isContiguous($previous, $segment);
            foreach (explode(' ', $segment['text']) as $word) {
                if ($word === '') {
                    continue;
                }
                if ($continuesWord) {
                    $words[array_key_last($words)] .= $word;
                    $continuesWord = false;
                    continue;
                }
                $words[] = $word;
                $marks[] = $segment['marks'];
            }
            $previous = $segment;
        }
        return [$words, $marks];
    }

    /**
     * @param string[] $words
     * @param string[] $originalMarks
     * @param string[] $changedMarks
     * @return array<string, mixed>
     */
    protected function renderFormattingFinding(array $words, array $originalMarks, array $changedMarks): array
    {
        return [
            'kind' => 'formatting',
            'text' => $this->renderExcerpt($words),
            'originalMarks' => $originalMarks,
            'changedMarks' => $changedMarks,
        ];
    }

    /**
     * Keeps a reformatted passage recognizable without repeating it in full.
     *
     * @param string[] $words
     */
    protected function renderExcerpt(array $words): string
    {
        if (count($words) <= self::EXCERPT_WORD_LIMIT) {
            return implode(' ', $words);
        }
        return implode(' ', array_slice($words, 0, self::EXCERPT_WORDS_KEPT))
            . ' … '
            . implode(' ', array_slice($words, -self::EXCERPT_WORDS_KEPT));
    }
}
