<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\ViewHelpers;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a node label or property value as plain readable text: markup is
 * stripped and HTML entities are decoded, so titles show "&" instead of
 * "&amp;". The result is escaped again by Fluid's regular output escaping.
 */
class CleanLabelViewHelper extends AbstractViewHelper
{
    public function initializeArguments()
    {
        $this->registerArgument('value', 'string', 'The label to clean', false, null);
    }

    public function render(): string
    {
        $value = $this->arguments['value'] ?? $this->renderChildren();
        if (!is_string($value) || $value === '') {
            return '';
        }
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $value));
    }
}
