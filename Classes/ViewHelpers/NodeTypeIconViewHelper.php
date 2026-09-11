<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\ViewHelpers;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;

/**
 * Turns a node type's "ui.icon" setting into Font Awesome classes the module
 * stylesheet can render, the way the backend UI does: "globe" and the legacy
 * "icon-globe" become "fas fa-globe", "fa-globe" gets the "fas" style, and a
 * value that already names its style ("far fa-file") is kept.
 */
class NodeTypeIconViewHelper extends AbstractViewHelper
{
    protected const FALLBACK = 'fas fa-file';

    public function initializeArguments()
    {
        $this->registerArgument('icon', 'string', 'The ui.icon value of a node type', false, null);
    }

    public function render(): string
    {
        $icon = trim((string)($this->arguments['icon'] ?? ''));
        if ($icon === '') {
            return self::FALLBACK;
        }
        if (strpos($icon, ' ') !== false) {
            return $icon;
        }
        if (strpos($icon, 'icon-') === 0) {
            $icon = substr($icon, 5);
        }
        if (strpos($icon, 'fa-') !== 0) {
            $icon = 'fa-' . $icon;
        }
        return 'fas ' . $icon;
    }
}
