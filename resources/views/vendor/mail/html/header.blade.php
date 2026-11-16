@props(['url'])
{{-- ComplyRobin's logo, as PNG: many email clients (Gmail among them) don't show SVG. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('images/brand/complyrobin-logo.png') }}" class="logo" alt="ComplyRobin" width="215" height="40" style="height: 40px; width: 215px; max-height: 40px;">
</a>
</td>
</tr>
