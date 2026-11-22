import { afterEach, expect, it, vi } from 'vitest';
import { useCodex } from './useCodex';

afterEach(() => vi.unstubAllGlobals());

it('opens a dedicated Pacu variant before the shared family article', async () => {
    vi.stubGlobal('fetch', async (url: string) =>
        Response.json(
            url === '/codex'
                ? [
                      {
                          codex_id: 'PacuSpecies',
                          parent_codex_id: null,
                          prefabs: [
                              'PacuTropical',
                              'PacuTropicalBaby',
                              'PacuBeta',
                          ],
                      },
                      {
                          codex_id: 'PacuTropical',
                          parent_codex_id: 'PacuSpecies',
                          prefabs: ['PacuTropical', 'PacuTropicalBaby'],
                      },
                  ]
                : { codex_id: url.split('/').at(-1) },
        ),
    );
    const codex = useCodex();

    await codex.openForPrefab('PacuTropical');
    expect(codex.article.value?.codex_id).toBe('PacuTropical');

    await codex.openForPrefab('PacuTropicalBaby');
    expect(codex.article.value?.codex_id).toBe('PacuTropical');

    await codex.openForPrefab('PacuBeta');
    expect(codex.article.value?.codex_id).toBe('PacuSpecies');
});
