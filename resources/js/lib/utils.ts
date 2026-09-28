import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { toRaw } from 'vue';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Deep copy of a (possibly reactive) value, e.g. an Inertia page prop, so editing the copy
 * never touches the original. structuredClone throws on Vue's reactive proxies, so unwrap first.
 */
export function plainCopy<T>(value: T): T {
    return structuredClone(toRaw(value));
}
