"""Resolve which kanim to render for each entity natural key.

worklist.json (generated from the app DB) has shape:
    [{"category": "critters", "key": "Hatch", "kanim": "hatch"}, ...]
where `kanim` is the snake_case default base name; OVERRIDES corrects the few
entities whose kanim base differs.
"""
import json
from pathlib import Path

WORKLIST = Path(__file__).resolve().parent / "worklist.json"

# natural key -> actual kanim base name, for entities whose kanim is not the
# snake_case id and is not caught by the normalized (separator-stripped) match.
# Morphs reuse their base species' kanim; a few species are simply renamed.
OVERRIDES: dict[str, str] = {
    # critter morphs -> base species
    "DreckoPlastic": "drecko",
    "HatchHard": "hatch",
    "HatchMetal": "hatch",
    "HatchVeggie": "hatch",
    "PuftAlpha": "puft",
    "PuftBleachstone": "puft",
    "PuftOxylite": "puft",
    "PacuCleaner": "pacu",
    "PacuTropical": "pacu",
    "PrehistoricPacu": "pacu",
    "SquirrelHug": "squirrel",
    "OilFloaterDecor": "oilfloater",
    "OilFloaterHighTemp": "oilfloater",
    "LightBugBlack": "lightbug",
    "LightBugBlue": "lightbug",
    "LightBugCrystal": "lightbug",
    "LightBugOrange": "lightbug",
    "LightBugPink": "lightbug",
    "LightBugPurple": "lightbug",
    # renamed species
    "Crab": "crabshell",
    "CrabWood": "woodcrabshell",
    "Moo": "gassy_moo",
    "DieselMoo": "gassy_moo",
    # plants
    "MushroomPlant": "fungusplant",
    "HardSkinBerryPlant": "bristleberry",
    "GasGrass": "gassygrass",
    "ForestTree": "tree",
}


def load_worklist() -> list[dict[str, str]]:
    return json.loads(WORKLIST.read_text())
