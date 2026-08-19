<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BlacklistConstants;
use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\BlacklistService;
use App\Services\BlacklistSweepService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminBlacklistController extends BasePageController
{
    public function __construct(
        private readonly BlacklistSweepService $blacklistSweeps,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;

        $meta_title = $title = 'Binary Black/White List';

        $binlist = $svc->getBlacklist(false);

        $this->viewData = array_merge($this->viewData, [
            'binlist' => $binlist,
            'sweepStatus' => $this->visibleSweepStatus(),
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.blacklist.index', $this->viewData);
    }

    public function startSweep(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:dry-run,delete'],
            'rule_id' => ['nullable', 'integer', 'exists:binaryblacklist,id'],
        ]);

        try {
            $run = $this->blacklistSweeps->start(
                (string) $validated['mode'],
                isset($validated['rule_id']) ? (int) $validated['rule_id'] : null,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        unset($run['log_path']);

        return response()->json(['message' => 'Blacklist sweep started.', 'run' => $run], 202);
    }

    public function sweepStatus(): JsonResponse
    {
        return response()->json($this->visibleSweepStatus());
    }

    /**
     * @return array{running:bool, current:array<string, mixed>|null, last:array<string, mixed>|null}
     */
    private function visibleSweepStatus(): array
    {
        $status = $this->blacklistSweeps->status();
        foreach (['current', 'last'] as $key) {
            if (is_array($status[$key])) {
                unset($status[$key]['log_path']);
            }
        }

        return $status;
    }

    /**
     * @return RedirectResponse|View|void
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;
        $error = '';
        $regex = ['id' => '', 'groupname' => '', 'regex' => '', 'description' => '', 'msgcol' => 1, 'status' => 1, 'optype' => 1];
        $meta_title = $title = 'Binary Black/White list';

        switch ($request->input('action') ?? 'view') {
            case 'submit':
                if ($request->input('groupname') === '') {
                    $error = 'Group must be a valid usenet group';
                    break;
                }

                if ($request->input('regex') === '') {
                    $error = 'Regex cannot be empty';
                    break;
                }

                if (empty($request->input('id'))) {
                    $svc->addBlacklist($request->all());
                } else {
                    $svc->updateBlacklist($request->all());
                }

                return redirect()->to('admin/binaryblacklist-list');

            case 'addtest':
                if ($request->has('regex') && $request->has('groupname')) {
                    $regex += [
                        'groupname' => $request->input('groupname'),
                        'regex' => $request->input('regex'),
                        'ordinal' => 1,
                        'status' => 1,
                    ];
                }
                break;

            case 'view':
            default:
                if ($request->has('id')) {
                    $title = 'Binary Black/Whitelist Edit';
                    $regex = $svc->getBlacklistByID((int) $request->input('id'));
                } else {
                    $title = 'Binary Black/Whitelist Add';
                    $regex += [
                        'status' => 1,
                        'optype' => 1,
                        'msgcol' => 1,
                    ];
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'optype_ids' => [1, 2],
            'optype_names' => ['Black', 'White'],
            'msgcol_ids' => [
                BlacklistConstants::BLACKLIST_FIELD_SUBJECT,
                BlacklistConstants::BLACKLIST_FIELD_FROM,
                BlacklistConstants::BLACKLIST_FIELD_MESSAGEID,
            ],
            'msgcol_names' => ['Subject', 'Posted By', 'MessageId'],
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.blacklist.edit', $this->viewData);
    }
}
