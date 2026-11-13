import type {
    ZoneBoxData,
    ZoneTerrainElementData,
} from '@/types/App/Data/data.d';
import { patternFor } from './substanceTexturePatterns';
import { type FluidCell, fluidAlpha } from './zoneFluids';
import { FLUID_FRAGMENT_SHADER, FLUID_VERTEX_SHADER } from './zoneFluidShaders';
import { parseRgba } from './zoneTemperature';

export type FluidRendererInput = {
    cells: FluidCell[];
    palette: ZoneTerrainElementData[];
    box: ZoneBoxData;
    cellPx: number;
    dpr: number;
};

export type FluidRenderer = {
    update(input: FluidRendererInput): void;
    start(): void;
    stop(): void;
    destroy(): void;
};

/** Flags in the blue byte of a packed cell. */
export const CELL_SURFACE_BIT = 1;
export const CELL_LIQUID_BIT = 2;

/** RGBA32F texels per palette entry: colour, pattern, effects, opacity. */
export const PALETTE_TEXELS = 4;

/** The animation is capped at 30 frames per second. */
export const FRAME_INTERVAL_MS = 1000 / 30;

/**
 * Scroll speed of a liquid whose export has none: the game does not scroll
 * it. Present values are the game's `LiquidShaderProperties.scrollSpeed`
 * (texture UV per second, 0.02 for most families), unchanged.
 */
const DEFAULT_SCROLL_SPEED = 0;

/** The cells texture stores the palette index in one byte. */
const MAX_PALETTE_INDEX = 255;

// region Packing

/**
 * RGBA8 bytes of the cells texture, row 0 at the top of the box: R palette
 * index, G amount, B flags, A 255 for a fluid cell (0 elsewhere). Reuses
 * `target` when it has the right size.
 */
export function packCells(
    cells: FluidCell[],
    box: ZoneBoxData,
    target?: Uint8Array,
): Uint8Array {
    const size = box.width * box.height * 4;
    const bytes =
        target !== undefined && target.length === size
            ? target
            : new Uint8Array(size);
    bytes.fill(0);

    for (const cell of cells) {
        if (
            cell.column < 0 ||
            cell.row < 0 ||
            cell.column >= box.width ||
            cell.row >= box.height ||
            cell.palette < 0 ||
            cell.palette > MAX_PALETTE_INDEX
        ) {
            continue;
        }

        const offset = (cell.row * box.width + cell.column) * 4;
        bytes[offset] = cell.palette;
        bytes[offset + 1] = Math.round(
            Math.min(1, Math.max(0, cell.amount)) * 255,
        );
        bytes[offset + 2] =
            (cell.surface ? CELL_SURFACE_BIT : 0) |
            (cell.kind === 'liquid' ? CELL_LIQUID_BIT : 0);
        bytes[offset + 3] = 255;
    }

    return bytes;
}

/**
 * RGBA32F texels of the palette texture, `PALETTE_TEXELS` per entry:
 * [r, g, b, alpha] (0 alpha without render colour, opaque liquids 1),
 * [noise scale, noise strength, flecks, streaks], [caustics, glows,
 * metallic, scroll speed], [opaque liquid, 0, 0, 0].
 */
export function packPalette(palette: ZoneTerrainElementData[]): Float32Array {
    const data = new Float32Array(
        Math.max(1, palette.length) * PALETTE_TEXELS * 4,
    );

    palette.forEach((entry, index) => {
        const offset = index * PALETTE_TEXELS * 4;
        const colour = parseRgba(entry.renderColour);
        const opaque = entry.state === 'liquid' && entry.opaqueLiquid;
        const pattern = patternFor(entry.substanceTexture);

        if (colour !== null) {
            data.set(
                [
                    colour[0] / 255,
                    colour[1] / 255,
                    colour[2] / 255,
                    fluidAlpha(
                        colour[3],
                        entry.state === 'gas' ? 'gas' : 'liquid',
                        opaque,
                    ),
                ],
                offset,
            );
        }

        data.set(
            [
                pattern.noiseScale,
                pattern.noiseStrength,
                pattern.flecks,
                pattern.streaks ? 1 : 0,
                entry.usesCaustics ? 1 : 0,
                entry.glows ? 1 : 0,
                entry.metallic ? 1 : 0,
                entry.scrollSpeed ?? DEFAULT_SCROLL_SPEED,
                opaque ? 1 : 0,
            ],
            offset + 4,
        );
    });

    return data;
}

// endregion

// region Frame budget

/** Animate only while the tab is visible, the map on screen and motion allowed. */
export function shouldAnimate(state: {
    hidden: boolean;
    onScreen: boolean;
    reducedMotion: boolean;
}): boolean {
    return !state.hidden && state.onScreen && !state.reducedMotion;
}

// endregion

// region WebGL

function compileShader(
    gl: WebGL2RenderingContext,
    type: number,
    source: string,
): WebGLShader | null {
    const shader = gl.createShader(type);

    if (!shader) {
        return null;
    }

    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.warn(
            `Zone fluids: shader failed to compile, showing static fluids.\n${gl.getShaderInfoLog(shader) ?? ''}`,
        );
        gl.deleteShader(shader);

        return null;
    }

    return shader;
}

function linkProgram(gl: WebGL2RenderingContext): WebGLProgram | null {
    const vertex = compileShader(gl, gl.VERTEX_SHADER, FLUID_VERTEX_SHADER);

    if (!vertex) {
        return null;
    }

    const fragment = compileShader(
        gl,
        gl.FRAGMENT_SHADER,
        FLUID_FRAGMENT_SHADER,
    );

    if (!fragment) {
        gl.deleteShader(vertex);

        return null;
    }

    const program = gl.createProgram();

    if (program) {
        gl.attachShader(program, vertex);
        gl.attachShader(program, fragment);
        gl.linkProgram(program);
    }

    gl.deleteShader(vertex);
    gl.deleteShader(fragment);

    if (program && !gl.getProgramParameter(program, gl.LINK_STATUS)) {
        console.warn(
            `Zone fluids: shader program failed to link, showing static fluids.\n${gl.getProgramInfoLog(program) ?? ''}`,
        );
        gl.deleteProgram(program);

        return null;
    }

    return program;
}

function nearestTexture(gl: WebGL2RenderingContext): WebGLTexture | null {
    const texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

    return texture;
}

/**
 * Animated liquids and gases on a transparent WebGL2 canvas, drawn above the
 * base map and under the solids. One draw call per frame; cells and palette
 * live in textures uploaded by `update`. Null without WebGL2 or when the
 * shaders fail, so the caller keeps the static fluids.
 */
export function createFluidRenderer(
    canvas: HTMLCanvasElement,
): FluidRenderer | null {
    const gl = canvas.getContext('webgl2', {
        alpha: true,
        premultipliedAlpha: true,
        antialias: false,
        depth: false,
        stencil: false,
    });

    if (!gl) {
        return null;
    }

    const program = linkProgram(gl);

    if (!program) {
        return null;
    }

    const vertexArray = gl.createVertexArray();
    const cellsTexture = nearestTexture(gl);
    const paletteTexture = nearestTexture(gl);
    const gridLocation = gl.getUniformLocation(program, 'uGrid');
    const resolutionLocation = gl.getUniformLocation(program, 'uResolution');
    const timeLocation = gl.getUniformLocation(program, 'uTime');

    gl.useProgram(program);
    gl.uniform1i(gl.getUniformLocation(program, 'uCells'), 0);
    gl.uniform1i(gl.getUniformLocation(program, 'uPalette'), 1);
    gl.disable(gl.BLEND);
    gl.clearColor(0, 0, 0, 0);

    const startedAt = performance.now();
    let cellBytes: Uint8Array | undefined;
    let cellsWidth = 0;
    let cellsHeight = 0;
    let hasCells = false;
    let running = false;
    let destroyed = false;
    let frameHandle = 0;
    let lastFrame = Number.NEGATIVE_INFINITY;

    const draw = (now: number): void => {
        if (destroyed || gl.isContextLost()) {
            return;
        }

        gl.viewport(0, 0, canvas.width, canvas.height);
        gl.clear(gl.COLOR_BUFFER_BIT);

        if (!hasCells) {
            return;
        }

        gl.useProgram(program);
        gl.bindVertexArray(vertexArray);
        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, cellsTexture);
        gl.activeTexture(gl.TEXTURE1);
        gl.bindTexture(gl.TEXTURE_2D, paletteTexture);
        gl.uniform2f(gridLocation, cellsWidth, cellsHeight);
        gl.uniform2f(resolutionLocation, canvas.width, canvas.height);
        gl.uniform1f(timeLocation, (now - startedAt) / 1000);
        gl.drawArrays(gl.TRIANGLES, 0, 3);
    };

    const tick = (now: number): void => {
        if (!running) {
            return;
        }

        frameHandle = requestAnimationFrame(tick);

        if (!hasCells || now - lastFrame < FRAME_INTERVAL_MS - 1) {
            return;
        }

        lastFrame = now;
        draw(now);
    };

    const stop = (): void => {
        running = false;
        cancelAnimationFrame(frameHandle);
    };

    const onContextLost = (): void => stop();
    canvas.addEventListener('webglcontextlost', onContextLost);

    return {
        update({ cells, palette, box, cellPx, dpr }) {
            if (destroyed || gl.isContextLost()) {
                return;
            }

            const width = Math.round(box.width * cellPx * dpr);
            const height = Math.round(box.height * cellPx * dpr);

            if (canvas.width !== width) {
                canvas.width = width;
            }

            if (canvas.height !== height) {
                canvas.height = height;
            }

            hasCells = cells.length > 0 && box.width > 0 && box.height > 0;

            if (hasCells) {
                cellBytes = packCells(cells, box, cellBytes);
                gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
                gl.activeTexture(gl.TEXTURE0);
                gl.bindTexture(gl.TEXTURE_2D, cellsTexture);

                if (cellsWidth === box.width && cellsHeight === box.height) {
                    gl.texSubImage2D(
                        gl.TEXTURE_2D,
                        0,
                        0,
                        0,
                        box.width,
                        box.height,
                        gl.RGBA,
                        gl.UNSIGNED_BYTE,
                        cellBytes,
                    );
                } else {
                    gl.texImage2D(
                        gl.TEXTURE_2D,
                        0,
                        gl.RGBA8,
                        box.width,
                        box.height,
                        0,
                        gl.RGBA,
                        gl.UNSIGNED_BYTE,
                        cellBytes,
                    );
                    cellsWidth = box.width;
                    cellsHeight = box.height;
                }

                gl.activeTexture(gl.TEXTURE1);
                gl.bindTexture(gl.TEXTURE_2D, paletteTexture);
                gl.texImage2D(
                    gl.TEXTURE_2D,
                    0,
                    gl.RGBA32F,
                    PALETTE_TEXELS,
                    Math.max(1, palette.length),
                    0,
                    gl.RGBA,
                    gl.FLOAT,
                    packPalette(palette),
                );
            }

            draw(performance.now());
        },
        start() {
            if (running || destroyed) {
                return;
            }

            running = true;
            lastFrame = Number.NEGATIVE_INFINITY;
            frameHandle = requestAnimationFrame(tick);
        },
        stop,
        destroy() {
            if (destroyed) {
                return;
            }

            stop();
            destroyed = true;
            canvas.removeEventListener('webglcontextlost', onContextLost);
            gl.deleteTexture(cellsTexture);
            gl.deleteTexture(paletteTexture);
            gl.deleteVertexArray(vertexArray);
            gl.deleteProgram(program);
            // Free the context now: browsers cap live WebGL contexts, and
            // Inertia remounts would otherwise pile them up until GC.
            gl.getExtension('WEBGL_lose_context')?.loseContext();
        },
    };
}

// endregion
