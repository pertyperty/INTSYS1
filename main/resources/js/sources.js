// Progressive enhancement for the approved sources catalog: unified sidebar drawer
// plus client-side filtering of the current page only.
const DESKTOP_QUERY = window.matchMedia('(min-width: 1024px)');

document.addEventListener('DOMContentLoaded', () => {
    /* ---------------------------------------------------------------- Drawer */
    const drawer = document.getElementById('chat-drawer');
    const drawerToggle = document.getElementById('drawer-toggle');
    const drawerBackdrop = document.getElementById('drawer-backdrop');
    const workspace = document.getElementById('chat-workspace');

    const setDrawer = (open, { restoreFocus = true } = {}) => {
        if (!drawer) {
            return;
        }
        const isDesktop = DESKTOP_QUERY.matches;
        const modalOpen = !isDesktop && open;
        drawer.dataset.open = open ? 'true' : 'false';
        drawer.inert = !isDesktop && !open;
        if (workspace) {
            workspace.inert = modalOpen;
        }
        if (modalOpen) {
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
        } else {
            drawer.removeAttribute('role');
            drawer.removeAttribute('aria-modal');
        }
        if (drawerBackdrop) {
            drawerBackdrop.hidden = isDesktop || !open;
        }
        drawerToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!isDesktop && open) {
            drawer.querySelector('a, button')?.focus();
        } else if (!isDesktop && restoreFocus) {
            drawerToggle?.focus();
        }
    };

    if (drawer) {
        setDrawer(false, { restoreFocus: false });
        drawerToggle?.addEventListener('click', () => setDrawer(drawer.dataset.open !== 'true'));
        document.querySelectorAll('[data-drawer-close]').forEach((element) => {
            element.addEventListener('click', () => setDrawer(false));
        });
        DESKTOP_QUERY.addEventListener('change', () => setDrawer(false, { restoreFocus: false }));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && drawer.dataset.open === 'true') {
                setDrawer(false);
            }
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const promptText = form.getAttribute('data-confirm');
            if (promptText && !window.confirm(promptText)) {
                event.preventDefault();
            }
        });
    });

    /* ------------------------------------------------------- Page filtering */
    const toolbar = document.getElementById('source-toolbar');
    const searchInput = document.getElementById('source-search');
    const countLabel = document.getElementById('source-count');
    const noMatch = document.getElementById('source-no-match');
    const cards = Array.from(document.querySelectorAll('[data-source-card]'));
    const hazardButtons = Array.from(document.querySelectorAll('[data-hazard-filter]'));

    if (!toolbar || cards.length === 0) {
        return;
    }

    toolbar.hidden = false;
    let activeHazard = '';

    const describe = (visible) => {
        const noun = cards.length === 1 ? 'source' : 'sources';
        return visible === cards.length
            ? `${cards.length} ${noun} on this page`
            : `Showing ${visible} of ${cards.length} ${noun} on this page`;
    };

    const applyFilters = () => {
        const query = (searchInput?.value ?? '').trim().toLowerCase();
        let visible = 0;

        cards.forEach((card) => {
            const haystack = card.dataset.search ?? '';
            const hazards = (card.dataset.hazards ?? '').split('|').filter(Boolean);
            const matchesQuery = query === '' || haystack.includes(query);
            const matchesHazard = activeHazard === '' || hazards.includes(activeHazard);
            const show = matchesQuery && matchesHazard;
            card.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        if (countLabel) {
            countLabel.textContent = describe(visible);
        }
        if (noMatch) {
            noMatch.hidden = visible !== 0;
        }
    };

    searchInput?.addEventListener('input', applyFilters);

    hazardButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeHazard = button.dataset.hazardFilter ?? '';
            hazardButtons.forEach((other) => {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            applyFilters();
        });
    });
});
