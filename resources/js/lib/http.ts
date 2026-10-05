// JSON requests outside Inertia, for background updates that shouldn't touch the page (Inertia
// visits always end in a page response). Laravel checks CSRF through the XSRF-TOKEN cookie.
const xsrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
};

/** Sends JSON; resolves to the response either way, rejects only if the network fails. */
export const sendJson = (method: 'PUT' | 'POST' | 'DELETE', url: string, body?: unknown) =>
    fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
