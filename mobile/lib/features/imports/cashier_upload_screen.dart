import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../app/theme/app_theme.dart';
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

  static final _rupiah = NumberFormat.currency(locale: 'id_ID', symbol: 'Rp ', decimalDigits: 0);

  Future<void> _pickAndUpload() async {
    try {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['xlsx', 'csv', 'txt'],
        withData: true,
      );
      if (result == null || result.files.isEmpty) return; // user batal

      final file = result.files.single;
      setState(() {
        _isUploading = true;
        _errorMessage = null;
      });

      final res = await ApiService.uploadFile(
        '/cashier/import',
        filePath: file.path,
        fileBytes: file.bytes,
        fileName: file.name,
      );

      if (!mounted) return;
      setState(() {
        _previewData = res['data'];
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

  Future<void> _confirmBatch() async {
    final preview = _previewData;
    if (preview == null) return;

    setState(() => _isConfirming = true);
    try {
      final res = await ApiService.post('/cashier/import/${preview['batch_id']}/confirm');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Batch import berhasil dikonfirmasi dan diterapkan ke KPI Kasir!'),
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
        SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  bool get _hasErrors {
    final preview = _previewData;
    return (preview?['error_rows'] as num?)?.toInt() != 0;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const Text(
            'Import Laporan Kasir POS',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 6),
          const Text(
            'Unggah file laporan penjualan (XLSX/CSV) untuk menghitung otomatis metrik KPI Kasir.',
            style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 24),

          // Upload Area
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.border, style: BorderStyle.solid),
            ),
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.cloud_upload_rounded, color: AppTheme.primary, size: 36),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Pilih File Laporan Kasir',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Format yang didukung: XLSX, CSV (Maks 20MB)',
                  style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                ),
                const SizedBox(height: 20),
                ElevatedButton.icon(
                  onPressed: _isUploading || _isConfirming ? null : _pickAndUpload,
                  icon: const Icon(Icons.file_open_rounded),
                  label: _isUploading
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Text('Pilih & Upload File'),
                ),
                if (_previewData != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _previewData!['file_name'] ?? '',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textMuted),
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
                  const Icon(Icons.error_outline_rounded, color: AppTheme.statusDanger, size: 20),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _errorMessage!,
                      style: const TextStyle(fontSize: 12.5, color: AppTheme.statusDanger),
                    ),
                  ),
                ],
              ),
            ),
          ],

          if (_previewData != null) ...[
            const SizedBox(height: 24),
            const Text(
              'Hasil Preview Analisis File:',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
            ),
            const SizedBox(height: 12),
            Card(
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
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: (_hasErrors ? AppTheme.statusDanger : AppTheme.primary).withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            _hasErrors ? 'PERLU DIPERBAIKI' : 'READY TO COMMIT',
                            style: TextStyle(
                              fontSize: 10,
                              fontWeight: FontWeight.bold,
                              color: _hasErrors ? AppTheme.statusDanger : AppTheme.primary,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        _statBox('Total Baris', '${_previewData!['total_rows']}', AppTheme.textInk),
                        _statBox('Valid', '${_previewData!['valid_rows']}', AppTheme.primary),
                        _statBox('Warning', '${_previewData!['warning_rows']}', AppTheme.statusRevision),
                        _statBox('Duplikat', '${_previewData!['duplicate_rows']}', AppTheme.textMuted),
                        _statBox('Error', '${_previewData!['error_rows']}', AppTheme.statusDanger),
                      ],
                    ),
                    const SizedBox(height: 16),
                    _summaryRow('Total Transaksi:', _formatAmount(_previewData!['summary']?['total_amount'])),
                    const SizedBox(height: 4),
                    _summaryRow('Total Selisih Kas:', _formatAmount(_previewData!['summary']?['total_difference'])),
                    if (_hasErrors) ...[
                      const SizedBox(height: 12),
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: AppTheme.statusDanger.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Text(
                          'Terdapat baris error — perbaiki file lalu unggah ulang. Konfirmasi tidak bisa dilanjutkan.',
                          style: TextStyle(fontSize: 12, color: AppTheme.statusDanger),
                        ),
                      ),
                    ],
                    if (_issues.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      const Text(
                        'Catatan Analisis:',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.textMuted),
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
                                  style: const TextStyle(fontSize: 11.5, color: AppTheme.textInk),
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
                        onPressed: (_hasErrors || _isConfirming) ? null : _confirmBatch,
                        child: _isConfirming
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                              )
                            : const Text('Konfirmasi & Terapkan ke KPI Kasir'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<dynamic> get _issues => (_previewData?['issues'] as List<dynamic>?) ?? [];

  Widget _summaryRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: AppTheme.textMuted)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, color: AppTheme.primary)),
      ],
    );
  }

  String _formatAmount(dynamic value) {
    if (value == null) return '-';
    return _rupiah.format((value as num).toDouble());
  }

  Widget _statBox(String label, String value, Color color) {
    return Expanded(
      child: Column(
        children: [
          Text(value, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: color)),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(fontSize: 10, color: AppTheme.textMuted)),
        ],
      ),
    );
  }
}
