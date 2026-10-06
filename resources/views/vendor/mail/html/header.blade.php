@props(['url'])
{{-- The app's name as a wordmark; styled in themes/default.css. No image, so it shows even when images are blocked. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
