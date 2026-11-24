<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.sandig.token' => 'server-test-token']);
    Http::preventStrayRequests();
});

test('chat page links to the approved sources catalog without leaking the token', function () {
    Http::fake(['127.0.0.1:8001/api/v1/models' => Http::response([
        'default_model' => 'llama3.1:8b',
        'models' => [['name' => 'llama3.1:8b', 'size' => 0]],
    ])]);

    $this->get('/')
        ->assertOk()
        ->assertSee(route('sources.index'), false)
        ->assertSee('Approved sources')
        ->assertDontSee('server-test-token');
});

test('sources catalog requests default limit 25 with bearer token and renders source metadata', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response([
        'items' => [[
            'source_id' => 'phivolcs-eq-2025',
            'title' => 'Earthquake Preparedness Guide',
            'publisher' => 'PHIVOLCS',
            'url' => 'https://www.phivolcs.dost.gov.ph/earthquake-guide',
            'publication_date' => '2025-02-15',
            'retrieval_date' => '2026-01-10',
            'jurisdiction' => 'Baguio City',
            'hazards' => ['earthquake', 'landslide'],
            'version' => '1.2',
            'verification_status' => 'approved',
            'usage_restrictions' => 'Public educational use only.',
        ]],
        'next_cursor' => 'opaque_cursor_page_2',
    ])]);

    $response = $this->get('/sources');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Approved Sources')
        ->assertSee('Earthquake Preparedness Guide')
        ->assertSee('PHIVOLCS')
        ->assertSee('phivolcs-eq-2025')
        ->assertSee('1.2')
        ->assertSee('approved')
        ->assertSee('Baguio City')
        ->assertSee('2025-02-15')
        ->assertSee('2026-01-10')
        ->assertSee('earthquake')
        ->assertSee('landslide')
        ->assertSee('Public educational use only.')
        ->assertSee('href="https://www.phivolcs.dost.gov.ph/earthquake-guide"', false)
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertSee('opaque_cursor_page_2')
        ->assertSee('Next page')
        ->assertDontSee('First page')
        ->assertDontSee('server-test-token');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:8001/api/v1/sources?limit=25'
        && $request->hasHeader('Authorization', 'Bearer server-test-token')
        && $request->hasHeader('Accept', 'application/json'));
});

test('sources catalog forwards only validated limit and opaque cursor', function () {
    $cursor = 'eyJvZmZzZXQiOjEwfQ==_opaque';

    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response([
        'items' => [],
        'next_cursor' => null,
    ])]);

    $this->get('/sources?'.http_build_query([
        'limit' => 10,
        'cursor' => $cursor,
        'admin' => 'true',
        'extra' => 'drop-me',
    ]))
        ->assertOk()
        ->assertSee('No approved sources available.')
        ->assertSee('Return to first page')
        ->assertDontSee('server-test-token');

    Http::assertSent(function (Request $request) use ($cursor): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'http://127.0.0.1:8001/api/v1/sources?')
            && $query === ['limit' => '10', 'cursor' => $cursor]
            && $request->hasHeader('Authorization', 'Bearer server-test-token');
    });
});

test('sources catalog renders empty state cleanly', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response([
        'items' => [],
        'next_cursor' => null,
    ])]);

    $this->get('/sources')
        ->assertOk()
        ->assertSee('No approved sources available.')
        ->assertDontSee('Next page')
        ->assertDontSee('server-test-token');
});

test('sources catalog escapes untrusted text and renders valid https links only', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response([
        'items' => [
            [
                'source_id' => '<b>src-1</b>',
                'title' => '<script>alert(1)</script>Guide',
                'publisher' => '<img src=x onerror=alert(1)>Agency',
                'url' => 'javascript:alert(1)',
                'publication_date' => null,
                'retrieval_date' => null,
                'jurisdiction' => '<em>Baguio</em>',
                'hazards' => ['<svg onload=alert(1)>flood'],
                'version' => '<i>1.0</i>',
                'verification_status' => 'approved',
                'usage_restrictions' => '<u>Restricted</u>',
            ],
            [
                'source_id' => 'src-http',
                'title' => 'HTTP Only Source',
                'publisher' => 'Agency',
                'url' => 'http://insecure.example.com/doc',
                'version' => '1.0',
                'verification_status' => 'approved',
            ],
            [
                'source_id' => 'src-https',
                'title' => 'Valid HTTPS Source',
                'publisher' => 'Agency',
                'url' => 'https://baguio.gov.ph/preparedness?doc=1&lang=en',
                'version' => '1.0',
                'verification_status' => 'approved',
            ],
        ],
        'next_cursor' => null,
    ])]);

    $this->get('/sources')
        ->assertOk()
        ->assertSee('<script>alert(1)</script>Guide')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('<img src=x onerror=alert(1)>Agency')
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('<svg onload=alert(1)>flood')
        ->assertDontSee('<svg onload=alert(1)>', false)
        ->assertDontSee('javascript:alert(1)', false)
        ->assertDontSee('http://insecure.example.com/doc', false)
        ->assertSee('href="https://baguio.gov.ph/preparedness?doc=1&amp;lang=en"', false)
        ->assertDontSee('server-test-token');
});

test('invalid pagination parameters are rejected without calling the backend or echoing raw input', function (array $params, string $rawMarker) {
    $this->get('/sources?'.http_build_query($params))
        ->assertUnprocessable()
        ->assertSee('Invalid catalog page parameters.')
        ->assertDontSee($rawMarker)
        ->assertDontSee('server-test-token');

    Http::assertNothingSent();
})->with([
    'limit zero' => [['limit' => 0], 'limit=0'],
    'limit over max' => [['limit' => 101], '101'],
    'non-integer limit' => [['limit' => 'bad-limit-value'], 'bad-limit-value'],
    'cursor too long' => [['cursor' => str_repeat('a', 513)], str_repeat('a', 32)],
]);

test('upstream 422 returns a generic 422 without leaking upstream details', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response(['detail' => 'internal cursor parse trace'], 422)]);

    $this->get('/sources?cursor=tampered-cursor')
        ->assertUnprocessable()
        ->assertSee('Invalid catalog page parameters.')
        ->assertDontSee('internal cursor parse trace')
        ->assertDontSee('tampered-cursor')
        ->assertDontSee('server-test-token');
});

test('missing service token fails with 503 without a backend request', function () {
    config(['services.sandig.token' => '']);

    $this->get('/sources')
        ->assertServiceUnavailable()
        ->assertSee('The approved source catalog is temporarily unavailable.');

    Http::assertNothingSent();
});

test('unavailable or unexpected upstream replies show a generic 503 error', function (int $status, array $body) {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response($body, $status)]);

    $this->get('/sources')
        ->assertServiceUnavailable()
        ->assertSee('The approved source catalog is temporarily unavailable.')
        ->assertDontSee('upstream secret detail')
        ->assertDontSee('server-test-token');
})->with([
    'redirect' => [302, ['detail' => 'upstream secret detail']],
    'unauthorized' => [401, ['detail' => 'upstream secret detail']],
    'server error' => [500, ['detail' => 'upstream secret detail']],
    'service unavailable' => [503, ['detail' => 'upstream secret detail']],
    'missing items key' => [200, ['next_cursor' => null, 'detail' => 'upstream secret detail']],
    'missing next_cursor key' => [200, ['items' => [], 'detail' => 'upstream secret detail']],
    'unapproved source' => [200, [
        'items' => [[
            'source_id' => 'draft-1',
            'title' => 'upstream secret detail',
            'publisher' => 'Agency',
            'version' => '1.0',
            'verification_status' => 'pending',
        ]],
        'next_cursor' => null,
    ]],
]);

test('connection failure does not leak transport details or token', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::failedConnection('private transport detail')]);

    $this->get('/sources')
        ->assertServiceUnavailable()
        ->assertSee('The approved source catalog is temporarily unavailable.')
        ->assertDontSee('private transport detail')
        ->assertDontSee('server-test-token');
});

test('sources catalog rate limit stops additional backend requests', function () {
    Http::fake(['127.0.0.1:8001/api/v1/sources*' => Http::response(['items' => [], 'next_cursor' => null])]);

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->get('/sources')->assertOk();
    }

    $this->get('/sources')->assertTooManyRequests();
    Http::assertSentCount(30);
});

test('sources catalog shares the unified chat sidebar shell and displays session chats', function () {
    Http::fake([
        '127.0.0.1:8001/api/v1/chat' => Http::response([
            'answer' => 'Pack water and flashlight.',
            'response_type' => 'preparedness',
            'evidence_status' => 'not_applicable',
            'citations' => [],
            'web_search_used' => false,
            'checked_at' => null,
            'model' => 'llama3.1:8b',
        ]),
        '127.0.0.1:8001/api/v1/sources*' => Http::response([
            'items' => [],
            'next_cursor' => null,
        ]),
    ]);

    $this->post('/chat', ['message' => 'What goes in a Baguio go-bag?'])->assertStatus(303);

    $this->get('/sources')
        ->assertOk()
        ->assertSee('id="chat-drawer"', false)
        ->assertSee('New chat')
        ->assertSee('Recent chats')
        ->assertSee('What goes in a Baguio go-bag?')
        ->assertSee('aria-current="page"', false)
        ->assertSee('href="tel:911"', false)
        ->assertDontSee('server-test-token');
});
