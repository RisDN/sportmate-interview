import { en } from '@/locales/en';
import type { TranslationKey } from '@/locales/en';
import { t } from '@/lib/translate';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

export function errorMessage(
    key: unknown,
    fallback: TranslationKey = 'errors.unexpected',
): string {
    const candidate = Array.isArray(key) ? key[0] : key;

    return typeof candidate === 'string' && Object.hasOwn(en, candidate)
        ? t(candidate as TranslationKey)
        : t(fallback);
}

export function isCancelledRequest(error: unknown): boolean {
    return isRecord(error) && error.code === 'ERR_CANCELLED';
}

export function apiErrorMessage(
    error: unknown,
    fallback: TranslationKey = 'errors.unexpected',
): string {
    if (!isRecord(error)) return t(fallback);
    if (error.code === 'ERR_NETWORK') return t('errors.network');
    if (!isRecord(error.response)) return t(fallback);
    if (error.response.status === 419) return t('errors.sessionExpired');

    let body: unknown = error.response.data;

    if (typeof body === 'string') {
        try {
            body = JSON.parse(body);
        } catch {
            return t(fallback);
        }
    }

    return isRecord(body) ? errorMessage(body.code, fallback) : t(fallback);
}
