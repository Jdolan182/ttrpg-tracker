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

/**
 * A short, fast fingerprint of a string (cyrb53). Used to tell whether an encounter has changed since
 * it was saved without keeping a second full copy of it. Not for anything security-related.
 */
export function fingerprint(text: string): string {
    let h1 = 0xdeadbeef;
    let h2 = 0x41c6ce57;
    for (let i = 0; i < text.length; i++) {
        const ch = text.charCodeAt(i);
        h1 = Math.imul(h1 ^ ch, 2654435761);
        h2 = Math.imul(h2 ^ ch, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
    return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}
