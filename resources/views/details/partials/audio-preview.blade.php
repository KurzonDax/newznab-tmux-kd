            <!-- Audio Preview -->
            @php
                $audioTags = $release->audioTags ?? null;
                // previewExtension() is null for a container the controller will not
                // serve, so the player is never offered where the route would 404.
                $audioPreviewMime = $audioTags?->playablePreviewMimeType();
            @endphp

            @if($audioPreviewMime !== null)
                @php
                    $audioPreviewUrl = route('preview.audio', $release->guid);
                    $audioPreviewMeta = $audioTags->previewSummary();
                    $spectrogramUrl = $audioTags->has_spectrogram
                        ? getImageAssetUrl('audiosample', $release->guid . '_spectrum', null, [], ['png'])
                        : null;
                @endphp
                <div class="surface-panel-alt rounded-lg p-6 border">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                        <i class="fas fa-headphones mr-2 text-primary-600 dark:text-primary-400"></i> Audio Preview
                    </h3>

                    <x-audio-preview-player
                        :audio-url="$audioPreviewUrl"
                        :audio-type="$audioPreviewMime"
                        :audio-meta="$audioPreviewMeta"
                    />

                    @if($spectrogramUrl)
                        <div class="mt-4">
                            <div class="block cursor-pointer image-modal-trigger" data-release-display-name="{{ release_display_name($release) }}" data-image-url="{{ $spectrogramUrl }}" data-image-title="Spectrogram">
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
