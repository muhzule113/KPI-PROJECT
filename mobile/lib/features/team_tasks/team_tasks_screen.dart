import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import '../approval/approval_detail_screen.dart';
import '../review/daily_assessment_screen.dart';
import '../review/review_detail_screen.dart';

class TeamTasksScreen extends StatefulWidget {
  final bool manager;

  const TeamTasksScreen({super.key, required this.manager});

  @override
  State<TeamTasksScreen> createState() => _TeamTasksScreenState();
}

class _TeamTasksScreenState extends State<TeamTasksScreen> {
  bool _loading = true;
  bool _completed = false;
  String? _error;
  String? _date = _dateValue(DateTime.now());
  Map<String, dynamic>? _data;

  static String _dateValue(DateTime value) =>
      value.toIso8601String().substring(0, 10);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await ApiService.get(
        '/team/tasks${_date == null ? '' : '?date=$_date'}',
      );
      if (!mounted) return;
      setState(() {
        _data = Map<String, dynamic>.from(response['data'] as Map);
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error.toString().replaceAll('Exception: ', '');
        _loading = false;
      });
    }
  }

  Future<void> _pickDate() async {
    final selected = await showDatePicker(
      context: context,
      initialDate: _date == null ? DateTime.now() : DateTime.parse(_date!),
      firstDate: DateTime(2020),
      lastDate: DateTime.now(),
      helpText: 'Pilih tanggal penilaian',
      cancelText: 'Batal',
      confirmText: 'Pilih',
      builder: (context, child) => Theme(
        data: Theme.of(context).copyWith(
          datePickerTheme: DatePickerThemeData(
            backgroundColor: AppTheme.surface,
            surfaceTintColor: Colors.transparent,
            headerBackgroundColor: AppTheme.primary,
            headerForegroundColor: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppTheme.radiusLg),
            ),
          ),
        ),
        child: child!,
      ),
    );
    if (selected == null) return;
    _date = _dateValue(selected);
    await _load();
  }

  Future<void> _selectDate(String? value) async {
    _date = value;
    await _load();
  }

  String _dateLabel(BuildContext context) {
    if (_date == null) return 'Semua tanggal';
    final formatted = MaterialLocalizations.of(
      context,
    ).formatMediumDate(DateTime.parse(_date!));
    return _date == _dateValue(DateTime.now())
        ? 'Hari ini, $formatted'
        : formatted;
  }

  Future<void> _openTask(Map<String, dynamic> task) async {
    final action = Map<String, dynamic>.from(
      task['action'] as Map? ?? const {},
    );
    final type = action['type']?.toString() ?? task['type']?.toString();
    final kpiId = task['kpi_id'].toString();
    final date = DateTime.tryParse(task['date']?.toString() ?? '');
    final Widget screen = switch (type) {
      'monthly_review' => ReviewDetailScreen(kpiId: kpiId),
      'monthly_approval' => ApprovalDetailScreen(kpiId: kpiId),
      _ => DailyAssessmentScreen(
        manager: widget.manager,
        initialDate: date,
        kpiId: kpiId,
      ),
    };

    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Penilaian Tim')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? _errorState()
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                children: [
                  Text(
                    'Mulai dari nama paling atas, lalu selesaikan satu karyawan sebelum beralih.',
                    style: TextStyle(color: AppTheme.textMuted),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(child: _tabButton('Belum selesai', false)),
                      const SizedBox(width: 8),
                      Expanded(child: _tabButton('Selesai', true)),
                    ],
                  ),
                  const SizedBox(height: 16),
                  _dateFilter(context),
                  const SizedBox(height: 18),
                  ..._rows(),
                ],
              ),
            ),
    );
  }

  Widget _tabButton(String label, bool completed) => completed == _completed
      ? FilledButton(
          onPressed: () => setState(() => _completed = completed),
          child: Text(label),
        )
      : OutlinedButton(
          onPressed: () => setState(() => _completed = completed),
          child: Text(label),
        );

  Widget _dateFilter(BuildContext context) {
    final today = _dateValue(DateTime.now());
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        border: Border.all(color: AppTheme.border),
        borderRadius: BorderRadius.circular(AppTheme.radiusMd),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 44,
                height: 44,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppTheme.primary.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(AppTheme.radiusSm),
                ),
                child: const Icon(
                  Icons.calendar_month_rounded,
                  color: AppTheme.primary,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Filter tanggal',
                      style: Theme.of(context).textTheme.labelLarge,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _dateLabel(context),
                      style: TextStyle(color: AppTheme.textMuted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: _pickDate,
              icon: const Icon(Icons.edit_calendar_rounded),
              label: const Text('Pilih tanggal'),
            ),
          ),
          const SizedBox(height: 4),
          Wrap(
            spacing: 4,
            runSpacing: 4,
            children: [
              if (_date != today)
                TextButton(
                  onPressed: () => _selectDate(today),
                  child: const Text('Hari ini'),
                ),
              if (_date != null)
                TextButton(
                  onPressed: () => _selectDate(null),
                  child: const Text('Semua tanggal'),
                ),
            ],
          ),
        ],
      ),
    );
  }

  List<Widget> _rows() {
    final key = _completed ? 'completed_employees' : 'employees';
    final employees = List<dynamic>.from(_data?[key] as List? ?? const []);
    if (employees.isEmpty) {
      return [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 44),
          child: Column(
            children: [
              const Icon(Icons.task_alt_rounded, size: 44),
              const SizedBox(height: 10),
              Text(
                _completed
                    ? 'Belum ada penilaian selesai'
                    : 'Tidak ada penilaian yang tertunda',
                textAlign: TextAlign.center,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 4),
              Text(
                _completed
                    ? 'Nama akan muncul setelah seluruh tindakan wajib selesai.'
                    : (_data?['empty_state']?['description']?.toString() ??
                          'Semua tindakan wajib yang disiapkan telah selesai.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: AppTheme.textMuted),
              ),
            ],
          ),
        ),
      ];
    }

    return employees
        .map((raw) => _employeeRow(Map<String, dynamic>.from(raw as Map)))
        .toList();
  }

  Widget _employeeRow(Map<String, dynamic> item) {
    final employee = Map<String, dynamic>.from(item['employee'] as Map);
    final tasks = List<dynamic>.from(
      item['required_tasks'] as List? ?? const [],
    );
    final task = tasks.isEmpty
        ? null
        : Map<String, dynamic>.from(tasks.first as Map);
    final action = Map<String, dynamic>.from(
      item['primary_action'] as Map? ?? const {},
    );

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        border: Border.all(color: AppTheme.border),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            employee['name']?.toString() ?? 'Karyawan tanpa nama',
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
          ),
          const SizedBox(height: 3),
          Text(
            '${employee['position'] ?? 'Jabatan belum tersedia'}, ${employee['branch'] ?? 'Cabang belum tersedia'}',
            style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
          ),
          const SizedBox(height: 8),
          Text(item['status']?.toString() ?? 'Menunggu tindakan.'),
          if (!_completed && task != null) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => _openTask(task),
                child: Text(action['label']?.toString() ?? 'Nilai sekarang'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _errorState() => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline_rounded, size: 44),
          const SizedBox(height: 12),
          Text(_error!, textAlign: TextAlign.center),
          const SizedBox(height: 12),
          ElevatedButton(onPressed: _load, child: const Text('Coba lagi')),
        ],
      ),
    ),
  );
}
