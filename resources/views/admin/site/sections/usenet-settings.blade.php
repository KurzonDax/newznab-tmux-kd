                <!-- Usenet Settings -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">Usenet Settings</h2>

                    <div class="space-y-4">
                        <div>
                            <label for="nzbsplitlevel" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-folder-tree mr-1"></i>NZB File Path Level Deep
                            </label>
                            <input type="text" id="nzbsplitlevel" name="nzbsplitlevel" value="{{ $site['nzbsplitlevel'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How many sub-directories deep, named after the leading characters of the release GUID, new NZB files are stored. Use <strong>0</strong> to store them flat, or leave the field blank for the default of 4. Changing this is safe on a live install: lookups fall back to the other depths, so existing files stay reachable without being moved.</p>
                        </div>

                        <div>
                            <label for="partretentionhours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-clock mr-1"></i>Part Retention Hours
                            </label>
                            <input type="text" id="partretentionhours" name="partretentionhours" value="{{ $site['partretentionhours'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The number of hours incomplete parts and binaries will be retained.</p>
                        </div>

                        <div>
                            <label for="releaseretentiondays" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-calendar-days mr-1"></i>Release Retention
                            </label>
                            <input type="text" id="releaseretentiondays" name="releaseretentiondays" value="{{ $site['releaseretentiondays'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The number of days releases will be retained for use throughout site. Set to 0 to disable.</p>
                        </div>

                        <div>
                            <label for="miscotherretentionhours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-hourglass mr-1"></i>Other->Misc Retention Hours
                            </label>
                            <input type="text" id="miscotherretentionhours" name="miscotherretentionhours" value="{{ $site['miscotherretentionhours'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The number of hours releases categorized as Misc->Other will be retained. Set to 0 to disable.</p>
                        </div>

                        <div>
                            <label for="mischashedretentionhours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-hashtag mr-1"></i>Other->Hashed Retention Hours
                            </label>
                            <input type="text" id="mischashedretentionhours" name="mischashedretentionhours" value="{{ $site['mischashedretentionhours'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The number of hours releases categorized as Misc->Hashed will be retained. Set to 0 to disable.</p>
                        </div>

                        <div>
                            <label for="partsdeletechunks" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-trash mr-1"></i>Parts Delete In Chunks
                            </label>
                            <input type="text" id="partsdeletechunks" name="partsdeletechunks" value="{{ $site['partsdeletechunks'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">Default is 0 (off), which will remove parts in one go. If backfilling or importing and parts table is large, using chunks of 5000+ will speed up removal. Normal indexing is fastest with this setting at 0.</p>
                        </div>

                        <div>
                            <label for="minfilestoformrelease" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-file-alt mr-1"></i>Minimum Files to Make a Release
                            </label>
                            <input type="text" id="minfilestoformrelease" name="minfilestoformrelease" value="{{ $site['minfilestoformrelease'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The minimum number of files to make a release. i.e. if set to two, then releases which only contain one file will not be created.</p>
                        </div>

                        <div>
                            <label for="minsizetoformrelease" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-compress mr-1"></i>Minimum File Size to Make a Release
                            </label>
                            <div class="flex gap-2">
                                <x-input type="number" step="any" min="0" id="minsizetoformrelease" name="minsizetoformrelease"
                                         class="flex-1" :value="$sizeFields['minsizetoformrelease']['value'] ?? 0" />
                                <div class="w-28 shrink-0">
                                    <x-select id="minsizetoformrelease_unit" name="minsizetoformrelease_unit">
                                        @foreach($sizeUnits as $unit)
                                            <option value="{{ $unit }}" {{ ($sizeFields['minsizetoformrelease']['unit'] ?? 'MB') === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">The minimum total size to make a release, stored as bytes. If set to 0, then ignored.</p>
                        </div>

                        <div>
                            <label for="maxsizetoformrelease" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-expand mr-1"></i>Maximum File Size to Make a Release
                            </label>
                            <div class="flex gap-2">
                                <x-input type="number" step="any" min="0" id="maxsizetoformrelease" name="maxsizetoformrelease"
                                         class="flex-1" :value="$sizeFields['maxsizetoformrelease']['value'] ?? 0" />
                                <div class="w-28 shrink-0">
                                    <x-select id="maxsizetoformrelease_unit" name="maxsizetoformrelease_unit">
                                        @foreach($sizeUnits as $unit)
                                            <option value="{{ $unit }}" {{ ($sizeFields['maxsizetoformrelease']['unit'] ?? 'MB') === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">The maximum total size to make a release, stored as bytes. If set to 0, then ignored. Only deletes during release creation.</p>
                        </div>

                        <div>
                            <label for="completionpercent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-percentage mr-1"></i>Minimum Completion Percent
                            </label>
                            <input type="text" id="completionpercent" name="completionpercent" value="{{ $site['completionpercent'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The minimum completion percent to make a release. i.e. if set to 97, then releases under 97% completion will not be created. If set to 0, then ignored.</p>
                        </div>

                        <div>
                            <label for="grabstatus" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-sync mr-1"></i>Update Grabs
                            </label>
                            <select id="grabstatus" name="grabstatus" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @foreach($yesno['ids'] as $index => $yesnoId)
                                    <option value="{{ $yesnoId }}" {{ ($site['grabstatus'] ?? '') == $yesnoId ? 'selected' : '' }}>
                                        {{ $yesno['names'][$index] }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Whether to update download counts when someone downloads a release.</p>
                        </div>

                        <div>
                            <label for="crossposttime" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-clock mr-1"></i>Crossposted Time Check
                            </label>
                            <input type="text" id="crossposttime" name="crossposttime" value="{{ $site['crossposttime'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The time in hours to check for crossposted releases - this will delete 1 of the releases if the 2 are posted by the same person in the same time period.</p>
                        </div>

                        <div>
                            <label for="maxmssgs" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-envelope mr-1"></i>Max Messages
                            </label>
                            <input type="text" id="maxmssgs" name="maxmssgs" value="{{ $site['maxmssgs'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The maximum number of messages to fetch at a time from the server.</p>
                        </div>

                        <div>
                            <label for="max_headers_iteration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-list-ol mr-1"></i>Max Headers Iteration
                            </label>
                            <input type="text" id="max_headers_iteration" name="max_headers_iteration" value="{{ $site['max_headers_iteration'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The maximum number of headers that update binaries sees as the total range. This ensures that a total of no more than this is attempted to be downloaded at one time per group.</p>
                        </div>

                        <div>
                            <label for="newgroupscanmethod" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-question-circle mr-1"></i>Where to Start New Groups
                            </label>
                            <select id="newgroupscanmethod" name="newgroupscanmethod" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 mb-2">
                                @foreach($yesno['ids'] as $index => $yesnoId)
                                    <option value="{{ $yesnoId }}" {{ ($site['newgroupscanmethod'] ?? '') == $yesnoId ? 'selected' : '' }}>
                                        {{ $newgroupscan_names[$index] ?? $yesno['names'][$index] }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="newgroupdaystoscan" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Days to Scan</label>
                                    <input type="text" id="newgroupdaystoscan" name="newgroupdaystoscan" value="{{ $site['newgroupdaystoscan'] ?? '' }}"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                </div>
                                <div>
                                    <label for="newgroupmsgstoscan" class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Posts to Scan</label>
                                    <input type="text" id="newgroupmsgstoscan" name="newgroupmsgstoscan" value="{{ $site['newgroupmsgstoscan'] ?? '' }}"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">Scan back X (posts/days) for each new group? Can backfill to scan further.</p>
                        </div>

                        <div>
                            <label for="safebackfilldate" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-calendar-alt mr-1"></i>Safe Backfill Date
                            </label>
                            <input type="text" id="safebackfilldate" name="safebackfilldate" value="{{ $site['safebackfilldate'] ?? '' }}"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-sm text-gray-500">The target date for safe backfill. Format: YYYY-MM-DD</p>
                        </div>

                        <div>
                            <label for="disablebackfillgroup" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fas fa-power-off mr-1"></i>Auto Disable Groups During Backfill
                            </label>
                            <select id="disablebackfillgroup" name="disablebackfillgroup" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-primary-500 focus:border-primary-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @foreach($yesno['ids'] as $index => $yesnoId)
                                    <option value="{{ $yesnoId }}" {{ ($site['disablebackfillgroup'] ?? '') == $yesnoId ? 'selected' : '' }}>
                                        {{ $yesno['names'][$index] }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-sm text-gray-500">Whether to disable a group automatically during backfill if the target date has been reached.</p>
                        </div>
                    </div>
                </div>

                <!-- Release Repair & Re-scan -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">Release Repair &amp; Re-scan</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        How hard the recovery passes work to rescue an incomplete release before the completion sweep
                        may delete it. Repair rebuilds segments whose message-IDs can be derived from the ones already
                        held; the re-scan goes back to the group's headers for files that were missed entirely.
                    </p>

                    <div class="space-y-4">
                        <div>
                            <x-label for="repair_retry_after_hours">
                                <i class="fas fa-rotate-right mr-1"></i>Repair Retry Window (hours)
                            </x-label>
                            <x-input type="number" min="0" step="any" id="repair_retry_after_hours" name="repair_retry_after_hours" :value="$site['repair_retry_after_hours'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How long after a repair pass falls short the final pass may run. Fresh releases are promoted at the collection timeout and repaired within hours, while their articles may still be propagating across the provider farm, so a first attempt can fail where a recheck days later succeeds. Every release gets two passes at most.</p>
                        </div>

                        <div>
                            <x-label for="repair_floor_completion">
                                <i class="fas fa-arrow-down-short-wide mr-1"></i>Repair Floor Completion
                            </x-label>
                            <x-input type="number" min="0" step="any" id="repair_floor_completion" name="repair_floor_completion" :value="$site['repair_floor_completion'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Releases measured below this percentage skip network repair entirely and go straight to a final outcome. A release holding under a tenth of its articles is not a header-scan miss, and confirming that costs article probes.</p>
                        </div>

                        <div>
                            <x-label for="repair_stat_sample_per_file">
                                <i class="fas fa-vial mr-1"></i>Repair Samples Per File
                            </x-label>
                            <x-input type="number" min="0" step="any" id="repair_stat_sample_per_file" name="repair_stat_sample_per_file" :value="$site['repair_stat_sample_per_file'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Synthesized message-IDs spot-checked per file before its segments are written into the NZB. A file is accepted only when every sampled ID exists. One sample cannot say whether the message-ID template was guessed correctly, so two is the sensible minimum.</p>
                        </div>

                        <div>
                            <x-label for="repair_max_stat_probes">
                                <i class="fas fa-gauge-high mr-1"></i>Repair Probe Ceiling Per Release
                            </x-label>
                            <x-input type="number" min="0" step="any" id="repair_max_stat_probes" name="repair_max_stat_probes" :value="$site['repair_max_stat_probes'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Hard ceiling on article existence probes for one release, however many files it has. When the budget runs out mid-way the remaining files are left for the next pass rather than accepted on a thinner sample.</p>
                        </div>

                        <div>
                            <x-label for="repair_limit">
                                <i class="fas fa-layer-group mr-1"></i>Repair Releases Per Run
                            </x-label>
                            <x-input type="number" min="0" step="any" id="repair_limit" name="repair_limit" :value="$site['repair_limit'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Releases one <code>releases:repair-completion</code> invocation works on. Repaired releases flow straight back into additional processing, so a large batch here starves fresh releases of post-processing capacity.</p>
                        </div>

                        <div>
                            <x-label for="rescan_limit">
                                <i class="fas fa-magnifying-glass-arrow-right mr-1"></i>Re-scan Releases Per Run
                            </x-label>
                            <x-input type="number" min="0" step="any" id="rescan_limit" name="rescan_limit" :value="$site['rescan_limit'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Releases one <code>releases:rescan-missing-files</code> invocation works on. The header re-scan recovers files the scan missed entirely, and it competes with live header scanning for the primary provider’s connections.</p>
                        </div>

                        <div>
                            <x-label for="rescan_window_minutes">
                                <i class="fas fa-clock-rotate-left mr-1"></i>Re-scan Window (minutes)
                            </x-label>
                            <x-input type="number" min="0" step="any" id="rescan_window_minutes" name="rescan_window_minutes" :value="$site['rescan_window_minutes'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How far either side of the release’s known article range the re-scan looks, in posting time. Widening it finds files posted further from the rest of the collection, at a proportional cost in overview lines fetched.</p>
                        </div>

                        <div>
                            <x-label for="rescan_max_articles_per_release">
                                <i class="fas fa-ruler-horizontal mr-1"></i>Re-scan Article Ceiling Per Release
                            </x-label>
                            <x-input type="number" min="0" step="any" id="rescan_max_articles_per_release" name="rescan_max_articles_per_release" :value="$site['rescan_max_articles_per_release'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">A release whose estimated article range is wider than this is stamped as skipped without fetching anything. Low-traffic groups give tight ranges; a busy group over a wide window can span millions of articles for one release.</p>
                        </div>

                        <div>
                            <x-label for="rescan_max_articles_per_run">
                                <i class="fas fa-ruler-combined mr-1"></i>Re-scan Article Ceiling Per Run
                            </x-label>
                            <x-input type="number" min="0" step="any" id="rescan_max_articles_per_run" name="rescan_max_articles_per_run" :value="$site['rescan_max_articles_per_run'] ?? ''" />
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The invocation stops fetching once this many overview lines have been read, whatever is left in the batch. The unfinished releases keep their state and are picked up next run.</p>
                        </div>
                    </div>
                </div>
