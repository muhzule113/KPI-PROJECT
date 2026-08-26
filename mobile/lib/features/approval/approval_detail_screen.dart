import 'dart:convert';

import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';

class ApprovalDetailScreen extends StatefulWidget {
  final String kpiId;

  const ApprovalDetailScreen({super.key, required this.kpiId});

  @override
  State<ApprovalDetailScreen> createState() => _ApprovalDetailScreenState();
}

class _ApprovalDetailScreenState extends State<ApprovalDetailScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _data;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final res = await ApiService.get('/manager/approval/${widget.kpiId}');
      setState(() {
        _data = res['data'];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  Future<void> _approve() async {
    final noteController = TextEditingController();
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Approve KPI'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Dengan menyetujui, penilaian KPI ini akan dikunci (Locked) dan diterbitkan ke karyawan.'),
            const SizedBox(height: 12),
            TextField(
              controller: noteController,
              decoration: const InputDecoration(labelText: 'Catatan Approval (Opsional)'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(minimumSize: const Size(100, 40)),
            child: const Text('Ya, Approve & Kunci'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      final res = await ApiService.post('/manager/approval/${widget.kpiId}/approve', {
        'note': noteController.text.trim(),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? 'KPI berhasil disetujui!'), backgroundColor: AppTheme.primary),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  Future<void> _returnToSpv() async {
    final reasonController = TextEditingController();
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kembalikan KPI ke Supervisor'),
        content: TextField(
          controller: reasonController,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'Alasan Pengembalian (Wajib)',
            hintText: 'Jelaskan mengapa KPI perlu direview ulang...',
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.statusDanger,
              minimumSize: const Size(100, 40),
            ),
            child: const Text('Kembalikan'),
          ),
        ],
      ),
    );
    if (confirm != true || reasonController.text.trim().isEmpty) return;

    try {
      final res = await ApiService.post('/manager/approval/${widget.kpiId}/return', {
        'reason': reasonController.text.trim(),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? 'KPI dikembalikan ke Supervisor.'), backgroundColor: AppTheme.statusRevision),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Detail Approval KPI')),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_errorMessage != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(_errorMessage!),
            const SizedBox(height: 12),
            ElevatedButton(onPressed: _load, child: const Text('Muat Ulang')),
          ],
        ),
      );
    }

    final data = _data!;
    final emp = data['employee'] ?? {};
    final items = (data['items'] as List<dynamic>?) ?? [];
    final explanation = data['calculation_explanation'];

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Employee & period
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  emp['name'] ?? 'Karyawan',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppTheme.textInk),
                ),
                const SizedBox(height: 4),
                Text(
                  '${emp['employee_number'] ?? ''} • ${emp['position'] ?? '-'} • ${emp['branch'] ?? '-'}',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
                const SizedBox(height: 8),
                Text(
                  'Periode: ${data['period'] ?? '-'}',
                  style: const TextStyle(fontSize: 13),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    '${data['rating_label'] ?? '-'} (${data['final_score'] ?? '-'}) • ${_formatStatus(data['status'])}',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppTheme.primary),
                  ),
                ),
              ],
            ),
          ),

          if (explanation != null) ...[
            const SizedBox(height: 16),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppTheme.parchment,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Penjelasan Perhitungan',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    _stringify(explanation),
                    style: const TextStyle(fontSize: 12, color: AppTheme.textInk),
                  ),
                ],
              ),
            ),
          ],

          const SizedBox(height: 20),
          const Text(
            'Breakdown Indikator',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 12),
          ...items.map((item) {
            final evidences = (item['evidences'] as List<dynamic>?) ?? [];
            return Card(
              margin: const EdgeInsets.only(bottom: 10),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            item['code'] ?? '',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.primary,
                            ),
                          ),
                        ),
                        Text(
                          'Bobot ${item['weight']}%',
                          style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      item['name'] ?? '',
                      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: AppTheme.textInk),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        _itemStat('Target', '${item['target_value']} ${item['target_unit']}'),
                        _itemStat('Aktual', '${item['actual_decimal']} ${item['target_unit']}'),
                        _itemStat('Pencapaian', '${item['achievement_percentage']}%'),
                        _itemStat('Skor', '${item['weighted_score']}'),
                      ],
                    ),
                    if (evidences.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      ...evidences.map((e) => Row(
                            children: [
                              const Icon(Icons.attach_file_rounded, size: 14, color: AppTheme.textMuted),
                              const SizedBox(width: 4),
                              Expanded(
                                child: Text(
                                  e['file_name'] ?? '',
                                  style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          )),
                    ],
                  ],
                ),
              ),
            );
          }),

          const SizedBox(height: 24),
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  icon: const Icon(Icons.check_circle_rounded, size: 18),
                  label: const Text('Approve & Lock'),
                  style: ElevatedButton.styleFrom(minimumSize: const Size(0, 44)),
                  onPressed: _approve,
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                icon: const Icon(Icons.undo_rounded, size: 16),
                label: const Text('Return'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppTheme.statusDanger,
                  minimumSize: const Size(80, 44),
                ),
                onPressed: _returnToSpv,
              ),
            ],
          ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _itemStat(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 12, color: AppTheme.textMuted)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
      ],
    );
  }

  String _stringify(dynamic v) {
    if (v is String) return v;
    if (v is Map || v is List) return const JsonEncoder.withIndent('  ').convert(v);
    return v.toString();
  }

  String _formatStatus(String? status) {
    switch (status) {
      case 'pending_approval': return 'Menunggu Approval';
      case 'approved': return 'Disetujui';
      case 'locked': return 'Terkunci';
      default: return status ?? '-';
    }
  }
}
