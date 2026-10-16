@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center space-x-3">
            <div class="flex items-center justify-center w-12 h-12 bg-linear-to-br from-primary-400 to-purple-500 rounded-xl shadow-md">
                <i class="fas fa-chart-bar text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-bold text-gray-700 dark:text-white">{{ $title }}</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Overview of your site's performance and activity</p>
            </div>
        </div>
    </div>

    <!-- Usenet provider downloads -->
    @php
        // One hue per provider by position, cycling. Written out whole so Tailwind compiles them.
        $providerHues = [
            ['pill' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200', 'figure' => 'text-sky-700 dark:text-sky-300'],
            ['pill' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200', 'figure' => 'text-amber-700 dark:text-amber-300'],
            ['pill' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/60 dark:text-violet-200', 'figure' => 'text-violet-700 dark:text-violet-300'],
            ['pill' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200', 'figure' => 'text-rose-700 dark:text-rose-300'],
            ['pill' => 'bg-lime-100 text-lime-800 dark:bg-lime-900/60 dark:text-lime-200', 'figure' => 'text-lime-700 dark:text-lime-300'],
        ];
        $providerColumns = 'grid grid-cols-[minmax(16rem,1fr)_12rem_12rem_12rem] gap-x-6';
    @endphp
    <section class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-300 dark:border-gray-700" aria-labelledby="provider-downloads-title">
        <div class="bg-linear-to-r from-cyan-600 to-cyan-700 px-6 py-4">
            <div class="flex items-center space-x-3">
                <div class="flex items-center justify-center w-10 h-10 bg-cyan-900/40 rounded-lg">
                    <i class="fas fa-cloud-arrow-down text-cyan-50 text-lg" aria-hidden="true"></i>
                </div>
                <h2 id="provider-downloads-title" class="text-xl font-bold text-white">Usenet provider downloads</h2>
            </div>
        </div>
        @if(empty($providerDownloads['providers']))
            <div class="p-6">
                <p class="text-sm text-gray-700 dark:text-gray-200">No Usenet providers are configured, so there is nothing to count.</p>
            </div>
        @else
            <div class="p-6 space-y-3">
                <div class="{{ $providerColumns }} px-4">
                    <div class="text-sm font-semibold text-gray-700 dark:text-gray-100 self-end">Provider</div>
                    @foreach($providerDownloads['windows'] as $window)
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-700 dark:text-gray-100">{{ $window['title'] }}</div>
                            <div class="text-xs text-gray-600 dark:text-gray-300">{{ $window['range'] }}</div>
                        </div>
                    @endforeach
                </div>
                <ul class="space-y-3">
                    @foreach($providerDownloads['providers'] as $index => $provider)
                        @php($hue = $providerHues[$index % count($providerHues)])
                        <li class="{{ $providerColumns }} items-start p-4 bg-white dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-transparent">
                            <div>
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-sm font-semibold {{ $hue['pill'] }}">{{ $provider['name'] }}</span>
                                <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $provider['address'] }}</div>
                                <div class="mt-1.5 text-xs text-gray-600 dark:text-gray-300">
                                    {{ $provider['began'] === null ? 'No downloads recorded yet' : 'Counting began '.$provider['began'] }}
                                </div>
                            </div>
                            @foreach($provider['bytes'] as $bytes)
                                @php($gigabytes = number_format($bytes / 1_000_000_000, 2))
                                <div class="text-right">
                                    <div class="text-xl font-semibold tabular-nums {{ $gigabytes === '0.00' ? 'text-gray-500 dark:text-gray-400' : $hue['figure'] }}">{{ $gigabytes }} GB</div>
                                    <div class="text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($bytes) }} bytes</div>
                                </div>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Grabbers -->
        @if(!empty($topgrabs) && count($topgrabs) > 0)
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-300 dark:border-gray-700 hover:shadow-lg transition-shadow duration-300">
                <div class="bg-linear-to-r from-primary-400 to-primary-500 px-6 py-4">
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center justify-center w-10 h-10 bg-primary-700/40 rounded-lg">
                            <i class="fas fa-trophy text-primary-100 text-lg"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white">Top Grabbers</h2>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach($topgrabs as $index => $grab)
                            <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200 border border-gray-200 dark:border-transparent">
                                <div class="flex items-center space-x-4">
                                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-600 dark:bg-gray-500 text-white font-bold text-sm shadow-sm">
                                        {{ $index + 1 }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-700 dark:text-gray-100">{{ $grab['username'] }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="px-4 py-2 bg-accent-surface text-accent-on rounded-full text-sm font-bold shadow-md dark:bg-accent-surface-dark dark:text-accent-on-dark">
                                        {{ number_format($grab['grabs']) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif


        <!-- Recently Added -->
        @if(!empty($recent) && count($recent) > 0)
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-300 dark:border-gray-700 hover:shadow-lg transition-shadow duration-300">
                <div class="bg-linear-to-r from-green-400 to-green-500 px-6 py-4">
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center justify-center w-10 h-10 bg-green-700/40 rounded-lg">
                            <i class="fas fa-clock text-green-100 text-lg"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white">Recently Added</h2>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach($recent as $item)
                            <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200 border border-gray-200 dark:border-transparent">
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center justify-center w-8 h-8 bg-green-400 dark:bg-green-600 rounded-xl shadow-sm">
                                        <i class="fas fa-folder text-white text-sm"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-100">{{ $item['category'] }}</p>
                                </div>
                                <span class="px-4 py-2 bg-green-600 dark:bg-green-500 text-white rounded-full text-sm font-bold shadow-md">
                                    {{ number_format($item['count']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Users by Month -->
        @if(!empty($usersbymonth) && count($usersbymonth) > 0)
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-300 dark:border-gray-700 hover:shadow-lg transition-shadow duration-300">
                <div class="bg-linear-to-r from-indigo-400 to-indigo-500 px-6 py-4">
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center justify-center w-10 h-10 bg-indigo-700/40 rounded-lg">
                            <i class="fas fa-calendar text-indigo-100 text-lg"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white">User Signups by Month</h2>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        @foreach($usersbymonth as $month)
                            <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200 border border-gray-200 dark:border-transparent">
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center justify-center w-8 h-8 bg-indigo-400 dark:bg-indigo-600 rounded-xl shadow-sm">
                                        <i class="fas fa-users text-white text-sm"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-100">{{ $month['month'] }}</p>
                                </div>
                                <span class="px-4 py-2 bg-indigo-600 dark:bg-indigo-500 text-white rounded-full text-sm font-bold shadow-md">
                                    {{ number_format($month['signups']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Users by Role -->
        @if(!empty($usersbyrole) && count($usersbyrole) > 0)
            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-300 dark:border-gray-700 hover:shadow-lg transition-shadow duration-300">
                <div class="bg-linear-to-r from-pink-400 to-pink-500 px-6 py-4">
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center justify-center w-10 h-10 bg-pink-700/40 rounded-lg">
                            <i class="fas fa-user-shield text-pink-100 text-lg"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white">Users by Role</h2>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach($usersbyrole as $role)
                            <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200 border border-gray-200 dark:border-transparent">
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center justify-center w-8 h-8 bg-pink-400 dark:bg-pink-600 rounded-xl shadow-sm">
                                        <i class="fas fa-shield-alt text-white text-sm"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-100 capitalize">{{ $role['role'] }}</p>
                                </div>
                                <span class="px-4 py-2 bg-pink-600 dark:bg-pink-500 text-white rounded-full text-sm font-bold shadow-md">
                                    {{ number_format($role['users']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if(empty($topgrabs) && empty($recent) && empty($usersbymonth) && empty($usersbyrole))
        <div class="bg-gray-50 dark:bg-gray-800 rounded-xl shadow-md p-12 text-center border border-gray-300 dark:border-gray-700">
            <div class="flex justify-center mb-6">
                <div class="flex items-center justify-center w-24 h-24 bg-linear-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-600 rounded-full">
                    <i class="fas fa-chart-bar text-gray-500 dark:text-gray-500 text-4xl"></i>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-700 dark:text-gray-100 mb-3">No statistics available</h3>
            <p class="text-gray-600 dark:text-gray-400 text-lg">Statistics will appear here once data is collected.</p>
        </div>
    @endif
</div>
@endsection

