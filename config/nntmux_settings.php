<?php

return [
    'unrar_path' => env('UNRAR_PATH', '/usr/bin/unrar'),
    'unzip_path' => env('UNZIP_PATH', '/usr/bin/unzip'),
    'check_passworded_rars' => env('CHECK_PASSWORDED_RARS', false),
    'fetch_last_file' => env('FETCH_LAST_FILE', true),
    'payload_sniffing' => env('ADDITIONAL_PAYLOAD_SNIFFING', true),
    'payload_sniff_max_candidates' => env('ADDITIONAL_PAYLOAD_SNIFF_MAX_CANDIDATES', 3),
    'payload_sniff_byte_budget' => env('ADDITIONAL_PAYLOAD_SNIFF_BYTE_BUDGET', 4194304),
    'payload_sniff_small_segment_limit' => env('ADDITIONAL_PAYLOAD_SNIFF_SMALL_SEGMENT_LIMIT', 4),
    'mp4_tail_fetch' => env('ADDITIONAL_MP4_TAIL_FETCH', true),
    'mp4_tail_max_segments' => env('ADDITIONAL_MP4_TAIL_MAX_SEGMENTS', 60),
    'path_to_nzbs' => env('PATH_TO_NZBS', storage_path('nzb')),
    'store_user_ip' => env('STORE_USER_IP', false),
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'mediainfo_path' => env('MEDIAINFO_PATH', '/usr/bin/mediainfo'),
    'timeout_path' => env('TIMEOUT_PATH', '/usr/bin/timeout'),
    'magic_file_path' => env('MAGIC_FILE_PATH', '/usr/share/misc/magic'),
    'covers_path' => env('COVERS_PATH', storage_path('covers')),
    // Free-disk guard: the share of the covers volume that must stay free
    // before disk-hungry artifacts (Clips, release imagery) are produced.
    'covers_minimum_free_fraction' => (float) env('COVERS_MINIMUM_FREE_FRACTION', 0.10),
    'add_par2' => env('ADD_PAR2', false),
];
