#!/usr/bin/env python3
"""
Extract element textures from ONI Unity asset bundles.
Outputs PNG files to public/assets/game/elements/{ElementId}.png
"""
import os
import sys
from pathlib import Path
from PIL import Image
import UnityPy

REPO_ROOT = Path(__file__).parent.parent
ASSETS_FILE = REPO_ROOT / "game-data/OxygenNotIncluded_Data/sharedassets0.assets"
OUTPUT_DIR = REPO_ROOT / "public/assets/game/elements"
ICON_SIZE = 128
WEBP_QUALITY = 85

# element_id → texture name in Unity bundle
# Gases/liquids that lack their own texture fall back to their solid-state equivalent.
ELEMENT_TO_TEXTURE: dict[str, str | None] = {
    # --- Solids ---
    "Algae":               "algae",
    "Aluminum":            "aluminum",
    "AluminumOre":         "aluminum_ore",
    "Amber":               "amber",
    "Bitumen":             "sludge",
    "BleachStone":         "bleach_stone",
    "BrineIce":            "frozen_brine",
    "Carbon":              "carbon",
    "Ceramic":             "ceramic",
    "Cinnabar":            "cinnabar",
    "Clay":                "clay",
    "Cobalt":              "cobalt_refined",
    "Cobaltite":           "cobalt_ore",
    "Copper":              "copper",
    "Corium":              "igneous_rock",
    "CrushedIce":          "crushed_ice",
    "CrushedRock":         "crushed_rock",
    "Cuprite":             "cuprite",
    "DepletedUranium":     "hematite",
    "Diamond":             "diamond",
    "Dirt":                "dirt",
    "DirtyIce":            "dirty_ice",
    "EnrichedUranium":     "hematite",
    "FabricatedWood":      "wood",
    "Fertilizer":          "fertilizer",
    "FoolsGold":           "fools_gold",
    "Fossil":              "fossil",
    "FrozenPhytoOil":      "frozen_phyto_oil",
    "Fullerene":           "fullerene",
    "Glass":               "diamond",        # translucent fallback
    "Gold":                "gold",
    "GoldAmalgam":         "gold_amalgam",
    "Granite":             "granite",
    "Graphite":            "graphite",
    "Gunk":                "gunk",
    "HardPolypropylene":   "hard_plastic",
    "Ice":                 "ice",
    "IgneousRock":         "igneous_rock",
    "Iridium":             "iridium",
    "Iron":                "iron",
    "IronOre":             "hematite",
    "Isoresin":            "isoresin",
    "Katairite":           "katairite",
    "Lead":                "lead",
    "Lime":                "limestone",
    "MaficRock":           "mafic_rock",
    "MilkFat":             "milkfat",
    "MilkIce":             "ice",
    "Mud":                 "sludge",
    "NaturalSolidResin":   "resin_solid",
    "Nickel":              "nickel",
    "NickelOre":           "nickel_ore",
    "Niobium":             "niobium",
    "Obsidian":            "obsidian",
    "OxyRock":             "oxyrocks",
    "Peat":                "peat",
    "Phosphorite":         "phosphorite",
    "Phosphorus":          "phosphorus",
    "Polypropylene":       "plastic",
    "Radium":              "phosphorite",    # closest visual
    "RefinedCarbon":       "graphite",
    "Regolith":            "regolith",
    "Rust":                "iron_oxide",
    "Salt":                "salt",
    "Sand":                "sand",
    "SandStone":           "sand_stone",
    "SedimentaryRock":     "sedimentary_rock",
    "Shale":               "shale",
    "SlimeMold":           "slime_mold",
    "Snow":                "snow",
    "SolidCarbonDioxide":  "solid_carbon_dioxide",
    "SolidChlorine":       "solid_chlorine",
    "SolidCrudeOil":       "frozen_crude_oil",
    "SolidEthanol":        "ethanol",
    "SolidHydrogen":       "solid_hydrogen",
    "SolidMercury":        "solid_mercury",
    "SolidMethane":        "solid_methane",
    "SolidNaphtha":        "sludge",
    "SolidNuclearWaste":   "hematite",
    "SolidOxygen":         "solid_oxygen",
    "SolidPetroleum":      "frozen_crude_oil",
    "SolidPropane":        "solid_propane",
    "SolidResin":          "resin_solid",
    "SolidSuperCoolant":   "super_coolant",
    "SolidViscoGel":       "viscogel",
    "StableSnow":          "snow",
    "Steel":               "steel",
    "Sucrose":             "sucrose",
    "Sulfur":              "sulfur",
    "SuperInsulator":      "super_insulator",
    "Tallow":              "tallow",
    "TempConductorSolid":  "temp_conductor",
    "ToxicMud":            "polluted_sludge",
    "ToxicSand":           "polluted_dirt",
    "Tungsten":            "tungsten",
    "Unobtanium":          "neutronium",
    "UraniumOre":          "hematite",
    "Wolframite":          "wolframite",
    "WoodLog":             "wood",

    # --- Liquids ---
    "Brine":               "salt",
    "Chlorine":            "solid_chlorine",
    "CrudeOil":            "frozen_crude_oil",
    "DirtyWater":          "polluted_sludge",
    "Ethanol":             "ethanol",
    "LiquidCarbonDioxide": "solid_carbon_dioxide",
    "LiquidGunk":          "gunk",
    "LiquidHydrogen":      "solid_hydrogen",
    "LiquidMethane":       "solid_methane",
    "LiquidOxygen":        "solid_oxygen",
    "LiquidPhosphorus":    "phosphorus",
    "LiquidPropane":       "solid_propane",
    "LiquidSulfur":        "sulfur",
    "Magma":               "igneous_rock",
    "Mercury":             "solid_mercury",
    "Milk":                "milk",
    "MoltenAluminum":      "aluminum",
    "MoltenCarbon":        "carbon",
    "MoltenCobalt":        "cobalt_refined",
    "MoltenCopper":        "copper",
    "MoltenGlass":         "diamond",
    "MoltenGold":          "gold",
    "MoltenIridium":       "iridium",
    "MoltenIron":          "iron",
    "MoltenLead":          "lead",
    "MoltenNickel":        "nickel",
    "MoltenNiobium":       "niobium",
    "MoltenSalt":          "salt",
    "MoltenSteel":         "steel",
    "MoltenSucrose":       "sucrose",
    "MoltenTungsten":      "tungsten",
    "MoltenUranium":       "hematite",
    "Naphtha":             "sludge",
    "NaturalResin":        "resin_solid",
    "NuclearWaste":        "hematite",
    "Petroleum":           "frozen_crude_oil",
    "PhytoOil":            "frozen_phyto_oil",
    "RefinedLipid":        "tallow",
    "Resin":               "resin_solid",
    "SaltWater":           "salt",
    "SugarWater":          "sucrose",
    "SuperCoolant":        "super_coolant",
    "ViscoGel":            "viscogel",
    "Water":               "ice",

    # --- Gases ---
    "AluminumGas":         "aluminum",
    "CarbonDioxide":       "solid_carbon_dioxide",
    "CarbonGas":           "carbon",
    "ChlorineGas":         "solid_chlorine",
    "CobaltGas":           "cobalt_refined",
    "ContaminatedOxygen":  "solid_oxygen",
    "CopperGas":           "copper",
    "EthanolGas":          "ethanol",
    "Fallout":             "regolith",
    "GoldGas":             "gold",
    "Hydrogen":            "solid_hydrogen",
    "IridiumGas":          "iridium",
    "IronGas":             "iron",
    "LeadGas":             "lead",
    "MercuryGas":          "solid_mercury",
    "Methane":             "solid_methane",
    "NickelGas":           "nickel",
    "NiobiumGas":          "niobium",
    "Oxygen":              "solid_oxygen",
    "PhosphorusGas":       "phosphorus",
    "RockGas":             "granite",
    "SaltGas":             "salt",
    "SourGas":             "sludge",
    "Steam":               "ice",
    "SteelGas":            "steel",
    "SulfurGas":           "sulfur",
    "SuperCoolantGas":     "super_coolant",
    "TungstenGas":         "tungsten",

    # --- Prehistoric / Aquatic Planet Pack additions ---
    "Basalt":              "basalt",
    "Coquina":             "coquina",
    "Corallium":           "corallium",
    "FishMilk":            "milk",
    "FishMilkIce":         "milk",
    "FrozenInk":           "ink_frozen",
    "Galena":              "galena",
    "Ink":                 "ink_frozen",
    "Latex":               "rubber",
    "MoltenZinc":          "zinc",
    "Mucus":               "mucus",
    "MurkyBrine":          "frozen_brine",
    "MurkyBrineIce":       "frozen_brine",
    "PalmWood":            "palmwood",
    "Pearl":               "pearl",
    "Rubber":              "rubber",
    "SiltStone":           "siltstone",
    "SolidMucus":          "mucus",
    "Zinc":                "zinc",
    "ZincGas":             "zinc",
    "ZincOre":             "zinc_ore",

    # --- Special ---
    "COMPOSITION":         None,
    "Creature":            None,
    "Vacuum":              None,
    "Void":                None,
}


def center_crop(img: Image.Image, size: int) -> Image.Image:
    w, h = img.size
    if w > h:
        left = (w - h) // 2
        img = img.crop((left, 0, left + h, h))
    elif h > w:
        top = (h - w) // 2
        img = img.crop((0, top, w, top + w))
    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    if not ASSETS_FILE.exists():
        print(f"ERROR: asset bundle not found at {ASSETS_FILE}", file=sys.stderr)
        sys.exit(1)

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    print(f"Loading {ASSETS_FILE} …")
    env = UnityPy.load(str(ASSETS_FILE))

    # Index all Texture2D by name (keep last if duplicates)
    texture_map: dict[str, object] = {}
    for obj in env.objects:
        if obj.type.name == "Texture2D":
            data = obj.read()
            texture_map[data.m_Name] = data

    print(f"Found {len(texture_map)} Texture2D objects\n")

    extracted = 0
    skipped_no_mapping = 0
    skipped_no_texture = 0

    for element_id, texture_name in ELEMENT_TO_TEXTURE.items():
        out_path = OUTPUT_DIR / f"{element_id}.webp"

        if texture_name is None:
            skipped_no_mapping += 1
            print(f"  SKIP  {element_id} (no texture mapping)")
            continue

        if texture_name not in texture_map:
            skipped_no_texture += 1
            print(f"  MISS  {element_id} → '{texture_name}' (not in bundle)")
            continue

        tex_data = texture_map[texture_name]
        img = tex_data.image
        img = center_crop(img, ICON_SIZE)
        img.save(out_path, "webp", quality=WEBP_QUALITY)
        extracted += 1
        print(f"  OK    {element_id} → {texture_name}.webp")

    print(f"\nDone: {extracted} extracted, {skipped_no_texture} missing textures, {skipped_no_mapping} no mapping")


if __name__ == "__main__":
    main()
