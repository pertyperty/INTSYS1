import hashlib
import hmac
import json
import os
import re
from datetime import date, datetime, timedelta, timezone
from secrets import compare_digest
from typing import Annotated, Literal
from uuid import UUID

import httpx
import psycopg
from fastapi import Depends, FastAPI, HTTPException, Query, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse, StreamingResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
from pydantic import BaseModel, ConfigDict, Field, StrictBool, ValidationError

from sandig.catalog import CatalogError, HttpsUrl, SOURCE_ID, Source, list_sources
from sandig.chat_evidence import find_passages
from sandig.curated_guidance import preparedness_guidance
from sandig.external import PAGASA, PHIVOLCS, ExternalInformation, requests_usgs
from sandig.locations import BarangayCatalog, load_barangays
from sandig.rag_chat import RagChatEngine
from sandig.safety import WEATHER_QUERY, barangay_pattern, classify, detect_language, emergency_text


class HealthResponse(BaseModel):
    status: Literal['ok'] = 'ok'


class HistoryMessage(BaseModel):
    model_config = ConfigDict(extra='forbid')

    role: Literal['user', 'assistant']
    content: str = Field(min_length=1, max_length=4000)


class UserLocation(BaseModel):
    model_config = ConfigDict(extra='forbid')

    latitude: float = Field(ge=-90.0, le=90.0)
    longitude: float = Field(ge=-180.0, le=180.0)
    accuracy: float | None = Field(default=None, ge=0.0, le=100000.0)


class ChatRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')

    message: str = Field(min_length=1, max_length=4000, pattern=r'\S')
    conversation_id: UUID | None = None
    barangay_id: Annotated[str, Field(min_length=1, max_length=64)] | None = None
    language: Literal['en', 'fil', 'auto'] = 'en'
    response_detail: Literal['brief', 'standard', 'detailed'] = 'standard'
    allow_web_search: StrictBool = False
    auto_web_search: StrictBool = False
    history: list[HistoryMessage] = Field(default_factory=list, max_length=20)
    model: Annotated[str, Field(min_length=1, max_length=128)] | None = None
    temperature: float | None = Field(default=None, ge=0.0, le=1.5)
    top_p: float | None = Field(default=None, ge=0.1, le=1.0)
    max_tokens: int | None = Field(default=None, ge=64, le=2048)
    num_ctx: int | None = Field(default=None, ge=1024, le=16384)
    system_prompt: str | None = Field(default=None, max_length=2000)
    location: UserLocation | None = None


class Citation(BaseModel):
    kind: Literal['indexed_document', 'official_live', 'supplementary_live', 'web_search', 'curated_guidance']
    source_id: str | None = None
    version: str | None = None
    title: str
    publisher: str
    url: HttpsUrl | None = None
    page: Annotated[int, Field(ge=1)] | None = None
    section: str | None = None
    publication_date: date | None = None
    observed_at: datetime | None = None
    passage: str | None = None


class ChatResponse(BaseModel):
    answer: str = Field(min_length=1)
    language: Literal['en', 'fil']
    response_type: Literal[
        'preparedness', 'hazard_assessment', 'live_update', 'general_information',
        'refusal', 'emergency',
    ]
    evidence_status: Literal[
        'supported', 'partially_supported', 'insufficient_evidence', 'out_of_scope', 'emergency', 'not_applicable',
    ]
    citations: list[Citation]
    web_search_used: bool
    checked_at: datetime | None
    model: str | None = None


class ModelEntry(BaseModel):
    name: str
    size: int = 0


class ModelsResponse(BaseModel):
    default_model: str
    models: list[ModelEntry]


class SourcesResponse(BaseModel):
    items: list[Source]
    next_cursor: str | None


def create_app(service_token: str | None = None, database_url: str | None = None,
               external: ExternalInformation | None = None,
               rag_chat: RagChatEngine | None = None,
               rag_chat_enabled: bool | None = None,
               llm_fallback_enabled: bool | None = None) -> FastAPI:
    expected_token = service_token if service_token is not None else os.getenv('SANDIG_SERVICE_TOKEN', '')
    catalog_url = database_url if database_url is not None else os.getenv('SANDIG_DATABASE_URL', '')
    providers = external if external is not None else ExternalInformation(
        usgs_enabled=os.getenv('SANDIG_ENABLE_USGS', '').lower() == 'true',
        weather_enabled=os.getenv('SANDIG_ENABLE_WEATHER', 'true').lower() == 'true',
        brave_key=os.getenv('SANDIG_BRAVE_SEARCH_KEY', ''),
    )
    rag_enabled = (
        rag_chat_enabled
        if rag_chat_enabled is not None
        else os.getenv('SANDIG_ENABLE_RAG_CHAT', 'true').lower() == 'true'
    )
    ai_chat_enabled = (
        llm_fallback_enabled
        if llm_fallback_enabled is not None
        else os.getenv('SANDIG_ENABLE_LLM_FALLBACK', 'true').lower() == 'true'
    )
    rag_engine = rag_chat if rag_chat is not None else RagChatEngine()
    app = FastAPI(title='SANDIG internal API', version='1.0.0', docs_url=None, redoc_url=None)
    bearer = HTTPBearer(scheme_name='serviceToken', auto_error=False)
    try:
        barangays = load_barangays()
    except (OSError, ValueError):
        barangays = None
    barangay_ids = {entry.id for entry in barangays.barangays} if barangays else set()
    local_places = barangay_pattern([entry.name for entry in barangays.barangays]) if barangays else None

    def require_token(credentials: HTTPAuthorizationCredentials | None = Depends(bearer)) -> None:
        if not expected_token or credentials is None or not compare_digest(
            credentials.credentials.encode('utf-8'), expected_token.encode('utf-8'),
        ):
            raise HTTPException(401, detail='Unauthorized', headers={'WWW-Authenticate': 'Bearer'})

    @app.exception_handler(RequestValidationError)
    async def invalid_request(_request: Request, _error: RequestValidationError) -> JSONResponse:
        return JSONResponse(status_code=422, content={'detail': 'Invalid request'})

    @app.get('/api/v1/health', operation_id='health', response_model=HealthResponse)
    def health() -> HealthResponse:
        return HealthResponse()

    @app.get('/api/v1/models', operation_id='models', response_model=ModelsResponse, dependencies=[Depends(require_token)])
    def models() -> ModelsResponse:
        return ModelsResponse.model_validate(rag_engine.list_chat_models())

    @app.get('/api/v1/locations/barangays', operation_id='barangays', dependencies=[Depends(require_token)],
             responses={401: {'description': 'Unauthorized'}, 503: {'description': 'Barangay catalog unavailable'}})
    def location_catalog() -> BarangayCatalog:
        if barangays is None:
            raise HTTPException(503, detail='Barangay catalog unavailable')
        return barangays

    def prepare_ai_context(payload: ChatRequest):
        intent = classify(payload.message, local_places)
        language = detect_language(payload.message) if payload.language == 'auto' else payload.language
        filipino = language == 'fil'

        # Emergency guardrail always short-circuits immediately (zero LLM latency)
        if intent.route == 'emergency':
            answer, citations = emergency_text(language, intent.hazard)
            return {
                'direct': ChatResponse(
                    answer=answer, language=language, response_type='emergency', evidence_status='emergency',
                    citations=[Citation.model_validate(item) for item in citations],
                    web_search_used=False, checked_at=None, model=None,
                ),
            }

        if payload.barangay_id is not None and barangays is not None and payload.barangay_id not in barangay_ids:
            raise HTTPException(422, detail='Invalid request')

        citations: list[dict] = []
        web_snippets: list[str] = []
        web_used = False
        checked_at: datetime | None = None
        weather_context: str | None = None
        usgs_context: str | None = None
        location_context: str | None = None

        if payload.location is not None:
            lat = round(payload.location.latitude, 4)
            lon = round(payload.location.longitude, 4)
            acc = f', accuracy ±{round(payload.location.accuracy)} m' if payload.location.accuracy is not None else ''
            location_context = f'latitude {lat}, longitude {lon}{acc}'

        # 1. Primary Source: Approved Indexed Documents (RAG)
        passage_limit = {'brief': 2, 'standard': 3, 'detailed': 5}[payload.response_detail]
        if rag_enabled and catalog_url:
            try:
                citations.extend(rag_engine.retrieve_semantic(
                    catalog_url, payload.message, intent.hazard, language, passage_limit, strict_gate=False,
                ))
            except (CatalogError, httpx.HTTPError, psycopg.Error, ValidationError, OSError, RuntimeError, ValueError):
                pass
        if not citations and catalog_url and intent.hazard:
            try:
                citations.extend(find_passages(
                    catalog_url, payload.message, intent.hazard, language,
                    {'brief': 1, 'standard': 2, 'detailed': 3}[payload.response_detail],
                ))
            except (CatalogError, psycopg.Error, ValidationError):
                pass

        # 2. Live Weather Context (when asked or location shared for weather)
        asks_weather = intent.route == 'weather_now' or bool(WEATHER_QUERY.search(payload.message.casefold()))
        if asks_weather:
            lat = payload.location.latitude if payload.location else 16.4023
            lon = payload.location.longitude if payload.location else 120.5960
            place_label = 'your shared browser location' if payload.location else 'Baguio City'
            weather = providers.weather_for(lat, lon)
            if weather is not None:
                checked, timestamp, temperature, precipitation, wind = weather
                checked_at = checked
                local_time = timestamp.astimezone(timezone(timedelta(hours=8))).strftime('%Y-%m-%d %H:%M PHT')
                weather_context = (
                    f'Open-Meteo current observation for {place_label} at {local_time}: '
                    f'{temperature:g} °C, precipitation {precipitation:g} mm, wind {wind:g} km/h. '
                    f'For official Philippine storm advisories, direct users to PAGASA ({PAGASA}).'
                )
                citations.append(dict(
                    kind='supplementary_live', publisher='Open-Meteo',
                    title=f'Model-based weather estimate ({place_label})',
                    url='https://open-meteo.com/', observed_at=timestamp,
                ))

        # 3. Live USGS Earthquakes (if enabled & requested)
        if requests_usgs(payload.message):
            reports = providers.earthquakes()
            if reports is not None:
                checked, eq_citations = reports
                checked_at = checked_at or checked
                citations.extend(eq_citations)
                usgs_context = (
                    'Recent USGS M2.5+ reports (past 24h near Philippines): '
                    + (', '.join(c['title'] for c in eq_citations) if eq_citations else 'None found in the past 24 hours.')
                    + f' For official Philippine bulletins, refer to PHIVOLCS ({PHIVOLCS}).'
                )

        # 4. Web Search (ON by default when allow_web_search or auto_web_search is true!)
        want_web = payload.allow_web_search or payload.auto_web_search
        if want_web and intent.route not in {'greeting', 'check_in', 'thanks'}:
            attempted, links, snippets = providers.web_search(payload.message, language)
            if attempted:
                web_used = True
                citations.extend(links)
                web_snippets.extend(snippets)

        has_approved_docs = any(c['kind'] == 'indexed_document' for c in citations)
        has_live = any(c['kind'] in ('official_live', 'supplementary_live') for c in citations)
        if has_approved_docs:
            evidence_status = 'supported'
            response_type = 'preparedness'
        elif has_live:
            evidence_status = 'partially_supported'
            response_type = 'live_update'
        else:
            evidence_status = 'not_applicable'
            response_type = 'general_information'

        history_dicts = [m.model_dump() for m in payload.history]
        messages = rag_engine.build_chat_messages(
            payload.message,
            history=history_dicts,
            citations=citations,
            weather_context=weather_context,
            usgs_context=usgs_context,
            location_context=location_context,
            web_snippets=web_snippets,
            web_search_enabled=want_web,
            language=language,
            response_detail=payload.response_detail,
            system_prompt=payload.system_prompt,
        )
        options = rag_engine.build_options(
            payload.response_detail,
            temperature=payload.temperature,
            top_p=payload.top_p,
            max_tokens=payload.max_tokens,
            num_ctx=payload.num_ctx,
        )
        active_model = rag_engine.resolve_model(payload.model)

        return {
            'direct': None,
            'intent': intent,
            'language': language,
            'filipino': filipino,
            'citations': [Citation.model_validate(item) for item in citations],
            'web_search_used': web_used,
            'checked_at': checked_at,
            'evidence_status': evidence_status,
            'response_type': response_type,
            'messages': messages,
            'options': options,
            'model': active_model,
            'weather_context': weather_context,
        }

    def fallback_when_ollama_offline(ctx: dict, payload: ChatRequest) -> ChatResponse:
        filipino = ctx['filipino']
        citations = ctx['citations']
        if ctx['weather_context']:
            return ChatResponse(
                answer=ctx['weather_context'], language=ctx['language'], response_type='live_update',
                evidence_status='partially_supported', citations=citations,
                web_search_used=ctx['web_search_used'], checked_at=ctx['checked_at'], model=None,
            )
        doc_cite = next((c for c in citations if c.kind == 'indexed_document' and c.passage), None)
        if doc_cite:
            return ChatResponse(
                answer=doc_cite.passage, language=ctx['language'], response_type='preparedness',
                evidence_status='partially_supported', citations=citations,
                web_search_used=ctx['web_search_used'], checked_at=ctx['checked_at'], model=None,
            )
        guidance = preparedness_guidance(payload.message, ctx['intent'].hazard or '', ctx['language'])
        if guidance:
            text, cite = guidance
            return ChatResponse(
                answer=text, language=ctx['language'], response_type='preparedness',
                evidence_status='partially_supported', citations=[Citation.model_validate(cite), *citations],
                web_search_used=ctx['web_search_used'], checked_at=ctx['checked_at'], model=None,
            )
        msg = (
            'Hindi maabot ang lokal na AI model (Ollama) ngayon. Siguraduhing tumatakbo ang Ollama sa iyong computer.'
            if filipino else
            'The local AI model (Ollama) could not be reached right now. Please make sure Ollama is running locally.'
        )
        return ChatResponse(
            answer=msg, language=ctx['language'], response_type='refusal',
            evidence_status='insufficient_evidence', citations=citations,
            web_search_used=ctx['web_search_used'], checked_at=ctx['checked_at'], model=None,
        )

    @app.post('/api/v1/chat/stream', operation_id='chat_stream', dependencies=[Depends(require_token)],
              responses={401: {'description': 'Unauthorized'}, 422: {'description': 'Invalid request'}})
    def chat_stream(payload: ChatRequest) -> StreamingResponse:
        ctx = prepare_ai_context(payload)

        def event_stream():
            if ctx['direct'] is not None:
                direct: ChatResponse = ctx['direct']
                meta = {
                    'type': 'meta',
                    'language': direct.language,
                    'response_type': direct.response_type,
                    'evidence_status': direct.evidence_status,
                    'citations': [c.model_dump(mode='json') for c in direct.citations],
                    'web_search_used': direct.web_search_used,
                    'checked_at': direct.checked_at.isoformat() if direct.checked_at else None,
                    'model': direct.model,
                }
                yield json.dumps(meta) + '\n'
                yield json.dumps({'type': 'token', 'text': direct.answer}) + '\n'
                yield json.dumps({'type': 'done', 'answer': direct.answer}) + '\n'
                return

            meta = {
                'type': 'meta',
                'language': ctx['language'],
                'response_type': ctx['response_type'],
                'evidence_status': ctx['evidence_status'],
                'citations': [c.model_dump(mode='json') for c in ctx['citations']],
                'web_search_used': ctx['web_search_used'],
                'checked_at': ctx['checked_at'].isoformat() if ctx['checked_at'] else None,
                'model': ctx['model'],
            }
            yield json.dumps(meta) + '\n'

            chunks: list[str] = []
            try:
                if ai_chat_enabled:
                    for token in rag_engine.stream_chat(ctx['messages'], model=ctx['model'], options=ctx['options']):
                        chunks.append(token)
                        yield json.dumps({'type': 'token', 'text': token}) + '\n'
            except (httpx.HTTPError, ValueError, KeyError, OSError):
                chunks = []

            full_answer = ''.join(chunks).strip()
            if not full_answer:
                fb = fallback_when_ollama_offline(ctx, payload)
                yield json.dumps({
                    'type': 'meta',
                    'language': fb.language,
                    'response_type': fb.response_type,
                    'evidence_status': fb.evidence_status,
                    'citations': [c.model_dump(mode='json') for c in fb.citations],
                    'web_search_used': fb.web_search_used,
                    'checked_at': fb.checked_at.isoformat() if fb.checked_at else None,
                    'model': fb.model,
                }) + '\n'
                yield json.dumps({'type': 'token', 'text': fb.answer}) + '\n'
                yield json.dumps({'type': 'done', 'answer': fb.answer}) + '\n'
                return

            yield json.dumps({'type': 'done', 'answer': full_answer}) + '\n'

        return StreamingResponse(event_stream(), media_type='application/x-ndjson')

    @app.post('/api/v1/chat', operation_id='chat', dependencies=[Depends(require_token)],
              responses={401: {'description': 'Unauthorized'}, 422: {'description': 'Invalid request'}})
    def chat(payload: ChatRequest) -> ChatResponse:
        ctx = prepare_ai_context(payload)
        if ctx['direct'] is not None:
            return ctx['direct']

        if ai_chat_enabled:
            answer = rag_engine.complete_chat(ctx['messages'], model=ctx['model'], options=ctx['options'])
            if answer:
                return ChatResponse(
                    answer=answer, language=ctx['language'], response_type=ctx['response_type'],
                    evidence_status=ctx['evidence_status'], citations=ctx['citations'],
                    web_search_used=ctx['web_search_used'], checked_at=ctx['checked_at'], model=ctx['model'],
                )

        return fallback_when_ollama_offline(ctx, payload)

    def cursor_for(source_id: str) -> str:
        signature = hmac.new(expected_token.encode(), f'sources:v1:{source_id}'.encode(), hashlib.sha256).hexdigest()
        return f'{source_id}.{signature}'

    @app.get('/api/v1/sources', operation_id='sources', dependencies=[Depends(require_token)],
             responses={401: {'description': 'Unauthorized'}, 422: {'description': 'Invalid pagination'},
                        503: {'description': 'Source catalog unavailable'}})
    def sources(limit: int = Query(25, ge=1, le=100), cursor: str | None = Query(None, max_length=512)) -> SourcesResponse:
        match = re.fullmatch(rf'({SOURCE_ID})\.[0-9a-f]{{64}}', cursor or '')
        if cursor is not None and (not match or not compare_digest(cursor_for(match[1]), cursor)):
            raise HTTPException(422, detail='Invalid pagination')
        if not catalog_url:
            return SourcesResponse(items=[], next_cursor=None)
        try:
            items = list_sources(catalog_url, match[1] if match else '', limit + 1)
        except (psycopg.Error, ValidationError, ValueError):
            raise HTTPException(503, detail='Source catalog unavailable') from None
        return SourcesResponse(items=items[:limit], next_cursor=cursor_for(items[limit - 1].source_id)
                               if len(items) > limit else None)

    return app
