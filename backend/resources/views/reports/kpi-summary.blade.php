<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap KPI {{ $period->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; }
        th { background: #e2e8f0; text-align: left; }
        td.number { text-align: right; }
    </style>
</head>
<body>
    <h1>Rekap KPI</h1>
    <p>Periode: {{ $period->name }}</p>

    <table>
        <thead>
            <tr>
                <th>Nomor Karyawan</th>
                <th>Nama Karyawan</th>
                <th>Jabatan</th>
                <th>Cabang</th>
                <th>Status KPI</th>
                <th>Progress (%)</th>
                <th>Skor Final</th>
                <th>Predikat</th>
                <th>Dikirim Pada</th>
                <th>Disetujui Pada</th>
                <th>Dikunci Pada</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row->employee_number }}</td>
                    <td>{{ $row->employee_name }}</td>
                    <td>{{ $row->position_name }}</td>
                    <td>{{ $row->branch_name }}</td>
                    <td>{{ $row->status }}</td>
                    <td class="number">{{ $row->progress_percentage !== null ? number_format((float) $row->progress_percentage, 2, '.', '') : '' }}</td>
                    <td class="number">{{ $row->final_score !== null ? number_format((float) $row->final_score, 2, '.', '') : '' }}</td>
                    <td>{{ $row->rating_label }}</td>
                    <td>{{ $row->submitted_at }}</td>
                    <td>{{ $row->approved_at }}</td>
                    <td>{{ $row->locked_at }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">Belum ada data KPI pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
