<?php

namespace App\Http\Controllers;

use App\Services\ConversationStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class SourceCatalogController extends Controller
{
    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    private const int MAX_CURSOR_LENGTH = 512;

    public function __invoke(Request $request): Response
    {
        $validator = Validator::make($request->only('limit', 'cursor'), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:'.self::MAX_CURSOR_LENGTH],
        ]);

        if ($validator->fails()) {
            return $this->invalidPagination($request);
        }

        $token = config('services.sandig.token');
        if (! is_string($token) || $token === '') {
            return $this->unavailable($request);
        }

        $validated = $validator->validated();
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : self::DEFAULT_LIMIT;
        $cursor = isset($validated['cursor']) && $validated['cursor'] !== '' ? $validated['cursor'] : null;

        $query = ['limit' => $limit];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        try {
            $upstream = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->withOptions(['proxy' => ''])->connectTimeout(2)->timeout(5)
                ->get('http://127.0.0.1:8001/api/v1/sources', $query);
        } catch (ConnectionException) {
            return $this->unavailable($request);
        }

        if ($upstream->status() === 422) {
            return $this->invalidPagination($request);
        }

        if ($upstream->status() !== 200) {
            return $this->unavailable($request);
        }

        $payload = $upstream->json();
        $parsed = $this->parseCatalogPayload($payload);
        if ($parsed === null) {
            return $this->unavailable($request);
        }

        return response()->view('sources', [
            'conversations' => $this->conversationsForSidebar($request),
            'sources' => $parsed['items'],
            'nextCursor' => $parsed['next_cursor'],
            'limit' => $limit,
            'hasCursor' => $cursor !== null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}|null
     */
    private function parseCatalogPayload(mixed $payload): ?array
    {
        if (! is_array($payload) || ! array_key_exists('items', $payload) || ! array_key_exists('next_cursor', $payload)) {
            return null;
        }

        $rawItems = $payload['items'];
        $nextCursor = $payload['next_cursor'];

        if (! is_array($rawItems) || ! array_is_list($rawItems)) {
            return null;
        }

        if ($nextCursor !== null) {
            if (! is_string($nextCursor) || $nextCursor === '' || strlen($nextCursor) > self::MAX_CURSOR_LENGTH) {
                return null;
            }
        }

        $items = [];
        foreach ($rawItems as $item) {
            $normalized = $this->normalizeSource($item);
            if ($normalized === null) {
                return null;
            }
            $items[] = $normalized;
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @return array{
     *     source_id: string,
     *     title: string,
     *     publisher: string,
     *     url: ?string,
     *     publication_date: ?string,
     *     retrieval_date: ?string,
     *     jurisdiction: ?string,
     *     hazards: list<string>,
     *     version: string,
     *     verification_status: string,
     *     usage_restrictions: ?string,
     * }|null
     */
    private function normalizeSource(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $requiredStrings = ['source_id', 'title', 'publisher', 'version'];
        foreach ($requiredStrings as $field) {
            if (! isset($item[$field]) || ! is_string($item[$field]) || trim($item[$field]) === '') {
                return null;
            }
        }

        if (($item['verification_status'] ?? null) !== 'approved') {
            return null;
        }

        $optionalStrings = ['publication_date', 'retrieval_date', 'jurisdiction', 'usage_restrictions'];
        $optionalValues = [];
        foreach ($optionalStrings as $field) {
            $value = $item[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                return null;
            }
            $optionalValues[$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        $hazards = [];
        if (array_key_exists('hazards', $item) && $item['hazards'] !== null) {
            if (! is_array($item['hazards']) || ! array_is_list($item['hazards'])) {
                return null;
            }
            foreach ($item['hazards'] as $hazard) {
                if (! is_string($hazard)) {
                    return null;
                }
                if (trim($hazard) !== '') {
                    $hazards[] = $hazard;
                }
            }
        }

        return [
            'source_id' => $item['source_id'],
            'title' => $item['title'],
            'publisher' => $item['publisher'],
            'url' => $this->sanitizeHttpsUrl($item['url'] ?? null),
            'publication_date' => $optionalValues['publication_date'],
            'retrieval_date' => $optionalValues['retrieval_date'],
            'jurisdiction' => $optionalValues['jurisdiction'],
            'hazards' => $hazards,
            'version' => $item['version'],
            'verification_status' => 'approved',
            'usage_restrictions' => $optionalValues['usage_restrictions'],
        ];
    }

    private function sanitizeHttpsUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }

    /**
     * @return list<array{id: string, title: string, updated_at: int}>
     */
    private function conversationsForSidebar(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $store = new ConversationStore($request->session());

        return array_map(fn (array $c): array => [
            'id' => $c['id'],
            'title' => $c['title'],
            'updated_at' => $c['updated_at'],
        ], $store->all());
    }

    private function invalidPagination(Request $request): Response
    {
        return response()->view('sources', [
            'conversations' => $this->conversationsForSidebar($request),
            'inputError' => 'Invalid catalog page parameters. Return to the first page to browse approved sources.',
        ], 422)->header('Cache-Control', 'no-store');
    }

    private function unavailable(Request $request): Response
    {
        return response()->view('sources', [
            'conversations' => $this->conversationsForSidebar($request),
            'unavailable' => true,
        ], 503)->header('Cache-Control', 'no-store');
    }
}
