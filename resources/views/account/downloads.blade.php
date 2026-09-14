<section class="card account-card"><h2>Download usage</h2>@include('account.usage', ['id' => 'downloads', 'label' => 'Downloads (24 h)', 'used' => $downloads, 'limit' => $downloadLimit])</section>
<section class="card account-card"><h2>Recent downloads</h2>
    @if($recentDownloads->isEmpty())<p class="account-muted">No downloads yet.</p>@else
        <div class="overflow-x-auto"><table class="account-table"><thead><tr><th>Release</th><th>Downloaded</th></tr></thead><tbody>
            @foreach($recentDownloads as $download)<tr><td>@if($download->release)<a href="{{ route('details', $download->release->guid) }}">{{ release_display_name($download->release) }}</a>@else Release no longer available @endif</td><td>{{ userDate('M d, Y H:i', $download->timestamp) }}</td></tr>@endforeach
        </tbody></table></div>{{ $recentDownloads->links() }}
    @endif
</section>
