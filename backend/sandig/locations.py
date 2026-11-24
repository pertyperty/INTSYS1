from datetime import date
from pathlib import Path
from typing import Annotated

from pydantic import BaseModel, Field

from sandig.catalog import HttpsUrl


class Barangay(BaseModel):
    id: Annotated[str, Field(pattern=r'^1430300[0-9]{3}$')]
    name: Annotated[str, Field(min_length=1)]


class BarangaySource(BaseModel):
    publisher: str
    url: HttpsUrl
    as_of: date


class BarangayCatalog(BaseModel):
    barangays: list[Barangay]
    source: BarangaySource


def load_barangays() -> BarangayCatalog:
    records = Path(__file__).with_name('baguio_barangays.tsv').read_text(encoding='utf-8').splitlines()
    entries = []
    for record in records:
        code, name = record.split('\t')
        entries.append(Barangay(id=code, name=name))
    if len(entries) != 129 or len({entry.id for entry in entries}) != len(entries):
        raise ValueError('Invalid Baguio barangay roster')
    return BarangayCatalog(
        barangays=sorted(entries, key=lambda entry: entry.name.casefold()),
        source=BarangaySource(publisher='Philippine Statistics Authority', as_of=date(2025, 7, 31),
                              url='https://psa.gov.ph/classification/psgc/barangays/1430300000'),
    )
