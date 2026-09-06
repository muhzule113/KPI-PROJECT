import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class DailyKpiScreen extends StatefulWidget {
  const DailyKpiScreen({super.key});

  @override
  State<DailyKpiScreen> createState() => _DailyKpiScreenState();
}

class _DailyKpiScreenState extends State<DailyKpiScreen> {
  DateTime _date = DateTime.now();
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _data = {};

  String get _dateValue => _date.toIso8601String().substring(0, 10);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final response = await ApiService.get('/my-kpi/daily?date=$_dateValue');
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
          _error = _message(exception);
        });
      }
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
      initialDate: _date,
    );
    if (picked != null && mounted) {
      setState(() => _date = picked);
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: OpsScreenLoading(rows: 5));
    if (_error != null) {
      return Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(_error!, textAlign: TextAlign.center),
                const SizedBox(height: 12),
                ElevatedButton(
                  onPressed: _load,
                  child: const Text('Muat ulang'),
                ),
              ],
            ),
          ),
        ),
      );
    }
    final items = List<dynamic>.from(_data['items'] as List? ?? const []);
    final period = Map<String, dynamic>.from(
      _data['period'] as Map? ?? const {},
    );
    return Scaffold(
      appBar: AppBar(
        title: const Text('KPI Harian Saya'),
        actions: [
          IconButton(
            onPressed: _pickDate,
            icon: const Icon(Icons.calendar_month_rounded),
          ),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh_rounded)),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 40),
          children: [
            OpsCard(
              child: Row(
                children: [
                  const Icon(Icons.today_rounded, color: AppTheme.primary),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      '$_dateValue · ${period['name'] ?? 'Periode aktif'}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  TextButton(onPressed: _pickDate, child: const Text('Ubah')),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Fakta harian dicatat Supervisor. Tampilan ini bersifat read-only.',
              style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
            ),
            const SizedBox(height: 14),
            if (items.isEmpty)
              const KpiEmptyState(
                icon: Icons.event_busy_rounded,
                title: 'Belum ada catatan',
                message: 'Belum ada fakta KPI harian untuk tanggal ini.',
              ),
            ...items.map(
              (raw) => _itemCard(Map<String, dynamic>.from(raw as Map)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _itemCard(Map<String, dynamic> item) {
    final definition = Map<String, dynamic>.from(
      item['item'] as Map? ?? const {},
    );
    final supervisorValue =
        item['supervisor_actual_decimal'] ??
        item['supervisor_score_percentage'];
    final effectiveValue =
        item['effective_actual_decimal'] ?? item['effective_rubric_score'];
    return OpsCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${definition['code'] ?? 'KPI'} · ${definition['name'] ?? 'Indikator'}',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              KpiStatusPill(
                label: item['entry_status']?.toString() ?? '—',
                color: AppTheme.primary,
                icon: Icons.fact_check_rounded,
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Target: ${definition['target_value'] ?? 'Belum ditentukan'} ${definition['target_unit'] ?? ''}',
                style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
              ),
              Text(
                'Bobot: ${definition['weight'] ?? 'N/A'}%',
                style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Nilai review: ${supervisorValue ?? 'Menunggu'}',
            style: TextStyle(color: AppTheme.textInk),
          ),
          const SizedBox(height: 4),
          Text(
            'Nilai efektif: ${effectiveValue ?? 'Menunggu sistem/review'}',
            style: TextStyle(color: AppTheme.textMuted),
          ),
          if (item['supervisor_note']?.toString().isNotEmpty == true)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                item['supervisor_note'].toString(),
                style: TextStyle(color: AppTheme.textMuted),
              ),
            ),
        ],
      ),
    );
  }

  static String _message(Object exception) =>
      exception.toString().replaceFirst('Exception: ', '');
}
