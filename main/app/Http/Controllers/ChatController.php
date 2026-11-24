<?php

namespace App\Http\Controllers;

use App\Services\ConversationStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * @var list<string>
     */
    private const array EVIDENCE_STATUSES = [
        'emergency',
        'out_of_scope',
        'not_applicable',
        'insufficient_evidence',
        'partially_supported',
        'supported',
    ];

    /**
     * @var list<string>
     */
    private const array RESPONSE_TYPES = [
        'preparedness',
        'hazard_assessment',
        'live_update',
        'general_information',
        'refusal',
        'emergency',
    ];

    /**
     * @var list<string>
     */
    private const array CITATION_KINDS = [
        'indexed_document',
        'curated_guidance',
        'official_live',
        'supplementary_live',
        'web_search',
    ];

    private const string LATEST_FLASH = 'sandig.latest';

    private const string STATUS_FLASH = 'sandig.status';

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, string $conversation): Response
    {
        $found = $this->store($request)->find($conversation);
        abort_if($found === null, 404);

        return $this->render($request, $found);
    }

    public function submit(Request $request): Response|RedirectResponse
    {
        $store = $this->store($request);
        $prepared = $this->validateAndBuildUpstreamPayload($request, $store);
        if (isset($prepared['error'])) {
            return $this->render($request, null, ['inputError' => $prepared['error']], 422);
        }

        $existing = $prepared['existing'];
        $conversationId = $prepared['conversationId'];
        $webOff = $prepared['webOff'];
        $allowWebSearch = $prepared['allowWebSearch'];

        $failure = fn (): Response => $this->render($request, $existing, [
            'unavailable' => true,
            'retryMessage' => $prepared['message'],
        ], 503);

        $token = config('services.sandig.token');
        if (! is_string($token) || $token === '') {
            return $failure();
        }

        try {
            $upstream = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->withOptions(['proxy' => ''])->connectTimeout(3)->timeout(90)
                ->post('http://127.0.0.1:8001/api/v1/chat', $prepared['payload']);
        } catch (ConnectionException) {
            return $failure();
        }

        if ($upstream->status() !== 200) {
            return $failure();
        }

        $parsed = $this->parseChatPayload($upstream->json(), $allowWebSearch);
        if ($parsed === null) {
            return $failure();
        }

        $store->recordExchange($conversationId, $prepared['message'], [
            ...$parsed,
            'web_mode' => $webOff ? 'off' : 'auto',
        ]);
        $request->session()->flash(self::LATEST_FLASH, $conversationId);

        return redirect()->route('chat.show', ['conversation' => $conversationId], 303);
    }

    public function stream(Request $request): JsonResponse|StreamedResponse
    {
        $store = $this->store($request);
        $prepared = $this->validateAndBuildUpstreamPayload($request, $store);
        if (isset($prepared['error'])) {
            return response()->json(['detail' => $prepared['error']], 422);
        }

        $token = config('services.sandig.token');
        if (! is_string($token) || $token === '') {
            return response()->json(['detail' => 'Service token is not configured.'], 503);
        }

        $conversationId = $prepared['conversationId'];
        $existing = $prepared['existing'];
        $messageText = $prepared['message'];
        $webOff = $prepared['webOff'];
        $allowWebSearch = $prepared['allowWebSearch'];
        $payload = $prepared['payload'];
        $conversationTitle = $existing !== null
            ? $existing['title']
            : Str::limit(trim((string) preg_replace('/\s+/', ' ', $messageText)), ConversationStore::MAX_TITLE_LENGTH);

        try {
            $upstream = Http::accept('application/x-ndjson')->withToken($token)->withoutRedirecting()
                ->withOptions(['proxy' => '', 'stream' => true])->connectTimeout(3)->timeout(120)
                ->post('http://127.0.0.1:8001/api/v1/chat/stream', $payload);
        } catch (ConnectionException) {
            return response()->json(['detail' => 'SANDIG cannot reach the AI backend right now.'], 503);
        }

        if ($upstream->status() !== 200) {
            return response()->json(['detail' => 'SANDIG cannot answer right now.'], 503);
        }

        $body = $upstream->toPsrResponse()->getBody();
        $session = $request->session();

        return response()->stream(function () use ($body, $store, $session, $conversationId, $conversationTitle, $messageText, $webOff, $allowWebSearch): void {
            $flushOutput = ! app()->runningUnitTests();
            if ($flushOutput && function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $emit = static function (array $event) use ($flushOutput): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if ($flushOutput) {
                    flush();
                }
            };

            $emit([
                'type' => 'conversation',
                'id' => $conversationId,
                'title' => $conversationTitle,
                'url' => route('chat.show', ['conversation' => $conversationId]),
                'delete_url' => route('chat.destroy', ['conversation' => $conversationId]),
            ]);

            $buffer = '';
            $meta = null;
            $accumulated = '';
            $finalAnswer = null;

            $processLine = function (string $line) use (&$meta, &$accumulated, &$finalAnswer, $allowWebSearch, $emit): void {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return;
                }

                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded) || ! is_string($decoded['type'] ?? null)) {
                    return;
                }

                if ($decoded['type'] === 'meta') {
                    $sanitizedMeta = $this->parseMetaPayload($decoded, $allowWebSearch);
                    if ($sanitizedMeta !== null) {
                        $meta = $sanitizedMeta;
                        $emit(['type' => 'meta', ...$sanitizedMeta]);
                    }
                } elseif ($decoded['type'] === 'token' && is_string($decoded['text'] ?? null)) {
                    $accumulated .= $decoded['text'];
                    $emit(['type' => 'token', 'text' => $decoded['text']]);
                } elseif ($decoded['type'] === 'done') {
                    $finalAnswer = is_string($decoded['answer'] ?? null) && trim($decoded['answer']) !== ''
                        ? $decoded['answer']
                        : $accumulated;
                }
            };

            while (! $body->eof()) {
                $chunk = $body->read(1024);
                if ($chunk === '') {
                    usleep(10000);

                    continue;
                }
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $processLine($line);
                }
            }

            if (trim($buffer) !== '') {
                $processLine($buffer);
            }

            $answerText = trim((string) ($finalAnswer ?? $accumulated));
            if ($meta !== null && $answerText !== '') {
                $store->recordExchange($conversationId, $messageText, [
                    ...$meta,
                    'answer' => $answerText,
                    'web_mode' => $webOff ? 'off' : 'auto',
                ]);
                $session->save();
                $emit(['type' => 'done', 'answer' => $answerText, 'conversation_id' => $conversationId]);
            } else {
                $session->save();
                $emit(['type' => 'error', 'detail' => 'SANDIG could not complete this response.']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        abort_unless($this->store($request)->delete($conversation), 404);

        return redirect()->route('chat.index', [], 303)->with(self::STATUS_FLASH, 'Chat deleted.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $this->store($request)->clear();

        return redirect()->route('chat.index', [], 303)->with(self::STATUS_FLASH, 'All chats deleted.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $store = $this->store($request);
        $validator = Validator::make($request->only(
            'response_detail', 'return_to', 'model', 'temperature', 'top_p', 'max_tokens', 'num_ctx', 'system_prompt',
        ), [
            'response_detail' => ['required', 'in:brief,standard,detailed'],
            'return_to' => ['nullable', 'uuid'],
            'model' => ['nullable', 'string', 'max:128'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1.5'],
            'top_p' => ['nullable', 'numeric', 'min:0.1', 'max:1'],
            'max_tokens' => ['nullable', 'integer', 'min:64', 'max:2048'],
            'num_ctx' => ['nullable', 'integer', 'min:1024', 'max:16384'],
            'system_prompt' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('chat.index', [], 303)->with(self::STATUS_FLASH, 'Settings were not saved. Check your model parameters and try again.');
        }

        $input = $validator->validated();
        $overrides = [];
        foreach (['model', 'temperature', 'top_p', 'max_tokens', 'num_ctx', 'system_prompt'] as $key) {
            if ($request->has($key)) {
                $overrides[$key] = $input[$key] ?? null;
            }
        }

        $store->saveSettings(
            $input['response_detail'],
            $request->has('web_off') ? $request->input('web_off') === '1' : null,
            $overrides,
        );

        $returnTo = ! empty($input['return_to']) && $store->find($input['return_to']) !== null
            ? route('chat.show', ['conversation' => $input['return_to']])
            : route('chat.index');

        return redirect($returnTo, 303)->with(self::STATUS_FLASH, 'Model & chat settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAndBuildUpstreamPayload(Request $request, ConversationStore $store): array
    {
        $validator = Validator::make($request->only(
            'message', 'language', 'response_detail', 'conversation_id', 'latitude', 'longitude', 'accuracy',
        ), [
            'message' => ['required', 'string', 'max:4000'],
            'language' => ['nullable', 'in:auto,en,fil'],
            'response_detail' => ['nullable', 'in:brief,standard,detailed'],
            'conversation_id' => ['nullable', 'uuid'],
            'latitude' => ['nullable', 'numeric', 'min:-90', 'max:90'],
            'longitude' => ['nullable', 'numeric', 'min:-180', 'max:180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if ($validator->fails()) {
            return ['error' => 'Enter a question of up to 4,000 characters, then try again.'];
        }

        $input = $validator->validated();
        $existing = null;
        if (! empty($input['conversation_id'])) {
            $existing = $store->find($input['conversation_id']);
            abort_if($existing === null, 404);
        }

        $store->saveSettings($input['response_detail'] ?? null, match ($request->input('web_off')) {
            '1' => true,
            '0' => false,
            default => null,
        });
        $settings = $store->settings();
        $webOff = $settings['web_off'];
        $allowWebSearch = ! $webOff;
        $conversationId = $existing['id'] ?? (string) Str::uuid();
        $history = $existing !== null ? $store->historyForApi($existing['id']) : [];

        $payload = [
            'message' => $input['message'],
            'language' => $input['language'] ?? 'auto',
            'response_detail' => $input['response_detail'] ?? $settings['detail'],
            'conversation_id' => $conversationId,
            'allow_web_search' => $allowWebSearch,
            'auto_web_search' => $allowWebSearch,
            'history' => $history,
            'temperature' => $settings['temperature'],
            'top_p' => $settings['top_p'],
            'max_tokens' => $settings['max_tokens'],
            'num_ctx' => $settings['num_ctx'],
        ];

        if ($settings['model'] !== null) {
            $payload['model'] = $settings['model'];
        }
        if ($settings['system_prompt'] !== '') {
            $payload['system_prompt'] = $settings['system_prompt'];
        }
        if (isset($input['latitude'], $input['longitude'])) {
            $payload['location'] = [
                'latitude' => (float) $input['latitude'],
                'longitude' => (float) $input['longitude'],
                'accuracy' => isset($input['accuracy']) ? (float) $input['accuracy'] : null,
            ];
        }

        return [
            'message' => $input['message'],
            'existing' => $existing,
            'conversationId' => $conversationId,
            'webOff' => $webOff,
            'allowWebSearch' => $allowWebSearch,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{default_model: string, models: list<array{name: string, size: int}>}
     */
    private function fetchAvailableModels(): array
    {
        $fallback = ['default_model' => 'llama3.1:8b', 'models' => [['name' => 'llama3.1:8b', 'size' => 0]]];
        $token = config('services.sandig.token');
        if (! is_string($token) || $token === '') {
            return $fallback;
        }

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->withOptions(['proxy' => ''])->connectTimeout(1)->timeout(2)
                ->get('http://127.0.0.1:8001/api/v1/models');
            if ($response->status() !== 200) {
                return $fallback;
            }
            $data = $response->json();
            if (! is_array($data) || ! is_string($data['default_model'] ?? null) || ! is_array($data['models'] ?? null)) {
                return $fallback;
            }
            $models = [];
            foreach ($data['models'] as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                    $models[] = [
                        'name' => $entry['name'],
                        'size' => is_int($entry['size'] ?? null) ? $entry['size'] : 0,
                    ];
                }
            }

            return $models !== [] ? ['default_model' => $data['default_model'], 'models' => $models] : $fallback;
        } catch (ConnectionException) {
            return $fallback;
        }
    }

    private function store(Request $request): ConversationStore
    {
        return new ConversationStore($request->session());
    }

    /**
     * @param  array{id: string, title: string, created_at: int, updated_at: int, messages: list<array<string, mixed>>}|null  $conversation
     * @param  array<string, mixed>  $extra
     */
    private function render(Request $request, ?array $conversation, array $extra = [], int $status = 200): Response
    {
        $store = $this->store($request);
        $latest = $request->session()->get(self::LATEST_FLASH);
        $modelInfo = $status === 200
            ? $this->fetchAvailableModels()
            : ['default_model' => 'llama3.1:8b', 'models' => [['name' => 'llama3.1:8b', 'size' => 0]]];
        $settings = $store->settings();

        return response()->view('chat', [
            'conversations' => array_map(fn (array $c): array => [
                'id' => $c['id'],
                'title' => $c['title'],
                'updated_at' => $c['updated_at'],
            ], $store->all()),
            'conversation' => $conversation,
            'settings' => $settings,
            'availableModels' => $modelInfo['models'],
            'activeModel' => $settings['model'] ?? $modelInfo['default_model'],
            'highlightLatest' => $conversation !== null && is_string($latest) && hash_equals($conversation['id'], $latest),
            'statusMessage' => $request->session()->get(self::STATUS_FLASH),
            'limits' => [
                'conversations' => ConversationStore::MAX_CONVERSATIONS,
                'messages' => ConversationStore::MAX_MESSAGES,
                'hours' => intdiv(ConversationStore::MAX_AGE_SECONDS, 3600),
            ],
            ...$extra,
        ], $status)->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{
     *     response_type: string,
     *     evidence_status: string,
     *     citations: list<array<string, mixed>>,
     *     web_search_used: bool,
     *     language: ?string,
     *     checked_at: ?string,
     *     model: ?string,
     * }|null
     */
    private function parseMetaPayload(mixed $payload, bool $allowWebSearch): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $responseType = $payload['response_type'] ?? null;
        $evidenceStatus = $payload['evidence_status'] ?? null;
        $rawCitations = $payload['citations'] ?? null;
        $webSearchUsed = $payload['web_search_used'] ?? null;
        $language = $payload['language'] ?? null;
        $checkedAt = $payload['checked_at'] ?? null;
        $model = $payload['model'] ?? null;

        if (! is_string($responseType) || ! in_array($responseType, self::RESPONSE_TYPES, true)) {
            return null;
        }

        if (! is_string($evidenceStatus) || ! in_array($evidenceStatus, self::EVIDENCE_STATUSES, true)) {
            return null;
        }

        if (($evidenceStatus === 'emergency') !== ($responseType === 'emergency')) {
            return null;
        }

        if (! is_bool($webSearchUsed)) {
            return null;
        }

        if ($language !== null && ! in_array($language, ['en', 'fil'], true)) {
            return null;
        }

        if ($webSearchUsed && (! $allowWebSearch || in_array($evidenceStatus, ['emergency', 'out_of_scope'], true))) {
            return null;
        }

        if ($checkedAt !== null && ! is_string($checkedAt)) {
            return null;
        }

        if ($model !== null && ! is_string($model)) {
            return null;
        }

        if (! is_array($rawCitations) || ! array_is_list($rawCitations)) {
            return null;
        }

        $citations = [];
        foreach ($rawCitations as $rawCitation) {
            $normalized = $this->normalizeCitation($rawCitation, $webSearchUsed);
            if ($normalized === null) {
                return null;
            }
            $citations[] = $normalized;
        }

        if (in_array($evidenceStatus, ['supported', 'partially_supported'], true) && $citations === []) {
            return null;
        }

        return [
            'response_type' => $responseType,
            'evidence_status' => $evidenceStatus,
            'citations' => $citations,
            'web_search_used' => $webSearchUsed,
            'language' => $language,
            'checked_at' => is_string($checkedAt) && trim($checkedAt) !== '' ? $checkedAt : null,
            'model' => is_string($model) && trim($model) !== '' ? trim($model) : null,
        ];
    }

    /**
     * @return array{
     *     answer: string,
     *     response_type: string,
     *     evidence_status: string,
     *     citations: list<array<string, mixed>>,
     *     web_search_used: bool,
     *     language: ?string,
     *     checked_at: ?string,
     *     model: ?string,
     * }|null
     */
    private function parseChatPayload(mixed $payload, bool $allowWebSearch): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $answer = $payload['answer'] ?? null;
        if (! is_string($answer) || trim($answer) === '') {
            return null;
        }

        $meta = $this->parseMetaPayload($payload, $allowWebSearch);
        if ($meta === null) {
            return null;
        }

        return ['answer' => $answer, ...$meta];
    }

    /**
     * @return array{
     *     kind: string,
     *     source_id: ?string,
     *     is_curated_emergency_reference: bool,
     *     version: ?string,
     *     title: string,
     *     publisher: string,
     *     url: ?string,
     *     page: ?int,
     *     section: ?string,
     *     publication_date: ?string,
     *     observed_at: ?string,
     *     passage: ?string,
     * }|null
     */
    private function normalizeCitation(mixed $item, bool $webSearchUsed): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $kind = $item['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::CITATION_KINDS, true)) {
            return null;
        }

        if ($kind === 'web_search' && ! $webSearchUsed) {
            return null;
        }

        $title = $item['title'] ?? null;
        $publisher = $item['publisher'] ?? null;
        if (! is_string($title) || trim($title) === '' || ! is_string($publisher) || trim($publisher) === '') {
            return null;
        }

        $optionalStrings = ['source_id', 'version', 'section', 'publication_date', 'observed_at', 'passage'];
        $optionalValues = [];
        foreach ($optionalStrings as $field) {
            $value = $item[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                return null;
            }
            $optionalValues[$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        $page = $item['page'] ?? null;
        if ($page !== null && (! is_int($page) || $page < 1)) {
            return null;
        }

        $rawUrl = $item['url'] ?? null;
        if ($rawUrl !== null && ! is_string($rawUrl)) {
            return null;
        }

        return [
            'kind' => $kind,
            'source_id' => $optionalValues['source_id'],
            'is_curated_emergency_reference' => $kind === 'indexed_document' && $optionalValues['source_id'] === null,
            'version' => $optionalValues['version'],
            'title' => $title,
            'publisher' => $publisher,
            'url' => $this->sanitizeHttpsUrl($rawUrl),
            'page' => $page,
            'section' => $optionalValues['section'],
            'publication_date' => $optionalValues['publication_date'],
            'observed_at' => $optionalValues['observed_at'],
            'passage' => $optionalValues['passage'],
        ];
    }

    private function sanitizeHttpsUrl(?string $url): ?string
    {
        if ($url === null) {
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
}
