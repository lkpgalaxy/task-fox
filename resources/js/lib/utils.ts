import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

const padDatePart = (value: number): string =>
    value.toString().padStart(2, '0');

const dateOnlyPattern = /^(\d{4})-(\d{2})-(\d{2})$/;

export function formatDisplayDate(value: string | null): string {
    if (!value) {
        return 'None';
    }

    const dateOnlyMatch = dateOnlyPattern.exec(value);

    if (dateOnlyMatch) {
        return `${dateOnlyMatch[3]}/${dateOnlyMatch[2]}/${dateOnlyMatch[1]}`;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return [
        padDatePart(date.getDate()),
        padDatePart(date.getMonth() + 1),
        date.getFullYear(),
    ].join('/');
}

export function formatDisplayDateTime(value: string | null): string {
    if (!value) {
        return 'None';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return formatDisplayDate(value);
    }

    return `${formatDisplayDate(value)} ${padDatePart(date.getHours())}:${padDatePart(date.getMinutes())}`;
}
