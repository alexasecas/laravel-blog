@extends('binshopsblog_admin::layouts.admin_layout')

@section('content')
    <h5>Admin — Uploaded Images</h5>
    <p>You can view all previously uploaded images here.</p>
    <p>It includes one thumbnail per photo — the smallest image is selected.</p>

    <script>
        function show_uploaded_file_row(id, img) {
            document.querySelectorAll('.' + id).forEach(function (el) { el.style.display = 'block'; });
            var el = document.getElementById(id);
            if (el) el.innerHTML = "<a href='" + img + "' target='_blank' rel='noopener'><img src='" + img + "' style='max-width:100%; height:auto;'></a>";
        }
    </script>

    @php
        $disk = config('binshopsblog.image_disk', 'public');
        $dir  = trim(str_replace('\\','/', config('binshopsblog.blog_upload_dir', 'images')), '/'); // normalize once
    @endphp

    @foreach ($uploaded_photos as $uploadedPhoto)
        @php
            $smallest = null;  // ['url'=>..., 'w'=>..., 'h'=>...]
            $files    = [];

            foreach ((array) $uploadedPhoto->uploaded_images as $fileKey => $f) {
                $name = $f['filename'] ?? null;
                if (!$name) continue;

                // compose normalized key and URL
                $nameNorm = ltrim(str_replace('\\','/',$name), '/');
                $key      = $dir ? "$dir/$nameNorm" : $nameNorm;
                $key      = preg_replace('~[\\\\/]+~','/',$key);

                $exists = \Storage::disk($disk)->exists($key);
                $url    = $exists ? \BinshopsBlog\Helpers::storage_url_clean($disk, $key) : null;

                $w = $f['w'] ?? null; $h = $f['h'] ?? null;

                $files[] = compact('fileKey','name','key','exists','url','w','h');

                if ($exists && $w && $h) {
                    $area = $w * $h;
                    if (!$smallest || ($w * $h) < ($smallest['w'] * $smallest['h'])) {
                        $smallest = ['url' => $url, 'w' => $w, 'h' => $h];
                    }
                }
            }
        @endphp

        <div class="rounded-3 border border-2 border-light bg-white my-3 p-3">
            <h3 class="h5 mb-1">
                Image ID: {{ $uploadedPhoto->id }}: {{ $uploadedPhoto->image_title ?? 'Untitled Photo' }}
            </h3>
            <h4 class="h6 text-muted">
                <small title="{{ $uploadedPhoto->created_at }}">
                    {{ __('Uploaded') }} {{ $uploadedPhoto->created_at->diffForHumans() }}
                </small>
            </h4>

            <div class="row">
                <div class="col-md-8">
                    <div class="row g-3 p-2" style="background:#eee; overflow:auto;">
                        @foreach ($files as $f)
                            @php $rowId = 'uploaded_'.$uploadedPhoto->id.'_'.$f['fileKey']; @endphp

                            <div class="col-12">
                                <h6 class="text-center mt-2">
                                    <strong>{{ $f['fileKey'] }}</strong>
                                    @if($f['w'] && $f['h']) — {{ $f['w'] }} × {{ $f['h'] }} @endif
                                </h6>

                                <p class="text-center mb-2">
                                    @if($f['exists'] && $f['url'])
                                        <a href="{{ $f['url'] }}" target="_blank" rel="noopener">[link]</a>
                                        /
                                        <button type="button"
                                                class="btn btn-sm btn-primary"
                                                style="cursor:zoom-in"
                                                onclick="show_uploaded_file_row('{{ $rowId }}','{{ $f['url'] }}')">
                                            show
                                        </button>
                                    @else
                                        <span class="badge text-bg-warning">Missing on {{ $disk }}</span>
                                    @endif
                                </p>

                                <div id="{{ $rowId }}"></div>
                            </div>

                            {{-- Reveal-on-show inputs --}}
                            <div class="col-md-6 {{ $rowId }}" style="display:none;">
                                <small class="text-muted d-block">Image URL</small>
                                <input type="text" readonly class="form-control" value="{{ $f['url'] ?? '' }}">
                            </div>
                            <div class="col-md-6 {{ $rowId }}" style="display:none;">
                                <small class="text-muted d-block">img tag</small>
                                <input type="text" readonly class="form-control"
                                       value='{{ $f["url"] ? "<img src=\"{$f["url"]}\" alt=\"".e($uploadedPhoto->image_title)."\" />" : "" }}'>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-md-4">
                    @if ($smallest && !empty($smallest['url']))
                        <div class="text-center">
                            <a style="cursor:zoom-in" href="{{ $smallest['url'] }}" target="_blank" rel="noopener">
                                <img src="{{ $smallest['url'] }}" alt="{{ $uploadedPhoto->image_title }}" style="max-width:100%; height:auto;">
                            </a>
                        </div>
                    @else
                        <div class="alert alert-danger mb-0">No image found</div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach

    <div class="text-center">
        {{ $uploaded_photos->appends([])->links() }}
    </div>
@endsection
