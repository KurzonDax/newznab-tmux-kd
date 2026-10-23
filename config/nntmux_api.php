<?php

return [
    'fanarttv_api_key' => env('FANARTTV_APIKEY', ''),
    'fanarttv_timeout' => env('FANARTTV_TIMEOUT', 30),
    'fanarttv_retry_times' => env('FANARTTV_RETRY_TIMES', 3),
    'fanarttv_retry_delay' => env('FANARTTV_RETRY_DELAY', 100),
    'google_books_api_key' => env('GOOGLE_BOOKS_API_KEY', ''),
    'isbndb_api_key' => env('ISBNDB_API_KEY', ''),
    'omdb_api_key' => env('OMDB_APIKEY', ''),
    'trakttv_api_key' => env('TRAKTTV_APIKEY', ''),
    'trakttv_timeout' => env('TRAKTTV_TIMEOUT', 30),
    'trakttv_retry_times' => env('TRAKTTV_RETRY_TIMES', 3),
    'trakttv_retry_delay' => env('TRAKTTV_RETRY_DELAY', 100),
];
