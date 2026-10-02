export function dateString(timestamp: number): string {
    return new Date(timestamp * 1000).toDateString();
}

export function dateTimeString(timestamp: number): string {
    return new Intl.DateTimeFormat('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(timestamp * 1000));
}

export function isoDateString(timestamp: number): string {
    return new Date(timestamp * 1000).toISOString();
}
