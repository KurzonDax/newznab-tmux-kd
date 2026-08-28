<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Image Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default image processing driver that will be
    | used when manipulating or converting images. This driver is always
    | utilized unless another driver is explicitly specified instead.
    |
    | Supported: "gd", "imagick"
    |
    */

    'default' => env('IMAGE_DRIVER', 'imagick'),

    /*
    |--------------------------------------------------------------------------
    | Stored Image Processing
    |--------------------------------------------------------------------------
    |
    | These limits apply to covers, previews, and image samples. Destination
    | directories remain owned by their callers; this configuration controls
    | only decoding, remote fetching, and the encoded output.
    |
    */

    'output_format' => env('IMAGE_OUTPUT_FORMAT', 'webp'),
    'output_quality' => (int) env('IMAGE_OUTPUT_QUALITY', 82),
    'max_source_bytes' => (int) env('IMAGE_MAX_SOURCE_BYTES', 20 * 1024 * 1024),
    'max_source_pixels' => (int) env('IMAGE_MAX_SOURCE_PIXELS', 40_000_000),

    /*
    |--------------------------------------------------------------------------
    | Full-size Copy
    |--------------------------------------------------------------------------
    |
    | Release imagery extracted from a post is stored twice: the small display
    | thumb, plus a Full-size copy fitted to this box at this quality. The copy
    | is always re-encoded -- the poster's bytes are never stored verbatim.
    |
    */

    'full_max_width' => (int) env('IMAGE_FULL_MAX_WIDTH', 1920),
    'full_max_height' => (int) env('IMAGE_FULL_MAX_HEIGHT', 1920),
    'full_output_quality' => (int) env('IMAGE_FULL_OUTPUT_QUALITY', 90),

    /*
    |--------------------------------------------------------------------------
    | Extracted Imagery Ceilings
    |--------------------------------------------------------------------------
    |
    | Hard ceilings for images extracted from releases. Anything at or under
    | them is downscaled rather than refused; anything over is refused and
    | logged. Dimensions are sniffed from the file header before any decode,
    | which is what keeps a decompression bomb out of memory.
    |
    */

    'extracted_max_source_bytes' => (int) env('IMAGE_EXTRACTED_MAX_SOURCE_BYTES', 100 * 1024 * 1024),
    'extracted_max_source_pixels' => (int) env('IMAGE_EXTRACTED_MAX_SOURCE_PIXELS', 120_000_000),
    'fetch_connect_timeout' => (int) env('IMAGE_FETCH_CONNECT_TIMEOUT', 5),
    'fetch_timeout' => (int) env('IMAGE_FETCH_TIMEOUT', 30),
    'fetch_max_redirects' => (int) env('IMAGE_FETCH_MAX_REDIRECTS', 5),

];
