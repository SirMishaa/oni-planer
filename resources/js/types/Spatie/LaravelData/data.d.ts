import type {
    CursorPaginator,
    LengthAwarePaginator,
} from '../../Illuminate/data.d';
export type CursorPaginatedDataCollection<TKey, TValue> = CursorPaginator<
    TKey,
    TValue
>;
export type PaginatedDataCollection<TKey, TValue> = LengthAwarePaginator<
    TKey,
    TValue
>;
