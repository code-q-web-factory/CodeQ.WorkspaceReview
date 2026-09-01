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
        self::assertSame('value', $changes['_index']['type']);
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
    public function renderContentChangesSkipsUntouchedDefaultsOfARemovedNode(): void
    {
        $nodeType = $this->createNodeType('Vendor.Site:Element', [], ['spaceBelow' => 'normal']);
        $originalNode = $this->createNode($nodeType, ['spaceBelow' => 'normal']);
        $changedNode = $this->createNode($nodeType, ['spaceBelow' => 'normal'], isRemoved: true);

        $changes = $this->createController($originalNode)->renderContentChangesForTest($changedNode);

        self::assertSame([], $changes);
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

            protected function translateOwn(string $id, array $arguments = [], ?int $quantity = null): string
            {
                return $id;
            }
        };
    }

    private function createNodeType(string $name, array $properties = [], array $defaultValues = []): NodeType
    {
        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('getName')->willReturn($name);
        $nodeType->method('getProperties')->willReturn($properties);
        $nodeType->method('getDefaultValuesForProperties')->willReturn($defaultValues);
        return $nodeType;
    }

    /**
     * A node whose system fields all hold the same values, so only the given
     * properties can produce change entries.
     */
    private function createNode(NodeType $nodeType, array $properties, bool $isRemoved = false): NodeInterface
    {
        $node = $this->createMock(NodeInterface::class);
        $node->method('getNodeType')->willReturn($nodeType);
        $node->method('getProperties')->willReturn(new ArrayPropertyCollection($properties));
        $node->method('getProperty')->willReturnCallback(
            static fn($propertyName) => $properties[$propertyName] ?? null
        );
        $node->method('isHidden')->willReturn(false);
        $node->method('isRemoved')->willReturn($isRemoved);
        $node->method('getHiddenBeforeDateTime')->willReturn(null);
        $node->method('getHiddenAfterDateTime')->willReturn(null);
        $node->method('isHiddenInIndex')->willReturn(false);
        $node->method('getAccessRoles')->willReturn([]);
        $node->method('getPath')->willReturn('/sites/example/moved');
        $node->method('getIndex')->willReturn(1);
        return $node;
    }
}
