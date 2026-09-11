<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Controller;

/*
 * This file is part of the CodeQ.WorkspaceReview package.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\View\FusionView;

/**
 * Renders a document for the visual compare of the workspace review: the
 * page as the site renders it, in the reviewed workspace or in live. While
 * this controller renders, Root.fusion marks every content element with its
 * node identifier, so the review can place change markers on the page.
 *
 * The content cache is switched off for this rendering. The marked-up output
 * must never be cached for site visitors, and a cached plain rendering must
 * not be served here instead of the marked-up one.
 */
class PreviewController extends ActionController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\IgnoreValidation("node")
     * @throws AccessDeniedException
     */
    public function showAction(NodeInterface $node): void
    {
        $workspace = $node->getContext()->getWorkspace();
        if ($workspace !== null && !$workspace->isPublicWorkspace() && !$this->userService->currentUserCanReadWorkspace($workspace)) {
            throw new AccessDeniedException('The current user may not read workspace "' . $workspace->getName() . '".', 1757600000);
        }

        $this->response->setHttpHeader('Cache-Control', 'no-store');
        $this->view->setOption('enableContentCache', false);
        $this->view->assign('value', $node);
    }
}
