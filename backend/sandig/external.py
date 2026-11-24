import json
import math
import re
import time
from datetime import UTC, datetime, timedelta
from threading import Lock
from urllib.parse import quote, urlsplit

import httpx


USGS_FEED = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/2.5_day.geojson'
BRAVE_SEARCH = 'https://api.search.brave.com/res/v1/web/search'
WIKIPEDIA_SEARCH = 'https://en.wikipedia.org/w/api.php'
PHIVOLCS = 'https://earthquake.phivolcs.dost.gov.ph/'
PAGASA = 'https://www.pagasa.dost.gov.ph/'
OPEN_METEO = 'https://api.open-meteo.com/v1/forecast'
WEATHER_FIELDS = 'temperature_2m,precipitation,wind_speed_10m'
HTML_TAG = re.compile(r'<[^>]+>')

SEARCH_TOPICS = {
    'earthquake': 'earthquake',
    'landslide': 'landslide',
    'flood': 'flood',
    'typhoon': 'typhoon',
    'fire': 'fire',
    'preparedness': 'disaster',
}
SEARCH_PUBLISHERS = {
    'baguio.gov.ph': 'Baguio City Government',
    'phivolcs.dost.gov.ph': 'PHIVOLCS',
    'pagasa.dost.gov.ph': 'PAGASA',
    'mgb.gov.ph': 'DENR-MGB',
    'ocd.gov.ph': 'Office of Civil Defense',
    'ndrrmc.gov.ph': 'NDRRMC',
    'bfp.gov.ph': 'Bureau of Fire Protection',
    'redcross.org.ph': 'Philippine Red Cross',
    'pia.gov.ph': 'Philippine Information Agency',
    'pna.gov.ph': 'Philippine News Agency',
    'ifrc.org': 'IFRC',
    'undrr.org': 'UNDRR',
}


def requests_usgs(message: str) -> bool:
    return bool(re.fullmatch(
        r'(?:show (?:me )?(?:the )?)?(?:(?:recent|latest) )?usgs earthquake reports(?: near the philippines)?[.!?]?',
        message.strip(), re.IGNORECASE,
    ))


def safe_link(value: object, domain: str | None = None) -> str | None:
    if not isinstance(value, str) or len(value) > 1200 or re.search(r'[\s\x00-\x1f\x7f\\]', value):
        return None
    try:
        parsed = urlsplit(value)
        if parsed.scheme != 'https' or parsed.netloc != parsed.hostname or not parsed.hostname:
            return None
        if domain is not None and not (parsed.hostname == domain or parsed.hostname.endswith('.' + domain)):
            return None
        return parsed._replace(query='', fragment='').geturl()
    except ValueError:
        pass
    return None


def instant(value: object) -> datetime:
    if type(value) is not int:
        raise ValueError('Invalid provider timestamp')
    return datetime.fromtimestamp(value / 1000, UTC)


class ExternalInformation:
    def __init__(self, *, usgs_enabled=False, weather_enabled=True, brave_key='', transport=None):
        self.usgs_enabled = usgs_enabled
        self.weather_enabled = weather_enabled
        self.brave_key = brave_key
        self.transport = transport
        self._usgs_lock = Lock()
        self._search_lock = Lock()
        self._next_usgs = 0.0
        self._next_search = 0.0
        self._usgs_cache = None
        self._weather_lock = Lock()
        self._weather_cache: dict[tuple[float, float], tuple[float, tuple]] = {}

    def _get(self, endpoint, *, params=None, headers=None):
        deadline = time.monotonic() + 5
        headers = {
            'Accept-Encoding': 'identity',
            'User-Agent': 'SANDIG-Assistant/1.0 (local academic prototype)',
            **(headers or {}),
        }
        with httpx.Client(timeout=4, follow_redirects=False, trust_env=False, transport=self.transport) as client:
            with client.stream('GET', endpoint, params=params, headers=headers) as response:
                response.raise_for_status()
                if response.headers.get('Content-Encoding', 'identity') != 'identity':
                    raise ValueError('Unexpected provider encoding')
                data = bytearray()
                for chunk in response.iter_bytes(chunk_size=16384):
                    if time.monotonic() > deadline or len(data) + len(chunk) > 512 * 1024:
                        raise ValueError('Provider response exceeded budget')
                    data.extend(chunk)
        payload = json.loads(data)
        if not isinstance(payload, dict):
            raise ValueError('Invalid provider envelope')
        return payload

    def earthquakes(self):
        if not self.usgs_enabled or not self._usgs_lock.acquire(blocking=False):
            return None
        try:
            if time.monotonic() < self._next_usgs:
                return self._usgs_cache
            self._next_usgs = time.monotonic() + 60
            self._usgs_cache = None
            payload = self._get(USGS_FEED)
            checked = datetime.now(UTC)
            generated = instant(payload['metadata']['generated'])
            if not checked - timedelta(minutes=9) <= generated <= checked + timedelta(minutes=1):
                return None
            features = payload['features']
            if payload.get('type') != 'FeatureCollection' or not isinstance(features, list):
                return None
            citations = []
            for feature in features:
                longitude, latitude, *_ = feature['geometry']['coordinates']
                if not (116 <= longitude <= 128 and 4 <= latitude <= 22):
                    continue
                properties = feature['properties']
                observed = instant(properties['time'])
                magnitude = properties['mag']
                link = safe_link(properties.get('url'), 'earthquake.usgs.gov')
                if (link is None or not re.fullmatch(r'https://earthquake\.usgs\.gov/earthquakes/eventpage/[a-zA-Z0-9]+', link)
                        or properties.get('type') != 'earthquake'
                        or not checked - timedelta(hours=24) <= observed <= min(generated, checked)
                        or type(magnitude) not in (int, float) or not math.isfinite(magnitude)
                        or not 2.5 <= magnitude <= 10):
                    continue
                citations.append(dict(kind='supplementary_live', publisher='USGS', url=link,
                                      title=f'USGS earthquake report (M{magnitude:g})', observed_at=observed))
            citations.sort(key=lambda entry: entry['observed_at'], reverse=True)
            self._usgs_cache = (checked, citations[:3])
            return self._usgs_cache
        except (httpx.HTTPError, KeyError, ValueError, TypeError, OverflowError, OSError, RecursionError):
            return None
        finally:
            self._usgs_lock.release()

    def weather_for(self, latitude: float = 16.4023, longitude: float = 120.5960):
        if not self.weather_enabled or not self._weather_lock.acquire(blocking=False):
            return None
        key = (round(latitude, 2), round(longitude, 2))
        try:
            cached = self._weather_cache.get(key)
            if cached and time.monotonic() < cached[0]:
                return cached[1]
            payload = self._get(OPEN_METEO, params={
                'latitude': f'{key[0]:.4f}', 'longitude': f'{key[1]:.4f}', 'current': WEATHER_FIELDS,
                'timezone': 'UTC', 'temperature_unit': 'celsius', 'wind_speed_unit': 'kmh',
                'precipitation_unit': 'mm',
            })
            current = payload['current']
            checked = datetime.now(UTC)
            timestamp = datetime.fromisoformat(current['time'])
            if timestamp.tzinfo is not None or payload['utc_offset_seconds'] != 0:
                return None
            timestamp = timestamp.replace(tzinfo=UTC)
            if not checked - timedelta(minutes=45) <= timestamp <= checked + timedelta(minutes=5):
                return None
            fields = WEATHER_FIELDS.split(',')
            if [payload['current_units'][field] for field in fields] != ['\u00b0C', 'mm', 'km/h']:
                return None
            values = [current[field] for field in fields]
            bounds = [(-90, 65), (0, 2000), (0, 500)]
            if any(type(value) not in (int, float) or not low <= value <= high
                   for value, (low, high) in zip(values, bounds, strict=True)):
                return None
            result = (checked, timestamp, *values)
            self._weather_cache[key] = (time.monotonic() + 60, result)
            return result
        except (httpx.HTTPError, KeyError, ValueError, TypeError, OverflowError, OSError, RecursionError):
            return None
        finally:
            self._weather_lock.release()

    def baguio_weather(self):
        return self.weather_for(16.4023, 120.5960)

    def search(self, hazard, language):
        if not self.brave_key or hazard not in SEARCH_TOPICS or not self._search_lock.acquire(blocking=False):
            return False, []
        try:
            if time.monotonic() < self._next_search:
                return False, []
            self._next_search = time.monotonic() + 5
            topic = SEARCH_TOPICS[hazard]
            language_terms = ' Filipino Tagalog' if language == 'fil' else ''
            payload = self._get(BRAVE_SEARCH, params={'q': f'{topic} preparedness Baguio Philippines{language_terms}', 'count': 20,
                                                     'text_decorations': 'false'},
                                headers={'X-Subscription-Token': self.brave_key})
            citations = {}
            for entry in payload.get('web', {}).get('results', []):
                if not isinstance(entry, dict):
                    continue
                for domain, publisher in SEARCH_PUBLISHERS.items():
                    link = safe_link(entry.get('url'), domain)
                    if link is None or link in citations:
                        continue
                    heading = entry.get('title')
                    title = ' '.join(heading.split())[:180] if isinstance(heading, str) else ''
                    citations[link] = dict(kind='web_search', title=title or 'External preparedness resource',
                                           publisher=publisher, url=link)
                    break
            ranked = sorted(citations.values(), key=lambda entry: entry['publisher'] in {'IFRC', 'UNDRR'})
            return True, ranked[:4]
        except (httpx.HTTPError, ValueError, TypeError, AttributeError, RecursionError):
            return True, []
        finally:
            self._search_lock.release()

    def web_search(self, query: str, language: str = 'en') -> tuple[bool, list[dict], list[str]]:
        """General web search: uses Brave Search when key is configured, otherwise free Wikipedia search."""
        cleaned_query = ' '.join(query.strip().split())[:200]
        if not cleaned_query or not self._search_lock.acquire(blocking=False):
            return False, [], []
        try:
            if self.brave_key:
                try:
                    payload = self._get(
                        BRAVE_SEARCH,
                        params={'q': cleaned_query, 'count': 6, 'text_decorations': 'false'},
                        headers={'X-Subscription-Token': self.brave_key},
                    )
                    citations: list[dict] = []
                    snippets: list[str] = []
                    seen: set[str] = set()
                    for entry in payload.get('web', {}).get('results', []):
                        if not isinstance(entry, dict):
                            continue
                        link = safe_link(entry.get('url'))
                        if link is None or link in seen:
                            continue
                        seen.add(link)
                        hostname = urlsplit(link).hostname or 'Web'
                        publisher = next(
                            (pub for dom, pub in SEARCH_PUBLISHERS.items() if hostname == dom or hostname.endswith('.' + dom)),
                            hostname,
                        )
                        title = ' '.join(str(entry.get('title') or '').split())[:180] or 'External web result'
                        desc = ' '.join(str(entry.get('description') or '').split())[:300]
                        citations.append(dict(kind='web_search', title=title, publisher=publisher, url=link))
                        if desc:
                            snippets.append(f'{title} ({publisher}): {desc}')
                        if len(citations) >= 4:
                            break
                    return True, citations, snippets
                except (httpx.HTTPError, ValueError, TypeError, AttributeError, RecursionError):
                    return True, [], []

            # Free zero-key public fallback via Wikipedia API (no CAPTCHA, clean HTTPS JSON)
            try:
                wiki_host = 'https://tl.wikipedia.org/w/api.php' if language == 'fil' else WIKIPEDIA_SEARCH
                wiki_base = 'https://tl.wikipedia.org/wiki/' if language == 'fil' else 'https://en.wikipedia.org/wiki/'
                payload = self._get(
                    wiki_host,
                    params={
                        'action': 'query',
                        'list': 'search',
                        'srsearch': cleaned_query,
                        'srlimit': 3,
                        'format': 'json',
                        'utf8': 1,
                    },
                )
                results = payload.get('query', {}).get('search', [])
                citations = []
                snippets = []
                for entry in results:
                    if not isinstance(entry, dict):
                        continue
                    title = ' '.join(str(entry.get('title') or '').split())[:180]
                    if not title:
                        continue
                    slug = quote(title.replace(' ', '_'), safe='()_-.:')
                    link = safe_link(f'{wiki_base}{slug}')
                    if link is None:
                        continue
                    raw_snippet = HTML_TAG.sub('', str(entry.get('snippet') or ''))
                    clean_snippet = ' '.join(raw_snippet.split())[:300]
                    citations.append(dict(kind='web_search', title=title, publisher='Wikipedia', url=link))
                    if clean_snippet:
                        snippets.append(f'{title} (Wikipedia): {clean_snippet}')
                return True, citations, snippets
            except (httpx.HTTPError, ValueError, TypeError, AttributeError, RecursionError):
                return True, [], []
        finally:
            self._search_lock.release()
