import re
import unicodedata

from sandig.safety import words


KIT_REQUEST = re.compile(
    r'^(?:what (?:should|do) (?:i|we) (?:pack|put) in|what (?:goes|belongs) in|'
    r'what should be in|what is in|list (?:items for|the contents of)|'
    r'mga gamit para sa|(?:ano (?:ang |yung )?|anong )(?:laman(?: ng)?|ilalagay (?:ko )?sa|dapat ilagay sa)) '
    r'(?:(?:an?|the|my|our) )?(?:emergency (?:supply )?kit|go ?bag)$'
)
LANDSLIDE_REQUEST = re.compile(
    r'^(?:how (?:(?:should|can|do) (?:i|we) (?:prepare|be prepared)|to prepare)|prepare|'
    r'paano (?:ako |kami )?(?:maghanda|mag prepare)) (?:for|sa) '
    r'(?:(?:a|mga) )?(?:landslides?|pagguho(?: ng lupa)?)$'
)

GUIDANCE = {
    'preparedness': {
        'en': ('For a basic emergency kit, pack drinking water, food that keeps without refrigeration, '
               'a flashlight and spare batteries, a first-aid kit, a whistle, and a phone charger. '
               "Adapt the kit to your household's needs."),
        'fil': ('Para sa go-bag, maghanda ng inuming tubig, pagkaing hindi madaling masira, '
                'flashlight at ekstrang baterya, first-aid kit, pito, at charger ng telepono. '
                'Iangkop ang laman sa pangangailangan ng inyong pamilya.'),
        'title': 'Recommended Items to Include in a Basic Emergency Supply Kit',
        'publisher': 'FEMA Ready Campaign',
        'url': 'https://www.ready.gov/sites/default/files/2024-05/ready_supply-kit-checklist.pdf',
    },
    'landslide': {
        'en': ('Prepare an emergency kit and practise a household evacuation plan, including what to do with pets. '
               'For a property-specific assessment, consult a qualified geologist or engineer with landslide experience.'),
        'fil': ('Maghanda ng go-bag at sanayin ang pamilya sa plano ng paglikas, kasama ang gagawin para sa mga alagang hayop. '
                'Para masuri ang inyong mismong ari-arian, kumonsulta sa kwalipikadong geologist o inhinyerong may karanasan sa landslide.'),
        'title': 'Landslide Preparedness',
        'publisher': 'U.S. Geological Survey',
        'url': 'https://www.usgs.gov/programs/landslide-hazards/landslide-preparedness',
    },
}


def preparedness_guidance(message: str, hazard: str, language: str) -> tuple[str, dict] | None:
    pattern = {'preparedness': KIT_REQUEST, 'landslide': LANDSLIDE_REQUEST}.get(hazard)
    if not pattern or not pattern.fullmatch(words(unicodedata.normalize('NFKC', message).casefold())):
        return None

    record = GUIDANCE[hazard]
    note = ('Pangkalahatang gabay ito, hindi opisyal na babala o lokal na pagsusuri.' if language == 'fil' else
            'This is general guidance, not an official warning or local assessment.')
    citation = {field: record[field] for field in ('title', 'publisher', 'url')}
    return f'{record[language]} {note}', {'kind': 'curated_guidance', **citation}
