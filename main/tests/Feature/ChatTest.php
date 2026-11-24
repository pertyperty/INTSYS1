<?php

use App\Services\ConversationStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function chatReply(array $overrides = []): array
{
    return array_replace([
        'answer' => 'General preparedness guidance.',
        'response_type' => 'preparedness',
        'evidence_status' => 'insufficient_evidence',
        'citations' => [],
        'web_search_used' => false,
        'checked_at' => null,
        'model' => 'llama3.1:8b',
    ], $overrides);
}

function modelsReply(array $overrides = []): array
{
    return array_replace([
        'default_model' => 'llama3.1:8b',
        'models' => [
            ['name' => 'llama3.1:8b', 'size' => 4920753328],
            ['name' => 'gemma3:4b', 'size' => 3338801804],
        ],
    ], $overrides);
}

function fakeChat(array $reply = [], array $extraFakes = []): void
{
    Http::fake([
        '127.0.0.1:8001/api/v1/models' => Http::response(modelsReply()),
        '127.0.0.1:8001/api/v1/chat' => Http::response(chatReply($reply)),
        ...$extraFakes,
    ]);
}

function lastChatPayload(): array
{
    $recorded = Http::recorded(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8001/api/v1/chat');

    return $recorded->last()[0]->data();
}

beforeEach(function () {
    config(['services.sandig.token' => 'server-test-token']);
    Http::preventStrayRequests();
    $this->chatInput = ['message' => 'How do I prepare?'];
});

test('chat shell renders model settings, streaming form, web search on by default, and no explicit web button', function () {
    Http::fake(['127.0.0.1:8001/api/v1/models' => Http::response(modelsReply())]);

    $this->get('/')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('name="_token"', false)
        ->assertSee('href="tel:911"', false)
        ->assertSee('id="chat-drawer"', false)
        ->assertSee('New chat')
        ->assertSee('Recent chats')
        ->assertSee('id="web-search-switch"', false)
        ->assertSee('id="web-search-state" class="font-semibold">On', false)
        ->assertDontSee('Send with web search (this request only)')
        ->assertSee('data-stream-url="'.route('chat.stream').'"', false)
        ->assertSee('id="setting-model"', false)
        ->assertSee('llama3.1:8b')
        ->assertSee('gemma3:4b')
        ->assertSee('id="setting-temperature"', false)
        ->assertSee('id="setting-top-p"', false)
        ->assertSee('id="setting-max-tokens"', false)
        ->assertSee('id="setting-num-ctx"', false)
        ->assertSee('id="setting-system-prompt"', false)
        ->assertSee('Check my device location (local only)')
        ->assertSee('Load Baguio City reference map (OpenStreetMap)')
        ->assertSee('does <strong>not</strong> infer your barangay', false)
        ->assertSee('id="map-tile-status"', false)
        ->assertSee('id="chat-submit-status"', false)
        ->assertSee('data-starter-prompt="Recent USGS earthquake reports"', false)
        ->assertSee('href="https://earthquake.phivolcs.dost.gov.ph/"', false)
        ->assertSee('href="https://www.pagasa.dost.gov.ph/"', false)
        ->assertDontSee('name="barangay_id"', false)
        ->assertDontSee('name="language"', false)
        ->assertDontSee('16.4023')
        ->assertDontSee('server-test-token');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8001/api/v1/models'
        && $request->hasHeader('Authorization', 'Bearer server-test-token'));
});

test('a greeting is rendered without an evidence warning', function () {
    fakeChat([
        'answer' => 'Hi! Want help with a go-bag?',
        'response_type' => 'general_information',
        'evidence_status' => 'not_applicable',
    ]);

    $this->followingRedirects()->post('/chat', ['message' => 'Hello'])
        ->assertOk()
        ->assertSee('Hi! Want help with a go-bag?')
        ->assertDontSee('Insufficient evidence')
        ->assertDontSee('Out of scope');
});

test('chat enables web search by default, forwards optional location without persisting coordinates, and drops barangay_id', function () {
    fakeChat(['answer' => 'Pack water and a flashlight.']);

    $response = $this->post('/chat', $this->chatInput + [
        'barangay_id' => '1430300050',
        'latitude' => 16.4023,
        'longitude' => 120.596,
        'accuracy' => 15,
    ]);

    $payload = lastChatPayload();
    expect($payload['message'])->toBe('How do I prepare?')
        ->and($payload['language'])->toBe('auto')
        ->and($payload['response_detail'])->toBe('standard')
        ->and($payload['conversation_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($payload['allow_web_search'])->toBeTrue()
        ->and($payload['auto_web_search'])->toBeTrue()
        ->and($payload['history'])->toBe([])
        ->and($payload['temperature'])->toBe(0.4)
        ->and($payload['top_p'])->toBe(0.9)
        ->and($payload['max_tokens'])->toBe(512)
        ->and($payload['num_ctx'])->toBe(4096)
        ->and($payload['location'])->toBe([
            'latitude' => 16.4023,
            'longitude' => 120.596,
            'accuracy' => 15.0,
        ])
        ->and(array_key_exists('barangay_id', $payload))->toBeFalse();

    $response->assertStatus(303)->assertRedirect(route('chat.show', ['conversation' => $payload['conversation_id']]));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer server-test-token'));

    $this->get(route('chat.show', ['conversation' => $payload['conversation_id']]))
        ->assertOk()
        ->assertSee('How do I prepare?')
        ->assertSee('Pack water and a flashlight.')
        ->assertSee('llama3.1:8b')
        ->assertSee('name="conversation_id" value="'.$payload['conversation_id'].'"', false)
        ->assertDontSee('16.4023')
        ->assertDontSee('120.596')
        ->assertDontSee('1430300050')
        ->assertDontSee('server-test-token');

    $stored = json_encode(session('sandig.conversations'));
    expect($stored)->not->toContain('16.4023')->not->toContain('120.596')->not->toContain('1430300050')->not->toContain('latitude');
});

test('legacy language and response detail values are still forwarded', function (string $language) {
    fakeChat();

    $this->post('/chat', ['message' => 'Paano maghanda?', 'language' => $language, 'response_detail' => 'brief'])->assertStatus(303);

    expect(lastChatPayload())->toMatchArray(['language' => $language, 'response_detail' => 'brief']);
})->with(['en', 'fil', 'auto']);

test('follow-up reuses the conversation id and forwards earlier turns as conversation memory', function () {
    fakeChat(['answer' => 'First answer about water storage.']);
    $this->post('/chat', ['message' => 'First question?'])->assertStatus(303);
    $id = lastChatPayload()['conversation_id'];

    $this->post('/chat', ['message' => 'Second question?', 'conversation_id' => $id])
        ->assertRedirect(route('chat.show', ['conversation' => $id]));

    $payload = lastChatPayload();
    expect($payload['conversation_id'])->toBe($id)
        ->and($payload['message'])->toBe('Second question?')
        ->and($payload['history'])->toBe([
            ['role' => 'user', 'content' => 'First question?'],
            ['role' => 'assistant', 'content' => 'First answer about water storage.'],
        ]);

    $this->get(route('chat.show', ['conversation' => $id]))
        ->assertOk()
        ->assertSeeInOrder(['First question?', 'First answer about water storage.', 'Second question?']);
});

test('web search switch off disables web search and is remembered until turned back on', function () {
    fakeChat();

    $this->post('/chat', $this->chatInput + ['web_off' => '1'])->assertStatus(303);
    expect(lastChatPayload())->toMatchArray(['allow_web_search' => false, 'auto_web_search' => false]);

    $this->get('/')
        ->assertOk()
        ->assertSee('id="web-search-state" class="font-semibold">Off', false);
    expect(session('sandig.settings')['web_off'])->toBeTrue();

    $this->post('/chat', $this->chatInput)->assertStatus(303);
    expect(lastChatPayload())->toMatchArray(['allow_web_search' => false, 'auto_web_search' => false]);

    $this->post('/chat', $this->chatInput + ['web_off' => '0'])->assertStatus(303);
    expect(lastChatPayload())->toMatchArray(['allow_web_search' => true, 'auto_web_search' => true]);
    expect(session('sandig.settings')['web_off'])->toBeFalse();
});

test('streaming endpoint proxies NDJSON tokens, emits conversation metadata, and persists the reply', function () {
    $ndjson = implode("\n", [
        json_encode([
            'type' => 'meta',
            'response_type' => 'preparedness',
            'evidence_status' => 'not_applicable',
            'citations' => [],
            'web_search_used' => false,
            'language' => 'en',
            'checked_at' => null,
            'model' => 'llama3.1:8b',
        ]),
        json_encode(['type' => 'token', 'text' => 'Pack water ']),
        json_encode(['type' => 'token', 'text' => 'and a flashlight.']),
        json_encode(['type' => 'done', 'answer' => 'Pack water and a flashlight.']),
    ])."\n";

    Http::fake([
        '127.0.0.1:8001/api/v1/models' => Http::response(modelsReply()),
        '127.0.0.1:8001/api/v1/chat/stream' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
    ]);

    $response = $this->post('/chat/stream', [
        'message' => 'What goes in a kit?',
        'latitude' => 16.41,
        'longitude' => 120.59,
    ]);

    $response->assertOk();
    $lines = array_values(array_filter(array_map('trim', explode("\n", $response->streamedContent()))));
    $events = array_map(fn (string $line): array => json_decode($line, true), $lines);
    $types = array_column($events, 'type');

    expect($types)->toBe(['conversation', 'meta', 'token', 'token', 'done'])
        ->and($events[0]['title'])->toBe('What goes in a kit?')
        ->and($events[0]['delete_url'])->toBe(route('chat.destroy', ['conversation' => $events[0]['id']]))
        ->and($events[1]['model'])->toBe('llama3.1:8b')
        ->and($events[2]['text'])->toBe('Pack water ')
        ->and($events[3]['text'])->toBe('and a flashlight.')
        ->and($events[4]['answer'])->toBe('Pack water and a flashlight.');

    $conversationId = $events[0]['id'];
    $stored = session('sandig.conversations');
    expect($stored)->toHaveCount(1)
        ->and($stored[0]['id'])->toBe($conversationId)
        ->and($stored[0]['messages'][0]['content'])->toBe('What goes in a kit?')
        ->and($stored[0]['messages'][1]['content'])->toBe('Pack water and a flashlight.')
        ->and($stored[0]['messages'][1]['model'])->toBe('llama3.1:8b')
        ->and(json_encode($stored))->not->toContain('16.41')->not->toContain('120.59');
});

test('streaming endpoint fails cleanly on invalid input or backend error', function () {
    Http::fake(['127.0.0.1:8001/api/v1/chat/stream' => Http::response('boom', 503)]);

    $this->post('/chat/stream', ['message' => ''])->assertUnprocessable()->assertJsonStructure(['detail']);
    $this->post('/chat/stream', ['message' => 'Hello'])->assertServiceUnavailable()->assertJsonStructure(['detail']);
    expect(session('sandig.conversations'))->toBeNull();
});

test('streaming endpoint respects web_off=1 and disables upstream web search', function () {
    $ndjson = implode("\n", [
        json_encode([
            'type' => 'meta',
            'response_type' => 'general_information',
            'evidence_status' => 'not_applicable',
            'citations' => [],
            'web_search_used' => false,
            'language' => 'en',
            'checked_at' => null,
            'model' => 'llama3.1:8b',
        ]),
        json_encode(['type' => 'token', 'text' => 'Offline reply.']),
        json_encode(['type' => 'done', 'answer' => 'Offline reply.']),
    ])."\n";

    Http::fake([
        '127.0.0.1:8001/api/v1/models' => Http::response(modelsReply()),
        '127.0.0.1:8001/api/v1/chat/stream' => Http::response($ndjson, 200, ['Content-Type' => 'application/x-ndjson']),
    ]);

    $response = $this->post('/chat/stream', ['message' => 'Tell me about Baguio', 'web_off' => '1']);
    $response->assertOk();
    $response->streamedContent();

    $recorded = Http::recorded(fn (Request $r): bool => $r->url() === 'http://127.0.0.1:8001/api/v1/chat/stream');
    $sent = $recorded->last()[0]->data();

    expect($sent['allow_web_search'])->toBeFalse()
        ->and($sent['auto_web_search'])->toBeFalse()
        ->and(session('sandig.settings')['web_off'])->toBeTrue();
});

test('conversations are isolated to the session that created them', function () {
    fakeChat();
    $this->post('/chat', $this->chatInput)->assertStatus(303);
    $id = lastChatPayload()['conversation_id'];
    $chatRequests = Http::recorded(fn (Request $r): bool => $r->url() === 'http://127.0.0.1:8001/api/v1/chat')->count();

    $this->flushSession();

    $this->get(route('chat.show', ['conversation' => $id]))->assertNotFound();
    $this->post('/chat', $this->chatInput + ['conversation_id' => $id])->assertNotFound();
    $this->delete(route('chat.destroy', ['conversation' => $id]))->assertNotFound();
    $this->get('/')->assertOk()->assertDontSee($id);

    expect(Http::recorded(fn (Request $r): bool => $r->url() === 'http://127.0.0.1:8001/api/v1/chat')->count())->toBe($chatRequests);
});

test('chats can be deleted individually or all at once with DELETE forms', function () {
    fakeChat();
    $this->post('/chat', ['message' => 'Keep me?'])->assertStatus(303);
    $first = lastChatPayload()['conversation_id'];
    $this->post('/chat', ['message' => 'Delete me?'])->assertStatus(303);
    $second = lastChatPayload()['conversation_id'];

    $this->get('/')
        ->assertSee('action="'.route('chat.destroy', ['conversation' => $second]).'"', false)
        ->assertSee('name="_method" value="DELETE"', false);

    $this->delete(route('chat.destroy', ['conversation' => $second]))->assertStatus(303)->assertRedirect(route('chat.index'));
    $this->get('/')->assertSee('Chat deleted.')->assertSee('Keep me?')->assertDontSee('Delete me?');
    $this->get(route('chat.show', ['conversation' => $second]))->assertNotFound();

    $this->delete(route('chat.destroyAll'))->assertStatus(303);
    $this->get('/')->assertSee('All chats deleted.')->assertDontSee('Keep me?');
    $this->get(route('chat.show', ['conversation' => $first]))->assertNotFound();
});

test('history retention is bounded by conversation count, message count, and age', function () {
    fakeChat();

    for ($i = 1; $i <= ConversationStore::MAX_CONVERSATIONS + 2; $i++) {
        $this->travel(10)->seconds();
        $this->post('/chat', ['message' => "Question {$i}"])->assertStatus(303);
    }
    expect(session('sandig.conversations'))->toHaveCount(ConversationStore::MAX_CONVERSATIONS);

    $id = lastChatPayload()['conversation_id'];
    for ($i = 0; $i < ConversationStore::MAX_MESSAGES; $i++) {
        $this->travel(10)->seconds();
        $this->post('/chat', ['message' => "Follow-up {$i}", 'conversation_id' => $id])->assertStatus(303);
    }
    $conversation = collect(session('sandig.conversations'))->firstWhere('id', $id);
    expect($conversation['messages'])->toHaveCount(ConversationStore::MAX_MESSAGES);

    $this->travel(ConversationStore::MAX_AGE_SECONDS + 60)->seconds();
    $this->get(route('chat.show', ['conversation' => $id]))->assertNotFound();
    expect(session('sandig.conversations'))->toBe([]);
});

test('settings update LM Studio model parameters and forward them for later questions', function () {
    fakeChat();

    $this->post(route('chat.settings'), [
        'response_detail' => 'detailed',
        'model' => 'gemma3:4b',
        'temperature' => '0.75',
        'top_p' => '0.85',
        'max_tokens' => '1024',
        'num_ctx' => '8192',
        'system_prompt' => 'Speak concisely with numbered steps.',
    ])->assertStatus(303)->assertRedirect(route('chat.index'));

    $this->get('/')->assertSee('Model &amp; chat settings saved.', false);

    $this->post('/chat', $this->chatInput)->assertStatus(303);
    expect(lastChatPayload())->toMatchArray([
        'response_detail' => 'detailed',
        'model' => 'gemma3:4b',
        'temperature' => 0.75,
        'top_p' => 0.85,
        'max_tokens' => 1024,
        'num_ctx' => 8192,
        'system_prompt' => 'Speak concisely with numbered steps.',
    ]);

    $this->post(route('chat.settings'), ['response_detail' => 'invalid', 'temperature' => '99'])->assertStatus(303);
    expect(session('sandig.settings')['detail'])->toBe('detailed')
        ->and(session('sandig.settings')['temperature'])->toBe(0.75);
});

test('supported and partially supported responses render escaped citations and https links only', function (string $evidenceStatus, string $expectedHeading) {
    fakeChat([
        'answer' => 'Prepare an emergency go-bag <script>alert(1)</script>.',
        'evidence_status' => $evidenceStatus,
        'citations' => [
            [
                'kind' => 'indexed_document',
                'source_id' => 'ndrrmc-bag-2025',
                'version' => '2.0',
                'title' => '<b>Family Preparedness Guide</b>',
                'publisher' => 'NDRRMC',
                'url' => 'https://ndrrmc.gov.ph/guide?lang=en&ref=1',
                'page' => 14,
                'section' => 'Go-Bag Checklist',
                'publication_date' => '2025-03-01',
                'observed_at' => null,
                'passage' => 'Pack water and flashlight <img src=x onerror=alert(1)>.',
            ],
            [
                'kind' => 'indexed_document',
                'source_id' => 'unsafe-link-doc',
                'version' => '1.0',
                'title' => 'Unsafe Link Source',
                'publisher' => 'Agency',
                'url' => 'javascript:alert(1)',
                'page' => 2,
                'section' => null,
                'publication_date' => null,
                'observed_at' => null,
                'passage' => null,
            ],
            [
                'kind' => 'indexed_document',
                'source_id' => 'http-link-doc',
                'version' => '1.0',
                'title' => 'HTTP Link Source',
                'publisher' => 'Agency',
                'url' => 'http://insecure.example.com/doc',
                'page' => null,
                'section' => null,
                'publication_date' => null,
                'observed_at' => null,
                'passage' => null,
            ],
        ],
    ]);

    $this->followingRedirects()->post('/chat', ['message' => 'What goes in a <i>go-bag</i>?'])
        ->assertOk()
        ->assertSee($expectedHeading)
        ->assertSee('Cited sources (3)')
        ->assertSee('What goes in a <i>go-bag</i>?')
        ->assertDontSee('What goes in a <i>go-bag</i>?', false)
        ->assertSee('Prepare an emergency go-bag <script>alert(1)</script>.')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('<b>Family Preparedness Guide</b>')
        ->assertDontSee('<b>Family Preparedness Guide</b>', false)
        ->assertSee('Indexed document')
        ->assertSee('NDRRMC')
        ->assertSee('ndrrmc-bag-2025')
        ->assertSee('2.0')
        ->assertSee('Go-Bag Checklist')
        ->assertSee('14')
        ->assertSee('2025-03-01')
        ->assertSee('Pack water and flashlight <img src=x onerror=alert(1)>.')
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('href="https://ndrrmc.gov.ph/guide?lang=en&amp;ref=1"', false)
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertDontSee('javascript:alert(1)', false)
        ->assertDontSee('http://insecure.example.com/doc', false)
        ->assertDontSee('server-test-token');
})->with([
    'supported' => ['supported', 'Supported by approved sources'],
    'partially_supported' => ['partially_supported', 'Partially supported'],
]);

test('emergency response is announced as an alert with a call action and open references', function () {
    fakeChat([
        'answer' => 'Backend emergency notice text.',
        'response_type' => 'emergency',
        'evidence_status' => 'emergency',
        'citations' => [
            [
                'kind' => 'indexed_document',
                'source_id' => null,
                'version' => null,
                'title' => 'Baguio Emergency Reference',
                'publisher' => 'City DRRMO',
                'url' => 'https://baguio.gov.ph/emergency',
                'page' => null,
                'section' => 'Emergency Contacts',
                'publication_date' => null,
                'observed_at' => null,
                'passage' => 'Curated reference excerpt.',
            ],
            [
                'kind' => 'indexed_document',
                'source_id' => 'baguio-evac-2025',
                'version' => '1.0',
                'title' => 'Indexed Evacuation Manual',
                'publisher' => 'City DRRMO',
                'url' => null,
                'page' => 5,
                'section' => null,
                'publication_date' => null,
                'observed_at' => null,
                'passage' => null,
            ],
        ],
    ]);

    $response = $this->post('/chat', ['message' => 'My house is flooding now']);
    $id = lastChatPayload()['conversation_id'];
    $response->assertRedirect(route('chat.show', ['conversation' => $id]));

    $this->get(route('chat.show', ['conversation' => $id]))
        ->assertOk()
        ->assertSee('role="alert" aria-live="assertive"', false)
        ->assertSee('Emergency')
        ->assertSee('Call 911')
        ->assertSee('References (2)')
        ->assertSee('Backend emergency notice text.')
        ->assertSee('Curated emergency reference')
        ->assertSee('Indexed document')
        ->assertSee('Baguio Emergency Reference')
        ->assertSee('Curated reference excerpt.')
        ->assertSee('href="https://baguio.gov.ph/emergency"', false)
        ->assertDontSee('server-test-token');

    $this->get(route('chat.show', ['conversation' => $id]))
        ->assertOk()
        ->assertDontSee('aria-live="assertive"', false)
        ->assertSee('Backend emergency notice text.');
});

test('out of scope response renders safely without claiming cited evidence', function () {
    fakeChat([
        'answer' => 'This question is outside disaster preparedness.',
        'response_type' => 'refusal',
        'evidence_status' => 'out_of_scope',
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()
        ->assertSee('Out of scope')
        ->assertSee('This question is outside disaster preparedness.')
        ->assertDontSee('Cited sources')
        ->assertDontSee('server-test-token');
});

test('insufficient evidence with fallback links labels them as not evidence and supports curated guidance', function () {
    fakeChat([
        'answer' => '<strong>Not verified</strong>',
        'response_type' => 'refusal',
        'evidence_status' => 'insufficient_evidence',
        'citations' => [[
            'kind' => 'curated_guidance',
            'source_id' => 'curated-go-bag',
            'version' => '1',
            'title' => 'General Go-Bag Guidance',
            'publisher' => 'SANDIG reviewed guidance',
            'url' => 'https://www.pagasa.dost.gov.ph/',
            'page' => null,
            'section' => null,
            'publication_date' => null,
            'observed_at' => null,
            'passage' => null,
        ]],
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()
        ->assertSee('Insufficient evidence')
        ->assertSee('Related links (not evidence for this answer) (1)')
        ->assertSee('Curated general guidance')
        ->assertSee('General Go-Bag Guidance')
        ->assertDontSee('Cited sources')
        ->assertSee('<strong>Not verified</strong>')
        ->assertDontSee('<strong>Not verified</strong>', false);
});

test('a bundled preparedness citation is displayed separately from indexed and emergency sources', function () {
    fakeChat([
        'evidence_status' => 'partially_supported',
        'citations' => [[
            'kind' => 'curated_guidance',
            'title' => 'Emergency Supply Kit',
            'publisher' => 'FEMA Ready Campaign',
            'url' => 'https://www.ready.gov/build-a-kit',
        ]],
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()->assertSee('Curated general guidance')->assertDontSee('Curated emergency reference');
});

test('invalid input is neither displayed nor forwarded', function () {
    Http::fake();

    $this->post('/chat', ['message' => 'private question', 'language' => 'invalid'])
        ->assertUnprocessable()->assertSee('Unable to submit question')->assertDontSee('private question')->assertDontSee('server-test-token');
    $this->post('/chat', ['message' => 'private question', 'conversation_id' => 'not-a-uuid'])
        ->assertUnprocessable()->assertDontSee('private question');
    $this->post('/chat', ['message' => ''])->assertUnprocessable();

    Http::assertNothingSent();
});

test('missing service token fails visibly without a backend request', function () {
    config(['services.sandig.token' => '']);
    Http::fake();

    $this->post('/chat', $this->chatInput)->assertServiceUnavailable()->assertSee('SANDIG cannot answer right now');
    Http::assertNothingSent();
});

test('unavailable or malformed upstream replies fail closed with a generic 503', function (int $status, array $body) {
    Http::fake(['127.0.0.1:8001/api/v1/chat' => Http::response($body, $status)]);

    $this->post('/chat', $this->chatInput + ['web_off' => '1'])
        ->assertServiceUnavailable()
        ->assertSee('SANDIG cannot answer right now')
        ->assertDontSee('upstream detail')
        ->assertDontSee('server-test-token');

    expect(session('sandig.conversations'))->toBeNull();
})->with([
    'incomplete 200' => [200, ['answer' => 'upstream detail']],
    'redirect' => [302, ['answer' => 'upstream detail']],
    'unauthorized' => [401, ['answer' => 'upstream detail']],
    'server error' => [500, ['answer' => 'upstream detail']],
    'unknown evidence status' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'invented_status'])],
    'unknown response type' => [200, chatReply(['answer' => 'upstream detail', 'response_type' => 'invented_type'])],
    'emergency status mismatch' => [200, chatReply(['answer' => 'upstream detail', 'response_type' => 'refusal', 'evidence_status' => 'emergency'])],
    'web search used while web is off' => [200, chatReply(['answer' => 'upstream detail', 'web_search_used' => true])],
    'supported without citations' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'supported'])],
    'partially supported without citations' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'partially_supported'])],
    'web citation without web search' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'supported', 'citations' => [['kind' => 'web_search', 'title' => 'Web Page', 'publisher' => 'Example']]])],
    'unknown citation kind' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'supported', 'citations' => [['kind' => 'invented_kind', 'title' => 'Unknown', 'publisher' => 'Example']]])],
    'invalid citation page' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'supported', 'citations' => [['kind' => 'indexed_document', 'source_id' => 'doc-1', 'title' => 'Guide', 'publisher' => 'Agency', 'page' => 0]]])],
    'citation missing publisher' => [200, chatReply(['answer' => 'upstream detail', 'evidence_status' => 'supported', 'citations' => [['kind' => 'indexed_document', 'source_id' => 'doc-1', 'title' => 'Guide', 'publisher' => '']]])],
]);

test('an upstream search result is rejected on routes that must not search', function (string $type, string $status) {
    fakeChat(['response_type' => $type, 'evidence_status' => $status, 'web_search_used' => true]);

    $this->post('/chat', $this->chatInput)->assertServiceUnavailable();
    expect(session('sandig.conversations'))->toBeNull();
})->with([
    ['emergency', 'emergency'],
    ['refusal', 'out_of_scope'],
]);

test('connection failure is visible, keeps the question for retry, and does not save it', function () {
    Http::fake(['127.0.0.1:8001/api/v1/chat' => Http::failedConnection('private transport detail')]);

    $this->post('/chat', $this->chatInput)
        ->assertServiceUnavailable()
        ->assertSee('role="alert"', false)
        ->assertSee('>How do I prepare?</textarea>', false)
        ->assertSee('It was not saved to your chat history.')
        ->assertDontSee('private transport detail')
        ->assertDontSee('server-test-token');

    expect(session('sandig.conversations'))->toBeNull();
});

test('official and supplementary live feeds render distinctly', function () {
    fakeChat([
        'answer' => 'Latest observations summary.',
        'response_type' => 'live_update',
        'evidence_status' => 'partially_supported',
        'citations' => [
            [
                'kind' => 'official_live',
                'source_id' => null,
                'version' => null,
                'title' => 'PAGASA Tropical Cyclone Bulletin',
                'publisher' => 'PAGASA',
                'url' => 'https://bagong.pagasa.dost.gov.ph/bulletin',
                'page' => null,
                'section' => 'Northern Luzon',
                'publication_date' => null,
                'observed_at' => '2026-11-23T14:00:00Z',
                'passage' => 'Signal No. 1 advisory text.',
            ],
            [
                'kind' => 'supplementary_live',
                'source_id' => null,
                'version' => null,
                'title' => 'Open-Meteo Rainfall Reading',
                'publisher' => 'Open-Meteo',
                'url' => 'https://open-meteo.com/baguio',
                'page' => null,
                'section' => null,
                'publication_date' => null,
                'observed_at' => '2026-11-23T14:05:00Z',
                'passage' => 'Observed rainfall 12mm/hr.',
            ],
        ],
        'checked_at' => '2026-11-23T14:10:00Z',
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()
        ->assertSee('Checked: 2026-11-23T14:10:00Z')
        ->assertSee('Official live feed')
        ->assertSee('2026-11-23T14:00:00Z')
        ->assertSee('href="https://bagong.pagasa.dost.gov.ph/bulletin"', false)
        ->assertSee('Supplementary live feed')
        ->assertSee('2026-11-23T14:05:00Z')
        ->assertSee('href="https://open-meteo.com/baguio"', false)
        ->assertDontSee('External web search contacted for this request:');
});

test('external search results are labelled and escaped', function () {
    fakeChat([
        'citations' => [
            [
                'kind' => 'web_search',
                'source_id' => null,
                'version' => null,
                'title' => '<b>External News Link</b>',
                'publisher' => 'Example News',
                'url' => 'http://insecure-web-result.example.com/article',
                'page' => null,
                'section' => null,
                'publication_date' => null,
                'observed_at' => null,
                'passage' => 'External snippet <script>alert(1)</script>.',
            ],
        ],
        'web_search_used' => true,
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput + ['web_off' => '0'])
        ->assertOk()
        ->assertSee('External web search contacted for this request:')
        ->assertSee('external links/search results, not verified guidance')
        ->assertSee('External web search')
        ->assertSee('<b>External News Link</b>')
        ->assertDontSee('<b>External News Link</b>', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('http://insecure-web-result.example.com/article', false)
        ->assertDontSee('server-test-token');
});

test('the answer language is marked on the rendered response', function () {
    fakeChat(['answer' => 'Maghanda ng inuming tubig.', 'language' => 'fil']);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()
        ->assertSee('lang="fil"', false)
        ->assertSee('Maghanda ng inuming tubig.');
});

test('automatic web search may report web_search_used with the external notice', function () {
    fakeChat([
        'answer' => 'No verified or external web results were found.',
        'response_type' => 'refusal',
        'web_search_used' => true,
        'checked_at' => '2026-11-23T14:15:00Z',
    ]);

    $this->followingRedirects()->post('/chat', $this->chatInput)
        ->assertOk()
        ->assertSee('External web search contacted for this request:')
        ->assertSee('Checked: 2026-11-23T14:15:00Z')
        ->assertDontSee('server-test-token');
});

test('chat rate limit stops additional backend requests', function () {
    Http::fake(['127.0.0.1:8001/api/v1/chat' => Http::response('', 503)]);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->post('/chat', $this->chatInput)->assertServiceUnavailable();
    }
    $this->post('/chat', $this->chatInput)->assertTooManyRequests();
    Http::assertSentCount(20);
});
