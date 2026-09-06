import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class SupervisorAttendanceScreen extends StatefulWidget {
  const SupervisorAttendanceScreen({super.key});

  @override
  State<SupervisorAttendanceScreen> createState() =>
      _SupervisorAttendanceScreenState();
}

class _SupervisorAttendanceScreenState
    extends State<SupervisorAttendanceScreen> {
  DateTime _date = DateTime.now();
  bool _loading = true;
  bool _saving = false;
  String? _errorText;
  Map<String, dynamic> _data = {};
  final Map<String, String> _statuses = {};
  final Map<String, TextEditingController> _notes = {};

  String get _dateValue => _date.toIso8601String().substring(0, 10);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final controller in _notes.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _errorText = null;
      });
    }
    try {
      final response = await ApiService.get(
        '/supervisor/attendance?date=$_dateValue',
      );
      if (!mounted) return;
      final data = Map<String, dynamic>.from(response['data'] as Map);
      for (final raw in List<dynamic>.from(data['rows'] as List? ?? const [])) {
        final row = Map<String, dynamic>.from(raw as Map);
        final id = row['employee_id'].toString();
        _statuses[id] = row['status']?.toString() ?? '';
        _notes[id] ??= TextEditingController(
          text: row['note']?.toString() ?? '',
        );
        _notes[id]!.text = row['note']?.toString() ?? '';
      }
      setState(() {
        _data = data;
        _loading = false;
      });
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _errorText = exception.toString().replaceFirst('Exception: ', '');
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

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      final response = await ApiService.post('/supervisor/attendance', {
        'date': _dateValue,
        'statuses': _statuses,
        'notes': _notes.map((key, value) => MapEntry(key, value.text.trim())),
      });
      if (!mounted) return;
      _message(response['message']?.toString() ?? 'Absensi tim tersimpan.');
      _load();
    } catch (exception) {
      if (mounted) {
        _showError(exception.toString().replaceFirst('Exception: ', ''));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
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
                  onPressed: _load,
                  child: const Text('Muat ulang'),
                ),
              ],
            ),
          ),
        ),
      );
    }
    final rows = List<dynamic>.from(_data['rows'] as List? ?? const []);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Absensi Tim'),
        actions: [
          IconButton(
            onPressed: _pickDate,
            icon: const Icon(Icons.calendar_month_rounded),
          ),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh_rounded)),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.save_rounded),
            label: Text(_saving ? 'Menyimpan…' : 'Simpan absensi'),
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
          children: [
            OpsCard(
              child: Row(
                children: [
                  const Icon(
                    Icons.calendar_today_rounded,
                    color: AppTheme.primary,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Tanggal $_dateValue',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  TextButton(onPressed: _pickDate, child: const Text('Ubah')),
                ],
              ),
            ),
            if (rows.isEmpty)
              const KpiEmptyState(
                icon: Icons.groups_outlined,
                title: 'Tim belum tersedia',
                message: 'Tidak ada anggota tim pada snapshot periode ini.',
              ),
            ...rows.map((raw) {
              final row = Map<String, dynamic>.from(raw as Map);
              final id = row['employee_id'].toString();
              return OpsCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      row['name']?.toString() ?? 'Karyawan',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${row['employee_number'] ?? ''} · ${row['position'] ?? ''}',
                      style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
                    ),
                    const SizedBox(height: 10),
                    OpsSelectionField<String>(
                      label: 'Status kehadiran',
                      sheetTitle: 'Pilih status kehadiran',
                      value: _statuses[id]?.isEmpty == true
                          ? null
                          : _statuses[id],
                      options: const [
                        OpsSelectionOption(
                          value: 'present',
                          label: 'Hadir',
                          icon: Icons.check_circle_outline_rounded,
                        ),
                        OpsSelectionOption(
                          value: 'late',
                          label: 'Terlambat',
                          icon: Icons.schedule_rounded,
                        ),
                        OpsSelectionOption(
                          value: 'permission',
                          label: 'Izin',
                          icon: Icons.event_note_outlined,
                        ),
                        OpsSelectionOption(
                          value: 'sick_leave',
                          label: 'Sakit',
                          icon: Icons.medical_information_outlined,
                        ),
                        OpsSelectionOption(
                          value: 'absent',
                          label: 'Alpha',
                          icon: Icons.cancel_outlined,
                        ),
                      ],
                      onChanged: (value) =>
                          setState(() => _statuses[id] = value),
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      controller: _notes[id]!,
                      decoration: const InputDecoration(
                        labelText: 'Catatan (wajib untuk izin/sakit/alpha)',
                      ),
                    ),
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  void _message(String message) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text(message), backgroundColor: AppTheme.primary),
  );
  void _showError(String message) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text(message), backgroundColor: AppTheme.statusDanger),
  );
}
