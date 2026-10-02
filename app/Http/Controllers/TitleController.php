<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BrowseRoot;
use App\Services\Releases\TitleMetadataLoader;
use App\Services\Releases\TitleReleaseBrowser;
use Illuminate\Http\Request;

class TitleController extends BasePageController
{
    public function show(Request $request, string $root, string $id, TitleMetadataLoader $metadata, TitleReleaseBrowser $browser): mixed
    {
        $category = BrowseRoot::fromRoute($root);
        abort_unless($category === BrowseRoot::Audio, 404);
        abort_unless($this->userdata->getDirectPermissions()->contains('name', 'view audio'), 403);
        abort_unless(ctype_digit($id) && (int) $id > 0, 404);
        $id = (string) (int) $id;
        $title = $metadata->load($category, $id);
        $data = array_merge($this->viewData, $browser->load($category, $id, $this->userdata, $request), [
            'title' => $title, 'meta_title' => $title->entity->title,
        ]);
        if ($request->input('_fragment') === 'releases') {
            return view('title.partials.releases', $data);
        }

        return view('title.index', $data);
    }
}
