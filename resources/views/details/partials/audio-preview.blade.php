            <!-- Audio Preview -->
            @php
                $audioTags = $release->audioTags ?? null;
                $hasAudioPreview = $audioTags !== null && (bool) $audioTags->has_preview;
            @endphp

            @if($hasAudioPreview)
                @php
                    $audioPreviewUrl = route('preview.audio', $release->guid);
                    $audioPreviewMime = (string) ($audioTags->preview_mime ?: 'audio/mpeg');
                    $audioPreviewExtension = strtoupper((string) $audioTags->preview_extension);
                    $audioPreviewEncoding = $audioTags->previewEncodingLabel();
                    $audioPreviewMeta = array_values(array_filter([
                        $audioTags->preview_seconds ? $audioTags->preview_seconds . 's' : null,
                        $audioPreviewExtension !== '' ? $audioPreviewExtension : null,
                        $audioPreviewEncoding,
                    ]));
                    $spectrogramUrl = $audioTags->has_spectrogram
                        ? getImageAssetUrl('audiosample', $release->guid . '_spectrum', null, [], ['png'])
                        : null;
                @endphp
                <div class="surface-panel-alt rounded-lg p-6 border">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                        <i class="fas fa-headphones mr-2 text-primary-600 dark:text-primary-400"></i> Audio Preview
                    </h3>

                    <audio controls preload="none" class="w-full" src="{{ $audioPreviewUrl }}">
                        <source src="{{ $audioPreviewUrl }}" type="{{ $audioPreviewMime }}">
                        Your browser does not support playing this audio preview.
                    </audio>

                    @if(!empty($audioPreviewMeta))
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                            {{ implode(' · ', $audioPreviewMeta) }}
                        </p>
                    @endif

                    @if($spectrogramUrl)
                        <div class="mt-4">
                            <div class="block cursor-pointer image-modal-trigger" data-image-url="{{ $spectrogramUrl }}" data-image-title="Spectrogram">
                                <img src="{{ $spectrogramUrl }}"
                                     alt="Spectrogram of the audio preview"
                                     class="w-full max-w-full h-auto rounded-lg"
                                     loading="lazy">
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 text-center">Spectrogram</p>
                        </div>
                    @endif
                </div>
            @endif
