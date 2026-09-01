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

        $controller = new class ($originalNode) extends WorkspacesController {
            private NodeInterface $originalNode;

            public function __construct(NodeInterface $originalNode)
            {
                $this->originalNode = $originalNode;
            }

            public function renderContentChangesForTest(NodeInterface $changedNode): array
            {
                return $this->renderContentChanges($changedNode);
            }

            protected function getOriginalNode(NodeInterface $modifiedNode): NodeInterface
            {
                return $this->originalNode;
            }

            protected function translateOwn(string $id, array $arguments = [], ?int $quantity = null): string
            {
                return $id;
            }
        };
        $changes = $controller->renderContentChangesForTest($changedNode);

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
}
