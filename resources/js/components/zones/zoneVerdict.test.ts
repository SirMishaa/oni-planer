import { describe, expect, it } from 'vitest';
import { entityProblemMark, zoneVerdict } from './zoneVerdict';

describe('zoneVerdict', () => {
    it('is unknown when nothing was read', () => {
        expect(zoneVerdict(0, false, false)).toBe('unknown');
    });

    it('never says all fine when a section is unknown', () => {
        expect(zoneVerdict(0, true, false)).toBe('partial');
        expect(zoneVerdict(0, false, true)).toBe('partial');
    });

    it('reports problems even from a partial read', () => {
        expect(zoneVerdict(2, true, false)).toBe('issues');
        expect(zoneVerdict(1, true, true)).toBe('issues');
    });

    it('is ok only when everything was read and nothing is wrong', () => {
        expect(zoneVerdict(0, true, true)).toBe('ok');
    });
});

describe('entityProblemMark', () => {
    it('marks problems, clear and unknown entities apart', () => {
        expect(entityProblemMark(1, true)).toBe('problem');
        expect(entityProblemMark(0, true)).toBe('clear');
        expect(entityProblemMark(0, false)).toBe('unknown');
    });
});
