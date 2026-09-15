<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Services\Nzb\NzbFileSummaryReader;
use App\Services\Nzb\NzbService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReleaseFilesController extends Controller
{
    public function __invoke(Request $request, string $guid, NzbService $nzb, NzbFileSummaryReader $reader): JsonResponse|View
    {
        $validator = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.intdiv(PHP_INT_MAX, 100)],
            'per' => ['sometimes', 'integer', 'in:24,48,100'],
        ]);
        abort_if($validator->fails(), 422, 'Invalid file list page.');
        $release = Release::query()->where('guid', $guid)->firstOrFail(['guid', 'searchname']);
        $path = $nzb->nzbPath($guid);
        abort_unless(is_string($path), 404, 'NZB file not found.');
        try {
            $data = ['release' => ['guid' => $release->guid, 'searchname' => $release->searchname]]
                + $reader->page($path, (int) $request->query('page', 1), (int) $request->query('per', 100));
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(422, 'The NZB file is invalid or incomplete.');
        }

        return $request->wantsJson() ? response()->json($data) : view('details.files', $data);
    }
}
