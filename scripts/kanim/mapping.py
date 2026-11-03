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
    "SquirrelHug": "squirrel",
    "OilfloaterDecor": "oilfloater",
    "OilfloaterHighTemp": "oilfloater",
    "LightBugBlack": "lightbug",
    "LightBugBlue": "lightbug",
    "LightBugCrystal": "lightbug",
    "LightBugOrange": "lightbug",
    "LightBugPink": "lightbug",
    "LightBugPurple": "lightbug",
    "GoldBelly": "ice_belly",
    "GlassDeer": "ice_floof",
    "MoleDelicacy": "driller",
    "SnailIron": "snail",
    "StaterpillarGas": "caterpillar",
    "StaterpillarLiquid": "caterpillar",
    "AlgaeStego": "stego",
    # renamed species
    # all Pokeshell morphs share pincher_kanim (crabshell = the molted shell item)
    "Crab": "pincher",
    "CrabWood": "pincher",
    "CrabFreshWater": "pincher",
    "Moo": "gassy_moo",
    "DieselMoo": "gassy_moo",
    "PrehistoricPacu": "paculacanth",
    "IceBelly": "ice_belly",
    "WoodDeer": "ice_floof",
    "Mole": "driller",
    "Chameleon": "chameleo",
    "PufferFish": "blowfish",
    "ParrotFish": "fish_bioluminescent",
    "SeaTurtle": "turtle",
    "Staterpillar": "caterpillar",
    "DivergentBeetle": "critter",
    "DivergentWorm": "worm_head",
    "Worm": "baby_worm",
    # plants
    "MushroomPlant": "fungusplant",
    "HardSkinBerryPlant": "bristleberry",
    "GasGrass": "gassygrass",
    "ForestTree": "tree",
}

# Critter morph -> name of its front-facing portrait anim inside the shared
# kanim. Base species use the plain "ui" anim; morphs get a prefixed variant
# (e.g. trp_ui = Tropical Pacu). AlgaeStego is suffixed by the game.
MORPH_UI_ANIM: dict[str, str] = {
    "PacuCleaner": "glp_ui",
    "PacuTropical": "trp_ui",
    "HatchHard": "hvy_ui",
    "HatchMetal": "mtl_ui",
    "HatchVeggie": "veg_ui",
    "PuftAlpha": "alp_ui",
    "PuftBleachstone": "anti_ui",
    "PuftOxylite": "com_ui",
    "LightBugBlack": "blk_ui",
    "LightBugBlue": "blu_ui",
    "LightBugCrystal": "cry_ui",
    "LightBugOrange": "org_ui",
    "LightBugPink": "pnk_ui",
    "LightBugPurple": "prp_ui",
    "OilfloaterDecor": "oxy_ui",
    "OilfloaterHighTemp": "hot_ui",
    "DreckoPlastic": "fbr_ui",
    "SquirrelHug": "hug_ui",
    "DieselMoo": "die_ui",
    "GlassDeer": "gla_ui",
    "MoleDelicacy": "del_ui",
    "SnailIron": "iron_ui",
    "StaterpillarGas": "gas_ui",
    "StaterpillarLiquid": "wtr_ui",
    "AlgaeStego": "ui_alg",
    "CrabWood": "wood_ui",
    "CrabFreshWater": "fresh_ui",
}

# Morphs without their own ui anim: render the base "ui" anim but substitute
# every symbol that has a "<prefix>_<name>" sibling in the build (the game
# does the same via SymbolOverrideController).
MORPH_SYMBOL_PREFIX: dict[str, str] = {
    "GoldBelly": "king",
}


def morph_symbol_prefix(key: str) -> str | None:
    """Symbol-override prefix for a morph's non-ui (idle) renders.

    Derived from its ui anim name: trp_ui -> trp, ui_alg -> alg. GoldBelly-style
    morphs use their explicit MORPH_SYMBOL_PREFIX.
    """
    explicit = MORPH_SYMBOL_PREFIX.get(key)
    if explicit is not None:
        return explicit
    ui_name = MORPH_UI_ANIM.get(key)
    if ui_name is None:
        return None
    return ui_name.removesuffix("_ui") if ui_name.endswith("_ui") else ui_name.removeprefix("ui_")

# Extra kanim bases whose builds must be merged in: Grubgrub is split across
# worm_head/worm_torso/worm_tail kanims but its symbols (incl. the ui
# portrait) live in the shared worm_build group.
EXTRA_BUILD_BASES: dict[str, list[str]] = {
    "DivergentWorm": ["worm"],
}


JSON_CACHE = Path(__file__).resolve().parents[2] / "json-cache"


def _cache_rows(category: str, file_name: str, id_field: str) -> list[dict[str, str]]:
    """Plants/geysers straight from the dotnet-extractor output, with the exact kanim
    the game config loads (no name guessing)."""
    path = JSON_CACHE / file_name
    if not path.exists():
        return []
    rows = []
    for entry in json.loads(path.read_text()):
        kanim = entry.get("kanim")
        if kanim:
            base = kanim.removesuffix("_kanim")
            rows.append({"category": category, "key": entry[id_field], "kanim": base, "exact_kanim": base})
    return rows


def load_worklist() -> list[dict[str, str]]:
    """Critters come from the curated worklist.json (morph overrides live here);
    plants and geysers come from json-cache when it carries their kanim."""
    curated = json.loads(WORKLIST.read_text())
    cached = [*_cache_rows("plants", "plants.json", "plant_id"), *_cache_rows("geysers", "geyser_types.json", "geyser_id")]
    cached_categories = {row["category"] for row in cached}
    return [*(row for row in curated if row["category"] not in cached_categories), *cached]
