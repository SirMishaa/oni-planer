import { describe, expect, it } from 'vitest';
import { summarize } from './jsonTree';

describe('summarize', () => {
    it('counts the items of an array', () => {
        expect(summarize([1, 2, 3])).toEqual({ kind: 'array', count: 3 });
    });

    it('counts the keys of an object', () => {
        expect(summarize({ a: 1 })).toEqual({ kind: 'object', count: 1 });
    });

    it('writes null as a literal', () => {
        expect(summarize(null)).toEqual({ kind: 'literal', text: 'null' });
    });

    it('quotes strings like JSON', () => {
        expect(summarize('x')).toEqual({ kind: 'literal', text: '"x"' });
    });

    it('writes numbers and booleans as JSON', () => {
        expect(summarize(1.5)).toEqual({ kind: 'literal', text: '1.5' });
        expect(summarize(false)).toEqual({ kind: 'literal', text: 'false' });
    });
});
