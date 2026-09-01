<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Tests\Unit\Controller\Module\Management;

use CodeQ\WorkspaceReview\Controller\Module\Management\WorkspacesController;
use Neos\ContentRepository\Domain\Model\ArrayPropertyCollection;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
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
    public function renderContentChangesSkipsUntouchedDefaultsOfARemovedNode(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element', [], ['spaceBelow' => 'normal']);
        $originalNode = $this->createNode($nodeType, ['spaceBelow' => 'normal']);
        $changedNode = $this->createNode($nodeType, ['spaceBelow' => 'normal'], isRemoved: true);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame([], $changes);
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
            }

            public function renderContentChangesForTest(NodeInterface $changedNode): array
            {
                return $this->renderContentChanges($changedNode);
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
        bool $isRemoved = false
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
