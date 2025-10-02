@extends('binshopsblog_admin::layouts.admin_layout')

@section('content')
    <h5>Admin — Upload Images</h5>
    <p>Upload was successful.</p>

    @php
        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(str_replace('\\', '/', config('binshopsblog.blog_upload_dir', 'images')), '/'); // normalize once
    @endphp

    @forelse($images as $image)
        @php
            $filename = $image['filename'] ?? null;
            $w = $image['w'] ?? null;
            $h = $image['h'] ?? null;

            // normalize filename and compose key
            $filenameNorm = $filename ? ltrim(str_replace('\\','/', $filename), '/') : null;
            $key = $filenameNorm ? ($dir ? "$dir/$filenameNorm" : $filenameNorm) : null;
            $key = $key ? preg_replace('~[\\\\/]+~', '/', $key) : null;

            $exists = $key && \Storage::disk($disk)->exists($key);
            $url    = $exists ? \BinshopsBlog\Helpers::storage_url_clean($disk, $key) : null;
        @endphp

        <div class="mb-4">
            <h4 class="mb-1">{{ $filename }}</h4>
            @if($w && $h)
                <h6 class="text-muted"><small>{{ $w }}x{{ $h }}</small></h6>
            @endif

            @if($exists && $url)
                <a href="{{ $url }}" target="_blank" rel="noopener">
                    <img src="{{ $url }}" alt="{{ $filename }}" style="max-width:400px; height:auto;">
                </a>

                <div class="mt-3">
                    <label class="form-label small mb-1">Direct URL</label>
                    <input type="text" readonly class="form-control" value="{{ $url }}">

                    <label class="form-label small mt-2 mb-1">HTML &lt;img&gt; tag</label>
                    <input type="text" readonly class="form-control" value="{{ "<img src=\"{$url}\" alt=\"\" />" }}">
                </div>
            @else
                <div class="alert alert-warning mt-2 mb-0">
                    This file was saved in the database, but could not be found on the
                    <code>{{ $disk }}</code> disk (key: <code>{{ $key }}</code>).
                </div>
            @endif
        </div>
    @empty
        <div class="alert alert-danger">No image was processed</div>
    @endforelse
@endsection
