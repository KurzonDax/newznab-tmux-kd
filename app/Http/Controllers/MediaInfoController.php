<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Services\MediaInfo\MediaInfoPresentationService;
use Illuminate\Http\JsonResponse;

final class MediaInfoController extends Controller
{
    public function show(int $release, MediaInfoPresentationService $mediaInfo): JsonResponse
    {
        $model = Release::query()->findOrFail($release, ['id', 'searchname', 'display_name']);

        return response()->json($mediaInfo->forRelease($model));
    }
}
