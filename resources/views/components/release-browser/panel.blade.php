            @php
                $row = $release->row_data;
                $entity = $row->entity;
            @endphp
            <div class="release-cover-release" data-cover-release="{{ $row->guid }}">
                <div class="min-w-0">
                    <div class="release-browser-titleline"><a data-release-title href="{{ route('details', $row->guid) }}">{{ $row->name }}</a></div>
                    @include('components.release-browser.facts')
                    <div class="release-cover-release-stats">
                        <span>{{ $row->size }}</span><span>{{ $row->files }} files</span><span>{{ $row->added }}</span>
                        <span><i class="fas fa-download text-green-600 dark:text-green-400" aria-hidden="true"></i> {{ number_format($row->grabs) }}</span>
                        <span><i class="fas fa-comment text-primary-600 dark:text-primary-400" aria-hidden="true"></i> {{ number_format($row->comments) }}</span>
                    </div>
                </div>
                @include('components.release-browser.actions')
            </div>
