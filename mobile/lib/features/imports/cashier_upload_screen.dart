import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class CashierUploadScreen extends StatefulWidget {
  const CashierUploadScreen({super.key});

  @override
  State<CashierUploadScreen> createState() => _CashierUploadScreenState();
}

class _CashierUploadScreenState extends State<CashierUploadScreen> {
  bool _isUploading = false;
  bool _isConfirming = false;
  Map<String, dynamic>? _previewData;
  String? _errorMessage;
  List<dynamic> _periods = [];
  String? _selectedPeriodId;

  static final _rupiah = NumberFormat.currency(
    locale: 'id_ID',
    symbol: 'Rp ',
    decimalDigits: 0,
  );

  @override
  void initState() {
    super.initState();
    _loadPeriods();
  }

  Future<void> _loadPeriods() async {
    try {
      final res = await ApiService.get('/periods');
      if (!mounted) return;
      final periods = (res['data'] as List<dynamic>?) ?? [];
      setState(() {
        _periods = periods;
        final open = periods.where((p) => p['status'] == 'OPEN').firstOrNull;
        _selectedPeriodId = (open ?? periods.firstOrNull)?['id']?.toString();
      });
    } catch (_) {
      // Gagal load periode — upload tetap jalan (backend default ke periode OPEN)
    }
  }

  Future<void> _pickAndUpload() async {
    try {
      final file = await FilePicker.pickFile(
        type: FileType.custom,
        allowedExtensions: ['xlsx', 'csv', 'txt'],
      );
      if (file == null) return; // user batal

      final fileBytes = await file.readAsBytes();
      setState(() {
        _isUploading = true;
        _errorMessage = null;
        _previewData = null;
      });

      final res = await ApiService.uploadFile(
        '/cashier/import',
        filePath: file.path,
        fileBytes: fileBytes,
        fileName: file.name,
        fields: {'period_id': ?_selectedPeriodId},
      );

      final data = Map<String, dynamic>.from(res['data'] as Map);
      if (data['status'] == 'parsing') {
        await _pollBatch(data['batch_id'].toString());
        return;
      }

      if (!mounted) return;
      setState(() {
        _previewData = data;
        _isUploading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _isUploading = false;
        _errorMessage = e.toString().replaceAll('Exception: ', '');
      });
    }
  }

  Future<void> _pollBatch(String batchId) async {
    for (var attempt = 0; attempt < 60; attempt++) {
      await Future<void>.delayed(const Duration(seconds: 1));
      if (!mounted) return;

      try {
        final res = await ApiService.get('/cashier/import/$batchId');
        final data = Map<String, dynamic>.from(res['data'] as Map);
        final status = data['status']?.toString();

        if (status == 'ready_for_preview') {
          if (!mounted) return;
          setState(() {
            _previewData = data;
            _isUploading = false;
          });
          return;
        }

        if (status == 'failed') {
          final issues = data['issues'] as List<dynamic>?;
          final firstIssue = issues?.firstOrNull;
          if (!mounted) return;
          setState(() {
            _isUploading = false;
            _errorMessage = firstIssue is Map
                ? firstIssue['message']?.toString()
                : 'Analisis file gagal. Silakan unggah ulang file.';
          });
          return;
        }
      } catch (_) {
        // Gangguan jaringan sementara tidak membatalkan job di server.
      }
    }

    if (!mounted) return;
    setState(() {
      _isUploading = false;
      _errorMessage =
          'Analisis masih berjalan. Periksa kembali beberapa saat lagi.';
    });
  }

  Future<void> _confirmBatch() async {
    final preview = _previewData;
    if (preview == null) return;

    setState(() => _isConfirming = true);
    try {
      final res = await ApiService.post(
        '/cashier/import/${preview['batch_id']}/confirm',
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            res['message'] ??
                'Batch import berhasil dikonfirmasi dan diterapkan ke KPI Kasir!',
          ),
          backgroundColor: AppTheme.primary,
        ),
      );
      setState(() {
        _previewData = null;
        _isConfirming = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _isConfirming = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  bool get _hasErrors {
    final preview = _previewData;
    return preview?['status'] != 'ready_for_preview' ||
        ((preview?['error_rows'] as num?)?.toInt() ?? 0) > 0;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            'Import Laporan Kasir POS',
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.bold,
              color: AppTheme.textInk,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Unggah file laporan penjualan (XLSX/CSV) untuk menghitung otomatis metrik KPI Kasir.',
            style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 16),

          // Pilih Periode KPI
          if (_periods.isNotEmpty) ...[
            OpsSelectionField<String>(
              label: 'Periode KPI',
              hint: 'Pilih periode KPI',
              sheetTitle: 'Pilih periode KPI',
              value: _selectedPeriodId,
              options: _periods.map((p) {
                return OpsSelectionOption<String>(
                  value: p['id'].toString(),
                  label: p['name']?.toString() ?? 'Periode',
                  supportingText: _statusLabel(p['status']),
                  icon: Icons.calendar_month_rounded,
                );
              }).toList(),
              onChanged: (value) => setState(() => _selectedPeriodId = value),
            ),
            const SizedBox(height: 16),
          ],
          const SizedBox(height: 8),

          // Upload Area
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: AppTheme.surface,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: AppTheme.border,
                style: BorderStyle.solid,
              ),
            ),
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.cloud_upload_rounded,
                    color: AppTheme.primary,
                    size: 36,
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Pilih File Laporan Kasir',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                Text(
                  'Format yang didukung: XLSX, CSV (Maks 20MB)',
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
                const SizedBox(height: 20),
                ElevatedButton.icon(
                  onPressed: _isUploading || _isConfirming
                      ? null
                      : _pickAndUpload,
                  icon: const Icon(Icons.file_open_rounded),
                  label: _isUploading
                      ? SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            color: AppTheme.surface,
                            strokeWidth: 2,
                          ),
                        )
                      : const Text('Pilih & Upload File'),
                ),
                if (_previewData != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _previewData!['file_name'] ?? '',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: AppTheme.textMuted,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
              ],
            ),
          ),

          if (_errorMessage != null) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppTheme.statusDanger.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.statusDanger),
              ),
              child: Row(
                children: [
                  Icon(
                    Icons.error_outline_rounded,
                    color: AppTheme.statusDanger,
                    size: 20,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _errorMessage!,
                      style: TextStyle(
                        fontSize: 12.5,
                        color: AppTheme.statusDanger,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],

          if (_previewData != null) ...[
            const SizedBox(height: 24),
            const KpiSectionHeader(title: 'Hasil preview analisis file'),
            const SizedBox(height: 12),
            OpsReveal(
              delay: const Duration(milliseconds: 80),
              child: OpsCard(
                padding: EdgeInsets.zero,
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              _previewData!['file_name'] ?? 'File',
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                              ),
                            ),
                          ),
                          KpiStatusPill(
                            label: _hasErrors
                                ? 'Perlu diperbaiki'
                                : 'Siap diproses',
                            color: _hasErrors
                                ? AppTheme.statusDanger
                                : AppTheme.statusApproved,
                            icon: _hasErrors
                                ? Icons.error_outline_rounded
                                : Icons.check_circle_rounded,
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      LayoutBuilder(
                        builder: (context, constraints) {
                          const gap = 8.0;
                          final columns = constraints.maxWidth < 390 ? 2 : 5;
                          final width =
                              (constraints.maxWidth - (gap * (columns - 1))) /
                              columns;
                          final stats = [
                            (
                              'Total Baris',
                              '${_previewData!['total_rows']}',
                              AppTheme.textInk,
                            ),
                            (
                              'Valid',
                              '${_previewData!['valid_rows']}',
                              AppTheme.primary,
                            ),
                            (
                              'Warning',
                              '${_previewData!['warning_rows']}',
                              AppTheme.statusRevision,
                            ),
                            (
                              'Duplikat',
                              '${_previewData!['duplicate_rows']}',
                              AppTheme.textMuted,
                            ),
                            (
                              'Error',
                              '${_previewData!['error_rows']}',
                              AppTheme.statusDanger,
                            ),
                          ];
                          return Wrap(
                            spacing: gap,
                            runSpacing: gap,
                            children: stats
                                .map(
                                  (stat) => SizedBox(
                                    width: width,
                                    child: _statBox(stat.$1, stat.$2, stat.$3),
                                  ),
                                )
                                .toList(),
                          );
                        },
                      ),
                      const SizedBox(height: 16),
                      _summaryRow(
                        'Total Transaksi:',
                        _formatAmount(
                          _previewData!['summary']?['total_amount'],
                        ),
                      ),
                      const SizedBox(height: 4),
                      _summaryRow(
                        'Total Selisih Kas:',
                        _formatAmount(
                          _previewData!['summary']?['total_difference'],
                        ),
                      ),
                      if (_hasErrors) ...[
                        const SizedBox(height: 12),
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: AppTheme.statusDanger.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            'Terdapat baris error — perbaiki file lalu unggah ulang. Konfirmasi tidak bisa dilanjutkan.',
                            style: TextStyle(
                              fontSize: 12,
                              color: AppTheme.statusDanger,
                            ),
                          ),
                        ),
                      ],
                      if (_issues.isNotEmpty) ...[
                        const SizedBox(height: 16),
                        Text(
                          'Catatan Analisis:',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: AppTheme.textMuted,
                          ),
                        ),
                        const SizedBox(height: 6),
                        ..._issues.map((issue) {
                          final type = issue['type'] ?? 'warning';
                          final color = type == 'error'
                              ? AppTheme.statusDanger
                              : type == 'duplicate'
                              ? AppTheme.textMuted
                              : AppTheme.statusRevision;
                          final icon = type == 'error'
                              ? Icons.cancel_rounded
                              : type == 'duplicate'
                              ? Icons.copy_rounded
                              : Icons.warning_amber_rounded;
                          return Padding(
                            padding: const EdgeInsets.only(bottom: 4),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Icon(icon, size: 15, color: color),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    'Baris ${issue['row']}: ${issue['message']}',
                                    style: TextStyle(
                                      fontSize: 12,
                                      color: AppTheme.textInk,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          );
                        }),
                      ],
                      const SizedBox(height: 20),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: (_hasErrors || _isConfirming)
                              ? null
                              : _confirmBatch,
                          child: _isConfirming
                              ? SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(
                                    color: AppTheme.surface,
                                    strokeWidth: 2,
                                  ),
                                )
                              : const Text(
                                  'Konfirmasi & Terapkan ke KPI Kasir',
                                ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<dynamic> get _issues =>
      (_previewData?['issues'] as List<dynamic>?) ?? [];

  String _statusLabel(String? status) {
    switch (status) {
      case 'OPEN':
        return 'Aktif';
      case 'DRAFT':
        return 'Draft';
      case 'READY':
        return 'Siap';
      case 'SUBMISSION_CLOSED':
        return 'Pengisian Ditutup';
      case 'IN_REVIEW':
        return 'Sedang Direview';
      case 'WAITING_APPROVAL':
        return 'Menunggu Approval';
      case 'PUBLISHED':
        return 'Diterbitkan';
      case 'LOCKED':
        return 'Terkunci';
      case 'CANCELLED':
        return 'Dibatalkan';
      default:
        return status ?? '-';
    }
  }

  Widget _summaryRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: TextStyle(color: AppTheme.textMuted)),
        Text(
          value,
          style: const TextStyle(
            fontWeight: FontWeight.bold,
            color: AppTheme.primary,
          ),
        ),
      ],
    );
  }

  String _formatAmount(dynamic value) {
    if (value == null) return '-';
    return _rupiah.format((value as num).toDouble());
  }

  Widget _statBox(String label, String value, Color color) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          value,
          style: TextStyle(
            fontWeight: FontWeight.w800,
            fontSize: 16,
            color: color,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          label,
          style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
          textAlign: TextAlign.center,
        ),
      ],
    );
  }
}
