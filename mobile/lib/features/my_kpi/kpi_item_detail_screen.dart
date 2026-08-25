import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';

class KpiItemDetailScreen extends StatefulWidget {
  final String itemId;
  final bool isEditable;

  const KpiItemDetailScreen({
    super.key,
    required this.itemId,
    required this.isEditable,
  });

  @override
  State<KpiItemDetailScreen> createState() => _KpiItemDetailScreenState();
}

class _KpiItemDetailScreenState extends State<KpiItemDetailScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _item;
  final _actualController = TextEditingController();
  final _notesController = TextEditingController();
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _loadItem();
  }

  Future<void> _loadItem() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/my-kpi/items/${widget.itemId}');
      setState(() {
        _item = res['data'];
        if (_item?['actual_decimal'] != null) {
          _actualController.text = _item!['actual_decimal'].toString();
        }
        _isLoading = false;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
        );
        Navigator.pop(context);
      }
    }
  }

  Future<void> _saveDraft() async {
    if (_actualController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Harap masukkan angka nilai aktual.')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final val = double.tryParse(_actualController.text.trim());
      await ApiService.post('/my-kpi/items/${widget.itemId}/draft', {
        'actual_decimal': val,
        'notes': _notesController.text.trim(),
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Draft berhasil disimpan!'), backgroundColor: AppTheme.primary),
        );
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
        );
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final item = _item!;
    final rubric = item['rubric'];
    final latestReview = item['latest_review'];

    return Scaffold(
      appBar: AppBar(
        title: Text(item['code'] ?? 'Detail Indikator'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Title & Badges
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    'Bobot: ${item['weight']}%',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      color: AppTheme.primary,
                      fontSize: 13,
                    ),
                  ),
                ),
                Text(
                  'Sumber: ${_formatSource(item['source_type'])}',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              item['name'],
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.textInk),
            ),
            const SizedBox(height: 20),

            // Target card
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppTheme.parchment,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.border),
              ),
              child: Row(
                children: [
                  const Icon(Icons.track_changes_rounded, color: AppTheme.textInk),
                  const SizedBox(width: 12),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Target Sasaran', style: TextStyle(fontSize: 12, color: AppTheme.textMuted)),
                      Text(
                        '${item['target_value']} ${item['target_unit']}',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppTheme.textInk),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            if (latestReview != null && latestReview['reason'] != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppTheme.statusRevision.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppTheme.statusRevision),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Row(
                      children: [
                        Icon(Icons.feedback_rounded, color: AppTheme.statusRevision, size: 20),
                        SizedBox(width: 6),
                        Text(
                          'Catatan Revisi Supervisor:',
                          style: TextStyle(fontWeight: FontWeight.bold, color: AppTheme.statusRevision),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(latestReview['reason']),
                  ],
                ),
              ),
            ],

            // Rubric Criteria Preview if applicable
            if (rubric != null && rubric['criteria'] != null) ...[
              const SizedBox(height: 24),
              const Text(
                'Kriteria Rubrik Observasi Supervisor:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
              const SizedBox(height: 8),
              ...((rubric['criteria'] as List<dynamic>).map((c) {
                return Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.check_circle_outline, size: 16, color: AppTheme.primary),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          c['criterion_text'],
                          style: const TextStyle(fontSize: 13, color: AppTheme.textInk),
                        ),
                      ),
                    ],
                  ),
                );
              })),
            ],

            const SizedBox(height: 24),
            const Text(
              'Input Nilai Aktual',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 10),

            TextField(
              controller: _actualController,
              enabled: widget.isEditable,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: 'Nilai Aktual (${item['target_unit']})',
                hintText: 'Contoh: 85',
                suffixText: item['target_unit'],
              ),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _notesController,
              enabled: widget.isEditable,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'Catatan Penjelasan (Opsional)',
                hintText: 'Tuliskan rincian pencapaian...',
              ),
            ),

            const SizedBox(height: 24),
            if (widget.isEditable)
              ElevatedButton(
                onPressed: _isSaving ? null : _saveDraft,
                child: _isSaving
                    ? const CircularProgressIndicator(color: Colors.white)
                    : const Text('Simpan Draft Nilai'),
              ),
          ],
        ),
      ),
    );
  }

  String _formatSource(String? source) {
    switch (source) {
      case 'employee': return 'Karyawan';
      case 'supervisor': return 'Supervisor';
      case 'cross_role': return 'Cross-Role';
      case 'import': return 'Import Kasir';
      case 'system': return 'Sistem';
      default: return source ?? '-';
    }
  }
}
