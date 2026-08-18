<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AdminGroupListOrigin;
use App\Http\Requests\Admin\AdminGroupListRequest;
use Illuminate\Http\Request;

final readonly class AdminGroupReturnContext
{
    /**
     * @param  array{groupname?: string, page?: int}  $query
     */
    private function __construct(
        public AdminGroupListOrigin $origin,
        public array $query,
    ) {}

    public static function forList(AdminGroupListRequest $request, AdminGroupListOrigin $origin): self
    {
        $query = [];
        $groupName = $request->groupName();

        if ($groupName !== '') {
            $query['groupname'] = $groupName;
        }

        if ($request->filled('page')) {
            $query['page'] = $request->integer('page');
        }

        return new self($origin, $query);
    }

    public static function fromRequest(Request $request): self
    {
        $origin = AdminGroupListOrigin::fromInput($request->input('return_to'));
        $query = [];
        $groupName = $request->input('groupname');

        if (is_string($groupName)) {
            $groupName = trim($groupName);

            if ($groupName !== '' && mb_strlen($groupName) <= 255) {
                $query['groupname'] = $groupName;
            }
        }

        $page = filter_var($request->input('page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (is_int($page)) {
            $query['page'] = $page;
        }

        return new self($origin, $query);
    }

    /**
     * @return array{returnTo: string, returnQuery: array{groupname?: string, page?: int}}
     */
    public function listViewData(): array
    {
        return [
            'returnTo' => $this->origin->value,
            'returnQuery' => $this->query,
        ];
    }

    /**
     * @return array{returnTo: string, returnQuery: array{groupname?: string, page?: int}, returnUrl: string}
     */
    public function formViewData(): array
    {
        return $this->listViewData() + [
            'returnUrl' => route($this->origin->routeName(), $this->query),
        ];
    }
}
