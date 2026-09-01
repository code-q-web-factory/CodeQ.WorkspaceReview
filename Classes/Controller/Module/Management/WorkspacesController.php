<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Controller\Module\Management;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Diff\SequenceMatcher;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\I18n\Locale;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Controller\Module\Management\WorkspacesController as NeosWorkspacesController;
use Neos\Neos\Service\UserService as BackendUserService;

/**
 * Workspace review module with human-readable change rendering ("change cards"):
 *
 * - word-level text diffs instead of line-based ones, so small edits do not
 *   mark whole paragraphs as changed
 * - property values of select/toggle editors are shown with their translated
 *   editor labels instead of raw identifiers
 * - booleans, references and other non-string values render as readable
 *   before/after values instead of being silently dropped
 * - node visibility changes appear as an explicit change entry
 * - HTML entities are decoded before diffing, so titles show "&" not "&amp;"
 * - every changed node is guaranteed a visible explanation
 *
 * @Flow\Scope("singleton")
 */
class WorkspacesController extends NeosWorkspacesController
{
    /**
     * When rendering unchanged words between two edits, runs longer than this
     * are collapsed to their first and last words plus an ellipsis.
     */
    protected const CONTEXT_COLLAPSE_THRESHOLD = 24;
    protected const CONTEXT_WORDS_KEPT = 10;

    /**
     * Inserted/deleted runs longer than this are shortened in the middle.
     */
    protected const EDITED_RUN_COLLAPSE_THRESHOLD = 50;

    /**
     * Editorially relevant Neos system fields which are stored outside the
     * regular property collection and therefore need explicit comparison.
     */
    protected const SYSTEM_FIELD_DEFINITIONS = [
        '_hiddenBeforeDateTime' => ['getter' => 'getHiddenBeforeDateTime', 'label' => 'system.hiddenBefore'],
        '_hiddenAfterDateTime' => ['getter' => 'getHiddenAfterDateTime', 'label' => 'system.hiddenAfter'],
        '_hiddenInIndex' => ['getter' => 'isHiddenInIndex', 'label' => 'system.hiddenInIndex'],
        '_accessRoles' => ['getter' => 'getAccessRoles', 'label' => 'system.accessRoles'],
        '_nodeType' => ['getter' => 'getNodeType', 'label' => 'system.nodeType'],
        '_path' => ['getter' => 'getPath', 'label' => 'system.path'],
        '_index' => ['getter' => 'getIndex', 'label' => 'system.position'],
    ];

    /**
     * @Flow\Inject
     * @var BackendUserService
     */
    protected $backendUserService;

    /**
     * Adds a human-readable per-document change summary on top of the site
     * changes computed by the core controller.
     *
     * @param Workspace $selectedWorkspace
     * @return array
     */
    protected function computeSiteChanges(Workspace $selectedWorkspace)
    {
        $siteChanges = parent::computeSiteChanges($selectedWorkspace);
        foreach ($siteChanges as $siteKey => $site) {
            foreach ($site['documents'] as $dimension => $documents) {
                foreach ($documents as $documentPath => $document) {
                    $siteChanges[$siteKey]['documents'][$dimension][$documentPath]['summary'] = $this->renderDocumentSummary($document);
                }
            }
        }
        return $siteChanges;
    }

    /**
     * Builds a summary like "2 texts · 1 setting · 1 new element" for one
     * changed document, counting change categories across all its nodes.
     */
    protected function renderDocumentSummary(array $document): string
    {
        $counts = ['created' => 0, 'deleted' => 0, 'moved' => 0, 'texts' => 0, 'media' => 0, 'settings' => 0, 'visibility' => 0];
        foreach ($document['changes'] ?? [] as $change) {
            /** @var NodeInterface $node */
            $node = $change['node'];
            if ($node->isRemoved()) {
                $counts['deleted']++;
                continue;
            }
            if ($change['isNew'] ?? false) {
                // A new node's property diff is all insertions; counting each
                // property would inflate the summary, so count the node once.
                $counts['created']++;
                continue;
            }
            if ($change['isMoved'] ?? false) {
                $counts['moved']++;
            }
            foreach ($change['contentChanges'] ?? [] as $contentChange) {
                switch ($contentChange['type']) {
                    case 'text':
                        $counts['texts']++;
                        break;
                    case 'image':
                    case 'asset':
                        $counts['media']++;
                        break;
                    case 'value':
                    case 'datetime':
                        $counts['settings']++;
                        break;
                    case 'visibility':
                        $counts['visibility']++;
                        break;
                }
            }
        }

        $labelIds = [
            'created' => 'summary.newElements',
            'deleted' => 'summary.deletedElements',
            'moved' => 'summary.movedElements',
            'texts' => 'summary.texts',
            'media' => 'summary.media',
            'settings' => 'summary.settings',
            'visibility' => 'summary.visibility',
        ];
        $parts = [];
        foreach ($labelIds as $countKey => $labelId) {
            if ($counts[$countKey] > 0) {
                $parts[] = $this->translateOwn($labelId, [$counts[$countKey]], $counts[$countKey]);
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Type-aware replacement for the core property diff rendering.
     *
     * @param NodeInterface $changedNode
     * @return array
     */
    protected function renderContentChanges(NodeInterface $changedNode)
    {
        $contentChanges = [];
        $originalNode = $this->getOriginalNode($changedNode);
        $changeNodePropertiesDefaults = $changedNode->getNodeType()->getDefaultValuesForProperties();

        foreach ($changedNode->getProperties() as $propertyName => $changedPropertyValue) {
            if (
                ($originalNode === null && empty($changedPropertyValue))
                || (isset($changeNodePropertiesDefaults[$propertyName]) && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName])
            ) {
                continue;
            }
            $originalPropertyValue = ($originalNode === null ? null : $originalNode->getProperty($propertyName));
            if ($changedPropertyValue === $originalPropertyValue && !$changedNode->isRemoved()) {
                continue;
            }
            $change = $this->renderPropertyChange($propertyName, $originalPropertyValue, $changedPropertyValue, $changedNode);
            if ($change !== null) {
                $contentChanges[$propertyName] = $change;
            }
        }

        if ($originalNode !== null && $originalNode->isHidden() !== $changedNode->isHidden()) {
            $contentChanges['_hidden'] = [
                'type' => 'visibility',
                'propertyLabel' => $this->translateOwn('visibility.label'),
                'hidden' => $changedNode->isHidden(),
                'message' => $this->translateOwn($changedNode->isHidden() ? 'visibility.hidden' : 'visibility.shown'),
            ];
        }

        if ($originalNode !== null) {
            $contentChanges += $this->renderSystemFieldChanges($originalNode, $changedNode);
        }

        // Guarantee an explanation: a node that is neither removed, new nor
        // moved but has no renderable property change would otherwise appear
        // in the list without any stated reason.
        $isNew = $originalNode === null;
        $isMoved = $originalNode !== null && $originalNode->getPath() !== $changedNode->getPath();
        if ($contentChanges === [] && !$changedNode->isRemoved() && !$isNew && !$isMoved) {
            $contentChanges['_note'] = [
                'type' => 'note',
                'propertyLabel' => '',
                'message' => $this->translateOwn('change.noVisibleChanges'),
            ];
        }

        return $contentChanges;
    }

    /**
     * Compares Neos system fields, which NodeInterface::getProperties() does
     * not expose, and renders every actual change as a regular review entry.
     */
    protected function renderSystemFieldChanges(NodeInterface $originalNode, NodeInterface $changedNode): array
    {
        $changes = [];
        foreach (self::SYSTEM_FIELD_DEFINITIONS as $propertyName => $definition) {
            $getter = $definition['getter'];
            $originalValue = $originalNode->{$getter}();
            $changedValue = $changedNode->{$getter}();
            if ($this->systemFieldValuesAreEqual($originalValue, $changedValue)) {
                continue;
            }

            if ($originalValue instanceof \DateTimeInterface || $changedValue instanceof \DateTimeInterface) {
                $changes[$propertyName] = [
                    'type' => 'datetime',
                    'propertyLabel' => $this->translateOwn($definition['label']),
                    'original' => $originalValue,
                    'changed' => $changedValue,
                ];
                continue;
            }

            $changes[$propertyName] = [
                'type' => 'value',
                'propertyLabel' => $this->translateOwn($definition['label']),
                'original' => $this->renderSystemFieldValue($originalValue),
                'changed' => $this->renderSystemFieldValue($changedValue),
            ];
        }
        return $changes;
    }

    protected function systemFieldValuesAreEqual($originalValue, $changedValue): bool
    {
        if ($originalValue instanceof \DateTimeInterface && $changedValue instanceof \DateTimeInterface) {
            return $originalValue->getTimestamp() === $changedValue->getTimestamp();
        }
        if ($originalValue instanceof NodeType && $changedValue instanceof NodeType) {
            return $originalValue->getName() === $changedValue->getName();
        }
        return $originalValue === $changedValue;
    }

    protected function renderSystemFieldValue($value): string
    {
        if ($value instanceof NodeType) {
            $label = (string)$value->getLabel();
            return $label === '' ? $value->getName() : $this->translateShorthand($label);
        }
        if ($value === null || $value === '' || $value === []) {
            return $this->translateOwn('value.empty');
        }
        if (is_bool($value)) {
            return $this->translateOwn($value ? 'value.yes' : 'value.no');
        }
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'renderSystemFieldValue'], $value));
        }
        return $this->cleanLabel((string)$value);
    }

    /**
     * Classifies a single property change and renders it human-readable.
     * Returns null if the change turns out to be invisible after formatting.
     */
    protected function renderPropertyChange(string $propertyName, $originalValue, $changedValue, NodeInterface $changedNode): ?array
    {
        $propertyLabel = $this->getPropertyLabel($propertyName, $changedNode);
        $isRemoved = $changedNode->isRemoved();

        if (
            ($originalValue instanceof ImageInterface || $originalValue === null)
            && ($changedValue instanceof ImageInterface || $changedValue === null)
            && ($originalValue !== null || $changedValue !== null)
        ) {
            return [
                'type' => 'image',
                'propertyLabel' => $propertyLabel,
                'original' => $this->loadAsset($originalValue),
                'changed' => $isRemoved ? null : $this->loadAsset($changedValue),
            ];
        }

        if ($originalValue instanceof AssetInterface || $changedValue instanceof AssetInterface) {
            return [
                'type' => 'asset',
                'propertyLabel' => $propertyLabel,
                'original' => $this->loadAsset($originalValue),
                'changed' => $isRemoved ? null : $this->loadAsset($changedValue),
            ];
        }

        if ($originalValue instanceof \DateTimeInterface || $changedValue instanceof \DateTimeInterface) {
            $bothDates = $originalValue instanceof \DateTimeInterface && $changedValue instanceof \DateTimeInterface;
            if ($bothDates && $originalValue->getTimestamp() === $changedValue->getTimestamp() && !$isRemoved) {
                return null;
            }
            return [
                'type' => 'datetime',
                'propertyLabel' => $propertyLabel,
                'original' => $originalValue,
                'changed' => $isRemoved ? null : $changedValue,
            ];
        }

        // Values with a select/toggle editor, booleans, arrays (references,
        // multi-selects) and remaining objects render as before/after labels.
        $editorValues = $this->getEditorValues($propertyName, $changedNode);
        $isTextual = (is_string($originalValue) || $originalValue === null) && (is_string($changedValue) || $changedValue === null);
        if ($editorValues !== null || !$isTextual) {
            $originalLabel = $this->renderValueLabel($originalValue, $propertyName, $changedNode);
            $changedLabel = $isRemoved ? $this->translateOwn('value.empty') : $this->renderValueLabel($changedValue, $propertyName, $changedNode);
            if ($originalLabel === $changedLabel) {
                return null;
            }
            return [
                'type' => 'value',
                'propertyLabel' => $propertyLabel,
                'original' => $originalLabel,
                'changed' => $changedLabel,
            ];
        }

        $diffHtml = $this->renderTextDiff((string)($originalValue ?? ''), $isRemoved ? '' : (string)($changedValue ?? ''));
        if ($diffHtml === null) {
            return null;
        }
        return [
            'type' => 'text',
            'propertyLabel' => $propertyLabel,
            'diffHtml' => $diffHtml,
        ];
    }

    /**
     * Word-level inline diff as safe HTML with <ins>/<del> markers. Long
     * unchanged runs are collapsed with an ellipsis. Returns null when both
     * sides are textually identical after normalization.
     */
    protected function renderTextDiff(string $original, string $changed): ?string
    {
        $originalWords = $this->tokenizeText($original);
        $changedWords = $this->tokenizeText($changed);
        if ($originalWords === $changedWords) {
            return null;
        }

        $matcher = new SequenceMatcher($originalWords, $changedWords);
        $html = [];
        foreach ($matcher->getOpCodes() as [$tag, $i1, $i2, $j1, $j2]) {
            switch ($tag) {
                case 'equal':
                    $words = array_slice($originalWords, $i1, $i2 - $i1);
                    $html[] = $this->renderContextWords($words, $i1 === 0, $i2 === count($originalWords));
                    break;
                case 'delete':
                    $html[] = '<del>' . $this->renderEditedWords(array_slice($originalWords, $i1, $i2 - $i1)) . '</del>';
                    break;
                case 'insert':
                    $html[] = '<ins>' . $this->renderEditedWords(array_slice($changedWords, $j1, $j2 - $j1)) . '</ins>';
                    break;
                case 'replace':
                    $html[] = '<del>' . $this->renderEditedWords(array_slice($originalWords, $i1, $i2 - $i1)) . '</del>';
                    $html[] = '<ins>' . $this->renderEditedWords(array_slice($changedWords, $j1, $j2 - $j1)) . '</ins>';
                    break;
            }
        }
        return implode(' ', array_filter($html, static fn($fragment) => $fragment !== ''));
    }

    /**
     * Renders an unchanged word run, keeping only the words adjacent to the
     * surrounding edits when the run is long. Runs at the very start or end
     * of the text only need context on their edit-facing side.
     */
    protected function renderContextWords(array $words, bool $isStart, bool $isEnd): string
    {
        $ellipsis = '<span class="codeq-review-ellipsis">…</span>';
        if (count($words) <= self::CONTEXT_COLLAPSE_THRESHOLD) {
            return $this->escapeWords($words);
        }
        if ($isStart && $isEnd) {
            return $this->escapeWords($words);
        }
        if ($isStart) {
            return $ellipsis . ' ' . $this->escapeWords(array_slice($words, -self::CONTEXT_WORDS_KEPT));
        }
        if ($isEnd) {
            return $this->escapeWords(array_slice($words, 0, self::CONTEXT_WORDS_KEPT)) . ' ' . $ellipsis;
        }
        return $this->escapeWords(array_slice($words, 0, self::CONTEXT_WORDS_KEPT))
            . ' ' . $ellipsis . ' '
            . $this->escapeWords(array_slice($words, -self::CONTEXT_WORDS_KEPT));
    }

    protected function escapeWords(array $words): string
    {
        return htmlspecialchars(implode(' ', $words), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Renders an inserted or deleted word run. Very long runs (e.g. the full
     * text of a newly created element) are shortened in the middle, so a
     * single change cannot dominate the whole review page.
     */
    protected function renderEditedWords(array $words): string
    {
        $limit = self::EDITED_RUN_COLLAPSE_THRESHOLD;
        if (count($words) <= $limit) {
            return $this->escapeWords($words);
        }
        $kept = (int)floor($limit / 2);
        return $this->escapeWords(array_slice($words, 0, $kept))
            . ' <span class="codeq-review-ellipsis">…</span> '
            . $this->escapeWords(array_slice($words, -$kept));
    }

    /**
     * Normalizes a rich-text or plain value into a list of comparable words:
     * markup is stripped and entities are decoded, so "&amp;" diffs as "&".
     *
     * @return string[]
     */
    protected function tokenizeText(string $value): array
    {
        $text = preg_replace('/<br[^>]*>/i', ' ', $value);
        $text = preg_replace('/<[^>]*>/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text);
        $text = trim($text);
        return $text === '' ? [] : explode(' ', $text);
    }

    /**
     * Renders any property value as a short human-readable label, resolving
     * select/toggle editor options to their translated labels.
     */
    protected function renderValueLabel($value, string $propertyName, NodeInterface $node): string
    {
        if ($value === null || $value === '' || $value === []) {
            return $this->translateOwn('value.empty');
        }
        if (is_bool($value)) {
            return $this->translateOwn($value ? 'value.yes' : 'value.no');
        }
        if ($value instanceof NodeInterface) {
            return $this->cleanLabel($value->getLabel());
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }
        if (is_array($value)) {
            $labels = array_map(fn($entry) => $this->renderValueLabel($entry, $propertyName, $node), $value);
            return implode(', ', $labels);
        }
        if (is_scalar($value)) {
            $editorValues = $this->getEditorValues($propertyName, $node);
            // Toggle editors may define only a description (plus an icon)
            // instead of a label, so fall back to the description.
            $valueLabel = $editorValues[(string)$value]['label'] ?? $editorValues[(string)$value]['description'] ?? null;
            if ($valueLabel !== null) {
                return $this->translateShorthand((string)$valueLabel);
            }
            return $this->cleanLabel((string)$value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->cleanLabel((string)$value);
        }
        // Last resort for unknown objects: name their type instead of hiding the change.
        return get_class($value);
    }

    /**
     * Returns the configured value-label map of a select box or toggle editor,
     * or null if the property has no such static options.
     */
    protected function getEditorValues(string $propertyName, NodeInterface $node): ?array
    {
        $properties = $node->getNodeType()->getProperties();
        $values = $properties[$propertyName]['ui']['inspector']['editorOptions']['values'] ?? null;
        return is_array($values) && $values !== [] ? $values : null;
    }

    /**
     * Translates the property label immediately (in the backend user's
     * interface language), so templates can print it as-is.
     *
     * @param string $propertyName
     * @param NodeInterface $changedNode
     * @return string
     */
    protected function getPropertyLabel($propertyName, NodeInterface $changedNode)
    {
        $label = $this->translateShorthand((string)parent::getPropertyLabel($propertyName, $changedNode));
        if ($label === $propertyName) {
            // No ui.label configured: humanize the camelCase property name
            // ("subtitleColor" -> "Subtitle color") instead of showing it raw.
            $label = ucfirst(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $propertyName)));
        }
        return $label;
    }

    /**
     * Translates a "Package:Source:id" shorthand label; plain strings are
     * returned unchanged.
     */
    protected function translateShorthand(string $label): string
    {
        if (preg_match(TranslationHelper::I18N_LABEL_ID_PATTERN, $label) !== 1) {
            return $label;
        }
        [$package, $source, $id] = explode(':', $label, 3);
        try {
            return (string)$this->translator->translateById($id, [], null, $this->getInterfaceLocale(), str_replace('.', '/', $source), $package);
        } catch (\Exception $exception) {
            return $label;
        }
    }

    /**
     * Translates a label from this package's Main.xlf.
     */
    protected function translateOwn(string $id, array $arguments = [], ?int $quantity = null): string
    {
        try {
            return (string)$this->translator->translateById($id, $arguments, $quantity, $this->getInterfaceLocale(), 'Main', 'CodeQ.WorkspaceReview');
        } catch (\Exception $exception) {
            return $id;
        }
    }

    protected function getInterfaceLocale(): ?Locale
    {
        try {
            return new Locale($this->backendUserService->getInterfaceLanguage());
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * Strips markup and decodes entities so node labels and raw values read
     * naturally ("&" instead of "&amp;"). Output is escaped again by Fluid.
     */
    protected function cleanLabel(string $label): string
    {
        $label = strip_tags($label);
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $label));
    }
}
