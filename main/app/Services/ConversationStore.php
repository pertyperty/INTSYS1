<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Session-scoped chat history and model settings owned by Laravel.
 *
 * Conversations live only in the current browser session (bounded in count,
 * length, and age) and are forwarded as multi-turn history for conversation
 * memory within the active chat.
 */
class ConversationStore
{
    public const int MAX_CONVERSATIONS = 10;

    public const int MAX_MESSAGES = 20;

    public const int MAX_AGE_SECONDS = 86400;

    public const int MAX_TITLE_LENGTH = 60;

    public const int MAX_ANSWER_LENGTH = 8000;

    public const int MAX_PASSAGE_LENGTH = 600;

    public const int MAX_CITATIONS = 8;

    private const string KEY = 'sandig.conversations';

    private const string SETTINGS_KEY = 'sandig.settings';

    /**
     * @var list<string>
     */
    public const array DETAILS = ['brief', 'standard', 'detailed'];

    public function __construct(private readonly Session $session) {}

    /**
     * @return list<array{id: string, title: string, created_at: int, updated_at: int, messages: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        $conversations = $this->load();
        usort($conversations, fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);

        return $conversations;
    }

    /**
     * @return array{id: string, title: string, created_at: int, updated_at: int, messages: list<array<string, mixed>>}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->load() as $conversation) {
            if (hash_equals($conversation['id'], $id)) {
                return $conversation;
            }
        }

        return null;
    }

    /**
     * Return bounded prior turns formatted for multi-turn LLM memory.
     *
     * @return list<array{role: string, content: string}>
     */
    public function historyForApi(string $id): array
    {
        $conversation = $this->find($id);
        if ($conversation === null) {
            return [];
        }

        $turns = [];
        foreach (array_slice($conversation['messages'], -16) as $msg) {
            $role = $msg['role'] ?? null;
            $content = trim((string) ($msg['content'] ?? ''));
            if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
                $turns[] = [
                    'role' => $role,
                    'content' => Str::limit($content, 3800, ''),
                ];
            }
        }

        return $turns;
    }

    /**
     * Record one completed exchange, creating the conversation when needed.
     *
     * @param  array<string, mixed>  $assistant
     */
    public function recordExchange(string $id, string $question, array $assistant): void
    {
        $now = now()->timestamp;
        $conversations = $this->load();
        $index = null;
        foreach ($conversations as $key => $conversation) {
            if (hash_equals($conversation['id'], $id)) {
                $index = $key;
                break;
            }
        }

        if ($index === null) {
            $conversations[] = [
                'id' => $id,
                'title' => Str::limit(trim((string) preg_replace('/\s+/', ' ', $question)), self::MAX_TITLE_LENGTH),
                'created_at' => $now,
                'updated_at' => $now,
                'messages' => [],
            ];
            $index = array_key_last($conversations);
        }

        $messages = $conversations[$index]['messages'];
        $messages[] = ['role' => 'user', 'content' => $question, 'created_at' => $now];
        $messages[] = ['role' => 'assistant', 'created_at' => $now, ...$this->sanitizeAssistant($assistant)];

        $conversations[$index]['messages'] = array_slice($messages, -self::MAX_MESSAGES);
        $conversations[$index]['updated_at'] = $now;
        $updated = $conversations[$index];
        unset($conversations[$index]);
        array_unshift($conversations, $updated);

        $this->save($conversations);
    }

    public function delete(string $id): bool
    {
        $conversations = $this->load();
        $remaining = array_values(array_filter($conversations, fn (array $c): bool => ! hash_equals($c['id'], $id)));
        if (count($remaining) === count($conversations)) {
            return false;
        }
        $this->save($remaining);

        return true;
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * @return array{
     *     detail: string,
     *     web_off: bool,
     *     model: ?string,
     *     temperature: float,
     *     top_p: float,
     *     max_tokens: int,
     *     num_ctx: int,
     *     system_prompt: string,
     * }
     */
    public function settings(): array
    {
        $stored = $this->session->get(self::SETTINGS_KEY);
        if (! is_array($stored)) {
            $stored = [];
        }

        $detail = in_array($stored['detail'] ?? null, self::DETAILS, true) ? $stored['detail'] : 'standard';
        $webOff = ($stored['web_off'] ?? false) === true;
        $model = is_string($stored['model'] ?? null) && trim($stored['model']) !== '' ? trim($stored['model']) : null;
        $temperature = is_numeric($stored['temperature'] ?? null) ? max(0.0, min(1.5, round((float) $stored['temperature'], 2))) : 0.4;
        $topP = is_numeric($stored['top_p'] ?? null) ? max(0.1, min(1.0, round((float) $stored['top_p'], 2))) : 0.9;
        $maxTokens = is_numeric($stored['max_tokens'] ?? null) ? max(64, min(2048, (int) $stored['max_tokens'])) : 512;
        $numCtx = is_numeric($stored['num_ctx'] ?? null) ? max(1024, min(16384, (int) $stored['num_ctx'])) : 4096;
        $systemPrompt = is_string($stored['system_prompt'] ?? null) ? Str::limit(trim($stored['system_prompt']), 2000, '') : '';

        return [
            'detail' => $detail,
            'web_off' => $webOff,
            'model' => $model,
            'temperature' => $temperature,
            'top_p' => $topP,
            'max_tokens' => $maxTokens,
            'num_ctx' => $numCtx,
            'system_prompt' => $systemPrompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function saveSettings(
        ?string $detail = null,
        ?bool $webOff = null,
        array $overrides = [],
    ): void {
        $current = $this->settings();
        $next = [
            'detail' => $detail !== null && in_array($detail, self::DETAILS, true) ? $detail : $current['detail'],
            'web_off' => $webOff ?? $current['web_off'],
            'model' => array_key_exists('model', $overrides)
                ? (is_string($overrides['model']) && trim($overrides['model']) !== '' ? Str::limit(trim($overrides['model']), 128, '') : null)
                : $current['model'],
            'temperature' => array_key_exists('temperature', $overrides) && is_numeric($overrides['temperature'])
                ? max(0.0, min(1.5, round((float) $overrides['temperature'], 2)))
                : $current['temperature'],
            'top_p' => array_key_exists('top_p', $overrides) && is_numeric($overrides['top_p'])
                ? max(0.1, min(1.0, round((float) $overrides['top_p'], 2)))
                : $current['top_p'],
            'max_tokens' => array_key_exists('max_tokens', $overrides) && is_numeric($overrides['max_tokens'])
                ? max(64, min(2048, (int) $overrides['max_tokens']))
                : $current['max_tokens'],
            'num_ctx' => array_key_exists('num_ctx', $overrides) && is_numeric($overrides['num_ctx'])
                ? max(1024, min(16384, (int) $overrides['num_ctx']))
                : $current['num_ctx'],
            'system_prompt' => array_key_exists('system_prompt', $overrides)
                ? Str::limit(trim((string) ($overrides['system_prompt'] ?? '')), 2000, '')
                : $current['system_prompt'],
        ];

        $this->session->put(self::SETTINGS_KEY, $next);
    }

    /**
     * Whitelist assistant fields so nothing unexpected (e.g. raw coordinates) is persisted.
     *
     * @param  array<string, mixed>  $assistant
     * @return array<string, mixed>
     */
    private function sanitizeAssistant(array $assistant): array
    {
        $citations = [];
        foreach (array_slice($assistant['citations'] ?? [], 0, self::MAX_CITATIONS) as $citation) {
            $citations[] = [
                'kind' => $citation['kind'],
                'source_id' => $citation['source_id'],
                'is_curated_emergency_reference' => $citation['is_curated_emergency_reference'],
                'version' => $citation['version'],
                'title' => Str::limit($citation['title'], 200),
                'publisher' => Str::limit($citation['publisher'], 120),
                'url' => $citation['url'],
                'page' => $citation['page'],
                'section' => $citation['section'] !== null ? Str::limit($citation['section'], 160) : null,
                'publication_date' => $citation['publication_date'],
                'observed_at' => $citation['observed_at'],
                'passage' => $citation['passage'] !== null ? Str::limit($citation['passage'], self::MAX_PASSAGE_LENGTH) : null,
            ];
        }

        return [
            'content' => Str::limit((string) ($assistant['answer'] ?? ''), self::MAX_ANSWER_LENGTH),
            'evidence_status' => $assistant['evidence_status'],
            'response_type' => $assistant['response_type'],
            'citations' => $citations,
            'web_search_used' => (bool) ($assistant['web_search_used'] ?? false),
            'language' => $assistant['language'] ?? null,
            'checked_at' => $assistant['checked_at'] ?? null,
            'model' => is_string($assistant['model'] ?? null) ? Str::limit($assistant['model'], 128, '') : null,
            'web_mode' => $assistant['web_mode'] ?? 'auto',
        ];
    }

    /**
     * @return list<array{id: string, title: string, created_at: int, updated_at: int, messages: list<array<string, mixed>>}>
     */
    private function load(): array
    {
        $stored = $this->session->get(self::KEY);
        if (! is_array($stored)) {
            return [];
        }

        $cutoff = now()->timestamp - self::MAX_AGE_SECONDS;
        $valid = array_values(array_filter($stored, fn (mixed $c): bool => is_array($c)
            && is_string($c['id'] ?? null)
            && is_string($c['title'] ?? null)
            && is_int($c['updated_at'] ?? null)
            && is_array($c['messages'] ?? null)
            && $c['updated_at'] >= $cutoff));

        if (count($valid) !== count($stored)) {
            $this->save($valid);
        }

        return $valid;
    }

    /**
     * @param  list<array<string, mixed>>  $conversations
     */
    private function save(array $conversations): void
    {
        usort($conversations, fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);
        $this->session->put(self::KEY, array_slice($conversations, 0, self::MAX_CONVERSATIONS));
    }
}
