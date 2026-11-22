"""Tests for the render worklist read from the OniExtract export.

Usage:
    uv run --with pytest --with UnityPy --with Pillow python -m pytest scripts/kanim
"""
import json
from pathlib import Path

from scripts.kanim.mapping import load_worklist


def _creature(name: str, adult: str | None = None) -> dict:
    entity = {
        "name": name,
        "critterTemperatureMonitorDef": {},
        "kPrefabID": {"tags": [{"Name": "CreatureBrain"}]},
    }
    if adult is not None:
        entity["babyMonitorDef"] = {"adultPrefab": {"Name": adult}}
    return entity


def _export(tmp_path: Path) -> Path:
    entities = [
        _creature("PacuTropical"),
        _creature("PacuTropicalBaby", adult="PacuTropical"),
        _creature("DivergentWorm"),
        _creature("PacuBeta"),
        {"name": "SweepBot", "critterTemperatureMonitorDef": None, "kPrefabID": {"tags": [{"Name": "CreatureBrain"}]}},
        {"name": "SeaTree", "kPrefabID": {"tags": [{"Name": "Plant"}]}},
    ]
    extras = {
        "PacuTropical": {"speciesTag": "PacuSpecies", "kanim": "pacu_build_kanim"},
        "PacuTropicalBaby": {"speciesTag": "PacuSpecies", "kanim": "baby_pacu_kanim"},
        "DivergentWorm": {"speciesTag": "DivergentSpecies", "kanim": "worm_build_kanim"},
        "PacuBeta": {"speciesTag": "PacuSpecies", "kanim": "betapacu_kanim", "sourceMod": {"staticID": "ONIPacuMorphsELU"}},
        "SweepBot": {"speciesTag": "Robot", "kanim": "sweep_bot_kanim"},
        "SeaTree": {"kanim": "sea_fairy_plant_kanim"},
    }
    geysers = [{"geyserType": {"id": "steam"}, "anim": "geyser_gas_steam_kanim"}]
    (tmp_path / "entities.json").write_text(json.dumps({"entities": entities}))
    (tmp_path / "planner_extras.json").write_text(json.dumps({"entities": extras}))
    (tmp_path / "geyser.json").write_text(json.dumps({"geysers": geysers}))
    return tmp_path


def _rows(tmp_path: Path) -> dict[str, dict[str, str]]:
    return {row["key"]: row for row in load_worklist(_export(tmp_path))}


def test_adults_render_the_anims_behind_their_build_only_kanim(tmp_path):
    row = _rows(tmp_path)["PacuTropical"]

    assert (row["category"], row["exact_kanim"], row["morph"]) == ("critters", "pacu", "PacuTropical")


def test_babies_render_their_own_kanim_in_their_adult_morph(tmp_path):
    row = _rows(tmp_path)["PacuTropicalBaby"]

    assert (row["exact_kanim"], row["morph"]) == ("baby_pacu", "PacuTropical")


def test_curated_overrides_win_over_the_export(tmp_path):
    assert _rows(tmp_path)["DivergentWorm"]["exact_kanim"] == "worm_head"


def test_mod_creatures_and_non_creatures_are_left_out(tmp_path):
    rows = _rows(tmp_path)

    assert "PacuBeta" not in rows
    assert "SweepBot" not in rows


def test_plants_and_geysers_come_from_the_export(tmp_path):
    rows = _rows(tmp_path)

    assert (rows["SeaTree"]["category"], rows["SeaTree"]["exact_kanim"]) == ("plants", "sea_fairy_plant")
    assert (rows["steam"]["category"], rows["steam"]["exact_kanim"]) == ("geysers", "geyser_gas_steam")


def test_a_missing_export_gives_an_empty_worklist(tmp_path):
    assert load_worklist(tmp_path / "nowhere") == []
