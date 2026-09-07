import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class DailyAssessmentScreen extends StatefulWidget {
  final bool manager;
  final DateTime? initialDate;
  final String? entryId;
  final String? kpiId;

  const DailyAssessmentScreen({
    super.key,
    required this.manager,
    this.initialDate,
    this.entryId,
    this.kpiId,
  });

  @override
  State<DailyAssessmentScreen> createState() => _DailyAssessmentScreenState();
}

class _DailyAssessmentScreenState extends State<DailyAssessmentScreen> {
  DateTime _date = DateTime.now();
  bool _loading = true;
  String? _errorText;
  List<dynamic> _entries = [];
  final Map<String, TextEditingController> _values = {};
  final Map<String, TextEditingController> _notes = {};
  final Map<String, String> _ratings = {};
  final Map<String, String> _attendance = {};
  final Map<String, Set<String>> _rubric = {};
  final Set<String> _editing = {};
  String? _approvingKpi;
  String _managerSection = 'staff_confirmation';

  String get _dateValue => _date.toIso8601String().substring(0, 10);
  String get _endpoint =>
      widget.manager ? '/manager/daily' : '/supervisor/daily';
  String get _roleLabel => widget.manager ? 'Manager' : 'Supervisor';

  @override
  void initState() {
    super.initState();
    _date = widget.initialDate ?? DateTime.now();
    _load();
  }

  @override
  void dispose() {
    for (final controller in _values.values) {
      controller.dispose();
    }
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
      final focus = widget.kpiId == null
          ? ''
          : '&kpi_id=${Uri.encodeQueryComponent(widget.kpiId!)}';
      final response = await ApiService.get(
        '$_endpoint?date=$_dateValue$focus',
      );
      if (!mounted) return;
      for (final raw in List<dynamic>.from(
        response['data'] as List? ?? const [],
      )) {
        final entry = Map<String, dynamic>.from(raw as Map);
        final id = entry['id'].toString();
        final item = Map<String, dynamic>.from(
          entry['item'] as Map? ?? const {},
        );
        _values[id] ??= TextEditingController();
        _notes[id] ??= TextEditingController();
        final actual = widget.manager
            ? entry['manager_actual_decimal'] ??
                  entry['supervisor_actual_decimal'] ??
                  entry['employee_actual_decimal'] ??
                  entry['system_actual_decimal'] ??
                  item['system_actual']
            : entry['supervisor_actual_decimal'] ??
                  entry['employee_actual_decimal'] ??
                  entry['system_actual_decimal'] ??
                  item['system_actual'];
        _values[id]!.text = actual?.toString() ?? '';
        _notes[id]!.text =
            (widget.manager ? entry['manager_note'] : entry['supervisor_note'])
                ?.toString() ??
            '';
        final inputType = item['input_type']?.toString();
        final selectedActual = widget.manager
            ? entry['manager_actual_json'] ?? entry['supervisor_actual_json']
            : entry['supervisor_actual_json'];
        if (inputType == 'rating') {
          _ratings[id] = selectedActual is Map
              ? selectedActual['rating_code']?.toString() ?? ''
              : '';
        }
        if (inputType == 'attendance') {
          _attendance[id] = selectedActual is Map
              ? selectedActual['attendance_status']?.toString() ?? ''
              : '';
        }
        if (inputType == 'rubric') {
          final answers = widget.manager
              ? entry['manager_answers'] ?? entry['supervisor_answers']
              : entry['supervisor_answers'];
          _rubric[id] = _fulfilledIds(answers);
        }
      }
      setState(() {
        _entries = List<dynamic>.from(response['data'] as List? ?? const [])
            .where(
              (entry) =>
                  widget.entryId == null ||
                  entry['id'].toString() == widget.entryId,
            )
            .toList();
        if (widget.manager &&
            (widget.entryId != null || widget.kpiId != null) &&
            _entries.isNotEmpty) {
          _managerSection =
              _entries.first['review_mode']?.toString() ?? _managerSection;
        }
        _loading = false;
      });
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _errorText = _exceptionMessage(exception);
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

  Future<void> _assess(Map<String, dynamic> entry, String decision) async {
    final id = entry['id'].toString();
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    final inputType = item['input_type']?.toString() ?? 'numeric';
    final body = <String, dynamic>{'decision': decision};
    final note = _notes[id]?.text.trim() ?? '';
    if (decision == 'revision_required') {
      if (note.isEmpty) {
        _errorSnack('Alasan revisi wajib diisi pada catatan.');
        return;
      }
      body['note'] = note;
    } else if (note.isNotEmpty) {
      body['note'] = note;
    }

    if (inputType == 'rating') {
      final rating = _ratings[id];
      if (rating == null || rating.isEmpty) {
        _errorSnack('Pilih predikat penilaian terlebih dahulu.');
        return;
      }
      final options = List<dynamic>.from(
        item['manual_rating_options'] as List? ?? const [],
      );
      final selected = options.cast<Map>().firstWhere(
        (option) => option['code']?.toString() == rating,
        orElse: () => const {},
      );
      final score = num.tryParse(selected['score']?.toString() ?? '');
      final target = num.tryParse(item['target_value']?.toString() ?? '');
      if (!widget.manager &&
          score != null &&
          target != null &&
          score < target &&
          note.isEmpty) {
        _errorSnack(
          'Catatan wajib diisi jika predikat berada di bawah target indikator.',
        );
        return;
      }
      body['actual_json'] = {'rating_code': rating};
      if (widget.manager && decision == 'approved') {
        final previous = _ratingCode(entry['supervisor_actual_json']);
        if (previous != null && previous != rating && note.isEmpty) {
          final reason = await _askReason(
            'Perubahan predikat Manager',
            'Alasan perubahan predikat',
          );
          if (reason == null) return;
          body['note'] = reason;
        }
      }
    } else if (inputType == 'attendance') {
      final status = _attendance[id];
      if (status == null || status.isEmpty) {
        _errorSnack('Pilih status kehadiran terlebih dahulu.');
        return;
      }
      if (!widget.manager &&
          ['permission', 'sick_leave', 'absent'].contains(status) &&
          note.isEmpty) {
        _errorSnack('Catatan wajib diisi untuk status kehadiran ini.');
        return;
      }
      body['actual_json'] = {'attendance_status': status};
      if (widget.manager && decision == 'approved') {
        final previous = _attendanceStatus(entry['supervisor_actual_json']);
        if (previous != null && previous != status && note.isEmpty) {
          final reason = await _askReason(
            'Perubahan status Manager',
            'Alasan perubahan status',
          );
          if (reason == null) return;
          body['note'] = reason;
        }
      }
    } else if (inputType == 'rubric') {
      final selected = _rubric[id] ?? <String>{};
      final criteria = List<dynamic>.from(
        item['rubric']?['criteria'] as List? ?? const [],
      );
      body['answers'] = criteria.map((raw) {
        final criterion = Map<String, dynamic>.from(raw as Map);
        return {
          'criterion_id': criterion['id'],
          'is_fulfilled': selected.contains(criterion['id'].toString()),
          'notes': null,
        };
      }).toList();
      if (widget.manager &&
          decision == 'approved' &&
          !_sameIds(selected, _fulfilledIds(entry['supervisor_answers'])) &&
          note.isEmpty) {
        final reason = await _askReason(
          'Perubahan checklist Manager',
          'Alasan perubahan checklist',
        );
        if (reason == null) return;
        body['note'] = reason;
      }
    } else if (decision == 'approved' && !_isOfficialSource(item)) {
      final value = double.tryParse(_values[id]?.text.trim() ?? '');
      if (value == null && entry['item']?['system_actual'] == null) {
        _errorSnack('Nilai aktual wajib diisi.');
        return;
      }
      if (value != null) body['actual_decimal'] = value;
      if (widget.manager && value != null) {
        final baseline =
            (entry['supervisor_actual_decimal'] ??
                    entry['employee_actual_decimal'] ??
                    entry['system_actual_decimal'] ??
                    item['system_actual'])
                as num?;
        if (baseline != null &&
            (value - baseline.toDouble()).abs() > 0.000001) {
          final reason = note.isNotEmpty
              ? note
              : await _askReason(
                  'Koreksi nilai aktual',
                  'Alasan dan referensi evidence',
                );
          if (reason == null) return;
          body['note'] = reason;
          body['actual_json'] = {'evidence': reason};
        }
      }
    }

    try {
      final response = await ApiService.post(
        '$_endpoint/${entry['id']}/assess',
        body,
      );
      if (!mounted) return;
      _showMessage(
        response['message']?.toString() ?? 'Penilaian berhasil disimpan.',
      );
      _load();
    } catch (exception) {
      if (mounted) _errorSnack(_exceptionMessage(exception));
    }
  }

  Future<void> _approveAll(List<Map<String, dynamic>> entries) async {
    if (entries.isEmpty || _approvingKpi != null) return;
    final kpiId = entries.first['kpi_id'].toString();
    setState(() => _approvingKpi = kpiId);
    try {
      final response = await ApiService.post(
        '/manager/daily/$kpiId/approve-all',
        {'date': _dateValue},
      );
      if (!mounted) return;
      _showMessage(
        response['message']?.toString() ??
            'Seluruh indikator staf berhasil disetujui.',
      );
      await _load();
    } catch (exception) {
      if (mounted) _errorSnack(_exceptionMessage(exception));
    } finally {
      if (mounted) setState(() => _approvingKpi = null);
    }
  }

  Future<void> _approveAutomatic(List<Map<String, dynamic>> entries) async {
    if (entries.isEmpty || _approvingKpi != null) return;
    final kpiId = entries.first['kpi_id'].toString();
    setState(() => _approvingKpi = kpiId);
    try {
      final response = await ApiService.post(
        '/supervisor/daily/$kpiId/approve-all',
        {'date': _dateValue},
      );
      if (!mounted) return;
      _showMessage(
        response['message']?.toString() ??
            'Indikator otomatis berhasil dikonfirmasi.',
      );
      await _load();
    } catch (exception) {
      if (mounted) _errorSnack(_exceptionMessage(exception));
    } finally {
      if (mounted) setState(() => _approvingKpi = null);
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
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.kpiId == null
              ? 'Penilaian Harian $_roleLabel'
              : 'Penilaian Tim',
        ),
        actions: [
          if (widget.kpiId == null)
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
            if (widget.kpiId == null)
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
                        _dateValue,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                    TextButton(onPressed: _pickDate, child: const Text('Ubah')),
                  ],
                ),
              ),
            if (widget.manager && widget.kpiId == null) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _sectionButton('Staf', 'staff_confirmation')),
                  const SizedBox(width: 8),
                  Expanded(
                    child: _sectionButton(
                      'Supervisor',
                      'supervisor_assessment',
                    ),
                  ),
                ],
              ),
            ],
            if (_visibleEntries.isEmpty)
              KpiEmptyState(
                icon: Icons.check_circle_outline,
                title: widget.kpiId == null
                    ? 'Belum ada data'
                    : 'Penilaian karyawan ini selesai',
                message: widget.kpiId != null
                    ? 'Tidak ada lagi tindakan wajib yang menunggu.'
                    : widget.manager && _managerSection == 'staff_confirmation'
                    ? 'Supervisor belum menyelesaikan penilaian, penugasan Manager belum tersedia, atau tidak ada data pada tanggal ini.'
                    : widget.manager
                    ? 'Tidak ada KPI Supervisor yang ditugaskan pada tanggal ini.'
                    : 'Belum ada fakta KPI yang siap diproses untuk tanggal ini.',
              ),
            if (_visibleEntries.isEmpty && widget.kpiId != null)
              FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Kembali ke Penilaian Tim'),
              ),
            if (!widget.manager && widget.kpiId != null && _automaticReady) ...[
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _approvingKpi == null
                      ? () => _approveAutomatic(_automaticEntries)
                      : null,
                  icon: const Icon(Icons.done_all_rounded),
                  label: Text(
                    _approvingKpi == null
                        ? 'Konfirmasi otomatis'
                        : 'Mengonfirmasi...',
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],
            if (widget.manager &&
                widget.kpiId == null &&
                _managerSection == 'staff_confirmation')
              ..._managerGroups.values.map(_managerGroup)
            else
              ..._visibleEntries.map(_entryCard),
          ],
        ),
      ),
    );
  }

  Widget _sectionButton(String label, String value) {
    final selected = _managerSection == value;
    return selected
        ? FilledButton(
            onPressed: () => setState(() => _managerSection = value),
            child: Text(label),
          )
        : OutlinedButton(
            onPressed: () => setState(() => _managerSection = value),
            child: Text(label),
          );
  }

  List<Map<String, dynamic>> get _visibleEntries {
    final entries = _entries
        .where(
          (raw) =>
              !widget.manager ||
              widget.kpiId != null ||
              raw['review_mode']?.toString() == _managerSection,
        )
        .map((raw) => Map<String, dynamic>.from(raw as Map))
        .toList();
    entries.sort(
      (left, right) => _entryPriority(left).compareTo(_entryPriority(right)),
    );
    return entries;
  }

  int _entryPriority(Map<String, dynamic> entry) {
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    if (item['input_type'] == 'attendance') return 0;
    return _isOfficialSource(item) ? 2 : 1;
  }

  List<Map<String, dynamic>> get _automaticEntries => _visibleEntries.where((
    entry,
  ) {
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    return item['input_type'] != 'attendance' && _isOfficialSource(item);
  }).toList();

  bool get _automaticReady =>
      _automaticEntries.isNotEmpty &&
      _automaticEntries.every((entry) {
        final item = Map<String, dynamic>.from(
          entry['item'] as Map? ?? const {},
        );
        return entry['system_actual_decimal'] != null ||
            item['system_actual'] != null;
      });

  Map<String, List<Map<String, dynamic>>> get _managerGroups {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final entry in _visibleEntries) {
      groups.putIfAbsent(entry['kpi_id'].toString(), () => []).add(entry);
    }
    return groups;
  }

  Widget _managerGroup(List<Map<String, dynamic>> entries) {
    final employee = Map<String, dynamic>.from(
      entries.first['employee'] as Map? ?? const {},
    );
    final allApproved = entries.every(
      (entry) => entry['manager_status'] == 'approved',
    );
    final changed = entries.any(_managerChanged);
    final status = allApproved
        ? (changed ? 'Diubah Manager' : 'Disetujui')
        : 'Menunggu tinjauan';
    final kpiId = entries.first['kpi_id'].toString();

    return OpsCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      employee['name']?.toString() ?? 'Karyawan',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    Text(
                      '${employee['position'] ?? 'Jabatan belum tersedia'} · ${employee['branch'] ?? 'Cabang belum tersedia'}',
                      style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              KpiStatusPill(
                label: status,
                color: changed || allApproved
                    ? AppTheme.statusApproved
                    : AppTheme.textMuted,
                icon: Icons.fact_check_rounded,
              ),
            ],
          ),
          if (!allApproved) ...[
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _approvingKpi == null
                    ? () => _approveAll(entries)
                    : null,
                icon: const Icon(Icons.done_all_rounded),
                label: Text(
                  _approvingKpi == kpiId ? 'Menyetujui...' : 'Setujui semua',
                ),
              ),
            ),
          ],
          const SizedBox(height: 8),
          ...entries.map(_managerEntry),
        ],
      ),
    );
  }

  Widget _managerEntry(Map<String, dynamic> entry) {
    final id = entry['id'].toString();
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    final editing = _editing.contains(id);
    final status = entry['manager_status'] == 'approved'
        ? (_managerChanged(entry) ? 'Diubah Manager' : 'Disetujui')
        : 'Menunggu tinjauan';

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: AppTheme.border)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${item['code'] ?? 'KPI'} · ${item['name'] ?? 'Indikator'}',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Hasil Supervisor: ${_supervisorSummary(entry, item)}',
                      style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Text(status, style: const TextStyle(fontSize: 12)),
            ],
          ),
          const SizedBox(height: 10),
          if (!editing)
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (entry['manager_status'] != 'approved')
                  FilledButton.icon(
                    onPressed: () => _assess(entry, 'approved'),
                    icon: const Icon(Icons.check_rounded),
                    label: const Text('Setujui'),
                  ),
                OutlinedButton(
                  onPressed: () => setState(() => _editing.add(id)),
                  child: const Text('Ubah'),
                ),
              ],
            )
          else ...[
            _inputFor(entry, item, item['input_type']?.toString() ?? 'numeric'),
            const SizedBox(height: 8),
            TextField(
              controller: _notes[id],
              maxLines: 2,
              decoration: const InputDecoration(labelText: 'Alasan perubahan'),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton.icon(
                  onPressed: () => _assess(entry, 'approved'),
                  icon: const Icon(Icons.save_rounded),
                  label: const Text('Simpan'),
                ),
                TextButton(
                  onPressed: () => setState(() => _editing.remove(id)),
                  child: const Text('Batal'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  String _supervisorSummary(
    Map<String, dynamic> entry,
    Map<String, dynamic> item,
  ) {
    final type = item['input_type']?.toString();
    if (type == 'rating') {
      final code = _ratingCode(entry['supervisor_actual_json']);
      final options = List<dynamic>.from(
        item['manual_rating_options'] as List? ?? const [],
      );
      final option = options.whereType<Map>().firstWhere(
        (candidate) => candidate['code']?.toString() == code,
        orElse: () => const {},
      );
      return option['label']?.toString() ?? code ?? 'Belum tersedia';
    }
    if (type == 'attendance') {
      return _attendanceStatus(entry['supervisor_actual_json']) ??
          'Belum tersedia';
    }
    final value = item['formula'] == 'rubric'
        ? entry['supervisor_score_percentage']
        : entry['supervisor_actual_decimal'] ??
              entry['employee_actual_decimal'] ??
              entry['system_actual_decimal'] ??
              item['system_actual'];
    return value == null ? 'Belum tersedia' : value.toString();
  }

  bool _managerChanged(Map<String, dynamic> entry) {
    if (entry['manager_status'] != 'approved') return false;
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    final type = item['input_type']?.toString();
    if (type == 'rating') {
      return _ratingCode(entry['manager_actual_json']) !=
          _ratingCode(entry['supervisor_actual_json']);
    }
    if (type == 'attendance') {
      return _attendanceStatus(entry['manager_actual_json']) !=
          _attendanceStatus(entry['supervisor_actual_json']);
    }
    if (item['formula'] == 'rubric') {
      return !_sameIds(
        _fulfilledIds(entry['manager_answers']),
        _fulfilledIds(entry['supervisor_answers']),
      );
    }
    final manager = num.tryParse(
      entry['manager_actual_decimal']?.toString() ?? '',
    );
    final supervisor = num.tryParse(
      (entry['supervisor_actual_decimal'] ??
                  entry['employee_actual_decimal'] ??
                  entry['system_actual_decimal'] ??
                  item['system_actual'])
              ?.toString() ??
          '',
    );
    return manager != null &&
        supervisor != null &&
        (manager - supervisor).abs() > 0.000001;
  }

  Widget _entryCard(Map<String, dynamic> entry) {
    final id = entry['id'].toString();
    final item = Map<String, dynamic>.from(entry['item'] as Map? ?? const {});
    final employee = Map<String, dynamic>.from(
      entry['employee'] as Map? ?? const {},
    );
    final type = item['input_type']?.toString() ?? 'numeric';
    final currentStatus = widget.manager
        ? entry['manager_status']
        : entry['supervisor_status'];
    return OpsCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  employee['name']?.toString() ?? 'Karyawan',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              KpiStatusPill(
                label: currentStatus?.toString() ?? 'Belum diproses',
                color: _statusColor(currentStatus?.toString()),
                icon: Icons.fact_check_rounded,
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '${item['code'] ?? 'KPI'} · ${item['name'] ?? 'Indikator'}',
            style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
          ),
          const SizedBox(height: 10),
          Text(
            'Target: ${item['target_value'] ?? 'Belum ditentukan'} ${item['target_unit'] ?? ''}',
            style: const TextStyle(fontSize: 12),
          ),
          if (item['system_actual'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Nilai sistem: ${item['system_actual']}',
                style: TextStyle(
                  color: AppTheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          const SizedBox(height: 12),
          _inputFor(entry, item, type),
          const SizedBox(height: 8),
          TextField(
            controller: _notes[id],
            maxLines: 2,
            decoration: InputDecoration(
              labelText: widget.manager
                  ? 'Catatan / alasan koreksi'
                  : 'Catatan penilaian',
            ),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.icon(
                onPressed: () => _assess(entry, 'approved'),
                icon: const Icon(Icons.check_rounded),
                label: const Text('Setujui'),
              ),
              if (!widget.manager)
                OutlinedButton.icon(
                  onPressed: () => _assess(entry, 'revision_required'),
                  icon: const Icon(Icons.replay_rounded),
                  label: const Text('Revisi'),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _inputFor(
    Map<String, dynamic> entry,
    Map<String, dynamic> item,
    String type,
  ) {
    final id = entry['id'].toString();
    if (type == 'attendance') {
      final options = List<dynamic>.from(
        item['attendance_options'] as List? ?? const [],
      );
      return OpsSelectionField<String>(
        label: 'Status kehadiran',
        sheetTitle: 'Pilih status kehadiran',
        value: _attendance[id]?.isEmpty == true ? null : _attendance[id],
        options: options.map((raw) {
          final option = Map<String, dynamic>.from(raw as Map);
          return OpsSelectionOption<String>(
            value: option['value'].toString(),
            label: option['label']?.toString() ?? option['value'].toString(),
          );
        }).toList(),
        onChanged: (value) => setState(() => _attendance[id] = value),
      );
    }
    if (type == 'rating') {
      final options = List<dynamic>.from(
        item['manual_rating_options'] as List? ?? const [],
      );
      final criteria = List<dynamic>.from(
        item['rubric']?['criteria'] as List? ?? const [],
      );
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (criteria.isNotEmpty) ...[
            const Text(
              'Panduan kriteria',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            ...criteria.map((raw) {
              final criterion = Map<String, dynamic>.from(raw as Map);
              return Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text('• ${criterion['criterion_text'] ?? 'Kriteria'}'),
              );
            }),
            const SizedBox(height: 8),
          ],
          OpsSelectionField<String>(
            label: 'Predikat',
            sheetTitle: 'Pilih predikat',
            value: _ratings[id]?.isEmpty == true ? null : _ratings[id],
            options: options.map((raw) {
              final option = Map<String, dynamic>.from(raw as Map);
              return OpsSelectionOption<String>(
                value: option['code'].toString(),
                label: option['label']?.toString() ?? option['code'].toString(),
                supportingText: 'Skor ${option['score'] ?? 0}%',
              );
            }).toList(),
            onChanged: (value) => setState(() => _ratings[id] = value),
          ),
        ],
      );
    }
    if (type == 'rubric') {
      final criteria = List<dynamic>.from(
        item['rubric']?['criteria'] as List? ?? const [],
      );
      final selected = _rubric[id] ?? <String>{};
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: criteria.map((raw) {
          final criterion = Map<String, dynamic>.from(raw as Map);
          final key = criterion['id'].toString();
          return CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            value: selected.contains(key),
            title: Text(criterion['criterion_text']?.toString() ?? 'Kriteria'),
            onChanged: (checked) => setState(() {
              final next = {...selected};
              checked == true ? next.add(key) : next.remove(key);
              _rubric[id] = next;
            }),
          );
        }).toList(),
      );
    }
    if (_isOfficialSource(item)) {
      return const Text(
        'Nilai resmi hanya dapat dikonfirmasi atau diminta koreksi sumber.',
      );
    }
    return TextField(
      controller: _values[id],
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      decoration: InputDecoration(
        labelText: 'Nilai aktual (${item['target_unit'] ?? 'angka'})',
      ),
    );
  }

  Future<String?> _askReason(String title, String label) async {
    final controller = TextEditingController();
    final result = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: controller,
          maxLines: 3,
          decoration: InputDecoration(labelText: label),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () =>
                Navigator.pop(dialogContext, controller.text.trim()),
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (result == null) return null;
    if (result.trim().length < 3) {
      if (mounted) _errorSnack('Alasan minimal 3 karakter.');
      return null;
    }
    return result.trim();
  }

  static Set<String> _fulfilledIds(dynamic answers) => {
    for (final raw in answers is List ? answers : const [])
      if (raw is Map && raw['is_fulfilled'] == true)
        raw['criterion_id'].toString(),
  };

  static String? _ratingCode(dynamic actual) =>
      actual is Map ? actual['rating_code']?.toString() : null;

  static String? _attendanceStatus(dynamic actual) =>
      actual is Map ? actual['attendance_status']?.toString() : null;

  static bool _sameIds(Set<String> left, Set<String> right) =>
      left.length == right.length && left.containsAll(right);

  static bool _isOfficialSource(Map<String, dynamic> item) => const {
    'system',
    'cross_role',
    'import',
  }.contains(item['source_type']?.toString().toLowerCase());

  Color _statusColor(String? status) => status == 'revision_required'
      ? AppTheme.statusRevision
      : status == 'approved'
      ? AppTheme.statusApproved
      : AppTheme.textMuted;
  void _showMessage(String message) =>
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: AppTheme.primary),
      );
  void _errorSnack(String message) =>
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
  static String _exceptionMessage(Object exception) =>
      exception.toString().replaceFirst('Exception: ', '');
}
