@php
    $hazardTone = fn (string $hazard) => match (true) {
        str_contains(strtolower($hazard), 'earthquake') => 'bg-orange-50 text-orange-900 ring-orange-200',
        str_contains(strtolower($hazard), 'landslide') => 'bg-amber-50 text-amber-900 ring-amber-200',
        str_contains(strtolower($hazard), 'typhoon'), str_contains(strtolower($hazard), 'flood'), str_contains(strtolower($hazard), 'rain') => 'bg-sky-50 text-sky-900 ring-sky-200',
        str_contains(strtolower($hazard), 'fire') => 'bg-red-50 text-red-900 ring-red-200',
        default => 'bg-slate-100 text-slate-800 ring-slate-200',
    };
    $pageHazards = collect($sources ?? [])->pluck('hazards')->flatten()->unique()->sort()->values()->all();
@endphp
<x-layout
    title="SANDIG | Approved Sources"
    heading="Approved Sources"
    active="sources"
    :conversations="$conversations ?? []"
    :entries="['resources/css/app.css', 'resources/js/sources.js']"
>
<div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
    <section aria-labelledby="catalog-heading" class="pt-1 sm:pt-2">
        <p class="inline-flex items-center gap-1.5 rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800 ring-1 ring-inset ring-teal-200">
            <x-icon name="check-circle" class="h-3.5 w-3.5" />
            Read-only approved source metadata catalog
        </p>
        <h2 id="catalog-heading" class="mt-3 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">Approved preparedness sources</h2>
        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-600 sm:text-base">
            Read-only metadata for approved preparedness references in the SANDIG catalog. Indexed answers prioritize and cite documents listed here.
        </p>
    </section>

    @if (isset($unavailable))
        <div role="alert" class="flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-950 shadow-xs">
            <x-icon name="alert-triangle" class="mt-0.5 h-5 w-5 text-red-700" />
            <p>The approved source catalog is temporarily unavailable. Please try again later.</p>
        </div>
    @elseif (isset($inputError))
        <div role="alert" class="flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-950 shadow-xs">
            <x-icon name="alert-circle" class="mt-0.5 h-5 w-5 text-red-700" />
            <div>
                <p>{{ $inputError }}</p>
                <p class="mt-2">
                    <a href="{{ route('sources.index') }}" class="font-semibold underline">Return to first page</a>
                </p>
            </div>
        </div>
    @elseif (empty($sources))
        <section aria-label="Empty source catalog" class="card flex flex-col items-center px-6 py-12 text-center text-slate-700">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-100">
                <x-icon name="book" class="h-6 w-6" />
            </span>
            <p class="mt-3 font-semibold text-slate-950">No approved sources available.</p>
            <p class="mt-1 max-w-md text-sm text-slate-600">There are currently no approved reference documents published in this catalog page.</p>
            @if (! empty($hasCursor))
                <p class="mt-4 text-sm">
                    <a href="{{ route('sources.index', ($limit ?? 25) !== 25 ? ['limit' => $limit] : []) }}" class="font-semibold text-teal-800 underline hover:text-teal-950">Return to first page</a>
                </p>
            @endif
        </section>
    @else
        <div id="source-toolbar" hidden class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <label class="relative block w-full sm:max-w-sm">
                <span class="sr-only">Filter sources on this page</span>
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input id="source-search" type="search" autocomplete="off" placeholder="Filter by title, publisher, or source ID"
                       class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 shadow-2xs placeholder:text-slate-400 focus:border-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-700/10">
            </label>
            @if ($pageHazards !== [])
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by hazard">
                    <button type="button" data-hazard-filter="" aria-pressed="true" class="hazard-filter chip border border-slate-300 bg-white py-1 text-slate-700 aria-pressed:border-teal-700 aria-pressed:bg-teal-700 aria-pressed:text-white">All hazards</button>
                    @foreach ($pageHazards as $hazard)
                        <button type="button" data-hazard-filter="{{ $hazard }}" aria-pressed="false" class="hazard-filter chip border border-slate-300 bg-white py-1 capitalize text-slate-700 aria-pressed:border-teal-700 aria-pressed:bg-teal-700 aria-pressed:text-white">{{ $hazard }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <p id="source-count" role="status" aria-live="polite" class="text-xs font-medium text-slate-500">
            {{ count($sources) }} {{ count($sources) === 1 ? 'source' : 'sources' }} on this page
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($sources as $source)
                <article
                    data-source-card
                    data-search="{{ strtolower($source['title'].' '.$source['publisher'].' '.$source['source_id']) }}"
                    data-hazards="{{ implode('|', $source['hazards']) }}"
                    class="card flex flex-col p-5 shadow-sm transition hover:border-slate-300"
                >
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-800 ring-1 ring-inset ring-teal-100">
                            <x-icon name="file" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h3 class="text-base font-semibold leading-snug text-slate-950">{{ $source['title'] }}</h3>
                                <span class="chip bg-teal-50 uppercase tracking-wide text-teal-900 ring-1 ring-inset ring-teal-200">
                                    <x-icon name="check" class="h-3 w-3" />
                                    {{ $source['verification_status'] }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-sm font-medium text-slate-600">{{ $source['publisher'] }}</p>
                        </div>
                    </div>

                    @if ($source['hazards'] !== [])
                        <div class="mt-4 flex flex-wrap gap-1.5" aria-label="Hazards">
                            @foreach ($source['hazards'] as $hazard)
                                <span class="chip font-medium capitalize ring-1 ring-inset {{ $hazardTone($hazard) }}">{{ $hazard }}</span>
                            @endforeach
                        </div>
                    @endif

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl bg-slate-50 p-3.5 text-sm">
                        <div class="min-w-0">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Source ID</dt>
                            <dd class="mt-0.5 truncate font-mono text-xs text-slate-900">{{ $source['source_id'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Version</dt>
                            <dd class="mt-0.5 text-slate-900">{{ $source['version'] }}</dd>
                        </div>
                        @if ($source['jurisdiction'] !== null)
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Jurisdiction</dt>
                                <dd class="mt-0.5 text-slate-900">{{ $source['jurisdiction'] }}</dd>
                            </div>
                        @endif
                        @if ($source['publication_date'] !== null)
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Publication date</dt>
                                <dd class="mt-0.5 text-slate-900">{{ $source['publication_date'] }}</dd>
                            </div>
                        @endif
                        @if ($source['retrieval_date'] !== null)
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Retrieval date</dt>
                                <dd class="mt-0.5 text-slate-900">{{ $source['retrieval_date'] }}</dd>
                            </div>
                        @endif
                        @if ($source['usage_restrictions'] !== null)
                            <div class="col-span-2">
                                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Usage restrictions</dt>
                                <dd class="mt-0.5 text-slate-900">{{ $source['usage_restrictions'] }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($source['url'] !== null)
                        <p class="mt-auto pt-4 text-sm">
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 font-semibold text-teal-800 hover:text-teal-950 hover:underline">
                                View source document
                                <x-icon name="external" class="h-3.5 w-3.5" />
                            </a>
                        </p>
                    @endif
                </article>
            @endforeach
        </div>

        <div id="source-no-match" hidden class="card px-6 py-10 text-center text-sm text-slate-600">
            <p class="font-semibold text-slate-900">No sources on this page match your filter.</p>
            <p class="mt-1">Try a different keyword or hazard. Filtering only applies to the current page.</p>
        </div>

        @if (! empty($hasCursor) || ! empty($nextCursor))
            <nav aria-label="Pagination" class="flex items-center justify-between border-t border-slate-200 pt-5">
                <div>
                    @if (! empty($hasCursor))
                        <a href="{{ route('sources.index', ($limit ?? 25) !== 25 ? ['limit' => $limit] : []) }}" class="btn btn-secondary px-4 py-2 text-sm font-medium">
                            First page
                        </a>
                    @endif
                </div>
                <div>
                    @if (! empty($nextCursor))
                        <a href="{{ route('sources.index', ['limit' => $limit ?? 25, 'cursor' => $nextCursor]) }}" class="btn btn-primary px-4 py-2 text-sm">
                            Next page
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    @endif
                </div>
            </nav>
        @endif
    @endif

    <section aria-labelledby="portals-heading" class="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-600">
        <h3 id="portals-heading" class="font-semibold text-slate-800">Official agency portals (external)</h3>
        <p class="mt-0.5">Check official bulletins directly; SANDIG does not verify current conditions.</p>
        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
            <a href="https://earthquake.phivolcs.dost.gov.ph/" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-teal-800 hover:underline">PHIVOLCS Earthquake Bulletins <x-icon name="external" class="h-3 w-3" /></a>
            <a href="https://www.pagasa.dost.gov.ph/" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-teal-800 hover:underline">PAGASA Weather Advisories <x-icon name="external" class="h-3 w-3" /></a>
        </p>
    </section>
</div>
</x-layout>
