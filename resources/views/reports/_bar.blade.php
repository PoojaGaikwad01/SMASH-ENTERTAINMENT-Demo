{{-- Horizontal bar (no chart library): $value of $max. --}}
<div class="h-2 w-full min-w-16 rounded-full bg-slate-100" role="presentation">
    <div class="h-2 rounded-full bg-orange-500" style="width: {{ $max > 0 ? round($value / $max * 100, 1) : 0 }}%"></div>
</div>
