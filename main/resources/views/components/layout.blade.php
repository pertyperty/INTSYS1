@props([
    'title',
    'heading' => 'SANDIG',
    'active' => 'chat',
    'conversations' => [],
    'entries' => ['resources/css/app.css'],
])
@php
    use Illuminate\Support\Carbon;
@endphp
<!doctype html>
<html lang="en" class="overflow-clip bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#134e4a">
    <meta name="description" content="SANDIG is an academic prototype assistant for evidence-grounded disaster preparedness information in Baguio City.">
    <title>{{ $title }}</title>
    @fonts
    @vite($entries)
</head>
<body class="h-dvh overflow-clip bg-slate-50 text-slate-900 antialiased">
<a href="#main" class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-semibold text-teal-900 shadow-lg ring-2 ring-teal-700 focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60]">
    Skip to main content
</a>

<div class="flex h-full">
    <div id="drawer-backdrop" hidden data-drawer-close class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-[1px] lg:hidden"></div>

    {{-- Drawer: unified navigation shell matching the chat sidebar. --}}
    <aside id="chat-drawer" aria-label="Chats and navigation" data-open="false"
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

        <nav aria-labelledby="history-heading" class="min-h-24 flex-1 overflow-y-auto px-3">
            <h2 id="history-heading" class="px-2 pb-1.5 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Recent chats</h2>
            @if ($conversations === [])
                <p class="px-2 py-2 text-xs leading-relaxed text-slate-500">No chats yet. Your chats appear here for this browser session only.</p>
            @else
                <ul class="space-y-0.5">
                    @foreach ($conversations as $item)
                        <li class="group/item relative flex items-center rounded-lg hover:bg-slate-100">
                            <a href="{{ route('chat.show', ['conversation' => $item['id']]) }}"
                               class="min-w-0 flex-1 rounded-lg px-2.5 py-2 pr-9 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700">
                                <span class="block truncate text-sm font-medium text-slate-800">{{ $item['title'] }}</span>
                                <span class="block text-[11px] text-slate-500">{{ Carbon::createFromTimestamp($item['updated_at'])->diffForHumans() }}</span>
                            </a>
                            <form method="post" action="{{ route('chat.destroy', ['conversation' => $item['id']]) }}" data-confirm="Delete this chat? This cannot be undone." class="absolute right-1.5">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 sm:opacity-0 sm:group-hover/item:opacity-100 sm:focus-visible:opacity-100"
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
            <a href="{{ route('sources.index') }}" @if ($active === 'sources') aria-current="page" @endif
               class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-700 {{ $active === 'sources' ? 'bg-teal-50 font-semibold text-teal-950 ring-1 ring-inset ring-teal-200' : 'font-medium text-slate-700 hover:bg-slate-100' }}">
                <x-icon name="book" class="h-4 w-4 {{ $active === 'sources' ? 'text-teal-700' : 'text-slate-500' }}" />
                Approved sources
            </a>
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
            <button type="button" id="drawer-toggle" aria-controls="chat-drawer" aria-expanded="false" class="btn btn-secondary h-9 w-9 p-0 lg:hidden" aria-label="Open chats and navigation menu">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900 sm:text-base">
                {{ $heading }}
            </h1>
            <a href="{{ route('chat.index') }}" class="btn btn-secondary hidden h-9 sm:inline-flex lg:hidden">
                <x-icon name="plus" class="h-4 w-4" /> New chat
            </a>
            <a href="tel:911" class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-red-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2">
                <x-icon name="phone" class="h-3.5 w-3.5" />
                <span><span class="sm:hidden">911</span><span class="hidden sm:inline">Emergency? Call 911</span></span>
            </a>
        </header>

        <main id="main" tabindex="-1" class="relative min-h-0 flex-1 overflow-y-auto overscroll-y-contain focus:outline-none">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
