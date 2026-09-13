@auth
<div x-data="releaseReport">
    <x-modal name="report" width="sm">
        <x-slot:title>Report release</x-slot:title>
        <x-slot:icon><i class="fas fa-flag text-red-600 dark:text-red-400"></i></x-slot:icon>
        <x-slot:subtitle><span x-text="releaseName"></span></x-slot:subtitle>
        <form id="shared-report-form" @submit.prevent="submit()">
                    <!-- Reason Select -->
                    <div class="mb-4">
                        <label for="shared-report-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Reason for Report <span class="text-red-500">*</span>
                        </label>
                        <select x-model="reason"
                                id="shared-report-reason"
                                required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <option value="">Select a reason...</option>
                            <option value="duplicate">Duplicate Release</option>
                            <option value="fake">Fake/Malicious Content</option>
                            <option value="password">Password Protected</option>
                            <option value="incomplete">Incomplete/Corrupted</option>
                            <option value="wrong_category">Wrong Category</option>
                            <option value="spam">Spam/Advertisement</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Description Textarea -->
                    <div class="mb-4">
                        <label for="shared-report-description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Additional Details (optional)
                        </label>
                        <textarea x-model="description"
                                  id="shared-report-description"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Provide any additional details that might help us review this report..."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 resize-none"></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-text="charCount()"></p>
                    </div>

                    <!-- Error Message -->
                    <div x-show="errorMsg" x-cloak class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                        <p class="text-sm text-red-600 dark:text-red-400" x-text="errorMsg"></p>
                    </div>

        </form>
        <x-slot:footer>
            <span class="flex-1"></span>
            <x-button variant="secondary" size="sm" @click="close()">Cancel</x-button>
            <x-button type="submit" form="shared-report-form" variant="danger" size="sm" ::disabled="!canSubmit()">
                <i x-show="isSubmitting" class="fas fa-spinner fa-spin"></i><span x-text="submitText()"></span>
            </x-button>
        </x-slot:footer>
    </x-modal>
</div>
@endauth
