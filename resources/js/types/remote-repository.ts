import type { PaginationMeta } from '@/types/pagination';

export type RepositorySort =
    | 'name'
    | 'issues_count'
    | 'pull_requests_count'
    | 'last_committed_at'
    | 'stars_count'
    | 'forks_count';

export interface RepositoryFilters {
    search: string;
    languages: string[];
    without_language: boolean;
    sort: RepositorySort;
    direction: 'asc' | 'desc';
}

export interface RemoteRepository {
    readonly external_id: string;
    readonly git_source_id: string;
    readonly name: string;
    readonly description: string | null;
    readonly url: string;
    readonly stars_count: number;
    readonly issues_count: number;
    readonly pull_requests_count: number;
    readonly forks_count: number;
    readonly language: string | null;
    readonly archived: boolean;
    readonly last_committed_at: number | null;
}

export interface RemoteRepositoryPage {
    readonly data: RemoteRepository[];
    readonly meta: PaginationMeta;
    readonly fingerprint: string;
    readonly languages: (string | null)[];
}

export interface RepositorySnapshot {
    readonly meta: PaginationMeta;
    readonly fingerprint: string;
    readonly languages: (string | null)[];
}

export interface RepositoryObservation {
    readonly sourceId: string;
    readonly page: number;
    readonly fingerprint: string;
    readonly filters: RepositoryFilters;
}
