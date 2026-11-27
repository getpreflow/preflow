<div class="pf-columns pf-columns--{{ $layout }}">@foreach ($cells as $cell)<div class="pf-columns__cell pf-columns__cell--w{{ $cell['width'] }}">@blocks($cell['blocks'])</div>@endforeach</div>
