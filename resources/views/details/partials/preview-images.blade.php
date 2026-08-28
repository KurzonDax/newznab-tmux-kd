            <!-- Sample/Preview Images -->
            @php
                $hasSpectrogram = (bool) ($release->audioTags?->has_spectrogram ?? false);
                $isAudioRelease = \App\Models\Category::rootCategoryFor((int) ($release->categories_id ?? 0)) === \App\Models\Category::MUSIC_ROOT
                    || $hasSpectrogram;
                $hasPreviewImage = isset($release->haspreview) && $release->haspreview == 1 && ! $isAudioRelease;
                $hasSampleImage = isset($release->jpgstatus) && $release->jpgstatus == 1;
                $previewImageUrl = $hasPreviewImage
                    ? getImageAssetUrl('preview', $release->guid . '_thumb', asset('assets/images/no-cover.png'))
                    : null;
                $sampleImageUrl = $hasSampleImage
                    ? getImageAssetUrl('sample', $release->guid . '_thumb', asset('assets/images/no-cover.png'))
                    : null;
                // The Fullscreen view is offered only where a Full-size copy is
                // on disk (ADR 0012): the back catalog kept only its thumb.
                $previewFullUrl = $hasPreviewImage ? getImageAssetUrl('preview', $release->guid) : null;
                $sampleFullUrl = $hasSampleImage ? getImageAssetUrl('sample', $release->guid) : null;
                $hasVideoPreview = (int) ($release->videostatus ?? 0) === 1 && ! $isAudioRelease;
                $videoPreviewMime = $hasVideoPreview
                    ? ($release->videoClip?->clipMimeType() ?? \App\Models\ReleaseVideoClip::VIDEO_MIME_TYPES['ogv'])
                    : null;
            @endphp

            @if($hasPreviewImage || $hasSampleImage || $hasVideoPreview)
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-3 flex items-center">
                        <i class="fas fa-images mr-2 text-primary-600"></i>
                        @if($hasPreviewImage && $hasSampleImage)
                            Preview & Sample Images
                        @elseif($hasPreviewImage)
                            Preview Image
                        @elseif($hasSampleImage)
                            Sample Image
                        @else
                            Video Preview
                        @endif
                        @if($hasVideoPreview)
                            <button type="button"
                                    class="preview-badge ml-3 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 dark:bg-primary-900 text-primary-800 dark:text-primary-200 hover:bg-primary-200 dark:hover:bg-primary-800 transition cursor-pointer"
                                    data-guid="{{ $release->guid }}"
                                    @if($hasPreviewImage)
                                        data-image-url="{{ $previewImageUrl }}"
                                    @endif
                                    data-video-url="{{ route('preview.video', $release->guid) }}"
                                    data-video-type="{{ $videoPreviewMime }}"
                                    title="Watch video preview">
                                <i class="fas fa-video mr-1"></i> Preview
                            </button>
                        @endif
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        @if($hasPreviewImage)
                            <!-- Preview image -->
                            <div>
                                <div class="block cursor-pointer image-modal-trigger" data-image-url="{{ $previewImageUrl }}" data-image-title="Preview Image" @if($previewFullUrl) data-full-url="{{ $previewFullUrl }}" @endif>
                                    <img src="{{ $previewImageUrl }}"
                                         alt="Preview"
                                         class="detail-gallery-image w-full h-auto rounded-lg"
                                         loading="lazy">
                                </div>
                                <p class="text-xs text-gray-500 mt-1 text-center">Preview</p>
                            </div>
                        @endif

                        @if($hasSampleImage)
                            <!-- Sample image -->
                            <div>
                                <div class="block cursor-pointer image-modal-trigger" data-image-url="{{ $sampleImageUrl }}" data-image-title="Sample Image" @if($sampleFullUrl) data-full-url="{{ $sampleFullUrl }}" @endif>
                                    <img src="{{ $sampleImageUrl }}"
                                         alt="Sample"
                                         class="detail-gallery-image w-full h-auto rounded-lg"
                                         loading="lazy">
                                </div>
                                <p class="text-xs text-gray-500 mt-1 text-center">Sample</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
