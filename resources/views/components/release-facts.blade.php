@props(['release', 'onlyWhenIncomplete' => false])

@php
    $loadedAudioTags = $release instanceof \Illuminate\Database\Eloquent\Model && $release->relationLoaded('audioTags')
        ? $release->getRelation('audioTags')
        : null;
    $loadedAudioPreviewMime = $loadedAudioTags?->playablePreviewMimeType();
    $audioPreviewMime = $release->audio_preview_mime ?? $loadedAudioPreviewMime;
    $hasAudioPreview = (bool) ($release->has_audio_preview ?? ($audioPreviewMime !== null));
    $audioPreviewMeta = $release->audio_preview_meta ?? ($hasAudioPreview ? $loadedAudioTags?->previewSummary() : null);
    $hasSpectrogram = (bool) ($release->has_spectrogram ?? $loadedAudioTags?->has_spectrogram ?? false);
    $isAudioRelease = \App\Models\Category::rootCategoryFor((int) ($release->categories_id ?? 0)) === \App\Models\Category::MUSIC_ROOT
        || $hasSpectrogram
        || $hasAudioPreview;
    $loadedVideoClip = $release instanceof \Illuminate\Database\Eloquent\Model && $release->relationLoaded('videoClip')
        ? $release->getRelation('videoClip')
        : null;
    $videoPreviewMime = $release->video_preview_mime ?? $loadedVideoClip?->clipMimeType();
    $hasVideoPreview = (bool) ($release->has_video_preview ?? ($videoPreviewMime !== null));
    $hasGeneratedPreview = isset($release->haspreview) && $release->haspreview == 1;
    $previewImageUrl = $isAudioRelease
        ? ($hasSpectrogram ? getImageAssetUrl('audiosample', $release->guid . '_spectrum', null, [], ['png']) : null)
        : ($hasGeneratedPreview ? getImageAssetUrl('preview', $release->guid . '_thumb') : null);
    $previewImageTitle = $hasAudioPreview ? 'Audio Preview' : ($isAudioRelease ? 'Spectrogram' : 'Preview Image');
    // The Fullscreen view is offered only where a Full-size copy is
    // on disk (ADR 0012): the back catalog and spectrograms have none.
    $previewFullUrl = ! $isAudioRelease && $hasGeneratedPreview
        ? getImageAssetUrl('preview', $release->guid)
        : null;
    $sampleFullUrl = isset($release->jpgstatus) && $release->jpgstatus == 1
        ? getImageAssetUrl('sample', $release->guid)
        : null;
    $showPreviewBadge = $isAudioRelease
        ? ($hasAudioPreview || ($hasGeneratedPreview && $hasSpectrogram && $previewImageUrl !== null))
        : ($hasGeneratedPreview || $hasVideoPreview);
@endphp

<x-release-completion-chips :release="$release" :only-when-incomplete="$onlyWhenIncomplete" />
@if($release->row_data?->passworded ?? ((int) ($release->passwordstatus ?? 0) > 0))
    <x-chip variant="danger" icon="fas fa-lock">Password</x-chip>
@endif
@if(!empty($release->has_media_info))
    <x-chip variant="primary" action icon="fas fa-circle-info" class="mediainfo-badge"
            :data-release-id="$release->id" :data-release-display-name="release_display_name($release)"
            title="View media info">{{ $release->media_info_summary ?? 'Media Info' }}</x-chip>
@endif
@if((int) ($release->nfostatus ?? 0) === 1 || !empty($release->nfoid))
    <x-chip variant="warning" action icon="fas fa-file-lines" class="nfo-badge" :data-guid="$release->guid" title="View NFO file">NFO</x-chip>
@endif
@if($showPreviewBadge)
    <x-chip variant="info" action :icon="$hasAudioPreview ? 'fas fa-headphones' : ($hasVideoPreview ? 'fas fa-video' : 'fas fa-image')"
            :class="'preview-badge '.($hasAudioPreview ? 'audio-preview-badge' : '')"
            :data-guid="$release->guid" :data-release-display-name="release_display_name($release)"
            :data-image-url="$previewImageUrl ?? ''" :data-image-title="$previewImageTitle" :data-full-url="$previewFullUrl"
            :data-audio-url="$hasAudioPreview ? route('preview.audio', $release->guid) : null"
            :data-audio-type="$hasAudioPreview ? $audioPreviewMime : null"
            :data-audio-meta="$hasAudioPreview ? $audioPreviewMeta : null"
            :data-audio-title="$hasAudioPreview ? ($release->audio_preview_title ?? $loadedAudioTags?->track_name ?? $loadedAudioTags?->album ?? release_display_name($release)) : null"
            :data-audio-artist="$hasAudioPreview ? ($release->audio_preview_artist ?? $loadedAudioTags?->performer ?? $loadedAudioTags?->album_performer) : null"
            :data-audio-artwork="$hasAudioPreview ? getReleaseCover($release) : null"
            :data-video-url="$hasVideoPreview ? route('preview.video', $release->guid) : null"
            :data-video-type="$hasVideoPreview ? $videoPreviewMime : null"
            :title="$hasAudioPreview ? 'Listen to audio preview' : ($hasVideoPreview ? 'Watch video preview' : 'View preview image')">{{ $hasAudioPreview ? 'Listen' : ($hasVideoPreview ? 'Clip' : 'Preview') }}</x-chip>
@endif
@if((int) ($release->jpgstatus ?? 0) === 1)
    <x-chip variant="success" action icon="fas fa-images" class="sample-badge"
            :data-guid="$release->guid" :data-release-display-name="release_display_name($release)"
            :data-image-url="getImageAssetUrl('sample', $release->guid . '_thumb')" :data-full-url="$sampleFullUrl"
            title="View sample image">Sample</x-chip>
@endif
