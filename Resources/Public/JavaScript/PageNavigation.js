(() => {
    'use strict';

    const STORAGE_PREFIX = 'codeq-workspace-review:';

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
        const toggles = pages.map(page => page.querySelector('[data-review-toggle]'));
        const staleBadges = pages.map(page => page.querySelector('[data-review-stale]'));

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

        /* collapsing ----------------------------------------------------------- */

        // The chevron in the page row folds the change rows with jQuery's
        // toggle(), which flips the inline display; the same property is set
        // here so both controls agree.
        function isCollapsed(index) {
            return changesOf[index].length > 0 && changesOf[index][0].style.display === 'none';
        }

        function setCollapsed(index, collapsed) {
            changesOf[index].forEach(row => { row.style.display = collapsed ? 'none' : ''; });
            const chevron = pages[index].querySelector('.fold-toggle');
            if (chevron) {
                chevron.classList.toggle('fa-chevron-down', collapsed);
                chevron.classList.toggle('fa-chevron-up', !collapsed);
            }
        }

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
            if (isCollapsed(index)) setCollapsed(index, false);
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
                    if (context.change !== -1) pages[context.index].focus({preventScroll: true});
                    else links[context.index].focus({preventScroll: true});
                    return;
                case ']':
                    if (context.change + 1 >= changesOf[context.index].length) return;
                    event.preventDefault();
                    focusChange(context.index, context.change + 1);
                    return;
                case '[':
                    if (context.change === -1) return;
                    event.preventDefault();
                    if (context.change === 0) jumpTo(context.index, true);
                    else focusChange(context.index, context.change - 1);
                    return;
                case 'v':
                    event.preventDefault();
                    setReviewed(context.index, !reviewed[context.index]);
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
        updateFromScroll();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
