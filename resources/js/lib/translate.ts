import { en } from '@/locales/en';
import type { TranslationKey } from '@/locales/en';

export function t(
    key: TranslationKey,
    params: Readonly<Record<string, string | number>> = {},
): string {
    return en[key].replace(
        /\{(\w+)\}/g,
        (placeholder: string, name: string) => {
            const value = params[name];

            return value === undefined ? placeholder : String(value);
        },
    );
}
