(() => {
    'use strict';

    const STORAGE_PREFIX = 'codeq-workspace-review:';
    const MODE_STORAGE_KEY = STORAGE_PREFIX + 'mode';
    // Set on every content element of a page rendered for the visual compare (Root.fusion).
    const NODE_ATTRIBUTE = 'data-codeq-review-node';
    const STATUSES = ['deleted', 'created', 'moved', 'hidden', 'edited'];
    const BLOCK_SELECTOR = 'p, div, section, article, aside, ul, ol, li, table, blockquote, h1, h2, h3, h4, h5, h6, figure, header, footer, nav';

    // Styles for the markers inside a rendered page. They travel with the
    // script because the page is the site's own document, not the module.
    const FRAME_STYLES = [
        '.codeq-review-overlay{position:absolute;top:0;left:0;width:100%;height:0;overflow:visible;pointer-events:none;z-index:2147483000}',
        '.codeq-review-halo{position:absolute;box-sizing:border-box;border:3px solid var(--codeq-review-colour);border-radius:4px;box-shadow:0 0 0 2px rgba(255,255,255,.75)}',
        '.codeq-review-halo--created{--codeq-review-colour:#00a338}',
        '.codeq-review-halo--edited{--codeq-review-colour:#ff8700}',
        '.codeq-review-halo--moved{--codeq-review-colour:#00b5ff}',
        '.codeq-review-halo--hidden{--codeq-review-colour:#8c8c8c;background:repeating-linear-gradient(135deg,rgba(140,140,140,.28) 0 6px,transparent 6px 14px)}',
        '.codeq-review-halo--deleted{--codeq-review-colour:#ff460d;background:rgba(255,70,13,.28)}',
        '.codeq-review-halo--active{box-shadow:0 0 0 3px #fff,0 0 0 6px var(--codeq-review-colour)}',
        '.codeq-review-halo__label{position:absolute;top:-13px;left:8px;max-width:calc(100% - 16px);margin:0;padding:2px 8px;border:0;border-radius:10px;background:var(--codeq-review-colour);font:600 11px/16px "Noto Sans",sans-serif;letter-spacing:.02em;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:auto;cursor:pointer}',
        '.codeq-review-halo--hidden .codeq-review-halo__label{color:#141414}',
        '.codeq-review-halo__label:hover,.codeq-review-halo__label:focus-visible{outline:2px solid #141414;outline-offset:1px}',
        '.codeq-review-diff ins{background:#dff2e4;color:#0f5132;text-decoration:underline;text-decoration-color:#0f5132;text-decoration-thickness:2px;text-underline-offset:2px;padding:0 2px;border-radius:2px}',
        '.codeq-review-diff del{background:#fbe7e7;color:#96262b;text-decoration:line-through;text-decoration-color:#96262b;padding:0 2px;border-radius:2px}',
        '.codeq-review-sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;border:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}',
        '.codeq-review-ellipsis{color:#9a9a9a;padding:0 4px}',
        '.codeq-review-unplaced{margin:24px;padding:16px;border:2px dashed #ff460d;border-radius:4px}',
        '.codeq-review-unplaced__title{margin:0 0 12px;font:600 14px/20px "Noto Sans",sans-serif;color:#96262b}'
    ].join('\n');

    function initialize() {
        const review = document.querySelector('[data-review-navigation]');
        if (!review) return;

        const sidebar = review.querySelector('.codeq-review-sidebar');
        const list = sidebar.querySelector('.codeq-review-sidebar__list');
        const links = Array.from(list.querySelectorAll('a'));
        const pages = links.map(link => document.getElementById(link.hash.slice(1)));
        if (!pages.length || pages.some(page => !page)) return;

        const groups = pages.map(page => page.closest('tbody'));
        const changesOf = groups.map(group => Array.from(group.querySelectorAll('tr.neos-change')));
        const visualRows = groups.map(group => group.querySelector('[data-review-visual]'));
        const toggles = pages.map(page => page.querySelector('[data-review-toggle]'));
        const staleBadges = pages.map(page => page.querySelector('[data-review-stale]'));
        const labels = review.dataset;

        let activeIndex = -1;
        let framePending = false;

        /* page index ----------------------------------------------------------- */

        function setActive(index) {
            if (index === activeIndex) return;
            activeIndex = index;
            links.forEach((link, linkIndex) => {
                if (linkIndex === index) link.setAttribute('aria-current', 'location');
                else link.removeAttribute('aria-current');
            });
            // Scroll only the index, leaving the review stream and keyboard focus alone.
            const linkBounds = links[index].getBoundingClientRect();
            const listBounds = list.getBoundingClientRect();
            if (linkBounds.top < listBounds.top) list.scrollTop += linkBounds.top - listBounds.top;
            else if (linkBounds.bottom > listBounds.bottom) list.scrollTop += linkBounds.bottom - listBounds.bottom;
        }

        function jumpTo(index, focusPage) {
            index = Math.max(0, Math.min(index, pages.length - 1));
            setActive(index);
            const target = focusPage ? pages[index] : links[index];
            target.focus({preventScroll: true});
            pages[index].scrollIntoView({block: 'start', behavior: 'instant'});
        }

        links.forEach((link, index) => {
            link.addEventListener('click', event => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
                event.preventDefault();
                jumpTo(index, event.detail === 0);
            });
        });

        /* view mode and collapsing --------------------------------------------- */

        const modeButtons = Array.from(review.querySelectorAll('[data-review-mode]'));
        const legendRow = review.querySelector('[data-review-legend]');
        const collapsed = pages.map(() => false);
        let mode = readMode();

        function readMode() {
            try {
                return window.localStorage.getItem(MODE_STORAGE_KEY) === 'visual' ? 'visual' : 'unified';
            } catch (error) {
                return 'unified';
            }
        }

        // The change rows show in the list view, the rendered page in the visual
        // view; a collapsed (reviewed) page shows neither.
        function applyRows(index) {
            const showChanges = mode === 'unified' && !collapsed[index];
            changesOf[index].forEach(row => { row.style.display = showChanges ? '' : 'none'; });
            if (visualRows[index]) visualRows[index].hidden = !(mode === 'visual' && !collapsed[index]);
        }

        function setCollapsed(index, value) {
            collapsed[index] = value;
            applyRows(index);
            const chevron = pages[index].querySelector('.fold-toggle');
            if (chevron) {
                chevron.classList.toggle('fa-chevron-down', value);
                chevron.classList.toggle('fa-chevron-up', !value);
            }
        }

        pages.forEach((page, index) => {
            const chevron = page.querySelector('.fold-toggle');
            if (chevron) chevron.addEventListener('click', () => setCollapsed(index, !collapsed[index]));
        });

        function setMode(newMode, persist) {
            mode = newMode === 'visual' ? 'visual' : 'unified';
            modeButtons.forEach(button => button.setAttribute('aria-pressed', button.getAttribute('data-review-mode') === mode ? 'true' : 'false'));
            if (legendRow) legendRow.hidden = mode !== 'visual';
            pages.forEach((page, index) => applyRows(index));
            if (mode === 'visual') observeVisualRows();
            if (persist) {
                try {
                    window.localStorage.setItem(MODE_STORAGE_KEY, mode);
                } catch (error) {
                    // Without storage the choice lasts for this page view.
                }
            }
            scheduleUpdate();
        }

        modeButtons.forEach(button => button.addEventListener('click', () => setMode(button.getAttribute('data-review-mode'), true)));

        /* reviewed state ------------------------------------------------------- */

        const storageKey = STORAGE_PREFIX + (review.getAttribute('data-review-workspace') || '');
        const reviewed = pages.map(() => false);

        // Which changes a page carries and when they were last modified, from
        // server-rendered attributes so the value is stable across page loads.
        // A page whose signature differs from the stored one was edited after
        // the review, so the reviewed mark is dropped and the page flagged.
        function signatureOf(index) {
            return changesOf[index].map(row =>
                (row.getAttribute('data-nodepath') || '') + '@' + (row.getAttribute('data-review-modified') || '')
            ).join('|');
        }

        function readStore() {
            try {
                const stored = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
                return stored && typeof stored === 'object' ? stored : {};
            } catch (error) {
                return {};
            }
        }

        function writeStore(store) {
            try {
                if (Object.keys(store).length) window.localStorage.setItem(storageKey, JSON.stringify(store));
                else window.localStorage.removeItem(storageKey);
            } catch (error) {
                // Private mode or blocked storage: the state simply lives for this page view.
            }
        }

        const progress = sidebar.querySelector('[data-review-progress]');
        const progressBar = sidebar.querySelector('[data-review-progress-bar]');
        const progressCount = sidebar.querySelector('[data-review-progress-count]');
        const progressReviewed = sidebar.querySelector('[data-review-progress-reviewed]');
        const progressTotal = sidebar.querySelector('[data-review-progress-total]');

        function renderProgress() {
            const done = reviewed.filter(Boolean).length;
            if (progressTotal) progressTotal.textContent = String(pages.length);
            if (progressReviewed) progressReviewed.textContent = String(done);
            if (progress) {
                progress.setAttribute('aria-valuemax', String(pages.length));
                progress.setAttribute('aria-valuenow', String(done));
                progress.hidden = false;
            }
            if (progressBar) progressBar.style.width = (done / pages.length * 100) + '%';
            if (progressCount) progressCount.hidden = false;
            sidebar.classList.toggle('codeq-review-sidebar--complete', done === pages.length);
        }

        function renderReviewed(index, stale) {
            const isReviewed = reviewed[index];
            groups[index].classList.toggle('codeq-review-page--reviewed', isReviewed);
            if (toggles[index]) toggles[index].setAttribute('aria-pressed', isReviewed ? 'true' : 'false');
            if (isReviewed) links[index].setAttribute('data-reviewed', '');
            else links[index].removeAttribute('data-reviewed');
            if (staleBadges[index]) staleBadges[index].hidden = !stale;
        }

        function setReviewed(index, value) {
            reviewed[index] = value;
            const store = readStore();
            if (value) store[pages[index].id] = signatureOf(index);
            else delete store[pages[index].id];
            writeStore(store);
            // Collapsing hides the focused change row; keep focus on the page instead.
            if (value && document.activeElement && changesOf[index].includes(document.activeElement)) {
                pages[index].focus({preventScroll: true});
            }
            setCollapsed(index, value);
            renderReviewed(index, false);
            renderProgress();
        }

        function restoreReviewed() {
            const store = readStore();
            let changed = false;
            pages.forEach((page, index) => {
                if (!(page.id in store)) return;
                const stale = store[page.id] !== signatureOf(index);
                if (stale) {
                    delete store[page.id];
                    changed = true;
                } else {
                    reviewed[index] = true;
                    setCollapsed(index, true);
                }
                renderReviewed(index, stale);
            });
            if (changed) writeStore(store);
            renderProgress();
        }

        toggles.forEach((toggle, index) => {
            if (!toggle) return;
            toggle.addEventListener('click', () => setReviewed(index, !reviewed[index]));
        });

        restoreReviewed();

        /* visual compare ------------------------------------------------------- */

        const statusLabels = {};
        review.querySelectorAll('[data-review-legend-status]').forEach(element => {
            statusLabels[element.getAttribute('data-review-legend-status')] = element.textContent.trim();
        });
        const visuals = pages.map(() => null);
        let visualObserver = null;

        // Pages render only once their frame is about to scroll into view.
        function observeVisualRows() {
            if (visualObserver !== null) return;
            if (typeof IntersectionObserver === 'undefined') {
                visualObserver = false;
                visualRows.forEach((row, index) => row && loadVisual(index));
                return;
            }
            visualObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    visualObserver.unobserve(entry.target);
                    loadVisual(visualRows.indexOf(entry.target));
                });
            }, {rootMargin: '800px 0px'});
            visualRows.forEach(row => row && visualObserver.observe(row));
        }

        // What the change cards of a page know about each changed element.
        function collectChanges(index) {
            return changesOf[index].map(row => {
                const cell = row.querySelector('td.neos-content-change');
                const status = STATUSES.find(candidate => cell && cell.classList.contains('legend-' + candidate)) || 'edited';
                const label = row.querySelector('.codeq-review-change__label');
                const type = row.querySelector('.codeq-review-change__type');
                const textDiffs = Array.from(row.querySelectorAll('.codeq-review-text')).map(text => {
                    const full = text.querySelector('template[data-review-diff]');
                    return {text: text.getAttribute('data-review-text') || '', html: full ? full.innerHTML : ''};
                }).filter(diff => diff.text && diff.html);
                return {
                    row,
                    id: row.getAttribute('data-review-node'),
                    status,
                    label: label ? label.textContent.trim() : '',
                    type: type ? type.textContent.trim() : '',
                    textDiffs
                };
            }).filter(change => change.id);
        }

        function frameDocument(iframe) {
            try {
                const doc = iframe.contentDocument;
                return doc && doc.body ? doc : null;
            } catch (error) {
                return null;
            }
        }

        function findNode(doc, identifier) {
            return doc.querySelector('[' + NODE_ATTRIBUTE + '="' + identifier.replace(/["\\]/g, '\\$&') + '"]');
        }

        function loadVisual(index) {
            const row = visualRows[index];
            if (!row || visuals[index]) return;
            const frame = row.querySelector('.codeq-review-visual__frame');
            const status = frame.querySelector('[data-review-visual-status]');
            const previewUri = row.getAttribute('data-review-preview');
            const liveUri = row.getAttribute('data-review-live-preview');
            const isRemovedDocument = row.getAttribute('data-review-document-removed') === 'true';
            const isNewDocument = row.getAttribute('data-review-document-new') === 'true';
            const visual = {frame, status, iframe: null, doc: null, overlay: null, liveUri: isRemovedDocument ? null : liveUri, marks: [], unlocated: [], notes: null};
            visuals[index] = visual;

            // A deleted page only exists in live and is shown as it is published.
            const mainUri = isRemovedDocument ? liveUri : previewUri;
            if (!mainUri) {
                status.textContent = labels.labelUnavailable;
                return;
            }
            if (isRemovedDocument || isNewDocument) {
                frame.classList.add('codeq-review-visual__frame--' + (isRemovedDocument ? 'removed' : 'new'));
                const banner = document.createElement('p');
                banner.className = 'codeq-review-visual__banner codeq-review-visual__banner--' + (isRemovedDocument ? 'removed' : 'new');
                banner.textContent = isRemovedDocument ? labels.labelRemovedPage : labels.labelNewPage;
                frame.insertBefore(banner, status);
            }

            const iframe = document.createElement('iframe');
            iframe.className = 'codeq-review-visual__iframe';
            const title = pages[index].querySelector('.codeq-review-page__title');
            iframe.setAttribute('title', title ? title.textContent.trim() : '');
            iframe.hidden = true;
            iframe.addEventListener('load', () => {
                const doc = frameDocument(iframe);
                if (!doc) {
                    status.textContent = labels.labelUnavailable;
                    return;
                }
                visual.doc = doc;
                visual.overlay = prepareDocument(doc);
                status.remove();
                iframe.hidden = false;
                decorate(visual, collectChanges(index));
            });
            iframe.src = mainUri;
            visual.iframe = iframe;
            frame.appendChild(iframe);
        }

        // Deleted elements no longer exist in the workspace rendering; the live
        // rendering is loaded out of sight to take them from.
        function loadLive(visual, liveUri, done) {
            const iframe = document.createElement('iframe');
            iframe.className = 'codeq-review-visual__iframe';
            iframe.setAttribute('aria-hidden', 'true');
            iframe.tabIndex = -1;
            iframe.style.cssText = 'position:absolute;top:0;left:0;height:0;min-height:0;visibility:hidden;pointer-events:none';
            iframe.addEventListener('load', () => {
                const liveDoc = frameDocument(iframe);
                done(liveDoc);
                iframe.remove();
            });
            iframe.src = liveUri;
            visual.frame.appendChild(iframe);
        }

        function prepareDocument(doc) {
            const style = doc.createElement('style');
            style.textContent = FRAME_STYLES;
            doc.head.appendChild(style);
            // The frame is for looking, not for browsing: links and forms stay put.
            doc.addEventListener('click', event => {
                const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
                if (anchor) event.preventDefault();
            }, true);
            doc.addEventListener('submit', event => event.preventDefault(), true);
            const overlay = doc.createElement('div');
            overlay.className = 'codeq-review-overlay';
            doc.documentElement.appendChild(overlay);
            return overlay;
        }

        function decorate(visual, changes) {
            const doc = visual.doc;
            const deleted = [];
            changes.forEach(change => {
                const element = findNode(doc, change.id);
                if (!element) {
                    if (change.status === 'deleted' && visual.liveUri) deleted.push(change);
                    else noteUnlocated(visual, change);
                    return;
                }
                if (change.status !== 'deleted') applyTextDiffs(element, change.textDiffs);
                addMark(visual, element, change);
            });
            layoutMarks(visual);
            const win = doc.defaultView;
            if (win && typeof win.ResizeObserver !== 'undefined') {
                new win.ResizeObserver(() => layoutMarks(visual)).observe(doc.documentElement);
            }
            // Deleted elements arrive with the live rendering; the page is
            // usable in the meantime.
            if (!deleted.length) return;
            loadLive(visual, visual.liveUri, liveDoc => {
                deleted.forEach(change => {
                    const element = liveDoc ? transplantDeleted(doc, liveDoc, change.id) : null;
                    if (element) addMark(visual, element, change);
                    else noteUnlocated(visual, change);
                });
                layoutMarks(visual);
            });
        }

        function addMark(visual, element, change) {
            visual.marks.push({element, change, halo: createHalo(visual.doc, visual.overlay, change)});
            visual.marks.sort((a, b) => (a.element.compareDocumentPosition(b.element) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1);
        }

        // Moves the live rendering of a deleted element to where it stood, next
        // to a neighbour that still exists. Without such a neighbour it is
        // listed at the end of the page.
        function transplantDeleted(doc, liveDoc, identifier) {
            const liveElement = findNode(liveDoc, identifier);
            if (!liveElement) return null;
            const clone = doc.importNode(liveElement, true);
            clone.classList.add('codeq-review-deleted-clone');
            for (let sibling = liveElement.previousElementSibling; sibling; sibling = sibling.previousElementSibling) {
                const target = sibling.hasAttribute(NODE_ATTRIBUTE) ? findNode(doc, sibling.getAttribute(NODE_ATTRIBUTE)) : null;
                if (target) {
                    target.insertAdjacentElement('afterend', clone);
                    return clone;
                }
            }
            for (let sibling = liveElement.nextElementSibling; sibling; sibling = sibling.nextElementSibling) {
                const target = sibling.hasAttribute(NODE_ATTRIBUTE) ? findNode(doc, sibling.getAttribute(NODE_ATTRIBUTE)) : null;
                if (target) {
                    target.insertAdjacentElement('beforebegin', clone);
                    return clone;
                }
            }
            let unplaced = doc.querySelector('.codeq-review-unplaced');
            if (!unplaced) {
                unplaced = doc.createElement('section');
                unplaced.className = 'codeq-review-unplaced';
                const heading = doc.createElement('p');
                heading.className = 'codeq-review-unplaced__title';
                heading.textContent = labels.labelUnplaced;
                unplaced.appendChild(heading);
                doc.body.appendChild(unplaced);
            }
            unplaced.appendChild(clone);
            return clone;
        }

        function normalizeText(text) {
            return (text || '').replace(/\s+/g, ' ').trim();
        }

        // The card's word diff replaces the new wording where it appears on the
        // page: the innermost element with exactly that text and no block
        // structure of its own, so paragraphs are not flattened into one.
        function applyTextDiffs(element, textDiffs) {
            textDiffs.forEach(diff => {
                const wanted = normalizeText(diff.text);
                if (!wanted) return;
                const candidates = [element].concat(Array.from(element.querySelectorAll('*')))
                    .filter(candidate => normalizeText(candidate.textContent) === wanted);
                const target = candidates.reverse().find(candidate => !candidates.some(other => other !== candidate && candidate.contains(other)));
                if (!target || target.querySelector(BLOCK_SELECTOR)) return;
                target.innerHTML = diff.html;
                target.classList.add('codeq-review-diff');
            });
        }

        function createHalo(doc, overlay, change) {
            const halo = doc.createElement('div');
            halo.className = 'codeq-review-halo codeq-review-halo--' + change.status;
            const chip = doc.createElement('button');
            chip.type = 'button';
            chip.className = 'codeq-review-halo__label';
            chip.textContent = (statusLabels[change.status] || change.status) + (change.type ? ' · ' + change.type : '');
            chip.title = (change.label ? change.label + ' – ' : '') + labels.labelShowInList;
            chip.addEventListener('click', event => {
                event.preventDefault();
                showInList(change.row);
            });
            halo.appendChild(chip);
            overlay.appendChild(halo);
            return halo;
        }

        // Halos are absolutely positioned in the page document, so they follow
        // its own scrolling; only size changes need a new layout.
        function layoutMarks(visual) {
            const doc = visual.doc;
            const scrollTop = doc.documentElement.scrollTop || doc.body.scrollTop || 0;
            const scrollLeft = doc.documentElement.scrollLeft || doc.body.scrollLeft || 0;
            visual.marks.forEach(mark => {
                const rect = mark.element.getBoundingClientRect();
                const visible = rect.width > 0 && rect.height > 0;
                mark.halo.style.display = visible ? '' : 'none';
                if (!visible) {
                    noteUnlocated(visual, mark.change);
                    return;
                }
                mark.halo.style.top = (rect.top + scrollTop - 4) + 'px';
                mark.halo.style.left = (rect.left + scrollLeft - 4) + 'px';
                mark.halo.style.width = (rect.width + 8) + 'px';
                mark.halo.style.height = (rect.height + 8) + 'px';
            });
        }

        // Changes that have no visible place on the rendered page are listed
        // below the frame, each opening its card in the change list.
        function noteUnlocated(visual, change) {
            if (visual.unlocated.includes(change)) return;
            visual.unlocated.push(change);
            if (!visual.notes) {
                visual.notes = document.createElement('div');
                visual.notes.className = 'codeq-review-visual__notes';
                visual.notes.appendChild(document.createTextNode(labels.labelUnlocated + ':'));
                visual.notes.appendChild(document.createElement('ul'));
                visual.frame.appendChild(visual.notes);
            }
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = [statusLabels[change.status] || change.status, change.type, change.label].filter(Boolean).join(' · ');
            button.title = labels.labelShowInList;
            button.addEventListener('click', () => showInList(change.row));
            item.appendChild(button);
            visual.notes.querySelector('ul').appendChild(item);
        }

        function showInList(row) {
            setMode('unified', true);
            row.classList.remove('codeq-review-change--highlight');
            row.scrollIntoView({block: 'center', behavior: 'instant'});
            row.focus({preventScroll: true});
            // Restart the flash even when the same row is opened twice in a row.
            void row.offsetWidth;
            row.classList.add('codeq-review-change--highlight');
        }

        // ] and [ walk the marked elements of the page in the visual compare.
        function stepVisual(index, direction) {
            const visual = visuals[index];
            if (!visual || !visual.marks.length) return;
            const visibleMarks = visual.marks.filter(mark => mark.halo.style.display !== 'none');
            if (!visibleMarks.length) return;
            const current = visibleMarks.findIndex(mark => mark.halo.classList.contains('codeq-review-halo--active'));
            const next = Math.max(0, Math.min(current + direction, visibleMarks.length - 1));
            if (next === current) return;
            visibleMarks.forEach((mark, markIndex) => mark.halo.classList.toggle('codeq-review-halo--active', markIndex === next));
            jumpTo(index, true);
            visibleMarks[next].element.scrollIntoView({block: 'center', behavior: 'instant'});
        }

        function clearVisualCursor(index) {
            const visual = visuals[index];
            if (visual) visual.marks.forEach(mark => mark.halo.classList.remove('codeq-review-halo--active'));
        }

        /* shortcuts overlay ---------------------------------------------------- */

        const shortcuts = document.querySelector('[data-review-shortcuts]');
        const dialog = shortcuts ? shortcuts.querySelector('[role="dialog"]') : null;
        let openerElement = null;

        function openShortcuts() {
            if (!shortcuts || !shortcuts.hidden) return;
            openerElement = document.activeElement;
            shortcuts.hidden = false;
            dialog.focus();
        }

        function closeShortcuts() {
            if (!shortcuts || shortcuts.hidden) return;
            shortcuts.hidden = true;
            // A hidden element keeps focus until something else takes it; the opener
            // may be the document body, which cannot.
            dialog.blur();
            if (openerElement && typeof openerElement.focus === 'function') openerElement.focus({preventScroll: true});
            openerElement = null;
        }

        if (shortcuts) {
            document.querySelectorAll('[data-review-shortcuts-open]').forEach(button => button.addEventListener('click', openShortcuts));
            shortcuts.querySelectorAll('[data-review-shortcuts-close]').forEach(element => element.addEventListener('click', closeShortcuts));
            shortcuts.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeShortcuts();
                    return;
                }
                // Keep Tab inside the dialog while it is open.
                if (event.key !== 'Tab') return;
                const focusable = Array.from(dialog.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])'));
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        }

        /* keyboard ------------------------------------------------------------- */

        function isEditingContext(element) {
            return !!element.closest('input, select, textarea, button, [contenteditable=""], [contenteditable="true"], a.neos-button');
        }

        // The page a shortcut acts on: the focused link, page row or change row,
        // otherwise the page currently shown at the top of the stream.
        function contextOf(target) {
            const link = target.closest('.codeq-review-sidebar__link');
            if (link) return {index: links.indexOf(link), inSidebar: true, change: -1};
            const page = target.closest('tr.neos-document');
            if (page) return {index: pages.indexOf(page), inSidebar: false, change: -1};
            const changeRow = target.closest('tr.neos-change');
            if (changeRow) {
                const index = groups.indexOf(changeRow.closest('tbody'));
                return {index, inSidebar: false, change: changesOf[index].indexOf(changeRow)};
            }
            return {index: Math.max(activeIndex, 0), inSidebar: false, change: -1};
        }

        function focusChange(index, changeIndex) {
            if (collapsed[index]) setCollapsed(index, false);
            const row = changesOf[index][changeIndex];
            row.focus({preventScroll: true});
            row.scrollIntoView({block: 'nearest', behavior: 'instant'});
        }

        document.addEventListener('keydown', event => {
            if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.isComposing) return;
            if (!(event.target instanceof Element)) return;
            if (shortcuts && !shortcuts.hidden) return;

            if (event.key === '?') {
                if (isEditingContext(event.target) && !event.target.closest('[data-review-shortcuts-open]')) return;
                event.preventDefault();
                openShortcuts();
                return;
            }
            if (event.shiftKey || isEditingContext(event.target)) return;

            const context = contextOf(event.target);
            if (context.index === -1) return;
            const focusPage = !context.inSidebar;

            switch (event.key) {
                case 'ArrowDown':
                case 'j':
                    event.preventDefault();
                    jumpTo(context.index + 1, focusPage);
                    return;
                case 'ArrowUp':
                case 'k':
                    event.preventDefault();
                    jumpTo(context.index - 1, focusPage);
                    return;
                case 'Home':
                    event.preventDefault();
                    jumpTo(0, focusPage);
                    return;
                case 'End':
                    event.preventDefault();
                    jumpTo(pages.length - 1, focusPage);
                    return;
                case 'Enter':
                    if (!context.inSidebar) return;
                    event.preventDefault();
                    jumpTo(context.index, true);
                    return;
                case 'Escape':
                    if (context.inSidebar) return;
                    event.preventDefault();
                    clearVisualCursor(context.index);
                    if (context.change !== -1) pages[context.index].focus({preventScroll: true});
                    else links[context.index].focus({preventScroll: true});
                    return;
                case ']':
                    event.preventDefault();
                    if (mode === 'visual') stepVisual(context.index, 1);
                    else if (context.change + 1 < changesOf[context.index].length) focusChange(context.index, context.change + 1);
                    return;
                case '[':
                    event.preventDefault();
                    if (mode === 'visual') stepVisual(context.index, -1);
                    else if (context.change === 0) jumpTo(context.index, true);
                    else if (context.change > 0) focusChange(context.index, context.change - 1);
                    return;
                case 'v':
                    event.preventDefault();
                    setReviewed(context.index, !reviewed[context.index]);
                    return;
                case 'd':
                    event.preventDefault();
                    setMode(mode === 'visual' ? 'unified' : 'visual', true);
                    if (!context.inSidebar) pages[context.index].focus({preventScroll: true});
                    return;
                default:
            }
        });

        /* follow the scroll position --------------------------------------------- */

        function updateFromScroll() {
            framePending = false;
            // Use the same clearance as anchor navigation, below the Neos toolbar.
            const top = parseFloat(getComputedStyle(pages[0]).scrollMarginTop) || 0;
            let index = 0;
            pages.forEach((page, pageIndex) => {
                if (page.getBoundingClientRect().top <= top + 1) index = pageIndex;
            });
            // A short final page cannot always reach the top before scrolling ends.
            const scroller = document.scrollingElement;
            if (scroller.scrollTop > 0 && Math.ceil(scroller.scrollTop + scroller.clientHeight) >= scroller.scrollHeight) {
                index = pages.length - 1;
            }
            setActive(index);
        }

        function scheduleUpdate(event) {
            if (event && event.type === 'scroll' && event.target instanceof Node && sidebar.contains(event.target)) return;
            if (framePending) return;
            framePending = true;
            requestAnimationFrame(updateFromScroll);
        }

        // Capture also observes scrolling when the module is inside a scroll container.
        document.addEventListener('scroll', scheduleUpdate, {capture: true, passive: true});
        window.addEventListener('resize', scheduleUpdate);
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(scheduleUpdate).observe(review);
        setMode(mode, false);
        updateFromScroll();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
