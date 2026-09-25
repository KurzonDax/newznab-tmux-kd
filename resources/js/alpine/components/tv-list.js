/**
 * Helpers shared by the TV screens' list components (tv-releases, tv-shows): posting a JSON
 * request with the CSRF token, rewriting one filter in the URL, and loading the list fragment.
 */
export async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('Request failed');
    return response.json();
}

/** The current URL with one filter's values replaced (as name[]) and the page dropped. */
export function filterUrl(href, name, values) {
    const url = new URL(href);
    [...url.searchParams.keys()].filter(key => key === name || key.startsWith(name + '[')).forEach(key => url.searchParams.delete(key));
    values.forEach(value => url.searchParams.append(name + '[]', value));
    url.searchParams.delete('page');
    return url;
}

/** The current URL without its page, for a new sort. */
export function firstPageUrl(href) {
    const url = new URL(href);
    url.searchParams.delete('page');
    return url;
}

/** Fetches the list fragment (?_fragment=list) of a URL; throws when it fails or redirects. */
export async function fetchList(url, signal) {
    const fragment = new URL(url.toString());
    fragment.searchParams.set('_fragment', 'list');
    const response = await fetch(fragment.toString(), { signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok || response.redirected) throw new Error('Could not load the list');
    return response.text();
}
