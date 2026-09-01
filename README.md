# Prototype - CodeQ.WorkspaceReview

Improve the human-readable workspace change review for the Neos CMS.
The package replaces only the controller and the change rendering of the 
existing module — publish/discard behavior, module path and permissions 
stay unchanged.

![The workspace module listing one card per changed node: a link retargeted to
another page, a link that now opens in a new tab, a readable position, a
formatting change and the notes for changes that stay
invisible](Documentation/workspace-review.png)

## What it improves over the core module

- **Word-level text diffs**: small edits no longer mark whole paragraphs as
  changed. Long unchanged passages are collapsed with an ellipsis.
- **Editor-aware value labels**: values of select box and toggle editors are
  shown with their translated `editorOptions.values` labels (e.g.
  "Kein Abstand" instead of `none`). Booleans render as Yes/No, references as
  node labels.
- **Rich-text aware diff**: changes the text diff cannot see, because it strips
  all tags, are compared on the markup level: a link pointing somewhere else
  (internal `node://` and `asset://` targets resolved to page and asset names),
  words that were linked or unlinked, and formatting such as bold, italic or a
  changed heading level. Added or removed links are only reported while the
  surrounding wording is unchanged — once the text changed too, the text diff
  owns the story.
- **Readable positions**: a moved node shows its place among its siblings
  ("Position 2 von 3 → 1 von 3") instead of the sparse internal sorting index
  ("100 → 150"). An index that only changed because the siblings were renumbered
  is named as internal re-sorting.
- **No invisible changes**: every changed node shows a reason. Visibility
  changes appear as an explicit entry, a property changed back to its NodeType
  default is reported instead of silently dropped, and nodes without any
  renderable property change get a note - either "no visible changes" or, when
  the node was edited and reverted, that it matches the published version again.
- **Correct entity handling**: HTML entities are decoded before diffing and
  in labels, so titles show "&" instead of "&amp;".
- **Per-page summary**: each document row shows a summary like
  "2 Texte · 1 Medium · 1 Einstellung".
- **Status badges**: created / deleted / moved / hidden are shown as visible
  badges per change instead of only a row background color.

## What a reviewer sees

Every changed node becomes one card, and a card lists one entry per thing that
would change on publish. A card never stays silent: when nothing renderable
changed, it says so instead of leaving an empty row.

| Entry | Appears when | Reads like |
| --- | --- | --- |
| Text | the wording changed | `… beginnt jeweils um ~~18~~ 19 Uhr …` |
| Link | a link points elsewhere, opens differently, or words were linked or unlinked | `Link "Anfahrt und Kontakt" · Text Hero → Image Hero` |
| Formatting | the same words carry different formatting | `"18 Uhr," · no formatting → bold` |
| Value | a property changed, shown with its editor labels | `Abstand unten · Groß → Klein` |
| Position | a node was reordered among its siblings | `Position · 3 of 3 → 1 of 3` |
| Image, Asset | media was replaced | the published and the new file side by side |
| Visibility | a node was hidden or made visible | `Element was hidden` |
| Note | nothing renderable changed | one of the three sentences below |

### Links and formatting

A word-level diff strips markup before comparing, so an editor who only
retargets a link or emphasises a word produces no visible difference at all.
Those edits are therefore compared a second time on the markup level:

- **A link points somewhere else.** Internal targets are resolved, so the entry
  reads `Text Hero → Image Hero` instead of two `node://` UUIDs. When both
  targets resolve to the same name, the raw URIs are shown, so the row is never
  `X → X`.
- **A link behaves differently.** A link that starts opening in a new window
  reads `Link "PDF herunterladen" – window · Same tab → New tab`.
- **Words were linked or unlinked.** Only reported while the surrounding
  wording is unchanged. Once the text changed too, the text diff already shows
  the passage and a guess about the link would contradict it.
- **Formatting changed.** Bold, italic, underline, strikethrough, sub- and
  superscript, code, highlight and the heading level, reported per passage.

Findings are deliberately conservative: what the comparison cannot attribute
with certainty becomes a note rather than a guess.

### The three notes

They look similar but answer different questions, and the difference decides
whether the reviewer has to open the preview at all:

- **"Changed - the wording is unchanged, please check details in the preview."**
  sits on a property whose stored value really differs while the words read the
  same. The field is named, so the reviewer knows where to look. Typical causes
  are a non-breaking space pasted in from a word processor, a changed `style` or
  `class`, or a bullet list that became a numbered list.
- **"Edited, but matching the published version again - no content differences
  found."** means the node was touched and then set back. Publishing it has no
  effect, so it can be discarded without reading further.
- **"No visible changes (internal update)"** is the remaining case: the node
  differs, but not in a way that can be attributed to a single field.

## How it works

- `Configuration/Settings.yaml` swaps the controller of the existing
  `management/workspaces` module to
  `CodeQ\WorkspaceReview\Controller\Module\Management\WorkspacesController`,
  which extends the core controller and only replaces the diff pipeline.
- `Classes/Diff/RichTextDiffer.php` performs the markup-level comparison. It
  parses both values with `\DOMDocument`, pairs their links by identity first
  and by label second, and compares formatting word by word. It needs no
  injected dependencies and knows nothing about the content repository, so it
  can be tested against real markup; turning its findings into labels and
  translations is the controller's job.
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

## Tests

```bash
ddev exec bin/phpunit --configuration UnitTests.xml \
  DistributionPackages/CodeQ.WorkspaceReview/Tests/Unit
```
