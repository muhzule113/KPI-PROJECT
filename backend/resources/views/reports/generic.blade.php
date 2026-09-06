<!doctype html>
<html lang="id"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px}h1{font-size:16px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:5px;text-align:left}th{background:#eee}
</style></head><body>
<h1>{{ $title }}</h1>
<table><thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
<tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</td>@endforeach</tr>@endforeach</tbody></table>
</body></html>
