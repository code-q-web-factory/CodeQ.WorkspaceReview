# Prototype - CodeQ.WorkspaceReview

Improve the human-readable workspace change review for the Neos CMS.
The package replaces only the controller and the change rendering of the 
existing module — publish/discard behavior, module path and permissions 
stay unchanged.

## What it improves over the core module

- **Word-level text diffs**: small edits no longer mark whole paragraphs as
  changed. Long unchanged passages are collapsed with an ellipsis.
- **Editor-aware value labels**: values of select box and toggle editors are
  shown with their translated `editorOptions.values` labels (e.g.
  "Kein Abstand" instead of `none`). Booleans render as Yes/No, references as
  node labels.
- **No invisible changes**: every changed node shows a reason. Visibility
  changes appear as an explicit entry, and nodes without any renderable
  property change get a "no visible changes" note instead of an empty row.
- **Correct entity handling**: HTML entities are decoded before diffing and
  in labels, so titles show "&" instead of "&amp;".
- **Per-page summary**: each document row shows a summary like
  "2 Texte · 1 Medium · 1 Einstellung".
- **Status badges**: created / deleted / moved / hidden are shown as visible
  badges per change instead of only a row background color.

## How it works

- `Configuration/Settings.yaml` swaps the controller of the existing
  `management/workspaces` module to
  `CodeQ\WorkspaceReview\Controller\Module\Management\WorkspacesController`,
  which extends the core controller and only replaces the diff pipeline.
- `Configuration/Views.yaml` adds Neos.Neos template/partial/layout fallbacks,
  so untouched actions (Index, New, Edit) keep using the core templates.
- `Configuration/Policy.yaml` re-grants the inherited core controller actions:
  the module's `ModulePrivilege` derives its method matcher from the configured
  controller class, so after the swap the core class' woven policy interception
  would deny inherited actions with only "Neos.Neos:AllControllerActions"
  (abstain) matching. Without this file, the module answers 403.
- Templates are resolved from this package first; only `Show.html`, the
  `ContentChangeDiff` partial and the `DocumentBreadcrumb` partial are
  overridden.

## Installation

The package lives in `DistributionPackages` and is installed through the
path repository of the distribution:

```bash
composer require codeq/workspace-review
./flow flow:cache:flush
```
