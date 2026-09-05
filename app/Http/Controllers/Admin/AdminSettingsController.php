<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\RootCategory;
use App\Services\Settings\SettingsCardUpdater;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The settings hub.
 *
 * Every page and every save is driven by {@see SettingsRegistry}: a URL that does not name a
 * registered section and card is a 404 rather than a blank form, and a save only ever touches
 * the keys the named card declares.
 */
class AdminSettingsController extends BasePageController
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsCardUpdater $updater,
    ) {
        parent::__construct();
    }

    /**
     * The hub has no page of its own; it opens on its first section.
     */
    public function index(): RedirectResponse
    {
        $section = $this->registry->defaultSectionId();

        if ($section === null) {
            throw new NotFoundHttpException('No settings sections are registered.');
        }

        return redirect()->to($this->sectionUrl($section));
    }

    /**
     * One page of the hub, plus the sidebar every page shares.
     */
    public function show(Request $request, string $section): View
    {
        $current = $this->registry->section($section);

        if ($current === null) {
            throw new NotFoundHttpException('Unknown settings section ['.$section.'].');
        }

        $this->setAdminPrefs();

        $query = trim((string) $request->query('q', ''));

        $this->viewData = array_merge($this->viewData, [
            'title' => $current->title,
            'meta_title' => $current->title.' Settings',
            'section' => $current,
            'sections' => $this->registry->sections(),
            'stages' => PipelineStage::strip(),
            'roots' => RootCategory::query()->orderBy('id')->get(),
            'searchQuery' => $query,
            'searchResults' => $this->registry->search($query),
        ]);

        return view('admin.settings.section', $this->viewData);
    }

    /**
     * Save one card. The card names the settings; the request supplies only their values.
     *
     * @throws ValidationException
     */
    public function update(Request $request, string $section, string $card): RedirectResponse
    {
        $settingCard = $this->registry->card($section, $card);

        if ($settingCard === null) {
            throw new NotFoundHttpException('Unknown settings card ['.$section.'/'.$card.'].');
        }

        // The updater owns the whole payload contract, envelope fields included, so this
        // hands it the request untouched rather than pre-filtering it in two places.
        $this->updater->update($settingCard, $request->all());

        return redirect()
            ->to($this->sectionUrl($section).'#card-'.$card)
            ->with('success', $settingCard->title.' saved.');
    }

    private function sectionUrl(string $section): string
    {
        return url('admin/settings/'.$section);
    }
}
