import { createInertiaApp } from '@inertiajs/vue3';
import { t } from '@/lib/translate';

void createInertiaApp({
    title: (title) => (title ? `${title} | ${t('app.name')}` : t('app.name')),
});
