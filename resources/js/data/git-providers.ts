import { PhGitBranch, PhGithubLogo } from '@phosphor-icons/vue';
import { markRaw } from 'vue';
import { t } from '@/lib/translate';
import type { GitSourceProvider } from '@/types/git-source';

export const GitHubProvider = {
    id: 'github',
    name: t('provider.github'),
    icon: markRaw(PhGithubLogo),
} satisfies GitSourceProvider;

export function getGitProvider(id: string): GitSourceProvider {
    return id === GitHubProvider.id
        ? GitHubProvider
        : { id, name: id, icon: markRaw(PhGitBranch) };
}
