import type { Component } from 'vue';

export interface GitSourceProvider {
    readonly id: string;
    readonly name: string;
    readonly icon: Component;
}

export interface GitSource {
    readonly id: string;
    readonly provider: GitSourceProvider;
    readonly account: string;
}
