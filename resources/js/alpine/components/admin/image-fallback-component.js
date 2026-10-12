/**
 * The `imageFallback` component body, kept free of any Alpine import so it can
 * be exercised headlessly. `image-fallback.js` is the thin registration shim.
 *
 * The root is the `<img>` itself and carries `data-fallback-src`, the placeholder
 * shown when the image cannot be loaded. The swap happens once: a placeholder
 * that fails too is left alone, so a missing placeholder cannot loop.
 */
export function imageFallback() {
    return {
        init() {
            const image = this.$el;
            let replaced = false;
            const useFallback = () => {
                if (replaced) { return; }
                replaced = true;
                image.src = image.dataset.fallbackSrc;
            };

            image.addEventListener('error', useFallback);

            // The component is fetched lazily, so the load may have failed before it started.
            if (image.complete && image.naturalWidth === 0) { useFallback(); }
        },
    };
}
