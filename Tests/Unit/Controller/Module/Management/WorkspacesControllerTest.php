<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Tests\Unit\Controller\Module\Management;

use CodeQ\WorkspaceReview\Controller\Module\Management\WorkspacesController;
use Neos\Flow\Mvc\View\ViewInterface;
use CodeQ\WorkspaceReview\Diff\RichTextDiffer;
use Neos\ContentRepository\Domain\Model\ArrayPropertyCollection;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\FluidAdaptor\View\TemplatePaths;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\FluidAdaptor\View\TemplateView;
use Neos\Flow\Tests\UnitTestCase;

class WorkspacesControllerTest extends UnitTestCase
{
    /** @test */
    public function renderContentChangesIncludesAllChangedNeosSystemFields(): void
    {
        $originalNodeType = $this->createMock(NodeType::class);
        $originalNodeType->method('getName')->willReturn('Vendor.Site:Original');

        $changedNodeType = $this->createMock(NodeType::class);
        $changedNodeType->method('getName')->willReturn('Vendor.Site:Changed');
        $changedNodeType->method('getDefaultValuesForProperties')->willReturn([]);

        $originalNode = $this->createMock(NodeInterface::class);
        $originalNode->method('getNodeType')->willReturn($originalNodeType);
        $originalNode->method('isHidden')->willReturn(false);
        $originalNode->method('getHiddenBeforeDateTime')->willReturn(null);
        $originalNode->method('getHiddenAfterDateTime')->willReturn(new \DateTimeImmutable('2026-09-10 08:00:00'));
        $originalNode->method('isHiddenInIndex')->willReturn(false);
        $originalNode->method('getAccessRoles')->willReturn([]);
        $originalNode->method('getPath')->willReturn('/sites/example/original');
        $originalNode->method('getIndex')->willReturn(100);

        $changedNode = $this->createMock(NodeInterface::class);
        $changedNode->method('getProperties')->willReturn(new ArrayPropertyCollection([]));
        $changedNode->method('getNodeType')->willReturn($changedNodeType);
        $changedNode->method('isHidden')->willReturn(true);
        $changedNode->method('getHiddenBeforeDateTime')->willReturn(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $changedNode->method('getHiddenAfterDateTime')->willReturn(new \DateTimeImmutable('2026-09-20 08:00:00'));
        $changedNode->method('isHiddenInIndex')->willReturn(true);
        $changedNode->method('getAccessRoles')->willReturn(['Vendor.Site:Members']);
        $changedNode->method('getPath')->willReturn('/sites/example/changed');
        $changedNode->method('getIndex')->willReturn(200);
        $changedNode->method('isRemoved')->willReturn(false);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                '_hidden',
                '_hiddenBeforeDateTime',
                '_hiddenAfterDateTime',
                '_hiddenInIndex',
                '_accessRoles',
                '_nodeType',
                '_path',
                '_index',
            ],
            array_keys($changes)
        );
        self::assertSame('visibility', $changes['_hidden']['type']);
        self::assertSame('datetime', $changes['_hiddenBeforeDateTime']['type']);
        self::assertSame('datetime', $changes['_hiddenAfterDateTime']['type']);
        self::assertSame('value', $changes['_hiddenInIndex']['type']);
        self::assertSame('value', $changes['_accessRoles']['type']);
        self::assertSame('value', $changes['_nodeType']['type']);
        self::assertSame('value', $changes['_path']['type']);
        // Without a reachable parent the position falls back to the raw index.
        self::assertSame(
            ['type' => 'value', 'propertyLabel' => 'system.position', 'original' => '100', 'changed' => '200'],
            $changes['_index']
        );
    }

    /** @test */
    public function renderContentChangesReportsAPropertyChangedBackToItsNodeTypeDefault(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element', [
            'spaceBelow' => [
                'ui' => [
                    'label' => 'Space below',
                    'inspector' => [
                        'editorOptions' => [
                            'values' => [
                                'normal' => ['label' => 'Normal'],
                                'big' => ['label' => 'Big'],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['spaceBelow' => 'normal']);

        $originalNode = $this->createNode($nodeType, ['spaceBelow' => 'big']);
        $changedNode = $this->createNode($nodeType, ['spaceBelow' => 'normal']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'value',
                'propertyLabel' => 'Space below',
                'original' => 'Big',
                'changed' => 'Normal',
            ],
            $changes['spaceBelow'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesSkipsDefaultAndEmptyPropertiesOfANewNode(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element', [], ['spaceBelow' => 'normal']);
        $changedNode = $this->createNode($nodeType, ['spaceBelow' => 'normal', 'title' => '']);

        $changes = $this->createController(null)->renderContentChangesForTest($changedNode);

        self::assertSame([], $changes);
    }

    /** @test */
    public function renderContentChangesReportsALinkTargetTheTextDiffCannotSee(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="http://neos.eu">neos.eu</a></p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="https://neos.eu">neos.eu</a></p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'link',
                'propertyLabel' => 'Text',
                'detail' => 'link.detail(neos.eu)',
                'original' => 'http://neos.eu',
                'changed' => 'https://neos.eu',
            ],
            $changes['text'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesKeepsTheTextDiffNextToItsRichTextFindings(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="http://neos.eu">neos.eu</a> lesen</p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="https://neos.eu">neos.eu</a> nachlesen</p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(['text', 'text#rt1'], array_keys($changes));
        self::assertSame('text', $changes['text']['type']);
        self::assertSame('link', $changes['text#rt1']['type']);
        // Both entries describe the same field, so its name is stated once.
        self::assertSame('Text', $changes['text']['propertyLabel']);
        self::assertSame('', $changes['text#rt1']['propertyLabel']);
    }

    /** @test */
    public function renderContentChangesNamesWhereALinkOpensInsteadOfTheRawKeyword(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="https://neos.eu">neos.eu</a></p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Mehr auf <a href="https://neos.eu" target="_blank">neos.eu</a></p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'link',
                'propertyLabel' => 'Text',
                'detail' => 'link.detailAttribute(neos.eu, link.attribute.target)',
                'original' => 'link.target.sameTab',
                'changed' => 'link.target.newTab',
            ],
            $changes['text'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesResolvesAnInternalLinkTargetToThePageTitle(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $context = $this->createMock(Context::class);
        $context->method('getNodeByIdentifier')->willReturnCallback(
            fn($identifier) => $identifier === 'c0ffee' ? $this->createLabelledNode('Kontakt &amp; Anfahrt') : null
        );
        $originalNode = $this->createNode($nodeType, ['text' => '<p><a href="node://deadbeef">Mehr</a></p>']);
        $changedNode = $this->createNode(
            $nodeType,
            ['text' => '<p><a href="node://c0ffee">Mehr</a></p>'],
            context: $context
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'link',
                'propertyLabel' => 'Text',
                'detail' => 'link.detail(Mehr)',
                // A target the context cannot resolve keeps its raw href.
                'original' => 'node://deadbeef',
                'changed' => 'Kontakt & Anfahrt',
            ],
            $changes['text'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesShowsTheRawTargetsOfTwoPagesWithTheSameTitle(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $context = $this->createMock(Context::class);
        $context->method('getNodeByIdentifier')->willReturnCallback(
            fn($identifier) => $this->createLabelledNode('Kontakt')
        );
        $originalNode = $this->createNode($nodeType, ['text' => '<p><a href="node://deadbeef">Mehr</a></p>']);
        $changedNode = $this->createNode(
            $nodeType,
            ['text' => '<p><a href="node://c0ffee">Mehr</a></p>'],
            context: $context
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // Two equal labels would read as "Kontakt → Kontakt" and hide that the
        // link points at a different page now.
        self::assertSame('node://deadbeef', $changes['text']['original'] ?? null);
        self::assertSame('node://c0ffee', $changes['text']['changed'] ?? null);
    }

    /** @test */
    public function renderContentChangesKeepsALinkTargetItCannotResolve(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p><a href="asset://deadbeef">Prospekt</a></p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p><a href="asset://c0ffee">Prospekt</a></p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // Without an asset repository the lookup fails; the review page must
        // still render, showing the raw targets.
        self::assertSame('asset://deadbeef', $changes['text']['original'] ?? null);
        self::assertSame('asset://c0ffee', $changes['text']['changed'] ?? null);
    }

    /** @test */
    public function renderContentChangesNamesTheFieldOfAnInvisibleTextChange(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Hallo</p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p >Hallo</p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'note',
                'propertyLabel' => 'Text',
                'message' => 'change.technicalOnly',
            ],
            $changes['text#note'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesStatesThatAnEditedNodeMatchesTheOriginalAgain(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $properties = ['text' => '<p>Hallo Welt</p>'];
        $originalNode = $this->createNode($nodeType, $properties);
        $changedNode = $this->createNode($nodeType, $properties);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                '_note' => [
                    'type' => 'note',
                    'propertyLabel' => '',
                    'message' => 'change.identicalToOriginal',
                ],
            ],
            $changes
        );
    }

    /** @test */
    public function renderContentChangesTreatsARebuiltDateTimeAsNoChangeAtAll(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['publishDate' => new \DateTimeImmutable('2026-09-01 08:00:00')]);
        $changedNode = $this->createNode($nodeType, ['publishDate' => new \DateTimeImmutable('2026-09-01 08:00:00')]);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // The two instances differ by identity only, which is no reason to
        // claim the node was changed and reverted.
        self::assertSame(
            [
                '_note' => [
                    'type' => 'note',
                    'propertyLabel' => '',
                    'message' => 'change.identicalToOriginal',
                ],
            ],
            $changes
        );
    }

    /** @test */
    public function renderContentChangesShowsAMovedNodeAsAPositionAmongItsSiblings(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode(
            $nodeType,
            [],
            100,
            $this->createParent('/sites/example', ['first', 'moved', 'third'])
        );
        $changedNode = $this->createNode(
            $nodeType,
            [],
            250,
            $this->createParent('/sites/example', ['moved', 'first', 'third'])
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'value',
                'propertyLabel' => 'system.position',
                'original' => 'position.ordinalOfTotal(2, 3)',
                'changed' => 'position.ordinalOfTotal(1, 3)',
            ],
            $changes['_index'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesCallsARenumberedSortingIndexAnInternalChange(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode(
            $nodeType,
            [],
            100,
            $this->createParent('/sites/example', ['first', 'moved', 'third'])
        );
        $changedNode = $this->createNode(
            $nodeType,
            [],
            150,
            $this->createParent('/sites/example', ['first', 'moved', 'third'])
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'note',
                'propertyLabel' => 'system.position',
                'message' => 'position.internalOnly',
            ],
            $changes['_index'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesLeavesThePositionOutWhenTheNodeChangedItsParent(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode(
            $nodeType,
            [],
            100,
            $this->createParent('/sites/example', ['first', 'moved']),
            '/sites/example/moved'
        );
        $changedNode = $this->createNode(
            $nodeType,
            [],
            250,
            $this->createParent('/sites/other', ['moved']),
            '/sites/other/moved'
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // An ordinal within another list would suggest a reordering that never
        // happened; the changed path already states the move.
        self::assertSame(['_path'], array_keys($changes));
    }

    /** @test */
    public function renderContentChangesNamesTheFormattingOfAReformattedPassage(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, [
            'text' => '<p>Hallo schöne Welt</p>',
            'heading' => '<p>Titel</p>',
        ]);
        $changedNode = $this->createNode($nodeType, [
            'text' => '<p>Hallo <strong>schöne</strong> Welt</p>',
            'heading' => '<h2>Titel</h2>',
        ]);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(
            [
                'type' => 'formatting',
                'propertyLabel' => 'Text',
                'detail' => 'formatting.detail(schöne)',
                'original' => 'value.formatNone',
                'changed' => 'format.bold',
            ],
            $changes['text'] ?? null
        );
        self::assertSame(
            [
                'type' => 'formatting',
                'propertyLabel' => 'Heading',
                'detail' => 'formatting.detail(Titel)',
                'original' => 'value.formatNone',
                'changed' => 'format.heading(2)',
            ],
            $changes['heading'] ?? null
        );
    }

    /** @test */
    public function renderContentChangesReportsOnlyTheFormattingWhenAWordBeforeACommaBecameBold(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, [
            'text' => '<p>Der Einlass beginnt jeweils um 18 Uhr, der Eintritt ist frei.</p>',
        ]);
        $changedNode = $this->createNode($nodeType, [
            'text' => '<p>Der Einlass beginnt jeweils um <strong>18 Uhr</strong>, der Eintritt ist frei.</p>',
        ]);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // Removing the inline tag must not leave a space behind, which would
        // let the word diff claim "Uhr," was rewritten as "Uhr ,".
        self::assertSame(['text'], array_keys($changes));
        self::assertSame(
            [
                'type' => 'formatting',
                'propertyLabel' => 'Text',
                'detail' => 'formatting.detail(18 Uhr,)',
                'original' => 'value.formatNone',
                'changed' => 'format.bold',
            ],
            $changes['text']
        );
    }

    /** @test */
    public function renderContentChangesShowsTheTextOfARemovedNodeAsDeleted(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Wird gelöscht</p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Wird gelöscht</p>'], isRemoved: true);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame(['text'], array_keys($changes));
        self::assertSame('text', $changes['text']['type']);
        self::assertStringContainsString('<del><span class="codeq-review-sr-only">diff.deleted </span>Wird gelöscht</del>', $changes['text']['diffHtml']);
    }

    /** @test */
    public function renderContentChangesLabelsEveryEditedRunForScreenReaders(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['text' => '<p>Der Vorverkauf startet am 15. September.</p>']);
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Der Vorverkauf startet am 1. September.</p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // The markers carry no visible text of their own: the hidden label is
        // what tells a screen reader user which run was removed and which
        // one was added.
        self::assertSame(
            'Der Vorverkauf startet am'
            . ' <del><span class="codeq-review-sr-only">diff.deleted </span>15.</del>'
            . ' <ins><span class="codeq-review-sr-only">diff.added </span>1.</ins>'
            . ' September.',
            $changes['text']['diffHtml']
        );
    }

    /** @test */
    public function renderContentChangesSaysNothingTechnicalAboutANodeCreatedAndDeletedAtOnce(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $changedNode = $this->createNode($nodeType, ['text' => '<p>Wird gelöscht</p>'], isRemoved: true);

        $changes = $this->createController(null)->renderContentChangesForTest($changedNode);

        // There is no published version this content could differ from; the
        // "deleted" badge is the whole story.
        self::assertSame([], $changes);
    }

    /** @test */
    public function renderContentChangesSkipsUntouchedDefaultsOfARemovedNode(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element', [], ['spaceBelow' => 'normal']);
        $originalNode = $this->createNode($nodeType, ['spaceBelow' => 'normal']);
        $changedNode = $this->createNode($nodeType, ['spaceBelow' => 'normal'], isRemoved: true);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame([], $changes);
    }

    /** @test */
    public function renderContentChangesSaysNothingAboutAValueThatWasNeverSet(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode($nodeType, ['title' => null, 'text' => '<p>Hallo</p>']);
        $changedNode = $this->createNode($nodeType, ['title' => '', 'text' => '<p>Hallo Welt</p>']);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // "not set" and "empty" describe the same state, so the empty title is
        // no change a reviewer needs to read about.
        self::assertSame(['text'], array_keys($changes));
    }

    /** @test */
    public function renderContentChangesSaysNothingTechnicalAboutAnEmptyParagraphOfANewNode(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $changedNode = $this->createNode($nodeType, ['text' => '<p>&nbsp;</p>']);

        $changes = $this->createController(null)->renderContentChangesForTest($changedNode);

        self::assertSame([], $changes);
    }

    /** @test */
    public function renderContentChangesCallsARevertedReferenceListIdenticalToTheOriginal(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element');
        $originalNode = $this->createNode($nodeType, ['related' => [$this->createLabelledNode('Kontakt')]]);
        $changedNode = $this->createNode($nodeType, ['related' => [$this->createLabelledNode('Kontakt')]]);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // The two arrays hold different instances of the same reference, which
        // is object identity, not a stored difference.
        self::assertSame(
            [
                '_note' => [
                    'type' => 'note',
                    'propertyLabel' => '',
                    'message' => 'change.identicalToOriginal',
                ],
            ],
            $changes
        );
    }

    /** @test */
    public function renderContentChangesCallsAnElementInsertedAboveAnInternalChange(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Text');
        $originalNode = $this->createNode(
            $nodeType,
            [],
            100,
            $this->createParent('/sites/example', ['first', 'moved'])
        );
        $changedNode = $this->createNode(
            $nodeType,
            [],
            200,
            $this->createParent('/sites/example', ['first', 'neu', 'moved'])
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // The element still follows "first"; only the new sibling in between
        // renumbered the indices.
        self::assertSame(
            [
                '_index' => [
                    'type' => 'note',
                    'propertyLabel' => 'system.position',
                    'message' => 'position.internalOnly',
                ],
            ],
            $changes
        );
    }

    /** @test */
    public function renderContentChangesCountsOnlyPagesWhenRankingAPage(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Page', isDocument: true);
        $originalNode = $this->createNode(
            $nodeType,
            [],
            100,
            $this->createParent('/sites/example', ['main'], ['first', 'moved', 'third'])
        );
        $changedNode = $this->createNode(
            $nodeType,
            [],
            50,
            $this->createParent('/sites/example', ['main'], ['moved', 'first', 'third'])
        );

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        // The page's own content collection is no sibling in the page order.
        self::assertSame(
            [
                'type' => 'value',
                'propertyLabel' => 'system.position',
                'original' => 'position.ordinalOfTotal(2, 3)',
                'changed' => 'position.ordinalOfTotal(1, 3)',
            ],
            $changes['_index'] ?? null
        );
    }

    /** @test */
    public function renderDocumentSummaryCountsEveryKindOfContentChange(): void
    {
        $node = $this->createMock(NodeInterface::class);
        $node->method('isRemoved')->willReturn(false);
        $document = [
            'changes' => [
                [
                    'node' => $node,
                    'contentChanges' => [
                        ['type' => 'text'],
                        ['type' => 'value'],
                        ['type' => 'link'],
                        ['type' => 'formatting'],
                        ['type' => 'note'],
                        '_index' => ['type' => 'value'],
                    ],
                ],
            ],
        ];

        $summary = $this->createController(null)->renderDocumentSummaryForTest($document);

        self::assertSame(
            'summary.movedElements(1) · summary.texts(1) · summary.settings(1) · summary.links(1) · summary.formatting(1) · summary.internal(1)',
            $summary
        );
    }

    /**
     * The sidebar shows the changed pages as a tree: the unchanged "blog" page
     * between the site and its changed post is listed as an ancestor, and a
     * post follows its parent even when a sibling like "blog-archive" sorts
     * between the two as plain strings.
     *
     * @test
     */
    public function computePageTreeListsUnchangedAncestorsAndKeepsChildrenBelowTheirParent(): void
    {
        $site = $this->createMock(NodeInterface::class);
        $blog = $this->createMock(NodeInterface::class);
        $blog->method('getParent')->willReturn($site);
        $post = $this->createMock(NodeInterface::class);
        $post->method('getParent')->willReturn($blog);
        $archive = $this->createMock(NodeInterface::class);
        $archive->method('getParent')->willReturn($site);
        $documents = [
            '' => ['documentNode' => $site],
            'blog-archive' => ['documentNode' => $archive],
            'blog/post' => ['documentNode' => $post],
        ];

        $pages = $this->createController(null)->computePageTreeForTest($documents);

        self::assertSame(
            [[$site, 0, true, true], [$blog, 1, false, true], [$post, 2, true, false], [$archive, 1, true, false]],
            array_map(function (array $entry): array {
                return [$entry['node'], $entry['depth'], $entry['document'] !== null, $entry['hasChildren']];
            }, $pages)
        );
        self::assertSame($documents['blog/post'], $pages[2]['document']);
    }

    /**
     * The bug this guards against: another package's view configuration won and
     * declared no layout root, so Fluid derived the module layout from this
     * package - which ships none - and the module failed to render.
     *
     * @test
     */
    public function initializeViewGivesTheViewEveryRootPathItNeedsToRender(): void
    {
        $templatePaths = $this->createTemplatePaths(
            ['resource://Flownative.WorkspacePreview/Private/Templates'],
            [],
            []
        );

        $this->createController(null)->initializeViewForTest($this->createViewWithPaths($templatePaths));

        self::assertContains('resource://Neos.Neos/Private/Layouts', $templatePaths->getLayoutRootPaths());
        self::assertContains('resource://Neos.Neos/Private/Partials', $templatePaths->getPartialRootPaths());
        self::assertContains('resource://Neos.Neos/Private/Templates', $templatePaths->getTemplateRootPaths());
        // The template of the package that won the configuration stays reachable.
        self::assertContains('resource://Flownative.WorkspacePreview/Private/Templates', $templatePaths->getTemplateRootPaths());
    }

    /**
     * @test
     */
    public function initializeViewLetsThisPackageResolveItsOwnTemplatesFirst(): void
    {
        $templatePaths = $this->createTemplatePaths(
            ['resource://Neos.Neos/Private/Templates'],
            ['resource://Neos.Neos/Private/Partials'],
            ['resource://Neos.Neos/Private/Layouts']
        );

        $this->createController(null)->initializeViewForTest($this->createViewWithPaths($templatePaths));

        // Fluid takes the first match, so this package has to come first.
        self::assertSame('resource://CodeQ.WorkspaceReview/Private/Templates', $templatePaths->getTemplateRootPaths()[0]);
        self::assertSame('resource://CodeQ.WorkspaceReview/Private/Partials', $templatePaths->getPartialRootPaths()[0]);
    }

    /**
     * A view configuration may name any view class. StandaloneView does not
     * extend TemplateView but carries the same template paths, including the
     * package-derived layout path that fails.
     *
     * @test
     */
    public function initializeViewAlsoCorrectsAViewThatIsNotTheDefaultTemplateView(): void
    {
        $templatePaths = $this->createTemplatePaths([], [], []);
        $view = $this->getMockBuilder(StandaloneView::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplatePaths', 'assign'])
            ->getMock();
        $view->method('getTemplatePaths')->willReturn($templatePaths);

        $this->createController(null)->initializeViewForTest($view);

        self::assertContains('resource://Neos.Neos/Private/Layouts', $templatePaths->getLayoutRootPaths());
    }

    /**
     * A view that is not template based has no root paths to correct.
     *
     * @test
     */
    public function initializeViewLeavesAViewWithoutTemplatePathsAlone(): void
    {
        $view = $this->createMock(ViewInterface::class);
        $view->expects(self::once())->method('assign')->with('moduleConfiguration');

        $this->createController(null)->initializeViewForTest($view);
    }

    /**
     * @test
     */
    public function completeRootPathsGuaranteesTheNeosLayoutPathWhenNoneWasConfigured(): void
    {
        // The failing case: another package's view configuration won and
        // carries no layout root, so Fluid would look for the module layout
        // inside this package, which ships none.
        self::assertSame(
            ['resource://Neos.Neos/Private/Layouts'],
            $this->createController(null)->completeRootPathsForTest([], 'Layouts', false)
        );
    }

    /**
     * @test
     */
    public function completeRootPathsKeepsPathsContributedByOtherPackages(): void
    {
        // A package overriding an action this package does not touch must keep
        // its template; this one only claims precedence for its own files.
        self::assertSame(
            [
                'resource://CodeQ.WorkspaceReview/Private/Templates',
                'resource://Flownative.WorkspacePreview/Private/Templates',
                'resource://Neos.Neos/Private/Templates',
            ],
            $this->createController(null)->completeRootPathsForTest(
                [
                    'resource://Flownative.WorkspacePreview/Private/Templates',
                    'resource://Neos.Neos/Private/Templates',
                ],
                'Templates'
            )
        );
    }

    /**
     * @test
     */
    public function completeRootPathsDoesNotRepeatAPathThatIsAlreadyConfigured(): void
    {
        self::assertSame(
            [
                'resource://CodeQ.WorkspaceReview/Private/Partials',
                'resource://Neos.Neos/Private/Partials',
            ],
            $this->createController(null)->completeRootPathsForTest(
                ['resource://CodeQ.WorkspaceReview/Private/Partials'],
                'Partials'
            )
        );
    }

    /**
     * Records what the controller sets without touching the filesystem: the
     * real TemplatePaths validates each path through the resource:// stream
     * wrapper, which is not registered in a unit test.
     */
    private function createTemplatePaths(array $templates, array $partials, array $layouts): TemplatePaths
    {
        return new class ($templates, $partials, $layouts) extends TemplatePaths {
            private array $templates;
            private array $partials;
            private array $layouts;

            public function __construct(array $templates, array $partials, array $layouts)
            {
                $this->templates = $templates;
                $this->partials = $partials;
                $this->layouts = $layouts;
            }

            public function getTemplateRootPaths()
            {
                return $this->templates;
            }

            public function setTemplateRootPaths(array $templateRootPaths)
            {
                $this->templates = $templateRootPaths;
            }

            public function getPartialRootPaths()
            {
                return $this->partials;
            }

            public function setPartialRootPaths(array $partialRootPaths)
            {
                $this->partials = $partialRootPaths;
            }

            public function getLayoutRootPaths()
            {
                return $this->layouts;
            }

            public function setLayoutRootPaths(array $layoutRootPaths)
            {
                $this->layouts = $layoutRootPaths;
            }
        };
    }

    private function createViewWithPaths(TemplatePaths $templatePaths): TemplateView
    {
        $view = $this->getMockBuilder(TemplateView::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplatePaths', 'assign'])
            ->getMock();
        $view->method('getTemplatePaths')->willReturn($templatePaths);

        return $view;
    }

    /**
     * The controller is exercised through an anonymous subclass, because a
     * unit test has neither Flow's dependency injection nor a base workspace
     * to read the original node from.
     */
    private function createController(?NodeInterface $originalNode)
    {
        return new class ($originalNode) extends WorkspacesController {
            private ?NodeInterface $originalNode;

            public function __construct(?NodeInterface $originalNode)
            {
                $this->originalNode = $originalNode;
                $this->richTextDiffer = new RichTextDiffer();
            }

            public function renderContentChangesForTest(NodeInterface $changedNode): array
            {
                return $this->renderContentChanges($changedNode);
            }

            public function renderDocumentSummaryForTest(array $document): string
            {
                return $this->renderDocumentSummary($document);
            }

            public function computePageTreeForTest(array $documents): array
            {
                return $this->computePageTree($documents);
            }

            public function completeRootPathsForTest(array $configuredPaths, string $type, bool $includeOwnPath = true): array
            {
                return $this->completeRootPaths($configuredPaths, $type, $includeOwnPath);
            }

            public function initializeViewForTest(ViewInterface $view): void
            {
                $this->initializeView($view);
            }

            protected function getOriginalNode(NodeInterface $modifiedNode): ?NodeInterface
            {
                return $this->originalNode;
            }

            /**
             * Stands in for the translator: assertions name the label id they
             * expect, and its arguments show which values were passed in.
             */
            protected function translateOwn(string $id, array $arguments = [], ?int $quantity = null): string
            {
                return $arguments === [] ? $id : $id . '(' . implode(', ', $arguments) . ')';
            }
        };
    }

    private function createNodeType(
        string $name,
        array $properties = [],
        array $defaultValues = [],
        bool $isDocument = false
    ): NodeType {
        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('getName')->willReturn($name);
        $nodeType->method('getProperties')->willReturn($properties);
        $nodeType->method('getDefaultValuesForProperties')->willReturn($defaultValues);
        $nodeType->method('isOfType')->willReturnCallback(
            static fn($superType): bool => $isDocument && $superType === 'Neos.Neos:Document'
        );
        return $nodeType;
    }

    /**
     * A node whose system fields all hold the same values, so only the given
     * properties, index and parent can produce change entries.
     */
    private function createNode(
        NodeType $nodeType,
        array $properties,
        int $index = 1,
        ?NodeInterface $parent = null,
        string $path = '/sites/example/moved',
        bool $isRemoved = false,
        ?Context $context = null
    ): NodeInterface {
        $node = $this->createMock(NodeInterface::class);
        $node->method('getNodeType')->willReturn($nodeType);
        $node->method('getProperties')->willReturn(new ArrayPropertyCollection($properties));
        $node->method('getProperty')->willReturnCallback(
            static fn($propertyName) => $properties[$propertyName] ?? null
        );
        $node->method('getIdentifier')->willReturn('moved');
        $node->method('isHidden')->willReturn(false);
        $node->method('isRemoved')->willReturn($isRemoved);
        $node->method('getHiddenBeforeDateTime')->willReturn(null);
        $node->method('getHiddenAfterDateTime')->willReturn(null);
        $node->method('isHiddenInIndex')->willReturn(false);
        $node->method('getAccessRoles')->willReturn([]);
        $node->method('getPath')->willReturn($path);
        $node->method('getIndex')->willReturn($index);
        $node->method('getParent')->willReturn($parent);
        $node->method('getContext')->willReturn($context);
        return $node;
    }

    /**
     * A node that only has to answer with a label, as a link target or as the
     * value of a reference property.
     */
    private function createLabelledNode(string $label): NodeInterface
    {
        $node = $this->createMock(NodeInterface::class);
        $node->method('getLabel')->willReturn($label);
        return $node;
    }

    /**
     * The children are answered per node type filter, mirroring the content
     * repository: a page lists its sub pages, a collection its elements.
     *
     * @param string[] $contentChildIdentifiers children of "!Neos.Neos:Document"
     * @param string[] $documentChildIdentifiers children of "Neos.Neos:Document"
     */
    private function createParent(
        string $path,
        array $contentChildIdentifiers,
        array $documentChildIdentifiers = []
    ): NodeInterface {
        $contentChildren = $this->createChildNodes($contentChildIdentifiers);
        $documentChildren = $this->createChildNodes($documentChildIdentifiers);

        $parent = $this->createMock(NodeInterface::class);
        $parent->method('getPath')->willReturn($path);
        $parent->method('getChildNodes')->willReturnCallback(
            static fn($nodeTypeFilter = null): array => $nodeTypeFilter === 'Neos.Neos:Document'
                ? $documentChildren
                : $contentChildren
        );
        return $parent;
    }

    /**
     * @param string[] $identifiers
     * @return NodeInterface[]
     */
    private function createChildNodes(array $identifiers): array
    {
        return array_map(function (string $identifier): NodeInterface {
            $child = $this->createMock(NodeInterface::class);
            $child->method('getIdentifier')->willReturn($identifier);
            return $child;
        }, $identifiers);
    }
}
