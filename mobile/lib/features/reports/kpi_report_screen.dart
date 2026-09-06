import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class KpiReportScreen extends StatefulWidget {
  const KpiReportScreen({super.key});

  @override
  State<KpiReportScreen> createState() => _KpiReportScreenState();
}

class _KpiReportScreenState extends State<KpiReportScreen> {
  bool _loading = true;
  bool _exporting = false;
  String? _errorText;
  Map<String, dynamic> _data = {};
  List<dynamic> _periods = [];
  String? _periodId;

  @override
  void initState() {
    super.initState();
    _loadPeriods();
  }

  Future<void> _loadPeriods() async {
    try {
      final response = await ApiService.get('/periods?include_history=1');
      if (!mounted) return;
      final periods = List<dynamic>.from(response['data'] as List? ?? const []);
      setState(() {
        _periods = periods;
        _periodId ??= (periods.firstOrNull)?['id']?.toString();
      });
      _loadReport();
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _errorText = _exceptionMessage(exception);
        });
      }
    }
  }

  Future<void> _loadReport() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _errorText = null;
      });
    }
    try {
      final query = _periodId == null ? '' : '?period_id=$_periodId';
      final response = await ApiService.get('/reports/kpi$query');
      if (mounted) {
        setState(() {
          _data = Map<String, dynamic>.from(response['data'] as Map);
          _loading = false;
        });
      }
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _errorText = _exceptionMessage(exception);
        });
      }
    }
  }

  Future<void> _export(String format) async {
    setState(() => _exporting = true);
    try {
      final query = _periodId == null ? '' : '?period_id=$_periodId';
      final bytes = await ApiService.getBytes(
        '/reports/kpi/export/$format$query',
      );
      final name = 'rekap-kpi-${_data['period']?['name'] ?? 'periode'}.$format'
          .replaceAll(' ', '-');
      final uri = await FilePicker.saveFile(
        fileName: name,
        bytes: bytes,
        mimeType: _mime(format),
      );
      if (mounted && uri != null) _message('File berhasil disimpan.');
    } catch (exception) {
      if (mounted) _showError(_exceptionMessage(exception));
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: OpsScreenLoading(rows: 5));
    if (_errorText != null) {
      return Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(_errorText!, textAlign: TextAlign.center),
                const SizedBox(height: 12),
                ElevatedButton(
                  onPressed: _loadReport,
                  child: const Text('Muat ulang'),
                ),
              ],
            ),
          ),
        ),
      );
    }
    final period = Map<String, dynamic>.from(
      _data['period'] as Map? ?? const {},
    );
    final rows = List<dynamic>.from(_data['rows'] as List? ?? const []);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Laporan KPI'),
        actions: [
          IconButton(
            onPressed: _loadReport,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _loadReport,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
          children: [
            if (_periods.isNotEmpty) ...[
              OpsSelectionField<String>(
                label: 'Periode',
                sheetTitle: 'Pilih periode',
                value: _periodId,
                searchable: _periods.length > 8,
                options: _periods.map((raw) {
                  final item = Map<String, dynamic>.from(raw as Map);
                  return OpsSelectionOption<String>(
                    value: item['id'].toString(),
                    label: item['name']?.toString() ?? 'Periode',
                  );
                }).toList(),
                onChanged: (value) {
                  setState(() => _periodId = value);
                  _loadReport();
                },
              ),
              const SizedBox(height: 14),
            ],
            OpsHeroCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    period['name']?.toString() ?? 'Periode KPI',
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${rows.length} karyawan',
                    style: TextStyle(color: AppTheme.textMuted),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _exporting ? null : () => _export('csv'),
                    icon: const Icon(Icons.table_view_rounded),
                    label: const Text('CSV'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _exporting ? null : () => _export('xlsx'),
                    icon: const Icon(Icons.grid_on_rounded),
                    label: const Text('XLSX'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _exporting ? null : () => _export('pdf'),
                    icon: const Icon(Icons.picture_as_pdf_rounded),
                    label: const Text('PDF'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            if (rows.isEmpty)
              const KpiEmptyState(
                icon: Icons.assessment_outlined,
                title: 'Belum ada laporan',
                message: 'Belum ada data KPI untuk periode ini.',
              ),
            ...rows.map(
              (raw) => _rowCard(Map<String, dynamic>.from(raw as Map)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _rowCard(Map<String, dynamic> row) {
    return OpsCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  row['employee_name']?.toString() ?? 'Karyawan',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              Text(
                row['final_score']?.toString() ?? '—',
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: AppTheme.primary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '${row['position_name'] ?? '—'} · ${row['branch_name'] ?? '—'}',
            style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(row['status']?.toString() ?? '—'),
              Text(row['rating_label']?.toString() ?? 'Belum ada predikat'),
            ],
          ),
        ],
      ),
    );
  }

  void _message(String message) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text(message), backgroundColor: AppTheme.primary),
  );
  void _showError(String message) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text(message), backgroundColor: AppTheme.statusDanger),
  );
  static String _exceptionMessage(Object exception) =>
      exception.toString().replaceFirst('Exception: ', '');
  static String _mime(String format) => switch (format) {
    'csv' => 'text/csv',
    'xlsx' =>
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'pdf' => 'application/pdf',
    _ => 'application/octet-stream',
  };
}
