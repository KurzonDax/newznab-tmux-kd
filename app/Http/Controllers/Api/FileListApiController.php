<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\HiddenCategoryGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileListApiController extends Controller
{
    /**
     * Get file list for a release
     */
    public function getFileList(Request $request, string $guid, HiddenCategoryGate $hiddenCategories): JsonResponse
    {
        $nzb = app(NzbService::class);
        $nzbParser = app(NzbParserService::class);

        $rel = Release::getByGuid($guid);
        if (! $rel) {
            return response()->json(['error' => 'Release not found'], 404);
        }
        abort_if($hiddenCategories->hides($request->user()?->id, $rel['categories_id']), 403);

        $nzbpath = $nzb->nzbPath($guid);

        if (! file_exists($nzbpath)) {
            return response()->json(['error' => 'NZB file not found'], 404);
        }

        ob_start();
        @readgzfile($nzbpath);
        $nzbfile = ob_get_clean();

        $files = $nzbParser->parseNzbFileList($nzbfile);

        return response()->json([
            'release' => [
                'guid' => $rel['guid'],
                'searchname' => $rel['searchname'],
            ],
            'files' => $files,
            'total' => count($files),
        ]);
    }
}
