import { PhGithubLogo } from '@phosphor-icons/vue';
import { markRaw } from 'vue';
import { t } from '@/lib/translate';
import type { GitSource, GitSourceProvider } from '@/types/git-source';

export const GitHubProvider = {
    id: 'github',
    name: t('provider.github'),
    icon: markRaw(PhGithubLogo),
} satisfies GitSourceProvider;

export const mockGitSources: GitSource[] = [
    'vuejs',
    'laravel',
    'tailwindlabs',
    'evan-you',
    'shadcn-ui',
].map((account) => ({
    id: `github:${account}`,
    provider: GitHubProvider,
    account,
}));
