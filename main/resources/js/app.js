import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const BAGUIO_CENTER = [16.4023, 120.596];
const BAGUIO_BOUNDS = [
    [16.34, 120.54],
    [16.46, 120.66],
];
const FIXED_CITY_ZOOM = 13;
const LOW_ACCURACY_THRESHOLD_METERS = 500;
const DESKTOP_QUERY = window.matchMedia('(min-width: 1024px)');

const LOCATION_PROMPT_REGEX =
    /\b(?:near me|my location|current location|around here|where am i|weather here|my area|nearby|malapit sa akin|lugar ko|dito ngayon|weather dito|panahon dito)\b/i;

const isTypingTarget = (element) =>
    element instanceof HTMLElement &&
    (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName));

const requestBrowserLocationOnce = () =>
    new Promise((resolve) => {
        if (!('geolocation' in navigator)) {
            resolve(null);
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                resolve({
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy: Number.isFinite(pos.coords.accuracy) ? Math.round(pos.coords.accuracy) : null,
                });
            },
            () => resolve(null),
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 }
        );
    });

const statusHeaderFor = (status) => {
    switch (status) {
        case 'emergency':
            return {
                heading: 'Emergency',
                summary: 'Contact emergency services now. SANDIG cannot dispatch responders.',
                wrap: 'border-2 border-red-600 bg-red-50 ring-4 ring-red-600/10',
                band: 'bg-red-600 text-white',
                summaryClass: 'text-red-50',
                metaClass: 'text-red-100',
                citationHeading: 'References',
                isEmergency: true,
            };
        case 'supported':
            return {
                heading: 'Supported by approved sources',
                summary: 'Grounded in the approved sources listed below.',
                wrap: 'border border-slate-200 bg-white',
                band: 'border-b border-teal-100 bg-teal-50/70 text-teal-950',
                summaryClass: 'text-slate-600',
                metaClass: 'text-slate-500',
                citationHeading: 'Cited sources',
                isEmergency: false,
            };
        case 'partially_supported':
            return {
                heading: 'Partially supported',
                summary: 'Some points could not be confirmed by approved sources. Treat them with caution.',
                wrap: 'border border-slate-200 bg-white',
                band: 'border-b border-amber-100 bg-amber-50/80 text-amber-950',
                summaryClass: 'text-slate-600',
                metaClass: 'text-slate-500',
                citationHeading: 'Cited sources',
                isEmergency: false,
            };
        case 'out_of_scope':
            return {
                heading: 'Out of scope',
                summary: 'SANDIG answers disaster preparedness questions only.',
                wrap: 'border border-slate-200 bg-white',
                band: 'border-b border-slate-100 bg-slate-50 text-slate-900',
                summaryClass: 'text-slate-600',
                metaClass: 'text-slate-500',
                citationHeading: 'Related links (not evidence for this answer)',
                isEmergency: false,
            };
        case 'insufficient_evidence':
            return {
                heading: 'Insufficient evidence',
                summary: 'Approved sources did not contain enough information to answer reliably.',
                wrap: 'border border-slate-200 bg-white',
                band: 'border-b border-slate-100 bg-slate-50 text-slate-900',
                summaryClass: 'text-slate-600',
                metaClass: 'text-slate-500',
                citationHeading: 'Related links (not evidence for this answer)',
                isEmergency: false,
            };
        default:
            return {
                heading: 'SANDIG',
                summary: null,
                wrap: 'border border-slate-200 bg-white',
                band: 'border-b border-slate-100 bg-white text-teal-950',
                summaryClass: 'text-slate-600',
                metaClass: 'text-slate-500',
                citationHeading: 'Sources',
                isEmergency: false,
            };
    }
};

const citationMetaFor = (cite) => {
    if (cite?.is_curated_emergency_reference) {
        return { label: 'Curated emergency reference', badge: 'bg-red-100 text-red-900 ring-red-200', number: 'bg-red-600 text-white', note: null };
    }
    switch (cite?.kind) {
        case 'curated_guidance':
            return {
                label: 'Curated general guidance',
                badge: 'bg-violet-50 text-violet-900 ring-violet-200',
                number: 'bg-violet-700 text-white',
                note: 'Bundled, source-reviewed general preparedness guidance; not specific to current conditions.',
            };
        case 'official_live':
            return { label: 'Official live feed', badge: 'bg-blue-100 text-blue-900 ring-blue-200', number: 'bg-blue-700 text-white', note: null };
        case 'supplementary_live':
            return {
                label: 'Supplementary live feed',
                badge: 'bg-sky-100 text-sky-900 ring-sky-200',
                number: 'bg-sky-700 text-white',
                note: 'Supplementary feed; confirm against official agency advisories.',
            };
        case 'web_search':
            return {
                label: 'External web search',
                badge: 'bg-amber-100 text-amber-950 ring-amber-300',
                number: 'bg-amber-600 text-white',
                note: 'External links/search results, not verified guidance.',
            };
        default:
            return { label: 'Indexed document', badge: 'bg-teal-50 text-teal-900 ring-teal-200', number: 'bg-teal-700 text-white', note: null };
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const chatForm = document.getElementById('chat-form');
    const submitStatus = document.getElementById('chat-submit-status');
    const messageInput = document.getElementById('message');
    const thread = document.getElementById('thread');
    const threadMessages = document.getElementById('thread-messages');
    const conversationIdInput = document.getElementById('conversation-id-input');

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
        drawer.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab' || drawer.getAttribute('aria-modal') !== 'true') {
                return;
            }
            const focusable = [...drawer.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')]
                .filter((element) => element.tabIndex >= 0 && element.getClientRects().length && !element.matches(':disabled'));
            const first = focusable[0];
            const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        });
    }

    /* ------------------------------------------- LM Studio parameter sliders */
    const tempSlider = document.getElementById('setting-temperature');
    const tempVal = document.getElementById('temperature-value');
    tempSlider?.addEventListener('input', () => {
        if (tempVal) {
            tempVal.textContent = Number(tempSlider.value).toFixed(2);
        }
    });

    const topPSlider = document.getElementById('setting-top-p');
    const topPVal = document.getElementById('top-p-value');
    topPSlider?.addEventListener('input', () => {
        if (topPVal) {
            topPVal.textContent = Number(topPSlider.value).toFixed(2);
        }
    });

    /* ------------------------------------------------------ Confirm deletes */
    const bindConfirmForm = (form) => {
        if (!form || form.dataset.confirmBound === 'true') {
            return;
        }
        form.dataset.confirmBound = 'true';
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.getAttribute('data-confirm') ?? 'Are you sure?')) {
                event.preventDefault();
            }
        });
    };
    document.querySelectorAll('form[data-confirm]').forEach(bindConfirmForm);

    /* --------------------------------------------------------- Web switch */
    const webSwitch = document.getElementById('web-search-switch');
    const webState = document.getElementById('web-search-state');
    const settingsWebOff = document.getElementById('settings-web-off');
    const syncWebSwitch = () => {
        if (!webSwitch) {
            return;
        }
        if (webState) {
            webState.textContent = webSwitch.checked ? 'On' : 'Off';
        }
        if (settingsWebOff) {
            settingsWebOff.value = webSwitch.checked ? '0' : '1';
        }
    };
    webSwitch?.addEventListener('change', syncWebSwitch);
    syncWebSwitch();

    /* ------------------------------------------- Real-time Recent Chats UI */
    const recentChatsNav = document.getElementById('recent-chats-nav');
    const headerTitle = document.getElementById('chat-header-title');
    const settingsReturnTo = document.getElementById('settings-return-to');
    const csrfToken = chatForm?.querySelector('input[name="_token"]')?.value ?? '';

    const upsertRecentChat = ({ id, title, url, deleteUrl }) => {
        if (!id || !url) {
            return;
        }
        const safeTitle = (title || 'New chat').trim();

        if (headerTitle) {
            headerTitle.textContent = safeTitle;
        }
        document.title = `${safeTitle} | SANDIG | Preparedness Chat`;
        if (settingsReturnTo) {
            settingsReturnTo.value = id;
        }
        if (messageInput) {
            messageInput.placeholder = 'Ask a follow-up question...';
        }

        if (!recentChatsNav) {
            return;
        }
        document.getElementById('recent-chats-empty')?.remove();

        let list = document.getElementById('recent-chats-list');
        if (!list) {
            list = document.createElement('ul');
            list.id = 'recent-chats-list';
            list.className = 'space-y-0.5';
            recentChatsNav.appendChild(list);
        }

        // Clear active styles on sibling items
        list.querySelectorAll('li[data-conversation-id]').forEach((item) => {
            item.className = 'group/item relative flex items-center rounded-lg hover:bg-slate-100';
            const link = item.querySelector('a');
            link?.removeAttribute('aria-current');
            const titleEl = item.querySelector('[data-chat-title]');
            if (titleEl) {
                titleEl.className = 'block truncate text-sm font-medium text-slate-800';
            }
            const delBtn = item.querySelector('form button[type="submit"]');
            delBtn?.classList.remove('sm:opacity-100');
        });

        let item = Array.from(list.children).find((el) => el.getAttribute('data-conversation-id') === id);
        if (!item) {
            item = document.createElement('li');
            item.setAttribute('data-conversation-id', id);

            const link = document.createElement('a');
            link.href = url;
            link.className = 'min-w-0 flex-1 rounded-lg px-2.5 py-2 pr-9 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700';

            const titleSpan = document.createElement('span');
            titleSpan.setAttribute('data-chat-title', '');
            titleSpan.textContent = safeTitle;

            const timeSpan = document.createElement('span');
            timeSpan.setAttribute('data-chat-timestamp', '');
            timeSpan.className = 'block text-[11px] text-slate-500';
            timeSpan.textContent = 'just now';

            link.appendChild(titleSpan);
            link.appendChild(timeSpan);
            item.appendChild(link);

            if (deleteUrl && csrfToken) {
                const delForm = document.createElement('form');
                delForm.method = 'post';
                delForm.action = deleteUrl;
                delForm.setAttribute('data-confirm', 'Delete this chat? This cannot be undone.');
                delForm.className = 'absolute right-1.5';

                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = '_token';
                tokenInput.value = csrfToken;

                const methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';

                const delBtn = document.createElement('button');
                delBtn.type = 'submit';
                delBtn.className = 'flex h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 sm:opacity-100';
                delBtn.setAttribute('aria-label', `Delete chat: ${safeTitle}`);
                delBtn.textContent = '×';

                delForm.appendChild(tokenInput);
                delForm.appendChild(methodInput);
                delForm.appendChild(delBtn);
                bindConfirmForm(delForm);
                item.appendChild(delForm);
            }
        } else {
            const titleEl = item.querySelector('[data-chat-title]');
            if (titleEl && safeTitle) {
                titleEl.textContent = safeTitle;
            }
            const timeEl = item.querySelector('[data-chat-timestamp]');
            if (timeEl) {
                timeEl.textContent = 'just now';
            }
        }

        item.className = 'group/item relative flex items-center rounded-lg bg-teal-50 ring-1 ring-inset ring-teal-200';
        const activeLink = item.querySelector('a');
        activeLink?.setAttribute('aria-current', 'page');
        const activeTitle = item.querySelector('[data-chat-title]');
        if (activeTitle) {
            activeTitle.className = 'block truncate text-sm font-semibold text-teal-950';
        }
        const activeDelBtn = item.querySelector('form button[type="submit"]');
        activeDelBtn?.classList.add('sm:opacity-100');

        list.prepend(item);
    };

    /* ------------------------------------------------ Live Streaming Submit */
    const charCount = document.getElementById('char-count');
    const maxLength = Number(messageInput?.getAttribute('maxlength') ?? 4000);
    const prefersTouch = window.matchMedia('(pointer: coarse)').matches;

    const isNearBottom = () => {
        if (!thread) {
            return true;
        }
        return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 140;
    };

    const scrollThreadToBottom = (force = false) => {
        if (thread && (force || isNearBottom())) {
            thread.scrollTop = thread.scrollHeight;
        }
    };

    const syncComposer = () => {
        if (!messageInput) {
            return;
        }
        messageInput.style.height = 'auto';
        messageInput.style.height = `${messageInput.scrollHeight}px`;
        if (charCount) {
            const length = messageInput.value.length;
            charCount.textContent = `${length.toLocaleString()} / ${maxLength.toLocaleString()}`;
            charCount.classList.toggle('text-amber-700', length > maxLength * 0.9);
        }
    };

    const bindCopyButton = (button, targetGetter) => {
        const label = button.querySelector('[data-copy-label]');
        button.addEventListener('click', async () => {
            const text = targetGetter();
            if (!text) {
                return;
            }
            try {
                if (!navigator.clipboard) {
                    throw new Error('Clipboard unavailable');
                }
                await navigator.clipboard.writeText(text);
                if (label) {
                    label.textContent = 'Copied';
                }
            } catch {
                if (label) {
                    label.textContent = 'Copy failed. Select the text to copy it.';
                }
            }
            window.setTimeout(() => {
                if (label) {
                    label.textContent = 'Copy answer';
                }
            }, 2500);
        });
    };

    const appendUserBubble = (text) => {
        if (!threadMessages) {
            return;
        }
        // Remove empty-state hero sections when first message is sent
        threadMessages.querySelectorAll('section[aria-labelledby="hero-heading"], section[aria-label="Example preparedness questions"], section[aria-labelledby="portals-heading"]').forEach((el) => el.remove());

        const row = document.createElement('div');
        row.className = 'flex justify-end';
        const bubble = document.createElement('div');
        bubble.className = 'max-w-[85%] rounded-2xl rounded-br-md bg-teal-700 px-4 py-2.5 text-[15px] leading-relaxed text-white shadow-sm';
        const p = document.createElement('p');
        p.className = 'whitespace-pre-wrap break-words';
        p.textContent = text;
        bubble.appendChild(p);
        row.appendChild(bubble);
        threadMessages.appendChild(row);
        scrollThreadToBottom(true);
    };

    const createStreamingAssistantCard = (webOffForTurn) => {
        const article = document.createElement('article');
        article.className = 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm';

        const header = document.createElement('div');
        header.className = 'flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-white px-4 py-3 text-teal-950 sm:px-5';

        const headingWrap = document.createElement('div');
        headingWrap.className = 'min-w-0';
        const heading = document.createElement('h2');
        heading.className = 'text-sm font-bold leading-tight sm:text-base';
        heading.textContent = 'SANDIG';
        const summaryP = document.createElement('p');
        summaryP.className = 'text-xs text-slate-600 empty:hidden';
        headingWrap.appendChild(heading);
        headingWrap.appendChild(summaryP);

        const checkedBadge = document.createElement('span');
        checkedBadge.className = 'text-xs text-slate-500 empty:hidden';
        checkedBadge.textContent = 'Thinking...';

        header.appendChild(headingWrap);
        header.appendChild(checkedBadge);

        const body = document.createElement('div');
        body.className = 'p-4 sm:p-5';

        const webBanner = document.createElement('p');
        webBanner.setAttribute('role', 'status');
        webBanner.hidden = true;
        webBanner.className = 'mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-slate-800';
        const webStrong = document.createElement('strong');
        webStrong.textContent = 'External web search contacted for this request: ';
        const webRest = document.createTextNode('Any web search results below are external links/search results, not verified guidance.');
        const webTextSpan = document.createElement('span');
        webTextSpan.appendChild(webStrong);
        webTextSpan.appendChild(webRest);
        webBanner.appendChild(webTextSpan);

        const answerP = document.createElement('p');
        answerP.className = 'whitespace-pre-wrap break-words text-[15px] leading-relaxed text-slate-900 sm:text-base';
        answerP.textContent = '';

        const emergencyBox = document.createElement('div');
        emergencyBox.hidden = true;
        emergencyBox.className = 'mt-4 flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-red-200 sm:flex-row sm:items-center sm:justify-between';
        const emergencyMsg = document.createElement('p');
        emergencyMsg.className = 'text-sm font-medium text-red-950';
        emergencyMsg.textContent = 'In the Philippines and in immediate danger? Call the national emergency hotline.';
        const callLink = document.createElement('a');
        callLink.href = 'tel:911';
        callLink.className = 'btn btn-danger px-4 py-2.5 text-sm';
        callLink.textContent = 'Call 911';
        emergencyBox.appendChild(emergencyMsg);
        emergencyBox.appendChild(callLink);

        const citationsBox = document.createElement('div');
        citationsBox.className = 'mt-4 empty:hidden';

        const footer = document.createElement('div');
        footer.className = 'mt-3 flex flex-wrap items-center gap-2';

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn px-2 py-1 font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800';
        const copyLabel = document.createElement('span');
        copyLabel.setAttribute('data-copy-label', '');
        copyLabel.setAttribute('aria-live', 'polite');
        copyLabel.textContent = 'Copy answer';
        copyBtn.appendChild(copyLabel);
        bindCopyButton(copyBtn, () => answerP.textContent ?? '');
        footer.appendChild(copyBtn);

        const modelPill = document.createElement('span');
        modelPill.className = 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 empty:hidden';
        footer.appendChild(modelPill);

        if (webOffForTurn) {
            const webOffNote = document.createElement('span');
            webOffNote.className = 'text-[11px] text-slate-500';
            webOffNote.textContent = 'Web search was off for this question.';
            footer.appendChild(webOffNote);
        }

        body.appendChild(webBanner);
        body.appendChild(answerP);
        body.appendChild(emergencyBox);
        body.appendChild(citationsBox);
        body.appendChild(footer);
        article.appendChild(header);
        article.appendChild(body);
        threadMessages?.appendChild(article);
        scrollThreadToBottom(true);

        return { article, header, heading, summaryP, checkedBadge, webBanner, answerP, emergencyBox, citationsBox, modelPill };
    };

    const renderCitationsList = (container, citations, { heading = 'Sources', isEmergency = false } = {}) => {
        container.textContent = '';
        if (!Array.isArray(citations) || citations.length === 0) {
            return;
        }
        const details = document.createElement('details');
        details.className = `rounded-xl border ${isEmergency ? 'border-red-200 bg-white' : 'border-slate-200 bg-white'}`;
        if (isEmergency) {
            details.open = true;
        }

        const summary = document.createElement('summary');
        summary.className = 'flex cursor-pointer list-none items-center justify-between gap-2 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50';
        summary.textContent = `${heading} (${citations.length})`;
        details.appendChild(summary);

        const list = document.createElement('ol');
        list.className = 'space-y-2.5 border-t border-slate-100 p-3';
        citations.forEach((cite, idx) => {
            const meta = citationMetaFor(cite);
            const li = document.createElement('li');
            li.className = 'flex items-start gap-3 rounded-lg bg-slate-50/70 p-3 text-sm';

            const num = document.createElement('span');
            num.className = `mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${meta.number}`;
            num.setAttribute('aria-hidden', 'true');
            num.textContent = String(idx + 1);

            const content = document.createElement('div');
            content.className = 'min-w-0 flex-1';

            const topRow = document.createElement('div');
            topRow.className = 'flex flex-wrap items-start justify-between gap-2';

            const title = document.createElement('p');
            title.className = 'font-semibold text-slate-950';
            title.textContent = cite.title ?? 'Source';

            const badge = document.createElement('span');
            badge.className = `chip ring-1 ring-inset ${meta.badge}`;
            badge.textContent = meta.label;

            topRow.appendChild(title);
            topRow.appendChild(badge);
            content.appendChild(topRow);

            if (cite.publisher) {
                const pub = document.createElement('p');
                pub.className = 'mt-0.5 text-xs font-medium text-slate-700';
                pub.textContent = cite.publisher;
                content.appendChild(pub);
            }

            if (meta.note) {
                const note = document.createElement('p');
                note.className = 'mt-1 text-xs font-medium text-amber-950';
                note.textContent = meta.note;
                content.appendChild(note);
            }

            if (cite.passage) {
                const quote = document.createElement('blockquote');
                quote.className = 'mt-2 whitespace-pre-wrap border-l-2 border-slate-300 pl-3 text-xs leading-relaxed text-slate-700';
                quote.textContent = cite.passage;
                content.appendChild(quote);
            }

            if (typeof cite.url === 'string' && cite.url.startsWith('https://')) {
                const linkWrap = document.createElement('p');
                linkWrap.className = 'mt-2 text-xs';
                const a = document.createElement('a');
                a.href = cite.url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.className = 'inline-flex items-center gap-1 font-semibold text-teal-800 underline decoration-teal-800/30 underline-offset-2 hover:text-teal-950';
                a.textContent = 'View original source';
                linkWrap.appendChild(a);
                content.appendChild(linkWrap);
            }

            li.appendChild(num);
            li.appendChild(content);
            list.appendChild(li);
        });
        details.appendChild(list);
        container.appendChild(details);
    };

    let isSubmitting = false;
    if (chatForm && submitStatus) {
        window.addEventListener('pageshow', () => {
            isSubmitting = false;
            chatForm.removeAttribute('aria-busy');
            submitStatus.textContent = '';
            submitStatus.hidden = true;
        });

        chatForm.addEventListener('submit', async (event) => {
            const streamUrl = chatForm.getAttribute('data-stream-url');
            if (!streamUrl || typeof window.fetch !== 'function' || !window.ReadableStream) {
                return;
            }
            event.preventDefault();
            if (isSubmitting || !messageInput) {
                return;
            }

            const promptText = messageInput.value.trim();
            if (!promptText) {
                return;
            }

            const webOffForTurn = Boolean(webSwitch && !webSwitch.checked);
            isSubmitting = true;
            chatForm.setAttribute('aria-busy', 'true');
            submitStatus.hidden = false;

            let locationPayload = null;
            if (LOCATION_PROMPT_REGEX.test(promptText)) {
                submitStatus.textContent = 'Requesting your browser location for this prompt...';
                locationPayload = await requestBrowserLocationOnce();
            }

            submitStatus.textContent = 'Streaming response from local AI model...';

            const formData = new FormData(chatForm);
            formData.set('web_off', webOffForTurn ? '1' : '0');
            if (locationPayload) {
                formData.set('latitude', String(locationPayload.latitude));
                formData.set('longitude', String(locationPayload.longitude));
                if (locationPayload.accuracy !== null) {
                    formData.set('accuracy', String(locationPayload.accuracy));
                }
            }

            appendUserBubble(promptText);
            messageInput.value = '';
            syncComposer();

            const card = createStreamingAssistantCard(webOffForTurn);

            try {
                const response = await fetch(streamUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/x-ndjson', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok || !response.body) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let streamedText = '';

                const handleEventLine = (rawLine) => {
                    const line = rawLine.trim();
                    if (!line) {
                        return;
                    }
                    let evt;
                    try {
                        evt = JSON.parse(line);
                    } catch {
                        return;
                    }

                    if (evt.type === 'conversation' && evt.id) {
                        if (conversationIdInput) {
                            conversationIdInput.value = evt.id;
                        }
                        if (evt.url && window.history?.replaceState) {
                            window.history.replaceState({}, '', evt.url);
                        }
                        upsertRecentChat({
                            id: evt.id,
                            title: evt.title || promptText,
                            url: evt.url,
                            deleteUrl: evt.delete_url,
                        });
                    } else if (evt.type === 'meta') {
                        const style = statusHeaderFor(evt.evidence_status);
                        card.article.className = `overflow-hidden rounded-2xl shadow-sm ${style.wrap}`;
                        card.header.className = `flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5 ${style.band}`;
                        card.heading.textContent = style.heading;
                        card.summaryP.className = `text-xs empty:hidden ${style.summaryClass}`;
                        card.summaryP.textContent = style.summary ?? '';
                        card.checkedBadge.className = `text-xs empty:hidden ${style.metaClass}`;
                        card.checkedBadge.textContent = evt.checked_at ? `Checked: ${evt.checked_at}` : '';
                        card.modelPill.textContent = evt.model ? evt.model : '';
                        card.webBanner.hidden = !evt.web_search_used;
                        card.emergencyBox.hidden = !style.isEmergency;
                        if (style.isEmergency) {
                            card.article.setAttribute('role', 'alert');
                            card.article.setAttribute('aria-live', 'assertive');
                        }
                        if (evt.language) {
                            card.answerP.setAttribute('lang', evt.language);
                        }
                        renderCitationsList(card.citationsBox, evt.citations, {
                            heading: style.citationHeading,
                            isEmergency: style.isEmergency,
                        });
                    } else if (evt.type === 'token' && typeof evt.text === 'string') {
                        streamedText += evt.text;
                        card.answerP.textContent = streamedText;
                        scrollThreadToBottom(false);
                    } else if (evt.type === 'done' && typeof evt.answer === 'string') {
                        card.answerP.textContent = evt.answer;
                        scrollThreadToBottom(false);
                    } else if (evt.type === 'error') {
                        card.checkedBadge.textContent = '';
                        card.answerP.textContent = evt.detail || 'SANDIG could not answer right now.';
                    }
                };

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) {
                        break;
                    }
                    buffer += decoder.decode(value, { stream: true });
                    let newlineIndex;
                    while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
                        handleEventLine(buffer.slice(0, newlineIndex));
                        buffer = buffer.slice(newlineIndex + 1);
                    }
                }
                buffer += decoder.decode();
                if (buffer.trim()) {
                    handleEventLine(buffer);
                }
            } catch {
                card.heading.textContent = 'Connection error';
                card.checkedBadge.textContent = '';
                card.answerP.textContent =
                    'Could not stream a response right now. Make sure FastAPI and local Ollama are running, then try again.';
                messageInput.value = promptText;
                syncComposer();
            } finally {
                isSubmitting = false;
                chatForm.removeAttribute('aria-busy');
                submitStatus.textContent = '';
                submitStatus.hidden = true;
                messageInput.focus();
            }
        });
    }

    /* ------------------------------------------------------------ Composer */
    if (messageInput) {
        syncComposer();
        messageInput.addEventListener('input', syncComposer);
        messageInput.addEventListener('keydown', (event) => {
            if (
                event.key !== 'Enter' ||
                event.shiftKey ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey ||
                event.isComposing ||
                prefersTouch
            ) {
                return;
            }
            event.preventDefault();
            if (messageInput.value.trim() === '' || !chatForm) {
                return;
            }
            chatForm.requestSubmit(document.getElementById('chat-send'));
        });
    }

    document.querySelectorAll('[data-starter-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!messageInput) {
                return;
            }
            const prompt = button.getAttribute('data-starter-prompt') ?? '';
            messageInput.value = prompt;
            syncComposer();
            messageInput.focus();
            messageInput.setSelectionRange(prompt.length, prompt.length);
        });
    });

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        const label = button.querySelector('[data-copy-label]');
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.getAttribute('data-copy-target') ?? '');
            if (!target) {
                return;
            }
            try {
                if (!navigator.clipboard) {
                    throw new Error('Clipboard unavailable');
                }
                await navigator.clipboard.writeText(target.textContent ?? '');
                if (label) {
                    label.textContent = 'Copied';
                }
            } catch {
                if (label) {
                    label.textContent = 'Copy failed. Select the text to copy it.';
                }
            }
            window.setTimeout(() => {
                if (label) {
                    label.textContent = 'Copy answer';
                }
            }, 2500);
        });
    });

    /* ------------------------------------------------- Thread scroll position */
    if (thread) {
        const latest = thread.querySelector('[data-scroll-into-view]');
        if (latest) {
            thread.scrollTop += latest.getBoundingClientRect().top - thread.getBoundingClientRect().top - 16;
        } else if (thread.querySelector('[role="alert"], #latest-answer')) {
            thread.scrollTop = thread.scrollHeight;
        }
    }

    /* ---------------------------------------------- Location panel (context) */
    const locationPanel = document.getElementById('location-panel');
    const locationToggle = document.getElementById('location-toggle');
    const setLocationPanel = (open) => {
        if (!locationPanel) {
            return;
        }
        locationPanel.hidden = !open;
        locationToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            locationPanel.querySelector('button:not([hidden])')?.focus();
        }
    };
    locationToggle?.addEventListener('click', () => setLocationPanel(locationPanel?.hidden ?? false));
    document.querySelectorAll('[data-close-location]').forEach((button) => {
        button.addEventListener('click', () => {
            setLocationPanel(false);
            locationToggle?.focus();
        });
    });
    document.querySelectorAll('[data-open-location]').forEach((button) => {
        button.addEventListener('click', () => setLocationPanel(true));
    });

    /* ------------------------------------------------------ Global shortcuts */
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (drawer && drawer.dataset.open === 'true' && !DESKTOP_QUERY.matches) {
                setDrawer(false);
                return;
            }
            if (locationPanel && !locationPanel.hidden) {
                setLocationPanel(false);
                locationToggle?.focus();
            }
            return;
        }
        if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isTypingTarget(document.activeElement)) {
            event.preventDefault();
            messageInput?.focus();
        }
    });

    /* ---------------------------------------------------- Device location/map */
    const locateBtn = document.getElementById('locate-device-btn');
    const clearDeviceBtn = document.getElementById('clear-device-location-btn');
    const locationStatus = document.getElementById('location-status');

    const loadMapBtn = document.getElementById('load-map-btn');
    const hideMapBtn = document.getElementById('hide-map-btn');
    const mapContainer = document.getElementById('sandig-map');
    const mapOptInPanel = document.getElementById('map-opt-in-panel');
    const mapActivePanel = document.getElementById('map-active-panel');
    const mapTileStatus = document.getElementById('map-tile-status');

    let mapInstance = null;
    let deviceMarker = null;
    let deviceAccuracyCircle = null;
    let lastDeviceReading = null;

    const clearMapTileStatus = () => {
        if (!mapTileStatus) {
            return;
        }
        mapTileStatus.textContent = '';
        mapTileStatus.hidden = true;
    };

    const showMapTileError = () => {
        if (!mapTileStatus) {
            return;
        }
        mapTileStatus.textContent =
            'Map tiles could not be loaded right now. You can still chat and mention your barangay or a landmark in your question.';
        mapTileStatus.hidden = false;
    };

    const renderDeviceOverlay = () => {
        if (!mapInstance) {
            return;
        }
        if (deviceMarker) {
            mapInstance.removeLayer(deviceMarker);
            deviceMarker = null;
        }
        if (deviceAccuracyCircle) {
            mapInstance.removeLayer(deviceAccuracyCircle);
            deviceAccuracyCircle = null;
        }
        if (!lastDeviceReading) {
            return;
        }
        const { latitude, longitude, accuracy } = lastDeviceReading;
        deviceAccuracyCircle = L.circle([latitude, longitude], {
            radius: Math.max(accuracy, 15),
            color: '#0f766e',
            fillColor: '#14b8a6',
            fillOpacity: 0.16,
            weight: 1.5,
        }).addTo(mapInstance);

        deviceMarker = L.circleMarker([latitude, longitude], {
            radius: 6,
            color: '#134e4a',
            fillColor: '#0f766e',
            fillOpacity: 0.95,
            weight: 2,
        }).addTo(mapInstance);
    };

    const initReferenceMap = () => {
        if (!mapContainer || mapInstance) {
            return;
        }
        clearMapTileStatus();
        if (mapOptInPanel) {
            mapOptInPanel.hidden = true;
        }
        if (mapActivePanel) {
            mapActivePanel.hidden = false;
        }
        mapInstance = L.map(mapContainer, {
            center: BAGUIO_CENTER,
            zoom: FIXED_CITY_ZOOM,
            minZoom: 12,
            maxZoom: FIXED_CITY_ZOOM,
            maxBounds: BAGUIO_BOUNDS,
            maxBoundsViscosity: 1.0,
            scrollWheelZoom: false,
        });

        const tileLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: FIXED_CITY_ZOOM,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
        });

        tileLayer.on('tileerror', showMapTileError);
        tileLayer.addTo(mapInstance);
        renderDeviceOverlay();
    };

    const destroyReferenceMap = () => {
        clearMapTileStatus();
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
            deviceMarker = null;
            deviceAccuracyCircle = null;
        }
        if (mapActivePanel) {
            mapActivePanel.hidden = true;
        }
        if (mapOptInPanel) {
            mapOptInPanel.hidden = false;
        }
    };

    loadMapBtn?.addEventListener('click', initReferenceMap);
    hideMapBtn?.addEventListener('click', destroyReferenceMap);

    const clearDeviceLocation = () => {
        lastDeviceReading = null;
        renderDeviceOverlay();
        if (clearDeviceBtn) {
            clearDeviceBtn.hidden = true;
        }
        if (locationStatus) {
            locationStatus.textContent = 'Local device reading cleared.';
        }
    };

    clearDeviceBtn?.addEventListener('click', clearDeviceLocation);

    if (locateBtn && locationStatus) {
        locateBtn.addEventListener('click', () => {
            if (!('geolocation' in navigator)) {
                locationStatus.textContent =
                    'Device location is unavailable in this browser. You can still mention your barangay or a landmark in your question.';
                return;
            }
            locateBtn.disabled = true;
            locationStatus.textContent = 'Checking device location locally...';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    locateBtn.disabled = false;
                    const { latitude, longitude, accuracy } = position.coords;
                    const roundedAccuracy = Number.isFinite(accuracy) ? Math.round(accuracy) : null;
                    lastDeviceReading = { latitude, longitude, accuracy: roundedAccuracy ?? 0 };
                    const outsideExtent =
                        latitude < BAGUIO_BOUNDS[0][0] ||
                        latitude > BAGUIO_BOUNDS[1][0] ||
                        longitude < BAGUIO_BOUNDS[0][1] ||
                        longitude > BAGUIO_BOUNDS[1][1];
                    const parts = [];
                    if (roundedAccuracy === null) {
                        parts.push('Device location checked locally (accuracy unavailable).');
                    } else if (roundedAccuracy > LOW_ACCURACY_THRESHOLD_METERS) {
                        parts.push(`Low location accuracy (±${roundedAccuracy.toLocaleString()} m).`);
                    } else {
                        parts.push(`Device location checked locally (accuracy: ±${roundedAccuracy.toLocaleString()} m).`);
                    }
                    if (outsideExtent) {
                        parts.push('Reading is outside the fixed map view.');
                    }
                    locationStatus.textContent = parts.join(' ');
                    if (clearDeviceBtn) {
                        clearDeviceBtn.hidden = false;
                    }
                    renderDeviceOverlay();
                },
                (error) => {
                    locateBtn.disabled = false;
                    if (error.code === error.PERMISSION_DENIED) {
                        locationStatus.textContent = 'Location permission was denied. No coordinates were read.';
                    } else if (error.code === error.TIMEOUT) {
                        locationStatus.textContent = 'Device location request timed out.';
                    } else {
                        locationStatus.textContent = 'Device location is currently unavailable.';
                    }
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    }
});
