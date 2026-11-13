/**
 * Shaders of the animated fluid layer. One full-screen triangle; the fragment
 * shader finds its cell in the cells texture (R palette index, G amount,
 * B flags, A presence) and its look in the palette texture (four RGBA32F
 * texels per entry: colour, pattern, effects, opacity). Output is
 * premultiplied and fully transparent outside fluid cells.
 */

// region Vertex

export const FLUID_VERTEX_SHADER = `#version 300 es
void main() {
    vec2 corner = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
    gl_Position = vec4(corner * 2.0 - 1.0, 0.0, 1.0);
}
`;

// endregion

// region Fragment

export const FLUID_FRAGMENT_SHADER = `#version 300 es
precision highp float;
precision highp int;

uniform sampler2D uCells;
uniform highp sampler2D uPalette;
uniform vec2 uGrid;
uniform vec2 uResolution;
uniform float uTime;

out vec4 outColour;

const int SURFACE_BIT = 1;
const int LIQUID_BIT = 2;
// Liquids
const float WAVE_AMPLITUDE = 0.05;
// Cells per second per unit of the game's scroll speed: 0.02 drifts 0.25 cells/s.
const float SCROLL_RATE = 12.5;
const float FLECKS_PER_CELL = 4.0;
const float BUBBLE_CHANCE = 0.18;
const float CAUSTIC_STRENGTH = 0.16;
const float HIGHLIGHT_PX = 1.6;
const float GLOW_HALO_ALPHA = 0.35;
// Gases
const int GAS_PARTICLES = 2;

struct Cell {
    bool present;
    int palette;
    float amount;
    bool surface;
    bool liquid;
};

float hash(vec2 p) {
    p = fract(p * vec2(123.34, 456.21));
    p += dot(p, p + 45.32);
    return fract(p.x * p.y);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),
        mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm(vec2 p) {
    float value = 0.0;
    float weight = 0.5;
    for (int octave = 0; octave < 3; octave++) {
        value += weight * noise(p);
        p *= 2.03;
        weight *= 0.5;
    }
    return value / 0.875;
}

Cell cellAt(ivec2 position) {
    Cell cell = Cell(false, 0, 0.0, false, false);
    if (any(lessThan(position, ivec2(0))) || any(greaterThanEqual(position, ivec2(uGrid)))) {
        return cell;
    }
    vec4 texel = texelFetch(uCells, position, 0);
    int flags = int(texel.b * 255.0 + 0.5);
    cell.present = texel.a > 0.5;
    cell.palette = int(texel.r * 255.0 + 0.5);
    cell.amount = texel.g;
    cell.surface = (flags & SURFACE_BIT) != 0;
    cell.liquid = (flags & LIQUID_BIT) != 0;
    return cell;
}

vec4 paletteTexel(int palette, int offset) {
    return texelFetch(uPalette, ivec2(offset, palette), 0);
}

float caustic(vec2 p, float t) {
    vec2 q = p * 1.6;
    float sum = 0.0;
    for (int iteration = 0; iteration < 2; iteration++) {
        q += vec2(sin(q.y * 1.3 + t * 0.7), cos(q.x * 1.1 - t * 0.6)) * 0.6;
        sum += abs(sin(q.x + q.y));
    }
    return pow(1.0 - sum * 0.5, 4.0);
}

/** Soft dot of radius ~1 px around a position in cell units. */
float dotAt(vec2 local, vec2 position, float pixelCells, float radiusPx) {
    float distancePx = length(local - position) / pixelCells;
    return 1.0 - smoothstep(radiusPx * 0.5, radiusPx + 0.5, distancePx);
}

float surfaceLevel(Cell cell, vec2 world) {
    float level = 1.0 - cell.amount;
    if (cell.surface) {
        level += (sin(world.x * 1.7 + uTime * 1.3) * 0.6 + sin(world.x * 3.1 - uTime * 0.9) * 0.4) * WAVE_AMPLITUDE;
    }
    return level;
}

vec4 glowHalo(vec3 colour, float distanceCells) {
    float alpha = GLOW_HALO_ALPHA * exp(-distanceCells * 4.0) * (0.85 + 0.15 * sin(uTime * 1.6));
    return vec4(colour * alpha, alpha);
}

vec4 shadeLiquid(Cell cell, ivec2 position, vec2 world, vec2 local, float pixelCells) {
    vec4 base = paletteTexel(cell.palette, 0);
    vec4 pattern = paletteTexel(cell.palette, 1);
    vec4 effects = paletteTexel(cell.palette, 2);
    bool opaque = paletteTexel(cell.palette, 3).x > 0.5;
    bool glows = effects.y > 0.5;

    if (base.a <= 0.0 || cell.amount <= 0.0) {
        return vec4(0.0);
    }

    float level = surfaceLevel(cell, world);
    float depth = local.y - level;

    if (depth < 0.0) {
        return glows ? glowHalo(base.rgb, -depth) : vec4(0.0);
    }

    vec2 seed = vec2(position);
    vec2 noisePoint = (world + vec2(uTime * effects.w * SCROLL_RATE, 0.0)) / max(pattern.x, 0.5);
    if (pattern.w > 0.5) {
        noisePoint.x *= 0.35;
    }
    noisePoint.y += uTime * 0.05;
    float grain = fbm(noisePoint);

    vec3 rgb = base.rgb * (1.0 + (grain - 0.5) * 2.0 * pattern.y);
    float alpha = base.a;

    if (effects.x > 0.5) {
        rgb += caustic(world, uTime) * CAUSTIC_STRENGTH;
    }

    if (effects.z > 0.5) {
        float sheen = pow(max(0.0, sin(world.x * 0.9 + world.y * 0.4 - uTime * 0.6)), 12.0);
        rgb = mix(rgb, vec3(1.0), sheen * 0.35);
    }

    if (glows) {
        rgb *= 1.15 + 0.15 * sin(uTime * 1.6 + grain * 6.2832);
    }

    int flecks = int(pattern.z * FLECKS_PER_CELL + 0.5);
    for (int index = 0; index < 4; index++) {
        if (index >= flecks) {
            break;
        }
        vec2 fleckSeed = seed + float(index) * 7.13;
        float phase = hash(fleckSeed + 9.1) * 6.2832;
        vec2 fleck = vec2(
            0.1 + 0.8 * hash(fleckSeed) + sin(uTime * 0.3 + phase) * 0.05,
            level + (1.0 - level) * (0.1 + 0.8 * hash(fleckSeed + 1.7)) + sin(uTime * 0.5 + phase) * 0.03
        );
        float speck = dotAt(local, fleck, pixelCells, 1.0);
        rgb = mix(rgb, vec3(1.0), speck * 0.3);
        alpha = max(alpha, speck * 0.5);
    }

    if (!opaque && hash(seed + 0.37) < BUBBLE_CHANCE) {
        float period = 3.0 + 3.0 * hash(seed + 2.9);
        float rise = fract(uTime / period + hash(seed + 4.3));
        vec2 bubble = vec2(
            0.3 + 0.4 * hash(seed + 6.1) + sin(rise * 12.0) * 0.04,
            1.0 - rise * (1.0 - level)
        );
        float radiusPx = max(1.5, 0.07 / pixelCells);
        float distancePx = length(local - bubble) / pixelCells;
        float ring = 1.0 - smoothstep(0.0, 1.0, abs(distancePx - radiusPx));
        float strength = ring * 0.5 * (1.0 - rise * 0.6);
        rgb = mix(rgb, vec3(1.0), strength);
        alpha = max(alpha, strength);
    }

    if (cell.surface) {
        float highlight = 1.0 - smoothstep(HIGHLIGHT_PX * 0.4, HIGHLIGHT_PX, depth / pixelCells);
        rgb = mix(rgb, vec3(1.0), highlight * 0.45);
        alpha = max(alpha, highlight * 0.8);
    }

    rgb = clamp(rgb, 0.0, 1.0);
    alpha = clamp(alpha, 0.0, 1.0);

    return vec4(rgb * alpha, alpha);
}

vec4 shadeGas(Cell cell, ivec2 position, vec2 world, vec2 local, float pixelCells) {
    vec4 base = paletteTexel(cell.palette, 0);

    if (base.a <= 0.0 || cell.amount <= 0.0) {
        return vec4(0.0);
    }

    vec2 drift = vec2(uTime * 0.12, -uTime * 0.05);
    float wisp = fbm(world * 0.45 + drift);
    float fine = fbm(world * 1.3 - drift * 1.7 + 3.7);
    float alpha = base.a * cell.amount * mix(0.75, 1.25, wisp);
    vec3 rgb = base.rgb * (0.9 + 0.25 * fine);
    vec3 particleColour = min(base.rgb * 1.4 + 0.1, vec3(1.0));
    vec2 seed = vec2(position);

    for (int index = 0; index < GAS_PARTICLES; index++) {
        vec2 particleSeed = seed + float(index) * 3.71;
        vec2 particle = fract(
            vec2(hash(particleSeed), hash(particleSeed + 1.3))
            + vec2(uTime * 0.04, uTime * 0.015 + sin(uTime * 0.4 + particleSeed.x) * 0.05)
        );
        float speck = dotAt(local, particle, pixelCells, 1.0);
        rgb = mix(rgb, particleColour, speck * 0.6);
        alpha += speck * 0.25 * cell.amount;
    }

    alpha = clamp(alpha, 0.0, 1.0);

    return vec4(clamp(rgb, 0.0, 1.0) * alpha, alpha);
}

void main() {
    vec2 pixel = vec2(gl_FragCoord.x, uResolution.y - gl_FragCoord.y);
    vec2 world = pixel / uResolution * uGrid;
    ivec2 position = ivec2(floor(world));
    vec2 local = fract(world);
    float pixelCells = uGrid.x / uResolution.x;

    Cell here = cellAt(position);
    vec4 colour = vec4(0.0);

    if (here.present && here.liquid) {
        colour = shadeLiquid(here, position, world, local, pixelCells);
    } else {
        if (here.present) {
            colour = shadeGas(here, position, world, local, pixelCells);
        }

        Cell below = cellAt(position + ivec2(0, 1));
        if (below.present && below.liquid && below.surface && paletteTexel(below.palette, 2).y > 0.5) {
            vec4 halo = glowHalo(paletteTexel(below.palette, 0).rgb, (1.0 - local.y) + (1.0 - below.amount));
            colour += halo * (1.0 - colour.a);
        }
    }

    outColour = colour;
}
`;

// endregion
