<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Tests\Unit\ViewHelpers;

use CodeQ\WorkspaceReview\ViewHelpers\NodeTypeIconViewHelper;
use Neos\Flow\Tests\UnitTestCase;

class NodeTypeIconViewHelperTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider icons
     */
    public function renderMapsEveryIconNotationToFontAwesomeClasses(?string $icon, string $expected): void
    {
        $viewHelper = new NodeTypeIconViewHelper();
        $viewHelper->setArguments(['icon' => $icon]);

        self::assertSame($expected, $viewHelper->render());
    }

    public function icons(): array
    {
        return [
            'plain name' => ['globe', 'fas fa-globe'],
            'legacy prefix' => ['icon-tags', 'fas fa-tags'],
            'font awesome name' => ['fa-newspaper', 'fas fa-newspaper'],
            'complete classes' => ['far fa-file-alt', 'far fa-file-alt'],
            'missing' => [null, 'fas fa-file'],
            'empty' => ['', 'fas fa-file'],
        ];
    }
}
