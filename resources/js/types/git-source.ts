import type { Component } from 'vue';

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
    readonly last_synced_at: string | null;
}

export interface GitSourcePagination {
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
    readonly total: number;
}

export interface GitSourcePage {
    readonly data: GitSource[];
    readonly meta: GitSourcePagination;
}

export interface GitSourceResponse {
    readonly data: GitSource;
}
