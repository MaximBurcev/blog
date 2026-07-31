<img src="{{ $src }}"
     @if($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
     alt="{{ $alt }}"
     @if($width) width="{{ $width }}" @endif
     @if($height) height="{{ $height }}" @endif
     loading="{{ $loading }}" decoding="async"
     @if($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
     {{ $attributes }}>
