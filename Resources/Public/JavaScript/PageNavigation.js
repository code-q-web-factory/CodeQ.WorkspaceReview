(() => {
    'use strict';

    function initialize() {
        const review = document.querySelector('[data-review-navigation]');
        if (!review) return;

        const sidebar = review.querySelector('.codeq-review-sidebar');
        const list = sidebar.querySelector('.codeq-review-sidebar__list');
        const links = Array.from(list.querySelectorAll('a'));
        const pages = links.map(link => document.getElementById(link.hash.slice(1)));
        if (!pages.length || pages.some(page => !page)) return;

        let activeIndex = -1;
        let framePending = false;

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

        review.addEventListener('keydown', event => {
            if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) return;
            // Single-letter shortcuts are scoped to navigation/page focus, never form controls.
            const link = event.target.closest('.codeq-review-sidebar__link');
            const pageIndex = pages.indexOf(event.target);
            if (!link && pageIndex === -1) return;
            const index = link ? links.indexOf(link) : pageIndex;
            let nextIndex;
            if (event.key === 'ArrowDown' || event.key === 'j') nextIndex = index + 1;
            else if (event.key === 'ArrowUp' || event.key === 'k') nextIndex = index - 1;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = pages.length - 1;
            else if (event.key === 'Escape' && pageIndex !== -1) {
                event.preventDefault();
                links[pageIndex].focus({preventScroll: true});
                return;
            } else return;
            event.preventDefault();
            jumpTo(nextIndex, !link);
        });

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
