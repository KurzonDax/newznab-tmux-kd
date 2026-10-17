<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Models\RootCategory;
use App\Support\SizeUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminCategoryController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $meta_title = $title = 'Category List';

        $categorylist = Category::getFlat();

        $this->viewData = array_merge($this->viewData, [
            'categorylist' => $categorylist,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.categories.index', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function create(Request $request)
    {
        // set the current action
        $action = $request->input('action') ?? 'view';

        switch ($action) {
            case 'submit':
                // Create new category
                $category = new Category;

                // Allow custom ID only when creating
                if ($request->filled('id')) {
                    $customId = $request->input('id');
                    // Check if ID already exists
                    if (Category::where('id', $customId)->exists()) {
                        return redirect()->back()->withInput()->with('error', 'Category ID '.$customId.' already exists. Please choose a different ID.');
                    }
                    $category->id = $customId;
                }

                $minimumSize = $this->submittedMinimumSize($request);

                $category->title = $request->input('title');
                $category->root_categories_id = $request->input('root_categories_id') ?: null;
                $category->status = Category::STATUS_ACTIVE;  // Always active
                $category->description = $request->input('description');
                $category->minsizetoformrelease = $minimumSize;
                $category->maxsizetoformrelease = 0;
                $category->save();

                return redirect()->to('admin/category-list')->with('success', 'Category created successfully');
            case 'view':
            default:
                $title = 'Add New Category';
                break;
        }

        // Get root categories for parent selection
        $rootCategories = RootCategory::orderBy('title')->get();

        $this->viewData = array_merge($this->viewData, [
            'category' => null,
            'rootCategories' => $rootCategories,
            'title' => $title,
            'meta_title' => 'Add New Category',
            'isCreate' => true,
            'minimumSize' => $this->minimumSizeField($request, 0),
            'sizeUnits' => SizeUnit::UNITS,
        ]);

        return view('admin.categories.edit', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        // set the current action
        $action = $request->input('action') ?? 'view';

        switch ($action) {
            case 'submit':
                $minimumSize = $this->submittedMinimumSize($request);
                $category = Category::find($request->input('id'));
                if ($category) {
                    $category->root_categories_id = $request->input('root_categories_id') ?: null;
                    $category->status = Category::STATUS_ACTIVE;  // Always active
                    $category->description = $request->input('description');
                    $category->minsizetoformrelease = $minimumSize;
                    $category->maxsizetoformrelease = 0;
                    $category->save();
                }

                return redirect()->to('admin/category-list')->with('success', 'Category updated successfully');
            case 'view':
            default:
                $category = null;
                $title = 'Category Edit';
                if ($request->has('id')) {
                    $id = $request->input('id');
                    $category = Category::find($id);
                }
                break;
        }

        // Get root categories for parent selection
        $rootCategories = RootCategory::orderBy('title')->get();

        $this->viewData = array_merge($this->viewData, [
            'category' => $category,
            'rootCategories' => $rootCategories,
            'title' => $title,
            'meta_title' => 'View/Edit categories',
            'isCreate' => false,
            'minimumSize' => $this->minimumSizeField($request, (int) ($category->minsizetoformrelease ?? 0)),
            'sizeUnits' => SizeUnit::UNITS,
        ]);

        return view('admin.categories.edit', $this->viewData);
    }

    /**
     * Delete a category.
     *
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function destroy(Request $request)
    {
        $id = $request->input('id');

        if (! $id) {
            return redirect()->to('admin/category-list')->with('error', 'No category ID provided.');
        }

        $category = Category::find($id);

        if (! $category) {
            return redirect()->to('admin/category-list')->with('error', 'Category not found.');
        }

        // Check for child categories
        if (Category::where('root_categories_id', $id)->exists()) {
            return redirect()->to('admin/category-list')->with('error', 'Cannot delete category "'.$category->title.'" because it has child categories. Remove or reassign them first.');
        }

        $title = $category->title;
        $category->delete();

        return redirect()->to('admin/category-list')->with('success', 'Category "'.$title.'" deleted successfully.');
    }

    /**
     * The submitted minimum size in bytes; an empty value is 0.
     *
     * @throws ValidationException when the unit is not one SizeUnit converts
     */
    private function submittedMinimumSize(Request $request): int
    {
        $value = $request->input('minsizetoformrelease');
        $unit = $request->input('minsizetoformrelease_unit', 'MB');

        try {
            return SizeUnit::toBytes(is_scalar($value) ? (string) $value : null, is_string($unit) ? $unit : '');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'minsizetoformrelease' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The value and unit the minimum size field shows.
     *
     * Input flashed by a failed submit wins over the stored minimum, an empty value included;
     * a flashed unit only when it is one the form offers.
     *
     * @return array{value: string, unit: string}
     */
    private function minimumSizeField(Request $request, int $storedBytes): array
    {
        $field = self::minimumSizeFromBytes($storedBytes);

        $old = $request->hasSession() ? $request->old() : [];
        if (is_array($old) && array_key_exists('minsizetoformrelease', $old)) {
            $value = $old['minsizetoformrelease'];
            $unit = $old['minsizetoformrelease_unit'] ?? null;
            $field = [
                'value' => is_scalar($value) ? (string) $value : '',
                'unit' => in_array($unit, SizeUnit::UNITS, true) ? $unit : $field['unit'],
            ];
        }

        return $field;
    }

    /**
     * Show stored bytes as a value and unit that save back to exactly those bytes.
     *
     * Whole gigabytes show in GB; anything else in MB with the fewest decimals that still
     * convert back to the same byte count, so an unchanged save never moves the minimum.
     * SizeUnit::fromBytes() rounds to two decimals and would.
     *
     * @return array{value: string, unit: string}
     */
    private static function minimumSizeFromBytes(int $bytes): array
    {
        if ($bytes <= 0) {
            return ['value' => '0', 'unit' => 'MB'];
        }

        if ($bytes % SizeUnit::GB === 0) {
            return ['value' => (string) intdiv($bytes, SizeUnit::GB), 'unit' => 'GB'];
        }

        // 20 decimals is the exact quotient, since a megabyte is 2^20 bytes
        for ($decimals = 0; $decimals < 20; $decimals++) {
            $value = sprintf('%.'.$decimals.'F', $bytes / SizeUnit::MB);
            if (SizeUnit::toBytes($value, 'MB') === $bytes) {
                return ['value' => $value, 'unit' => 'MB'];
            }
        }

        return ['value' => sprintf('%.20F', $bytes / SizeUnit::MB), 'unit' => 'MB'];
    }
}
