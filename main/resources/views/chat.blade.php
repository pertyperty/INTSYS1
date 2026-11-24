@php
    use Illuminate\Support\Carbon;

    $messages = $conversation['messages'] ?? [];
    $lastAssistantIndex = null;
    foreach ($messages as $i => $m) {
        if ($m['role'] === 'assistant') {
            $lastAssistantIndex = $i;
        }
    }
    $isEmpty = $messages === [] && ! isset($unavailable) && ! isset($inputError);
    $webOff = $settings['web_off'];

    $starterPrompts = [
        ['prompt' => 'What should I do before, during, and after an earthquake?', 'label' => 'Earthquake safety', 'description' => 'Drop, cover, and hold on, plus home checks', 'icon' => 'activity', 'tone' => 'teal'],
        ['prompt' => 'How should I prepare for a landslide?', 'label' => 'Landslide prep', 'description' => 'Warning signs and evacuation planning', 'icon' => 'mountain', 'tone' => 'teal'],
        ['prompt' => 'How do I prepare my household for a typhoon?', 'label' => 'Typhoon readiness', 'description' => 'Before heavy rain and strong winds', 'icon' => 'cloud-rain', 'tone' => 'teal'],
        ['prompt' => 'What should I pack in a go-bag?', 'label' => 'Emergency go-bag', 'description' => 'Essentials for your household kit', 'icon' => 'backpack', 'tone' => 'teal'],
        ['prompt' => 'Ano ang laman ng go-bag?', 'label' => 'Ano ang laman ng go-bag?', 'description' => 'Ask in Filipino or English; replies follow your language', 'icon' => 'languages', 'tone' => 'teal'],
        ['prompt' => 'Recent USGS earthquake reports', 'label' => 'USGS reports (supplementary)', 'description' => 'Optional non-Philippine feed; confirm with PHIVOLCS', 'icon' => 'radio', 'tone' => 'sky', 'title' => 'Fills an optional supplementary USGS earthquake query without sending; not an official Philippine agency feed.'],
    ];

    $statusMetaFor = fn (string $status): array => match ($status) {
        'emergency' => ['heading' => 'Emergency', 'summary' => 'Contact emergency services now. SANDIG cannot dispatch responders.', 'icon' => 'alert-triangle', 'wrap' => 'border-2 border-red-600 bg-red-50 ring-4 ring-red-600/10', 'band' => 'bg-red-600 text-white', 'iconClass' => 'text-white', 'summaryClass' => 'text-red-50', 'metaClass' => 'text-red-100', 'isEmergency' => true, 'citationHeading' => 'References'],
        'supported' => ['heading' => 'Supported by approved sources', 'summary' => 'Grounded in the approved sources listed below.', 'icon' => 'check-circle', 'wrap' => 'border border-slate-200 bg-white', 'band' => 'border-b border-teal-100 bg-teal-50/70 text-teal-950', 'iconClass' => 'text-teal-700', 'summaryClass' => 'text-slate-600', 'metaClass' => 'text-slate-500', 'isEmergency' => false, 'citationHeading' => 'Cited sources'],
        'partially_supported' => ['heading' => 'Partially supported', 'summary' => 'Some points could not be confirmed by approved sources. Treat them with caution.', 'icon' => 'alert-circle', 'wrap' => 'border border-slate-200 bg-white', 'band' => 'border-b border-amber-100 bg-amber-50/80 text-amber-950', 'iconClass' => 'text-amber-700', 'summaryClass' => 'text-slate-600', 'metaClass' => 'text-slate-500', 'isEmergency' => false, 'citationHeading' => 'Cited sources'],
        'out_of_scope' => ['heading' => 'Out of scope', 'summary' => 'SANDIG answers disaster preparedness questions only.', 'icon' => 'ban', 'wrap' => 'border border-slate-200 bg-white', 'band' => 'border-b border-slate-100 bg-slate-50 text-slate-900', 'iconClass' => 'text-slate-600', 'summaryClass' => 'text-slate-600', 'metaClass' => 'text-slate-500', 'isEmergency' => false, 'citationHeading' => 'Related links (not evidence for this answer)'],
        'not_applicable' => ['heading' => 'SANDIG', 'summary' => null, 'icon' => 'shield', 'wrap' => 'border border-slate-200 bg-white', 'band' => 'bg-white text-teal-950', 'iconClass' => 'text-teal-700', 'summaryClass' => 'text-slate-600', 'metaClass' => 'text-slate-500', 'isEmergency' => false, 'citationHeading' => 'Sources'],
        default => ['heading' => 'Insufficient evidence', 'summary' => 'Approved sources did not contain enough information to answer reliably.', 'icon' => 'help-circle', 'wrap' => 'border border-slate-200 bg-white', 'band' => 'border-b border-slate-100 bg-slate-50 text-slate-900', 'iconClass' => 'text-slate-600', 'summaryClass' => 'text-slate-600', 'metaClass' => 'text-slate-500', 'isEmergency' => false, 'citationHeading' => 'Related links (not evidence for this answer)'],
    };

    $citationMetaFor = fn (array $citation): array => match (true) {
        $citation['is_curated_emergency_reference'] => ['label' => 'Curated emergency reference', 'icon' => 'alert-triangle', 'badge' => 'bg-red-100 text-red-900 ring-red-200', 'number' => 'bg-red-600 text-white', 'note' => null],
        $citation['kind'] === 'curated_guidance' => ['label' => 'Curated general guidance', 'icon' => 'shield', 'badge' => 'bg-violet-50 text-violet-900 ring-violet-200', 'number' => 'bg-violet-700 text-white', 'note' => 'Bundled, source-reviewed general preparedness guidance; not specific to current conditions.'],
        $citation['kind'] === 'official_live' => ['label' => 'Official live feed', 'icon' => 'radio', 'badge' => 'bg-blue-100 text-blue-900 ring-blue-200', 'number' => 'bg-blue-700 text-white', 'note' => null],
        $citation['kind'] === 'supplementary_live' => ['label' => 'Supplementary live feed', 'icon' => 'radio', 'badge' => 'bg-sky-100 text-sky-900 ring-sky-200', 'number' => 'bg-sky-700 text-white', 'note' => 'Supplementary feed; confirm against official agency advisories.'],
        $citation['kind'] === 'web_search' => ['label' => 'External web search', 'icon' => 'globe', 'badge' => 'bg-amber-100 text-amber-950 ring-amber-300', 'number' => 'bg-amber-600 text-white', 'note' => 'External links/search results, not verified guidance.'],
        default => ['label' => 'Indexed document', 'icon' => 'file', 'badge' => 'bg-teal-50 text-teal-900 ring-teal-200', 'number' => 'bg-teal-700 text-white', 'note' => null],
    };
@endphp
<!doctype html>
<html lang="en" class="overflow-clip bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#134e4a">
    <meta name="description" content="SANDIG is an academic prototype assistant for evidence-grounded disaster preparedness information in Baguio City.">
    <title>{{ $conversation !== null ? $conversation['title'].' | ' : '' }}SANDIG | Preparedness Chat</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-dvh overflow-clip bg-slate-50 text-slate-900 antialiased">
<a href="#message" class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-semibold text-teal-900 shadow-lg ring-2 ring-teal-700 focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60]">
    Skip to message box
</a>

<div class="flex h-full">
    <div id="drawer-backdrop" hidden data-drawer-close class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-[1px] lg:hidden"></div>

    {{-- Drawer: chats + settings. Persistent sidebar on large screens, off-canvas on small screens. --}}
    <aside id="chat-drawer" aria-label="Chats and settings" data-open="false"
           class="fixed inset-y-0 left-0 z-50 flex w-80 max-w-[85vw] -translate-x-full flex-col overflow-y-auto overscroll-y-contain border-r border-slate-200 bg-white shadow-xl transition-transform duration-200 data-[open=true]:translate-x-0 motion-reduce:transition-none lg:static lg:z-auto lg:w-72 lg:translate-x-0 lg:shadow-none">
        <div class="flex items-center justify-between gap-2 px-4 pb-2 pt-4">
            <a href="{{ route('chat.index') }}" class="flex items-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-teal-600 to-teal-900 text-white shadow-sm">
                    <x-icon name="shield" class="h-5 w-5" />
                </span>
                <span class="leading-tight">
                    <span class="block text-lg font-bold tracking-tight text-slate-950">SANDIG</span>
                    <span class="block text-[11px] text-slate-500">Baguio City preparedness</span>
                </span>
            </a>
            <button type="button" data-drawer-close class="btn btn-secondary h-9 w-9 p-0 lg:hidden" aria-label="Close menu">
                <x-icon name="x" class="h-4 w-4" />
            </button>
        </div>

        <div class="px-3 pb-3 pt-2">
            <a href="{{ route('chat.index') }}" class="btn btn-primary w-full py-2.5 text-sm">
                <x-icon name="plus" class="h-4 w-4" />
                New chat
            </a>
        </div>

        <nav id="recent-chats-nav" aria-labelledby="history-heading" class="min-h-24 flex-1 overflow-y-auto px-3">
            <h2 id="history-heading" class="px-2 pb-1.5 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Recent chats</h2>
            @if ($conversations === [])
                <p id="recent-chats-empty" class="px-2 py-2 text-xs leading-relaxed text-slate-500">No chats yet. Your chats appear here for this browser session only.</p>
            @else
                <ul id="recent-chats-list" class="space-y-0.5">
                    @foreach ($conversations as $item)
                        @php $isActive = $conversation !== null && $conversation['id'] === $item['id']; @endphp
                        <li data-conversation-id="{{ $item['id'] }}" class="group/item relative flex items-center rounded-lg {{ $isActive ? 'bg-teal-50 ring-1 ring-inset ring-teal-200' : 'hover:bg-slate-100' }}">
                            <a href="{{ route('chat.show', ['conversation' => $item['id']]) }}" @if ($isActive) aria-current="page" @endif
                               class="min-w-0 flex-1 rounded-lg px-2.5 py-2 pr-9 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700">
                                <span data-chat-title class="block truncate text-sm {{ $isActive ? 'font-semibold text-teal-950' : 'font-medium text-slate-800' }}">{{ $item['title'] }}</span>
                                <span data-chat-timestamp class="block text-[11px] text-slate-500">{{ Carbon::createFromTimestamp($item['updated_at'])->diffForHumans() }}</span>
                            </a>
                            <form method="post" action="{{ route('chat.destroy', ['conversation' => $item['id']]) }}" data-confirm="Delete this chat? This cannot be undone." class="absolute right-1.5">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 sm:opacity-0 sm:group-hover/item:opacity-100 sm:focus-visible:opacity-100 {{ $isActive ? 'sm:opacity-100' : '' }}"
                                        aria-label="Delete chat: {{ $item['title'] }}">
                                    <x-icon name="x" class="h-3.5 w-3.5" />
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>

        <div class="space-y-1 border-t border-slate-200 px-3 py-3">
            <a href="{{ route('sources.index') }}" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700">
                <x-icon name="book" class="h-4 w-4 text-slate-500" />
                Approved sources
            </a>

            <details id="settings-panel" class="group/settings rounded-lg">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700 [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center gap-2"><x-icon name="info" class="h-4 w-4 text-slate-500" /> Model &amp; settings</span>
                    <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open/settings:rotate-180" />
                </summary>
                <div class="space-y-4 px-2.5 pb-2 pt-2 text-xs text-slate-600">
                    <form id="settings-form" method="post" action="{{ route('chat.settings') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" id="settings-return-to" name="return_to" value="{{ $conversation['id'] ?? '' }}">
                        <input type="hidden" id="settings-web-off" name="web_off" value="{{ $webOff ? '1' : '0' }}">

                        <div>
                            <label for="setting-model" class="block font-semibold text-slate-800">Local AI model</label>
                            <select id="setting-model" name="model" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-900 focus:border-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-700/20">
                                @foreach ($availableModels as $m)
                                    <option value="{{ $m['name'] }}" @selected($activeModel === $m['name'])>
                                        {{ $m['name'] }}@if ($m['size'] > 0) ({{ number_format($m['size'] / 1073741824, 1) }} GB)@endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <fieldset>
                            <legend class="font-semibold text-slate-800">Answer length</legend>
                            <div class="mt-1.5 grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1">
                                @foreach (['brief' => 'Brief', 'standard' => 'Standard', 'detailed' => 'Detailed'] as $value => $label)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="response_detail" value="{{ $value }}" class="peer sr-only" @checked($settings['detail'] === $value)>
                                        <span class="block rounded-md px-2 py-1.5 text-center font-medium text-slate-600 peer-checked:bg-white peer-checked:text-teal-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-teal-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <div class="flex items-center justify-between">
                                    <label for="setting-temperature" class="font-semibold text-slate-800">Temperature</label>
                                    <span id="temperature-value" class="font-mono text-[11px] text-slate-700">{{ number_format($settings['temperature'], 2) }}</span>
                                </div>
                                <input id="setting-temperature" name="temperature" type="range" min="0" max="1.5" step="0.05" value="{{ $settings['temperature'] }}" class="mt-1 w-full accent-teal-700">
                            </div>
                            <div>
                                <div class="flex items-center justify-between">
                                    <label for="setting-top-p" class="font-semibold text-slate-800">Top-P</label>
                                    <span id="top-p-value" class="font-mono text-[11px] text-slate-700">{{ number_format($settings['top_p'], 2) }}</span>
                                </div>
                                <input id="setting-top-p" name="top_p" type="range" min="0.1" max="1.0" step="0.05" value="{{ $settings['top_p'] }}" class="mt-1 w-full accent-teal-700">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5">
                            <div>
                                <label for="setting-max-tokens" class="block font-semibold text-slate-800">Max tokens</label>
                                <input id="setting-max-tokens" name="max_tokens" type="number" min="64" max="2048" step="32" value="{{ $settings['max_tokens'] }}" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-900">
                            </div>
                            <div>
                                <label for="setting-num-ctx" class="block font-semibold text-slate-800">Context</label>
                                <input id="setting-num-ctx" name="num_ctx" type="number" min="1024" max="16384" step="512" value="{{ $settings['num_ctx'] }}" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-900">
                            </div>
                        </div>

                        <div>
                            <label for="setting-system-prompt" class="block font-semibold text-slate-800">System prompt (optional)</label>
                            <textarea id="setting-system-prompt" name="system_prompt" rows="3" maxlength="2000" placeholder="Leave blank for default SANDIG instructions..." class="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-900 focus:border-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-700/20">{{ $settings['system_prompt'] }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-secondary w-full">Save model settings</button>
                    </form>

                    <div>
                        <p class="font-semibold text-slate-800">Keyboard shortcuts</p>
                        <dl class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                            <dt><kbd class="kbd">Enter</kbd></dt><dd>Send message</dd>
                            <dt><kbd class="kbd">Shift</kbd>+<kbd class="kbd">Enter</kbd></dt><dd>New line</dd>
                            <dt><kbd class="kbd">/</kbd></dt><dd>Focus message box</dd>
                            <dt><kbd class="kbd">Esc</kbd></dt><dd>Close menu or panel</dd>
                        </dl>
                    </div>

                    <div>
                        <p class="flex items-center gap-1.5 font-semibold text-slate-800"><x-icon name="lock" class="h-3.5 w-3.5" /> Memory &amp; privacy</p>
                        <p class="mt-1 leading-relaxed">
                            Chats stay in your browser session (up to {{ $limits['conversations'] }} chats, {{ $limits['messages'] }} messages each) and are sent to your local AI model as conversation memory within the active chat. Browser location is requested only when a prompt needs it.
                        </p>
                    </div>

                    @if ($conversations !== [])
                        <form method="post" action="{{ route('chat.destroyAll') }}" data-confirm="Delete all chats in this session? This cannot be undone.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn w-full border border-red-200 bg-white text-red-700 hover:bg-red-50 focus-visible:ring-red-600">Delete all chats</button>
                        </form>
                    @endif
                </div>
            </details>
        </div>

        <div class="border-t border-slate-200 p-3">
            <a href="tel:911" class="flex items-center gap-3 rounded-xl bg-red-50 px-3 py-2.5 text-red-900 ring-1 ring-inset ring-red-200 hover:bg-red-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-red-600 text-white"><x-icon name="phone" class="h-4 w-4" /></span>
                <span class="leading-tight">
                    <span class="block text-sm font-bold">Emergency? Call 911</span>
                    <span class="block text-[11px] text-red-800">SANDIG cannot dispatch responders.</span>
                </span>
            </a>
            <p class="mt-2.5 px-1 text-[11px] leading-relaxed text-slate-500">Baguio City academic prototype. Not an official government service or agency endorsement.</p>
        </div>
    </aside>

    <div id="chat-workspace" class="flex min-w-0 flex-1 flex-col">
        <header class="flex shrink-0 items-center gap-2 border-b border-slate-200 bg-white/90 px-3 py-2.5 backdrop-blur sm:px-4">
            <button type="button" id="drawer-toggle" aria-controls="chat-drawer" aria-expanded="false" class="btn btn-secondary h-9 w-9 p-0 lg:hidden" aria-label="Open chats and settings menu">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 id="chat-header-title" class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900 sm:text-base">
                {{ $conversation['title'] ?? 'New chat' }}
            </h1>
            <span class="hidden items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-mono text-[11px] font-medium text-slate-700 md:inline-flex" title="Active local Ollama model">
                {{ $activeModel }}
            </span>
            <a href="{{ route('chat.index') }}" class="btn btn-secondary hidden h-9 sm:inline-flex lg:hidden">
                <x-icon name="plus" class="h-4 w-4" /> New chat
            </a>
            <a href="tel:911" class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-red-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2">
                <x-icon name="phone" class="h-3.5 w-3.5" />
                <span><span class="sm:hidden">911</span><span class="hidden sm:inline">Emergency? Call 911</span></span>
            </a>
        </header>

        <main id="thread" tabindex="-1" class="relative min-h-0 flex-1 overflow-y-auto overscroll-y-contain focus:outline-none">
            <div id="thread-messages" class="mx-auto max-w-3xl space-y-6 px-4 py-6 sm:py-8">
                @if (! empty($statusMessage))
                    <p role="status" class="flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-3.5 py-2.5 text-sm font-medium text-teal-900">
                        <x-icon name="check" class="h-4 w-4" /> {{ $statusMessage }}
                    </p>
                @endif

                @if ($isEmpty)
                    <section aria-labelledby="hero-heading" class="pt-2 sm:pt-8">
                        <p class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800 ring-1 ring-inset ring-teal-200">
                            <x-icon name="map-pin" class="h-3.5 w-3.5" />
                            Baguio City &middot; Earthquake, landslide &amp; typhoon readiness
                        </p>
                        <h2 id="hero-heading" class="mt-3 text-2xl font-bold tracking-tight text-slate-950 sm:text-4xl">Prepare before disaster strikes.</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600 sm:text-base">
                            Ask in English or Filipino. Mention your barangay or a landmark for local context. Every answer is labeled by how well approved sources support it.
                        </p>
                    </section>

                    <section aria-label="Example preparedness questions">
                        <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Try asking</h2>
                        <div class="mt-2.5 grid gap-2.5 sm:grid-cols-2">
                            @foreach ($starterPrompts as $starter)
                                <button type="button" data-starter-prompt="{{ $starter['prompt'] }}" @isset($starter['title']) title="{{ $starter['title'] }}" @endisset
                                    class="flex items-start gap-3 rounded-xl border bg-white p-3.5 text-left shadow-2xs transition hover:-translate-y-px hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700 motion-reduce:transform-none {{ $starter['tone'] === 'sky' ? 'border-sky-200 hover:border-sky-400' : 'border-slate-200 hover:border-teal-600' }}">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $starter['tone'] === 'sky' ? 'bg-sky-50 text-sky-800' : 'bg-teal-50 text-teal-800' }}">
                                        <x-icon :name="$starter['icon']" class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-slate-900">{{ $starter['label'] }}</span>
                                        <span class="mt-0.5 block text-xs leading-snug text-slate-500">{{ $starter['description'] }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </section>

                    <section aria-labelledby="portals-heading" class="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-600">
                        <h2 id="portals-heading" class="font-semibold text-slate-800">Official agency portals (external)</h2>
                        <p class="mt-0.5">Check official bulletins directly; SANDIG does not verify current conditions.</p>
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
                            <a href="https://earthquake.phivolcs.dost.gov.ph/" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-teal-800 hover:underline">PHIVOLCS Earthquake Bulletins <x-icon name="external" class="h-3 w-3" /></a>
                            <a href="https://www.pagasa.dost.gov.ph/" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-teal-800 hover:underline">PAGASA Weather Advisories <x-icon name="external" class="h-3 w-3" /></a>
                        </p>
                    </section>
                @endif

                @foreach ($messages as $index => $message)
                    @if ($message['role'] === 'user')
                        <div class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-md bg-teal-700 px-4 py-2.5 text-[15px] leading-relaxed text-white shadow-sm">
                                <span class="sr-only">You asked:</span>
                                <p class="whitespace-pre-wrap break-words">{{ $message['content'] }}</p>
                            </div>
                        </div>
                    @else
                        @php
                            $meta = $statusMetaFor($message['evidence_status']);
                            $isLatest = $index === $lastAssistantIndex;
                            $announce = $isLatest && $highlightLatest && $meta['isEmergency'];
                            $citations = $message['citations'];
                        @endphp
                        <article
                            id="{{ $isLatest ? 'latest-answer' : 'answer-'.$index }}"
                            @if ($announce) role="alert" aria-live="assertive" @endif
                            @if ($isLatest && $highlightLatest) data-scroll-into-view @endif
                            aria-labelledby="answer-heading-{{ $index }}"
                            class="overflow-hidden rounded-2xl shadow-sm {{ $meta['wrap'] }}"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5 {{ $meta['band'] }}">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-icon :name="$meta['icon']" class="h-5 w-5 {{ $meta['iconClass'] }}" />
                                    <div class="min-w-0">
                                        <h2 id="answer-heading-{{ $index }}" class="text-sm font-bold leading-tight sm:text-base">{{ $meta['heading'] }}</h2>
                                        @if ($meta['summary'])
                                            <p class="text-xs {{ $meta['summaryClass'] }}">{{ $meta['summary'] }}</p>
                                        @endif
                                    </div>
                                </div>
                                @if (! empty($message['checked_at']))
                                    <span class="text-xs {{ $meta['metaClass'] }}">Checked: {{ $message['checked_at'] }}</span>
                                @endif
                            </div>

                            <div class="p-4 sm:p-5">
                                @if ($message['web_search_used'])
                                    <p role="status" class="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-slate-800">
                                        <x-icon name="globe" class="mt-px h-3.5 w-3.5 text-amber-700" />
                                        <span><strong>External web search contacted for this request:</strong> Any web search results below are external links/search results, not verified guidance.</span>
                                    </p>
                                @endif

                                <p id="answer-text-{{ $index }}" lang="{{ $message['language'] ?? 'en' }}" class="whitespace-pre-wrap break-words text-[15px] leading-relaxed text-slate-900 sm:text-base">{{ $message['content'] }}</p>

                                @if ($meta['isEmergency'])
                                    <div class="mt-4 flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-red-200 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-sm font-medium text-red-950">In the Philippines and in immediate danger? Call the national emergency hotline.</p>
                                        <a href="tel:911" class="btn btn-danger px-4 py-2.5 text-sm"><x-icon name="phone" class="h-4 w-4" /> Call 911</a>
                                    </div>
                                @endif

                                @if ($message['response_type'] === 'hazard_assessment')
                                    <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-700">
                                        <x-icon name="map-pin" class="mt-px h-3.5 w-3.5 text-slate-500" />
                                        <p>
                                            SANDIG has no approved barangay boundary data, so it cannot tell which barangay you are in from GPS or the map. For local context, name your barangay or a landmark in your question.
                                            <button type="button" data-open-location class="font-semibold text-teal-800 underline underline-offset-2 hover:text-teal-950">Open location tools</button>
                                        </p>
                                    </div>
                                @endif

                                @if ($citations !== [])
                                    <details class="group/sources mt-4 rounded-xl border {{ $meta['isEmergency'] ? 'border-red-200 bg-white' : 'border-slate-200' }}" @if ($meta['isEmergency']) open @endif>
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700 [&::-webkit-details-marker]:hidden">
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="flex -space-x-1" aria-hidden="true">
                                                    @foreach (array_slice($citations, 0, 4) as $cIndex => $citation)
                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold ring-2 ring-white {{ $citationMetaFor($citation)['number'] }}">{{ $cIndex + 1 }}</span>
                                                    @endforeach
                                                </span>
                                                <span class="truncate">{{ $meta['citationHeading'] }} ({{ count($citations) }})</span>
                                            </span>
                                            <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition group-open/sources:rotate-180" />
                                        </summary>
                                        <ol class="space-y-2.5 border-t border-slate-100 p-3">
                                            @foreach ($citations as $cIndex => $citation)
                                                @php $cMeta = $citationMetaFor($citation); @endphp
                                                <li class="flex items-start gap-3 rounded-lg bg-slate-50/70 p-3 text-sm">
                                                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $cMeta['number'] }}" aria-hidden="true">{{ $cIndex + 1 }}</span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                                            <p class="font-semibold text-slate-950">{{ $citation['title'] }}</p>
                                                            <span class="chip ring-1 ring-inset {{ $cMeta['badge'] }}"><x-icon :name="$cMeta['icon']" class="h-3 w-3" /> {{ $cMeta['label'] }}</span>
                                                        </div>
                                                        <p class="mt-0.5 text-xs font-medium text-slate-700">{{ $citation['publisher'] }}</p>
                                                        @if ($cMeta['note'] !== null)
                                                            <p class="mt-1 text-xs font-medium text-amber-950">{{ $cMeta['note'] }}</p>
                                                        @endif
                                                        @if ($citation['passage'] !== null)
                                                            <blockquote class="mt-2 whitespace-pre-wrap border-l-2 border-slate-300 pl-3 text-xs leading-relaxed text-slate-700">{{ $citation['passage'] }}</blockquote>
                                                        @endif
                                                        <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                                                            @foreach (['source_id' => 'Source ID', 'version' => 'Version', 'section' => 'Section', 'page' => 'Page', 'publication_date' => 'Published', 'observed_at' => 'Observed'] as $field => $label)
                                                                @if ($citation[$field] !== null)
                                                                    <div>
                                                                        <dt class="inline font-semibold">{{ $label }}:</dt>
                                                                        <dd class="inline {{ $field === 'source_id' ? 'font-mono' : '' }}">{{ $citation[$field] }}</dd>
                                                                    </div>
                                                                @endif
                                                            @endforeach
                                                        </dl>
                                                        @if ($citation['url'] !== null)
                                                            <p class="mt-2 text-xs">
                                                                <a href="{{ $citation['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-teal-800 underline decoration-teal-800/30 underline-offset-2 hover:text-teal-950">
                                                                    View original source <x-icon name="external" class="h-3 w-3" />
                                                                </a>
                                                            </p>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                    </details>
                                @endif

                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <button type="button" data-copy-target="answer-text-{{ $index }}" class="btn px-2 py-1 font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800">
                                        <x-icon name="copy" class="h-3.5 w-3.5" />
                                        <span data-copy-label aria-live="polite">Copy answer</span>
                                    </button>
                                    @if (! empty($message['model']))
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">{{ $message['model'] }}</span>
                                    @endif
                                    @if (($message['web_mode'] ?? 'auto') === 'off')
                                        <span class="text-[11px] text-slate-500">Web search was off for this question.</span>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endif
                @endforeach

                @if (isset($unavailable))
                    <div role="alert" class="flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-950 shadow-xs">
                        <x-icon name="alert-triangle" class="mt-0.5 h-5 w-5 text-red-700" />
                        <div>
                            <p class="font-semibold">Service temporarily unavailable</p>
                            <p class="mt-1">SANDIG cannot answer right now. Please try again later. If you are in immediate danger, contact local emergency services.</p>
                            <p class="mt-2 text-xs text-red-900">Your question has been kept in the message box so you can resend it. It was not saved to your chat history.</p>
                        </div>
                    </div>
                @elseif (isset($inputError))
                    <div role="alert" class="flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-950 shadow-xs">
                        <x-icon name="alert-circle" class="mt-0.5 h-5 w-5 text-red-700" />
                        <div>
                            <p class="font-semibold">Unable to submit question</p>
                            <p class="mt-1">{{ $inputError }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </main>

        <div class="max-h-[65%] shrink-0 overflow-y-auto overscroll-y-contain border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
            <form id="chat-form" action="{{ route('chat.submit') }}" data-stream-url="{{ route('chat.stream') }}" method="post" class="group mx-auto max-w-3xl px-3 pb-2 pt-3 sm:px-4">
                @csrf
                <input type="hidden" id="conversation-id-input" name="conversation_id" value="{{ $conversation['id'] ?? '' }}">

                {{-- Context-only location tools: hidden until requested. Nothing here is submitted. --}}
                <section id="location-panel" hidden aria-labelledby="location-heading" class="mb-3 max-h-[55vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 shadow-lg">
                    <div class="flex items-start justify-between gap-3">
                        <h2 id="location-heading" class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <x-icon name="map" class="h-4 w-4 text-teal-700" /> Location tools
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">On-device only</span>
                        </h2>
                        <button type="button" data-close-location class="btn btn-secondary h-8 w-8 p-0" aria-label="Close location tools"><x-icon name="x" class="h-4 w-4" /></button>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-slate-700">
                        Checking your device position here shows coordinates only in this browser. When you ask a location-dependent question (such as weather near you), your browser requests permission to send coordinates for that single request only — coordinates are never saved in your chat history. Without approved barangay boundaries, SANDIG does <strong>not</strong> infer your barangay from GPS or the map. You can type your barangay or a nearby landmark in your question.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" id="locate-device-btn" class="btn border border-teal-700 bg-white py-1.5 text-teal-800 hover:bg-teal-50">
                            <x-icon name="navigation" class="h-3.5 w-3.5" /> Check my device location (local only)
                        </button>
                        <button type="button" id="clear-device-location-btn" hidden class="btn btn-secondary py-1.5 font-medium">Clear device location</button>
                    </div>
                    <p id="location-status" role="status" aria-live="polite" class="mt-2 text-xs text-slate-700 empty:hidden"></p>

                    <div id="map-opt-in-panel" class="mt-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
                        <p class="text-xs leading-relaxed text-slate-700">
                            <strong>Third-party map tiles opt-in:</strong> Loading the reference map requests tiles for a fixed Baguio City extent from OpenStreetMap (<code>tile.openstreetmap.org</code>), which receives your IP address. The map will not pan or zoom to precise device coordinates and shows no hazard layers or barangay boundaries.
                        </p>
                        <button type="button" id="load-map-btn" class="btn mt-2.5 bg-slate-800 py-1.5 text-white hover:bg-slate-900 focus-visible:ring-slate-700">
                            Load Baguio City reference map (OpenStreetMap)
                        </button>
                    </div>
                    <div id="map-active-panel" hidden class="mt-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-xs text-slate-600">Fixed Baguio City extent &middot; &copy; OpenStreetMap contributors</p>
                            <button type="button" id="hide-map-btn" class="btn btn-secondary px-2.5 py-1 font-medium">Hide map</button>
                        </div>
                        <p id="map-tile-status" role="status" aria-live="polite" hidden class="mb-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-950"></p>
                        <div id="sandig-map" role="region" aria-label="Baguio City reference map" class="h-56 w-full rounded-xl border border-slate-300"></div>
                    </div>
                </section>

                <div class="rounded-2xl border border-slate-300 bg-white shadow-sm transition focus-within:border-teal-600 focus-within:ring-4 focus-within:ring-teal-600/10">
                    <label for="message" class="sr-only">{{ $messages === [] ? 'Ask a preparedness question' : 'Ask a follow-up preparedness question' }}</label>
                    <textarea
                        id="message"
                        name="message"
                        required
                        maxlength="4000"
                        rows="1"
                        aria-describedby="composer-hint"
                        class="block max-h-[min(15rem,30dvh)] min-h-[3rem] w-full resize-none border-0 bg-transparent px-4 pb-1 pt-3 text-[15px] leading-relaxed text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 sm:text-base"
                        placeholder="{{ $messages === [] ? 'Ask about earthquake, landslide, or typhoon preparedness...' : 'Ask a follow-up question...' }}"
                    >{{ $retryMessage ?? '' }}</textarea>

                    <div class="flex flex-wrap items-center gap-1.5 px-2 pb-2">
                        <button type="button" id="location-toggle" aria-controls="location-panel" aria-expanded="false" class="tool-btn">
                            <x-icon name="map-pin" class="h-3.5 w-3.5" /> <span>Location</span>
                        </button>

                        {{-- Web search switch: ON by default for every chat. --}}
                        <input type="hidden" name="web_off" value="1">
                        <label class="tool-btn cursor-pointer select-none" title="Web search is on by default. Toggle off to disable external web search.">
                            <input type="checkbox" id="web-search-switch" name="web_off" value="0" role="switch" class="peer sr-only" @checked(! $webOff)>
                            <span aria-hidden="true" class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full bg-slate-300 transition peer-checked:bg-teal-600 peer-focus-visible:ring-2 peer-focus-visible:ring-teal-700 peer-focus-visible:ring-offset-1 after:absolute after:left-0.5 after:h-3 after:w-3 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-3"></span>
                            <span>Web search</span>
                            <span id="web-search-state" class="font-semibold">{{ $webOff ? 'Off' : 'On' }}</span>
                        </label>

                        <div class="ml-auto flex items-center gap-2">
                            <span id="char-count" class="hidden text-[11px] tabular-nums text-slate-600 sm:inline">0 / 4,000</span>
                            <button type="submit" id="chat-send" class="btn btn-primary h-9 px-3.5 text-sm" aria-label="Send question">
                                <x-icon name="loader" class="hidden h-4 w-4 animate-spin group-aria-busy:block" />
                                <x-icon name="send" class="h-4 w-4 group-aria-busy:hidden" />
                                <span class="hidden sm:inline">Send</span>
                            </button>
                        </div>
                    </div>
                </div>

                <p id="chat-submit-status" role="status" aria-live="polite" hidden class="mt-2 rounded-md bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-900"></p>
                <p id="composer-hint" class="mt-1.5 text-center text-[11px] leading-relaxed text-slate-500">
                    Academic prototype, not an official service. Answers may be incomplete; check PHIVOLCS and PAGASA for current advisories.
                    <span class="hidden sm:inline">Web search returns external links/search results, not verified guidance. <kbd class="kbd">Enter</kbd> to send, <kbd class="kbd">Shift</kbd>+<kbd class="kbd">Enter</kbd> for a new line.</span>
                </p>
            </form>
        </div>
    </div>
</div>
</body>
</html>
