<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Controller\Module\Management;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use CodeQ\WorkspaceReview\Diff\RichTextDiffer;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Diff\SequenceMatcher;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use Neos\Flow\I18n\Locale;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Repository\AssetRepository;
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
 * - rich-text changes the text diff cannot see (a link pointing somewhere
 *   else, words that became bold) are reported on the markup level, with
 *   internal link targets resolved to the page or asset they point at
 * - a node moved among its siblings shows its position, not the sparse
 *   internal sorting index
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
     * @Flow\Inject
     * @var RichTextDiffer
     */
    protected $richTextDiffer;

    /**
     * Resolves the "asset://<uuid>" targets of rich-text links to asset names.
     *
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

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
        $counts = [
            'created' => 0,
            'deleted' => 0,
            'moved' => 0,
            'texts' => 0,
            'media' => 0,
            'settings' => 0,
            'visibility' => 0,
            'links' => 0,
            'formatting' => 0,
            'internal' => 0,
        ];
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
            foreach ($change['contentChanges'] ?? [] as $contentChangeKey => $contentChange) {
                // A reordering within the same parent keeps the node path, so
                // the core "isMoved" flag stays false; the position entry is
                // recognizable by its key and counts as a move, not a setting.
                if ($contentChangeKey === '_index' && $contentChange['type'] === 'value') {
                    $counts['moved']++;
                    continue;
                }
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
                    case 'link':
                        $counts['links']++;
                        break;
                    case 'formatting':
                        $counts['formatting']++;
                        break;
                    case 'note':
                        $counts['internal']++;
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
            'links' => 'summary.links',
            'formatting' => 'summary.formatting',
            'internal' => 'summary.internal',
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
        $hasScalarDifference = false;

        foreach ($changedNode->getProperties() as $propertyName => $changedPropertyValue) {
            $isDefaultValue = isset($changeNodePropertiesDefaults[$propertyName])
                && $changedPropertyValue === $changeNodePropertiesDefaults[$propertyName];
            if ($originalNode === null && (empty($changedPropertyValue) || $isDefaultValue)) {
                // A new node carries no review information in properties that
                // are empty or still hold their NodeType default.
                continue;
            }
            if ($changedNode->isRemoved() && $isDefaultValue) {
                // A deleted node is compared against nothing, so listing every
                // untouched default would bury what was actually deleted.
                continue;
            }
            $originalPropertyValue = ($originalNode === null ? null : $originalNode->getProperty($propertyName));
            if ($changedPropertyValue === $originalPropertyValue && !$changedNode->isRemoved()) {
                // On an existing node a property that still holds its default
                // may well be a real change back from a non-default value, so
                // equality with the published value is the only valid skip.
                continue;
            }
            if (!$this->valueCarriesObjectIdentity($originalPropertyValue) && !$this->valueCarriesObjectIdentity($changedPropertyValue)) {
                // Only a difference between values that compare by value proves
                // the stored content really changed.
                $hasScalarDifference = true;
            }
            foreach ($this->renderPropertyChange($propertyName, $originalPropertyValue, $changedPropertyValue, $changedNode) as $index => $entry) {
                // One property can yield several entries (a text diff plus the
                // rich-text findings), so each of them needs its own key.
                $suffix = $entry['type'] === 'note' ? '#note' : ($index === 0 ? '' : '#rt' . $index);
                if ($index > 0) {
                    // All entries describe the same field; repeating its name
                    // would read as several changed fields instead of one.
                    $entry['propertyLabel'] = '';
                }
                $contentChanges[$propertyName . $suffix] = $entry;
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
        // in the list without any stated reason. Without a scalar difference
        // the node was edited and reverted, which is worth saying out loud.
        $isNew = $originalNode === null;
        $isMoved = $originalNode !== null && $originalNode->getPath() !== $changedNode->getPath();
        if ($contentChanges === [] && !$changedNode->isRemoved() && !$isNew && !$isMoved) {
            $contentChanges['_note'] = [
                'type' => 'note',
                'propertyLabel' => '',
                'message' => $this->translateOwn($hasScalarDifference ? 'change.noVisibleChanges' : 'change.identicalToOriginal'),
            ];
        }

        return $contentChanges;
    }

    /**
     * Tells whether a value compares by identity rather than by content.
     * Objects are re-instantiated per request and therefore differ even when
     * they mean the same; reference properties hand out arrays of such objects,
     * so an array counts as soon as it holds one.
     */
    protected function valueCarriesObjectIdentity($value): bool
    {
        if (is_object($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (is_object($entry)) {
                return true;
            }
        }
        return false;
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

            if ($propertyName === '_index') {
                $positionChange = $this->renderPositionChange($originalNode, $changedNode);
                if ($positionChange !== null) {
                    $changes['_index'] = $positionChange;
                }
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
     * Describes a changed sorting index as the node's position among its
     * siblings, because the raw index is a sparse internal number ("100 →
     * 150") that tells a reviewer nothing. Returns null when no position can
     * be stated honestly.
     */
    protected function renderPositionChange(NodeInterface $originalNode, NodeInterface $changedNode): ?array
    {
        $originalParent = $originalNode->getParent();
        $changedParent = $changedNode->getParent();
        if ($originalParent === null || $changedParent === null) {
            return $this->renderRawPositionChange($originalNode, $changedNode);
        }
        if ($originalParent->getPath() !== $changedParent->getPath()) {
            // Ordinals of two different sibling lists are not comparable; the
            // path entry and the "moved" badge already tell that story.
            return null;
        }

        $nodeTypeFilter = $changedNode->getNodeType()->isOfType('Neos.Neos:Document')
            ? 'Neos.Neos:Document'
            : '!Neos.Neos:Document';
        $originalSiblings = $this->collectSiblingIdentifiers($originalParent, $nodeTypeFilter);
        $changedSiblings = $this->collectSiblingIdentifiers($changedParent, $nodeTypeFilter);
        // Siblings that exist on one side only - created or deleted in this
        // workspace - would shift the ordinal without anything having moved, so
        // both sides are ranked among the siblings they share.
        $sharedSiblings = array_intersect($originalSiblings, $changedSiblings);

        $originalPosition = $this->findSiblingPosition($originalSiblings, $sharedSiblings, $originalNode->getIdentifier());
        $changedPosition = $this->findSiblingPosition($changedSiblings, $sharedSiblings, $changedNode->getIdentifier());
        if ($originalPosition === null || $changedPosition === null) {
            return $this->renderRawPositionChange($originalNode, $changedNode);
        }

        $propertyLabel = $this->translateOwn('system.position');
        if ($originalPosition['ordinal'] === $changedPosition['ordinal']) {
            // Re-sorting the siblings or inserting one above renumbers indices,
            // so a node can get a new index while keeping the place the reader
            // sees it in relative to the elements that already existed.
            return [
                'type' => 'note',
                'propertyLabel' => $propertyLabel,
                'message' => $this->translateOwn('position.internalOnly'),
            ];
        }
        return [
            'type' => 'value',
            'propertyLabel' => $propertyLabel,
            'original' => $this->translateOwn('position.ordinalOfTotal', [$originalPosition['ordinal'], $originalPosition['total']]),
            'changed' => $this->translateOwn('position.ordinalOfTotal', [$changedPosition['ordinal'], $changedPosition['total']]),
        ];
    }

    /**
     * Falls back to the raw sorting index when the node's surroundings are
     * unavailable: an unexplained number still beats hiding the change.
     */
    protected function renderRawPositionChange(NodeInterface $originalNode, NodeInterface $changedNode): array
    {
        return [
            'type' => 'value',
            'propertyLabel' => $this->translateOwn('system.position'),
            'original' => $this->renderSystemFieldValue($originalNode->getIndex()),
            'changed' => $this->renderSystemFieldValue($changedNode->getIndex()),
        ];
    }

    /**
     * Reads the identifiers of the parent's children in document order, limited
     * to the node's own kind: a page is ordered among pages, a content element
     * among the elements of its collection. The list is read in the node's own
     * context, which shows hidden and removed nodes alike, so the ordinals of
     * the base and the user workspace stay comparable.
     *
     * @return string[]
     */
    protected function collectSiblingIdentifiers(NodeInterface $parent, string $nodeTypeFilter): array
    {
        $identifiers = [];
        foreach ($parent->getChildNodes($nodeTypeFilter) as $sibling) {
            $identifiers[] = $sibling->getIdentifier();
        }
        return $identifiers;
    }

    /**
     * Ranks a node among the siblings both workspaces know about.
     *
     * @param string[] $identifiers sibling identifiers in document order
     * @param string[] $sharedIdentifiers identifiers present on both sides
     * @return array{ordinal: int, total: int}|null null if the node is not among the shared siblings
     */
    protected function findSiblingPosition(array $identifiers, array $sharedIdentifiers, string $identifier): ?array
    {
        $ordinal = null;
        $total = 0;
        foreach ($identifiers as $siblingIdentifier) {
            if (!in_array($siblingIdentifier, $sharedIdentifiers, true)) {
                continue;
            }
            $total++;
            if ($siblingIdentifier === $identifier) {
                $ordinal = $total;
            }
        }
        return $ordinal === null ? null : ['ordinal' => $ordinal, 'total' => $total];
    }

    /**
     * Classifies a single property change and renders it human-readable.
     *
     * Returns a list of change entries, because one rich-text property can
     * carry several independent stories (a text edit plus a changed link).
     * An empty list means the difference is not a real editorial change.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderPropertyChange(string $propertyName, $originalValue, $changedValue, NodeInterface $changedNode): array
    {
        $propertyLabel = $this->getPropertyLabel($propertyName, $changedNode);
        $isRemoved = $changedNode->isRemoved();

        if (
            ($originalValue instanceof ImageInterface || $originalValue === null)
            && ($changedValue instanceof ImageInterface || $changedValue === null)
            && ($originalValue !== null || $changedValue !== null)
        ) {
            return [[
                'type' => 'image',
                'propertyLabel' => $propertyLabel,
                'original' => $this->loadAsset($originalValue),
                'changed' => $isRemoved ? null : $this->loadAsset($changedValue),
            ]];
        }

        if ($originalValue instanceof AssetInterface || $changedValue instanceof AssetInterface) {
            return [[
                'type' => 'asset',
                'propertyLabel' => $propertyLabel,
                'original' => $this->loadAsset($originalValue),
                'changed' => $isRemoved ? null : $this->loadAsset($changedValue),
            ]];
        }

        if ($originalValue instanceof \DateTimeInterface || $changedValue instanceof \DateTimeInterface) {
            $bothDates = $originalValue instanceof \DateTimeInterface && $changedValue instanceof \DateTimeInterface;
            if ($bothDates && $originalValue->getTimestamp() === $changedValue->getTimestamp() && !$isRemoved) {
                // Two instances of the same moment: the stored value is
                // unchanged, only the object identity differs.
                return [];
            }
            return [[
                'type' => 'datetime',
                'propertyLabel' => $propertyLabel,
                'original' => $originalValue,
                'changed' => $isRemoved ? null : $changedValue,
            ]];
        }

        // Values with a select/toggle editor, booleans, arrays (references,
        // multi-selects) and remaining objects render as before/after labels.
        $editorValues = $this->getEditorValues($propertyName, $changedNode);
        $isTextual = (is_string($originalValue) || $originalValue === null) && (is_string($changedValue) || $changedValue === null);
        if ($editorValues !== null || !$isTextual) {
            $originalLabel = $this->renderValueLabel($originalValue, $propertyName, $changedNode);
            $changedLabel = $isRemoved ? $this->translateOwn('value.empty') : $this->renderValueLabel($changedValue, $propertyName, $changedNode);
            if ($originalLabel === $changedLabel) {
                if ((is_scalar($originalValue) || $originalValue === null) && (is_scalar($changedValue) || $changedValue === null)) {
                    // Scalars compare by value, so equal labels over different
                    // values mean a real but invisible change.
                    return $this->renderTechnicalOnlyNote($propertyLabel, $originalValue, $changedValue, $isRemoved);
                }
                // Objects and arrays are rebuilt per request and differ by
                // identity, so an entry here would be a false positive.
                return [];
            }
            return [[
                'type' => 'value',
                'propertyLabel' => $propertyLabel,
                'original' => $originalLabel,
                'changed' => $changedLabel,
            ]];
        }

        $entries = [];
        $diffHtml = $this->renderTextDiff((string)($originalValue ?? ''), $isRemoved ? '' : (string)($changedValue ?? ''));
        if ($diffHtml !== null) {
            $entries[] = [
                'type' => 'text',
                'propertyLabel' => $propertyLabel,
                'diffHtml' => $diffHtml,
            ];
        }
        if (is_string($originalValue) && is_string($changedValue) && !$isRemoved) {
            // The text diff strips all tags, so link and formatting edits are
            // invisible to it and are compared on the markup level instead.
            foreach ($this->richTextDiffer->compare($originalValue, $changedValue) as $finding) {
                $entries[] = $this->renderRichTextFinding($finding, $propertyLabel, $changedNode);
            }
        }
        if ($entries === []) {
            return $this->renderTechnicalOnlyNote($propertyLabel, $originalValue, $changedValue, $isRemoved);
        }
        return $entries;
    }

    /**
     * Names the field of a change that is real on the stored value but has no
     * visible effect, instead of dropping it and leaving the reviewer with an
     * unexplained card.
     *
     * The note claims that a published version exists and reads differently, so
     * it stays silent without a published counterpart - a removed node is
     * compared against nothing, a new node has nothing to be compared to - and
     * whenever both sides read the same once null and "" are treated as the
     * same emptiness.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderTechnicalOnlyNote(string $propertyLabel, $originalValue, $changedValue, bool $isRemoved): array
    {
        if ($isRemoved || $originalValue === null) {
            return [];
        }
        if ((string)$originalValue === (string)($changedValue ?? '')) {
            return [];
        }
        return [[
            'type' => 'note',
            'propertyLabel' => $propertyLabel,
            'message' => $this->translateOwn('change.technicalOnly'),
        ]];
    }

    /**
     * Turns one finding of the rich-text comparison into a review entry, where
     * the detail line names the affected link or passage and the before/after
     * values name what changed about it.
     *
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    protected function renderRichTextFinding(array $finding, string $propertyLabel, NodeInterface $changedNode): array
    {
        return match ($finding['kind']) {
            'linkTarget' => $this->renderLinkTargetChange($finding, $propertyLabel, $changedNode),
            'linkAttribute' => [
                'type' => 'link',
                'propertyLabel' => $propertyLabel,
                'detail' => $this->translateOwn('link.detailAttribute', [
                    $finding['linkText'],
                    $this->translateOwn('link.attribute.' . $finding['attribute']),
                ]),
                'original' => $this->renderLinkAttributeValue($finding['attribute'], $finding['original']),
                'changed' => $this->renderLinkAttributeValue($finding['attribute'], $finding['changed']),
            ],
            'linkAdded' => [
                'type' => 'link',
                'propertyLabel' => $propertyLabel,
                'detail' => $this->translateOwn('link.detail', [$finding['linkText']]),
                'original' => $this->translateOwn('link.none'),
                'changed' => $this->renderLinkTargetLabel($finding['href'], $changedNode),
            ],
            'linkRemoved' => [
                'type' => 'link',
                'propertyLabel' => $propertyLabel,
                'detail' => $this->translateOwn('link.detail', [$finding['linkText']]),
                'original' => $this->renderLinkTargetLabel($finding['href'], $changedNode),
                'changed' => $this->translateOwn('link.none'),
            ],
            'formatting' => [
                'type' => 'formatting',
                'propertyLabel' => $propertyLabel,
                'detail' => $this->translateOwn('formatting.detail', [$finding['text']]),
                'original' => $this->renderMarkList($finding['originalMarks']),
                'changed' => $this->renderMarkList($finding['changedMarks']),
            ],
        };
    }

    /**
     * Shows both targets of a re-pointed link by name. Two different targets
     * that happen to carry the same title would read as "X → X", so such a pair
     * falls back to the raw hrefs, which do differ.
     *
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    protected function renderLinkTargetChange(array $finding, string $propertyLabel, NodeInterface $changedNode): array
    {
        $original = $this->renderLinkTargetLabel($finding['original'], $changedNode);
        $changed = $this->renderLinkTargetLabel($finding['changed'], $changedNode);
        if ($original === $changed) {
            $original = $finding['original'];
            $changed = $finding['changed'];
        }
        return [
            'type' => 'link',
            'propertyLabel' => $propertyLabel,
            'detail' => $this->translateOwn('link.detail', [$finding['linkText']]),
            'original' => $original,
            'changed' => $changed,
        ];
    }

    /**
     * Neos stores internal links as "node://<uuid>" and "asset://<uuid>", which
     * tells a reviewer nothing, so both are resolved to the title of what they
     * point at. Anything that cannot be resolved keeps its raw href: an opaque
     * target still beats a name we do not have.
     */
    protected function renderLinkTargetLabel(string $href, NodeInterface $changedNode): string
    {
        try {
            if (preg_match('#^node://([^/?\#]+)#', $href, $matches) === 1) {
                $context = $changedNode->getContext();
                $node = $context === null ? null : $context->getNodeByIdentifier($matches[1]);
                return $node === null ? $href : $this->orRawHref($this->cleanLabel($node->getLabel()), $href);
            }
            if (preg_match('#^asset://([^/?\#]+)#', $href, $matches) === 1) {
                return $this->renderAssetTargetLabel($matches[1], $href);
            }
        } catch (\Throwable $exception) {
            // Resolving reads from the content repository and the asset
            // storage; a review page must render even when they cannot answer.
            return $href;
        }
        return $href;
    }

    protected function renderAssetTargetLabel(string $identifier, string $href): string
    {
        $asset = $this->assetRepository->findByIdentifier($identifier);
        if (!$asset instanceof AssetInterface) {
            return $href;
        }
        $title = $this->cleanLabel((string)$asset->getTitle());
        if ($title !== '') {
            return $title;
        }
        // Assets uploaded without a title are known to editors by their file name.
        $resource = $asset->getResource();
        return $resource === null ? $href : $this->orRawHref($this->cleanLabel($resource->getFilename()), $href);
    }

    protected function orRawHref(string $label, string $href): string
    {
        return $label === '' ? $href : $label;
    }

    /**
     * Names what a link attribute value means to a reader: the target keyword
     * describes where the link opens, every other attribute shows as stored.
     */
    protected function renderLinkAttributeValue(string $attribute, ?string $value): string
    {
        if ($attribute === 'target') {
            if ($value === null || $value === '') {
                return $this->translateOwn('link.target.sameTab');
            }
            return strtolower($value) === '_blank' ? $this->translateOwn('link.target.newTab') : $value;
        }
        return $value === null || $value === '' ? $this->translateOwn('value.empty') : $value;
    }

    /**
     * Names a set of formatting marks in the reviewer's language.
     *
     * @param string[] $marks
     */
    protected function renderMarkList(array $marks): string
    {
        if ($marks === []) {
            return $this->translateOwn('value.formatNone');
        }
        $labels = array_map(function (string $mark): string {
            // Heading levels share one label with the level as an argument.
            if (preg_match('/^heading(\d)$/', $mark, $matches) === 1) {
                return $this->translateOwn('format.heading', [$matches[1]]);
            }
            return $this->translateOwn('format.' . $mark);
        }, $marks);
        return implode(', ', $labels);
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
     * Only a block-level element ends a word and is therefore replaced by a
     * space. An inline element may sit inside a word or between a word and its
     * punctuation, so it is dropped without leaving a space behind - bolding
     * "18 Uhr" in front of a comma must not turn "Uhr," into "Uhr ,".
     *
     * @return string[]
     */
    protected function tokenizeText(string $value): array
    {
        $blockLevelTagNames = implode('|', RichTextDiffer::BLOCK_LEVEL_TAG_NAMES);
        // The lookahead keeps the tag name whole, so "<pre>" is not read as a
        // "<p>" and "<header>" not as an "<hr>".
        $text = preg_replace('#</?(?:' . $blockLevelTagNames . ')(?=[\s/>])[^>]*>#i', ' ', $value);
        $text = preg_replace('/<[^>]*>/', '', $text);
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
