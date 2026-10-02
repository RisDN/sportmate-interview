import type { Component } from 'vue';
import type { PaginationMeta } from '@/types/pagination';

export type GitSourceSyncStatus =
    | 'idle'
    | 'queued'
    | 'syncing'
    | 'waiting'
    | 'succeeded'
    | 'failed';

export interface GitSourceProvider {
    readonly id: string;
    readonly name: string;
    readonly icon: Component;
}

export interface GitSource {
    readonly id: string;
    readonly provider: string;
    readonly account: string;
    readonly name: string;
    readonly url: string;
    readonly avatar_url: string | null;
    readonly account_type: 'user' | 'organization';
    readonly last_synced_at: number | null;
    readonly sync_status: GitSourceSyncStatus;
    readonly last_sync_error_code: string | null;
    readonly last_sync_error_at: number | null;
    readonly sync_retry_at: number | null;
    readonly sync_revision: number;
}

export type GitSourcePagination = PaginationMeta;

export interface GitSourcePage {
    readonly data: GitSource[];
    readonly meta: GitSourcePagination;
}

export interface GitSourceResponse {
    readonly data: GitSource;
}
