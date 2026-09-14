                        @if(!empty($originalReportData) && $originalReportData->count() > 0)
                            <div class="mt-4 rounded-lg border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-900/20 p-4">
                                <h3 class="text-[13px] font-semibold text-orange-800 dark:text-orange-200 mb-3 flex items-center">
                                    <i class="fas fa-flag mr-2"></i> Original report
                                </h3>
                                <div class="space-y-3">
                                    @foreach($originalReportData as $originalReport)
                                        <div class="text-[13px] text-orange-900 dark:text-orange-100">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200 border border-orange-200 dark:border-orange-800">
                                                    {{ $originalReport->reason_label }}
                                                </span>
                                                <span class="text-xs text-orange-700 dark:text-orange-300">
                                                    {{ ucfirst($originalReport->status) }} · Reported {{ $originalReport->created_at->format('M d, Y H:i') }}
                                                </span>
                                            </div>
                                            <div class="whitespace-pre-wrap break-words">
                                                {{ $originalReport->description ?: 'No additional report details were provided.' }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if(!empty($publicReportResponses) && $publicReportResponses->count() > 0)
                            <div class="mt-4 rounded-lg border border-primary-200 dark:border-primary-800 bg-primary-50 dark:bg-primary-900/20 p-4">
                                <h3 class="text-[13px] font-semibold text-primary-800 dark:text-primary-200 mb-3 flex items-center">
                                    <i class="fas fa-reply mr-2"></i> Staff response
                                </h3>
                                <div class="space-y-3">
                                    @foreach($publicReportResponses as $responseReport)
                                        <div class="text-[13px] text-primary-900 dark:text-primary-100">
                                            <div class="whitespace-pre-wrap break-words">{{ $responseReport->response }}</div>
                                            <div class="mt-2 text-xs text-primary-700 dark:text-primary-300">
                                                {{ $responseReport->responded_at ? 'Responded ' . $responseReport->responded_at->format('M d, Y H:i') : 'Staff response' }}
                                                @if($responseReport->responder)
                                                    by {{ $responseReport->responder->username }}
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
