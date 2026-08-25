import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';

class CashierUploadScreen extends StatefulWidget {
  const CashierUploadScreen({super.key});

  @override
  State<CashierUploadScreen> createState() => _CashierUploadScreenState();
}

class _CashierUploadScreenState extends State<CashierUploadScreen> {
  bool _isUploading = false;
  Map<String, dynamic>? _previewData;

  void _simulateUpload() async {
    setState(() => _isUploading = true);

    // Simulate analysis and API response for mobile UX
    await Future.delayed(const Duration(seconds: 1));

    setState(() {
      _previewData = {
        'batch_id': '01J6G7H8K9L0M1N2P3Q4R5S6T7',
        'file_name': 'laporan_kasir_shift_agustus.xlsx',
        'total_rows': 128,
        'valid_rows': 125,
        'warning_rows': 3,
        'duplicate_rows': 0,
        'error_rows': 0,
        'total_amount': 'Rp 34.850.000',
        'cash_difference': 'Rp 0',
      };
      _isUploading = false;
    });

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('File berhasil diunggah & dianalisis. Silakan tinjau ringkasan.'),
          backgroundColor: AppTheme.primary,
        ),
      );
    }
  }

  void _confirmBatch() {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('128 transaksi berhasil dicatat dan KPI Kasir (KSR-01 s/d KSR-04) diperbarui otomatis!'),
        backgroundColor: AppTheme.primary,
      ),
    );
    setState(() => _previewData = null);
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
                  onPressed: _isUploading ? null : _simulateUpload,
                  icon: const Icon(Icons.file_open_rounded),
                  label: _isUploading
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Text('Pilih & Upload File'),
                ),
              ],
            ),
          ),

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
                            _previewData!['file_name'],
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: const Text(
                            'READY TO COMMIT',
                            style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppTheme.primary),
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
                      ],
                    ),
                    const SizedBox(height: 16),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Total Transaksi:', style: TextStyle(color: AppTheme.textMuted)),
                        Text(_previewData!['total_amount'], style: const TextStyle(fontWeight: FontWeight.bold)),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Total Selisih Kas:', style: TextStyle(color: AppTheme.textMuted)),
                        Text(_previewData!['cash_difference'], style: const TextStyle(fontWeight: FontWeight.bold, color: AppTheme.primary)),
                      ],
                    ),
                    const SizedBox(height: 20),
                    ElevatedButton(
                      onPressed: _confirmBatch,
                      child: const Text('Konfirmasi & Terapkan ke KPI Kasir'),
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
