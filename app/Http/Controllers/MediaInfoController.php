<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Services\MediaInfo\MediaInfoPresentationService;
use App\Services\Releases\HiddenCategoryGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MediaInfoController extends Controller
{
    public function show(Request $request, int $release, MediaInfoPresentationService $mediaInfo, HiddenCategoryGate $hiddenCategories): JsonResponse
    {
        $model = Release::query()->findOrFail($release, ['id', 'searchname', 'display_name', 'resolution', 'categories_id']);
        abort_if($hiddenCategories->hides($request->user()?->id, $model->categories_id), 403);

        return response()->json($mediaInfo->forRelease($model));
    }
}
