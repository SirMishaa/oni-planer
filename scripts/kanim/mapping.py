"""Resolve which kanim to render for each entity natural key.

The worklist is read from the synced OniExtract export (entities.json,
planner_extras.json, geyser.json), one row per entity:
    {"category": "critters", "key": "HatchBaby", "kanim": "baby_hatch", "exact_kanim": "baby_hatch", "morph": "Hatch"}
`exact_kanim` is the kanim the game loads; OVERRIDES corrects the few
entities whose curated kanim base differs.
"""
import json
import os
from pathlib import Path

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


ROOT = Path(os.environ.get("KANIM_DATA_ROOT") or Path(__file__).resolve().parents[2])
EXPORT_DATABASE = ROOT / "game-data" / "oniextract" / "current" / "database"


def _export_json(name: str, export: Path) -> dict:
    path = export / name
    return json.loads(path.read_text()) if path.exists() else {}


def _tags(entity: dict) -> set[str]:
    return {tag.get("Name") for tag in (entity.get("kPrefabID") or {}).get("tags") or []}


def _is_creature(entity: dict) -> bool:
    """The game:import rule (CritterReader): a temperature monitor and a CreatureBrain tag."""
    return entity.get("critterTemperatureMonitorDef") is not None and "CreatureBrain" in _tags(entity)


def _kanim_base(kanim: str) -> str:
    """Kanim file -> anim/build base: adults load a build-only "{x}_build_kanim"
    whose anims live in "{x}_anim" (pacu_build_kanim -> pacu); babies load
    "baby_{x}_kanim" directly (baby_pacu)."""
    return kanim.removesuffix("_kanim").removesuffix("_build")


def _critter_rows(entities: list[dict], extras: dict) -> list[dict[str, str]]:
    """Every creature of the export, adults and babies, with the kanim the game loads.

    A baby keeps its adult in "morph": baby kanims carry the same prefixed ui anims
    and symbols (trp_ui, king_*) as the adult's, so MORPH_UI_ANIM / MORPH_SYMBOL_PREFIX
    apply to it under its adult's key. Curated OVERRIDES win over the export. Mod
    creatures are left out: their kanims ship with the mod, not in the game bundles.
    """
    rows = []
    for entity in entities:
        key = str(entity["name"])
        extra = extras.get(key) or {}
        if not _is_creature(entity) or "sourceMod" in extra or not extra.get("kanim"):
            continue
        adult = ((entity.get("babyMonitorDef") or {}).get("adultPrefab") or {}).get("Name")
        base = OVERRIDES.get(key) or _kanim_base(extra["kanim"])
        rows.append({"category": "critters", "key": key, "kanim": base, "exact_kanim": base, "morph": adult or key})
    return rows


def _plant_rows(entities: list[dict], extras: dict) -> list[dict[str, str]]:
    """Every plant of the export (PlantReader rule: a Plant tag), with its exact kanim."""
    rows = []
    for entity in entities:
        key = str(entity["name"])
        extra = extras.get(key) or {}
        if "Plant" not in _tags(entity) or "sourceMod" in extra or not extra.get("kanim"):
            continue
        base = extra["kanim"].removesuffix("_kanim")
        rows.append({"category": "plants", "key": key, "kanim": base, "exact_kanim": base})
    return rows


def _geyser_rows(geysers: list[dict]) -> list[dict[str, str]]:
    """Every geyser type of the export, keyed by geyserType id like the geyser_types table."""
    rows = []
    for geyser in geysers:
        if not geyser.get("anim"):
            continue
        base = geyser["anim"].removesuffix("_kanim")
        rows.append({"category": "geysers", "key": geyser["geyserType"]["id"], "kanim": base, "exact_kanim": base})
    return rows


def load_worklist(export: Path = EXPORT_DATABASE) -> list[dict[str, str]]:
    """Critters, plants and geysers to render, read from the synced OniExtract export
    (the same entities game:import reads), each with the exact kanim the game loads."""
    entities = _export_json("entities.json", export).get("entities", [])
    extras = _export_json("planner_extras.json", export).get("entities", {})
    geysers = _export_json("geyser.json", export).get("geysers", [])
    return [*_critter_rows(entities, extras), *_plant_rows(entities, extras), *_geyser_rows(geysers)]
