import re
import unicodedata
from dataclasses import dataclass
from datetime import date


HOTLINE = {
    'kind': 'indexed_document', 'publisher': 'DILG-NCR',
    'title': 'DILG Leads Nationwide Campaign for Unified 911 Emergency Hotline',
    'url': 'https://ncr.dilg.gov.ph/dilg-leads-nationwide-campaign-for-unified-911-emergency-hotline/',
}
EARTHQUAKE = {
    'kind': 'indexed_document', 'publisher': 'PHIVOLCS',
    'title': 'Briefer on the Recent Earthquakes in Talacogon, Agusan del Sur',
    'url': 'https://www.phivolcs.dost.gov.ph/briefer-on-the-recent-earthquakes-in-talacogon-agusan-del-sur-as-of-27-december-2024/',
    'publication_date': date(2024, 12, 27),
    'section': 'Protective actions during shaking',
}
TRAPPED = {
    'kind': 'indexed_document', 'publisher': 'FEMA', 'title': 'CERT Hazard Annexes Participant Manual',
    'url': 'https://www.ready.gov/sites/default/files/2019.CERT_.HazardAnnexes.PM_.FINAL_.508c.pdf',
    'section': 'Earthquake / After an Earthquake (EQ-5)',
}
INDOORS = {
    'kind': 'indexed_document', 'publisher': 'FEMA', 'title': 'Be Prepared for Any Disaster',
    'url': 'https://www.ready.gov/sites/default/files/2025-02/fema_full-suite-hazard-info-sheets.pdf',
    'section': 'Earthquake / During',
}
FIRE = {
    'kind': 'indexed_document', 'publisher': 'U.S. Fire Administration', 'title': 'Pictographs: Fire Escape Plans',
    'url': 'https://www.usfa.fema.gov/gallery/pictographs/escape-planning.html',
}
FLOOD = {
    'kind': 'indexed_document', 'publisher': 'FEMA', 'title': 'Be Prepared for Any Disaster',
    'url': 'https://www.ready.gov/sites/default/files/2024-03/ready-gov_full-suite_hazard-info-sheets.pdf',
    'section': 'Flooding',
}

TOPICS = {
    'earthquake': r'earthquakes?|lindol|lumulindol|lumilindol',
    'landslide': r'landslides?|pagguho|gumuguho',
    'flood': r'flood(?:s|ing)?|baha|bumabaha|binabaha',
    'typhoon': r'typhoons?|storms?|severe weather|weather|forecast|rainfall|bagyo|ulan|panahon',
    'fire': r'fires?|sunog|nasusunog',
    'preparedness': r'emergency (?:supply )?kits?|emergency supplies|go[ -]?bags?|disaster(?:s| preparedness)?|preparedness|evacuation|sakuna|kalamidad|paglikas|lumikas|first aid|risk reduction|recovery|ra 10121',
}
TRAP_SIGNAL = r'\b(?:trapped|pinned|buried|stuck|naipit|naiipit|nakulong|natabunan)\b'
DISTRESS = re.compile(
    r'\b(?:i am|i m|we are|we re|someone is|my \w+ is|he is|she is|they are) '
    r'(?:currently |still |possibly )?(?:trapped|pinned|buried|stuck (?:under|in rubble)|unconscious|bleeding heavily)\b'
    r'|\b(?:i|we|they|he|she) (?:can t|cannot|can not) breathe\b'
    r'|\b(?:naipit|naiipit|natabunan|nakulong) (?:po )?(?:ako|kami|siya|sila)\b'
    r'|\b(?:hindi|di) (?:po )?(?:ako|kami|siya|sila) makahinga\b|\b(?:di|hindi) makahinga\b'
    r'|\b(?:house|home|building|room|kitchen|apartment) is (?:on fire|flooding)\b'
    r'|\b(?:nasusunog|binabaha) (?:na )?(?:po )?(?:ang |yung )?(?:bahay|gusali|kwarto|kusina)\b'
    r'|\b(?:fire in (?:my|our|the) (?:house|home|building|room|kitchen|apartment)|may sunog sa (?:bahay|gusali|kwarto|kusina))\b'
    r'|^(?:trapped|pinned|buried) (?:under|in) (?:the )?(?:rubble|debris)\b'
    r'|\blandslide (?:is )?(?:hitting|burying) (?:my|our|the) (?:house|home|building)\b'
    r'|\bgumuguho (?:na )?(?:po )?ang lupa\b'
    r'|\b(?:send help|need rescue|please rescue|tulong po|saklolo)\b'
)
ACTIVE_QUAKE = re.compile(r'\b(?:lumilindol|lumulindol|ground is shaking|earthquake (?:is happening|right now))\b')
STRUCTURE_FIRE = re.compile(r'\b(?:on fire|nasusunog|fire in|may sunog)\b')
STRUCTURE_FLOOD = re.compile(r'\b(?:flooding|binabaha)\b')
HYPOTHETICAL = re.compile(r'^(?:what if|if i|if we|if someone|in case|for a drill|in a drill|kunwari|halimbawa|paano kung|kapag)\b')
CURRENT = re.compile(r'\b(?:current|currently|latest|today|tonight|tomorrow|right now|live|warnings?|bulletins?|alerts?|updates?|forecast|ngayon|kasalukuyan|bukas|babala)\b')
LOCAL = re.compile(r'\b(?:baguio|barangay|brgy|my (?:house|home|area|road|location)|near me|safe (?:here|to go|to drive)|dito|bahay ko|lugar namin|evacuation cent(?:er|re)s?|road closures?|safe routes?)\b')
LOCAL_CONTEXT = re.compile(r'\b(?:here|our (?:house|home|area|road|location)|this (?:area|road|location))\b')
CITY_PREP = re.compile(
    r'(?:how (?:do|can|should) (?:i|we) prepare for (?:a |an )?|paano (?:ako |kami )?maghanda sa )'
    r'(?P<topic>[^\n]+) (?:in|sa) baguio(?: city)?',
)
WEATHER_QUERY = re.compile(
    r'\b(?:weather(?: updates?| forecast| report| today| now| here| in baguio(?: city)?)?'
    r'|lagay ng panahon|balita sa panahon|panahon(?: dito| sa baguio(?: city)?)?(?: ngayon)?'
    r'|may bagyo(?: po)? ba|is there (?:a )?(?:storm|typhoon))\b'
)
WEATHER_EXCLUSIONS = re.compile(
    r'\b(?:pagasa|warnings?|bulletins?|advisories?|advisory|signals?|babala|prepare|preparedness|maghanda|paghahanda'
    r'|go ?bags?|kits?|safe|ligtas|roads?|evacuation|barangay|brgy|manila|cebu|davao|quezon city|makati)\b'
)
UNRELATED = re.compile(r'\b(?:(?:write|create|build|make|solve|gawin|isulat)\b.{0,45}\b(?:code|program|script|assignment|website|game|poem|essay|tula)\b|online shopping|ignore (?:all |previous |your )?(?:instructions|rules)|system prompt)\b')
GENERAL = re.compile(
    r'^(?:(?:can you |please )?(?:how|what|why|when|explain|describe|list|give|tell me|prepare for)|paano|ano|anong|bakit|kailan|ipaliwanag|magbigay|mga gamit|disaster preparation)\b'
    r'|\b(?:tips|guide|checklist|advice|steps|safety|preparedness|dapat gawin|gabay|payo)\b'
)
FILIPINO = re.compile(
    r'\b(?:paano|ano|anong|saan|bakit|kailan|puwede|pwede|maaari|dapat|kailangan|ipaliwanag|magbigay|maghanda|paghahanda|tulungan|sunog|gumuguho|ulan|'
    r'tulong|saklolo|naipit|naiipit|natabunan|nakulong|makahinga|nasusunog|binabaha|lumulindol|lumilindol|'
    r'lindol|pagguho|baha|bumabaha|bagyo|sakuna|kalamidad|paglikas|lumikas|ligtas|ngayon|babala)\b'
    r'|\b(?:ang|mga) (?:laman|gagawin|bahay|barangay)\b'
)


def detect_language(message: str) -> str:
    text = words(unicodedata.normalize('NFKC', message).casefold())
    if re.fullmatch(r'kumusta(?: po)?|kamusta(?: po)?|magandang (?:umaga|hapon|gabi)(?: po)?|salamat(?: po)?', text):
        return 'fil'
    if re.match(r'^(?:what|why|how|when|where|which|can|should|is|are|explain|list)\b', text) and not re.search(
        r'\b(?:ako|kami|po|ang|mga|paano|namin|ninyo)\b', text
    ):
        return 'en'
    return 'fil' if FILIPINO.search(text) else 'en'


@dataclass(frozen=True)
class Intent:
    route: str
    hazard: str | None = None


def words(text: str) -> str:
    return ' '.join(re.findall(r'[^\W_]+', text))


def barangay_pattern(names: list[str]) -> re.Pattern[str] | None:
    variants = []
    for name in names:
        prefix, separator, suffix = name.partition(',')
        if separator and suffix.strip():
            variants.append(f'{suffix.strip()} {prefix}')
    names = [*names, *variants]
    normalized_names = {words(unicodedata.normalize('NFKC', name).casefold()) for name in names} - {''}
    if not normalized_names:
        return None
    common_words = normalized_names & {'hillside', 'honeymoon', 'ferdinand'}
    alternatives = [re.escape(name) for name in sorted(normalized_names - common_words)]
    alternatives.extend(rf'(?:in|at|near|sa) {re.escape(name)}' for name in sorted(common_words))
    return re.compile(rf'\b(?:{"|".join(alternatives)})\b')


def classify(message: str, local_places: re.Pattern[str] | None = None) -> Intent:
    normalized = unicodedata.normalize('NFKC', message).casefold()
    text = words(normalized)
    hazards = [hazard for hazard, pattern in TOPICS.items() if re.search(rf'\b(?:{pattern})\b', text)]
    physical = [hazard for hazard in hazards if hazard != 'preparedness']
    clauses = [words(clause) for clause in re.split(r'[.!?;\n]+', normalized)]
    if text == 'tulong':
        return Intent('emergency')
    for clause in clauses:
        clause = re.sub(r'^in case of (?:an )?emergency (?=send help|need rescue|my |our )', '', clause)
        if HYPOTHETICAL.search(clause):
            continue
        if DISTRESS.search(clause):
            matches = []
            if 'earthquake' in hazards and re.search(TRAP_SIGNAL, clause):
                matches.append('trapped_after_quake')
            if STRUCTURE_FIRE.search(clause):
                matches.append('structure_fire')
            if STRUCTURE_FLOOD.search(clause):
                matches.append('structure_flood')
            return Intent('emergency', matches[0] if len(matches) == 1 else None)
        if ACTIVE_QUAKE.search(clause) and (clause.startswith('may nararamdaman ako ') or
                                           not re.match(r'^(?:is|was|could|may|posible|totoo)\b', clause)):
            return Intent('emergency', 'active_quake')
    if UNRELATED.search(text):
        return Intent('out_of_scope')
    if re.fullmatch(r'hi|hello|hey|good (?:morning|afternoon|evening)|kumusta(?: po)?|kamusta(?: po)?|magandang (?:umaga|hapon|gabi)(?: po)?', text):
        return Intent('greeting')
    if re.fullmatch(r'test(?:ing)?(?: \d{1,12})?|\d{1,12}', text):
        return Intent('check_in')
    if re.fullmatch(r'thanks|thank you|salamat(?: po)?', text):
        return Intent('thanks')
    if WEATHER_QUERY.search(text) and not WEATHER_EXCLUSIONS.search(text) and not (local_places and local_places.search(text)):
        return Intent('weather_now', 'typhoon')
    city_prep = CITY_PREP.fullmatch(text)
    if city_prep and len(physical) == 1 and re.fullmatch(TOPICS[physical[0]], city_prep['topic']):
        return Intent('city_preparedness', physical[0])
    if CURRENT.search(text) or LOCAL.search(text) or LOCAL_CONTEXT.search(text) or len(physical) > 1:
        return Intent('unverified', physical[0] if len(physical) == 1 else None)
    if local_places and local_places.search(text):
        return Intent('unverified', physical[0] if len(physical) == 1 else None)
    if not hazards:
        return Intent('out_of_scope')
    if not GENERAL.search(text):
        return Intent('unverified')
    return Intent('preparedness', (physical or hazards)[0])


def emergency_text(language: str, hazard: str | None) -> tuple[str, list[dict]]:
    if language == 'fil':
        contact = ('Kung nasa Pilipinas, tumawag sa 911 para sa agarang tulong. Kung hindi makakonekta, '
                   'makipag-ugnayan sa lokal na rescuer o magpatulong sa taong malapit sa iyo. '
                   'Hindi nakakatawag ng rescuer ang SANDIG.')
        during = ('Kung nasa loob at umuuga pa, dumapa, sumilong sa ilalim ng matibay na mesa, at kumapit. '
                  'Kung walang masisilungan, takpan ang ulo at leeg. Huwag tumakbo palabas habang umuuga. ')
        trapped = ('Kung naipit matapos ang lindol, tumapik sa tubo o pader, o gumamit ng pito kung mayroon, '
                   'para marinig ka ng mga rescuer.')
        fire = ('Kung may madaraanan na ligtas, lumabas agad at huwag nang bumalik sa gusali. '
                'Kung hindi makalabas, isara ang pinto sa pagitan mo at ng usok at humingi agad ng saklolo. ')
        flood = ('Lumipat sa mas mataas na lugar o palapag kung ligtas ang daan. '
                 'Huwag lumakad, lumangoy, o magmaneho sa tubig-baha. ')
    else:
        contact = ('If you are in the Philippines, call 911 for emergency help. If it does not connect, '
                   'contact local responders directly or ask someone nearby to call. SANDIG cannot dispatch help.')
        during = ('If you are indoors and the ground is still shaking, drop, cover under a sturdy table, and hold on. '
                  'If there is no shelter, protect your head and neck. Do not run outside during shaking. ')
        trapped = ('If trapped after an earthquake, tap on a pipe or wall, or use a whistle if available, '
                   'so rescuers can locate you.')
        fire = ('If you have a safe way out, leave now and do not go back inside. '
                'If trapped, close the door between you and the smoke and call for help immediately. ')
        flood = ('Move to higher ground or a higher floor if you can reach it safely. '
                 'Do not walk, swim, or drive through floodwater. ')
    if hazard == 'active_quake':
        return during + contact, [EARTHQUAKE, INDOORS, HOTLINE]
    if hazard == 'trapped_after_quake':
        return contact + ' ' + trapped, [HOTLINE, TRAPPED]
    if hazard == 'structure_fire':
        return fire + contact, [FIRE, HOTLINE]
    if hazard == 'structure_flood':
        return flood + contact, [FLOOD, HOTLINE]
    return contact, [HOTLINE]
