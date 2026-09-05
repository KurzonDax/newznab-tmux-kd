<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\Settings\SettingsCardUpdater;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
