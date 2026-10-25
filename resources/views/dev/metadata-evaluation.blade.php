{{-- Report of `php artisan metadata:evaluate`: every model's answer for the same image, side by side. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metadata models</title>
    <style>
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, sans-serif; color: #1f2329; background: #f5f6f8; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #6b7280; margin-bottom: 20px; }
        .sample { display: grid; grid-template-columns: 260px repeat({{ count($models) }}, minmax(240px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid #e4e7ec; border-radius: 10px; padding: 12px; }
        img { width: 100%; border-radius: 6px; display: block; }
        .model { font-weight: 600; margin-bottom: 8px; }
        .label { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; margin-top: 8px; }
        .len { color: #9ca3af; font-size: 11px; }
        .kw span { display: inline-block; margin: 2px 3px 0 0; padding: 1px 6px; border-radius: 4px; background: #f0f2f5; }
        .kw span.top { background: #dbeafe; }
        .fix { color: #b45309; }
        .error { color: #b91c1c; }
        .foot { color: #6b7280; font-size: 11px; margin-top: 8px; }
    </style>
</head>
<body>
    <h1>Metadata models</h1>
    <p class="meta">{{ now()->toDateTimeString() }} · {{ count($samples) }} images · settings: {{ json_encode($overrides ?: 'config defaults') }}</p>

    @foreach ($skipped as $name => $reason)
        <p class="error">Skipped {{ $name }}: {{ $reason }}</p>
    @endforeach

    @foreach ($samples as $index => $sample)
        <div class="sample">
            <div class="card">
                <img src="data:image/jpeg;base64,{{ base64_encode($sample['image']) }}" alt="">
                <div class="foot">{{ $sample['name'] }}</div>
            </div>

            @foreach ($models as $model)
                @php($result = $results[$model][$index])
                <div class="card">
                    <div class="model">{{ $model }}</div>

                    @if (is_string($result))
                        <div class="error">{{ $result }}</div>
                    @else
                        <div class="label">Title <span class="len">{{ mb_strlen($result->title) }}</span></div>
                        <div>{{ $result->title }}</div>

                        <div class="label">Description <span class="len">{{ mb_strlen($result->description) }}</span></div>
                        <div>{{ $result->description }}</div>

                        <div class="label">Keywords <span class="len">{{ count($result->keywords) }}</span></div>
                        <div class="kw">
                            @foreach ($result->keywords as $i => $keyword)
                                <span @class(['top' => $i < 10])>{{ $keyword }}</span>
                            @endforeach
                        </div>

                        <div class="label">Categories</div>
                        <div>
                            @forelse ($result->categories as $code => $ids)
                                {{ $code }}: {{ collect($ids)->map(fn ($id) => $sample['request']->categories[$code][$id] ?? $id)->implode(', ') }}<br>
                            @empty
                                —
                            @endforelse
                        </div>

                        @if ($result->looksEditorial)
                            <div class="label">Looks editorial</div>
                        @endif

                        @if ($result->fixes)
                            <div class="fix">Fixed: {{ implode(', ', $result->fixes) }}</div>
                        @endif

                        <div class="foot">
                            in {{ $result->usage->input }} · out {{ $result->usage->output }} (reasoning {{ $result->usage->reasoning }})
                            · {{ $result->cost() !== null ? '$'.number_format($result->cost(), 5) : 'no price' }}
                            · {{ round($result->durationMs / 1000, 1) }} s
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
