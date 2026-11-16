@php
    /** @var \App\Modules\Credentials\Support\RotationEvidenceReport $report */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rotation evidence: {{ $report->organisation }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0F172A; }
        .brand { border-bottom: 2px solid #C2410C; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        p { margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .summary td { border: none; padding: 0 12px 0 0; width: auto; }
        .late { color: #b91c1c; font-weight: bold; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    {{-- The logo travels inside the file: PDF renderers don't fetch outside assets. --}}
    <div class="brand"><img src="data:image/png;base64,{{ base64_encode((string) file_get_contents(public_path('images/brand/complyrobin-logo.png'))) }}" alt="ComplyRobin" style="height: 22px;"></div>
    <h1>Rotation evidence report</h1>
    <p><strong>{{ $report->organisation }}</strong></p>
    <p>Period: {{ $report->from->format('j M Y') }} to {{ $report->to->format('j M Y H:i') }} ({{ $report->timezone }})</p>
    <p class="muted">Generated {{ $report->generatedAt->format('j M Y H:i') }} ({{ $report->timezone }}) by ComplyRobin. Lists what is known about each credential, never its secret value.</p>

    <table class="summary">
        <tr>
            <td>Rotations: <strong>{{ count($report->rotations) }}</strong></td>
            <td>On time: <strong>{{ $report->onTime() }}</strong></td>
            <td>Late: <strong>{{ $report->late() }}</strong></td>
            <td>Overdue now: <strong>{{ count($report->overdue) }}</strong></td>
        </tr>
    </table>

    <h2>Rotations</h2>
    @if ($report->rotations === [])
        <p>No rotations were recorded in this period.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Reference</th><th>Credential</th><th>Application</th><th>Environment</th><th>Due</th><th>Rotated</th>
                    <th>Recorded</th><th>On time</th><th>Rotated by</th><th>Evidence</th><th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->rotations as $rotation)
                    <tr>
                        <td>{{ $rotation['reference'] }}</td>
                        <td>{{ $rotation['credential'] }}</td>
                        <td>{{ $rotation['application'] }}</td>
                        <td>{{ $rotation['environment'] }}</td>
                        <td>{{ $rotation['due'] }}</td>
                        <td>{{ $rotation['rotated'] }}</td>
                        <td>{{ $rotation['recorded'] }}</td>
                        <td @class(['late' => ! $rotation['on_time']])>{{ $rotation['on_time'] ? 'On time' : 'Late' }}</td>
                        <td>{{ $rotation['rotated_by'] }}</td>
                        <td>{{ $rotation['evidence'] === [] ? 'None' : implode(', ', $rotation['evidence']) }}</td>
                        <td>{{ $rotation['notes'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Exceptions: overdue now</h2>
    @if ($report->overdue === [])
        <p>No credential is overdue.</p>
    @else
        <table>
            <thead>
                <tr><th>Reference</th><th>Credential</th><th>Application</th><th>Owner</th><th>Was due</th><th>Days overdue</th></tr>
            </thead>
            <tbody>
                @foreach ($report->overdue as $credential)
                    <tr>
                        <td>{{ $credential['reference'] }}</td>
                        <td>{{ $credential['credential'] }}</td>
                        <td>{{ $credential['application'] }}</td>
                        <td>{{ $credential['owner'] === '' ? 'No owner' : $credential['owner'] }}</td>
                        <td>{{ $credential['due'] }}</td>
                        <td class="late">{{ $credential['days_overdue'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
