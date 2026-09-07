import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class FeedbackScreen extends StatefulWidget {
  const FeedbackScreen({super.key});

  @override
  State<FeedbackScreen> createState() => _FeedbackScreenState();
}

class _FeedbackScreenState extends State<FeedbackScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _data = {};

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
      final response = await ApiService.get('/operational/feedback');
      if (!mounted) return;
      setState(() {
        _data = Map<String, dynamic>.from(response['data'] as Map);
        _loading = false;
      });
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = _message(exception);
        });
      }
    }
  }

  Future<void> _copyLink(String ticketId) async {
    try {
      final response = await ApiService.get(
        '/operational/tickets/$ticketId/feedback-link',
      );
      final url = response['data']?['url']?.toString();
      if (url == null) {
        throw const ApiException(
          statusCode: 422,
          message: 'Link progres servis tidak tersedia.',
        );
      }
      await Clipboard.setData(ClipboardData(text: url));
      if (mounted) _messageSnack('Link progres servis berhasil disalin.');
    } catch (exception) {
      if (mounted) _errorSnack(_message(exception));
    }
  }

  Future<void> _updateFollowUp(Map<String, dynamic> followUp) async {
    final summaryController = TextEditingController();
    final evidenceController = TextEditingController();
    summaryController.text = followUp['response_summary']?.toString() ?? '';
    var status = followUp['status']?.toString() == 'contacted'
        ? 'completed'
        : 'contacted';
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Tindak lanjut feedback'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                OpsSelectionField<String>(
                  label: 'Status',
                  sheetTitle: 'Pilih status tindak lanjut',
                  value: status,
                  options: const [
                    OpsSelectionOption(
                      value: 'contacted',
                      label: 'Sudah dihubungi',
                      icon: Icons.call_outlined,
                    ),
                    OpsSelectionOption(
                      value: 'completed',
                      label: 'Selesai',
                      icon: Icons.check_circle_outline_rounded,
                    ),
                    OpsSelectionOption(
                      value: 'no_response',
                      label: 'Tidak terhubung',
                      icon: Icons.phone_missed_outlined,
                    ),
                    OpsSelectionOption(
                      value: 'escalated',
                      label: 'Eskalasi',
                      icon: Icons.arrow_circle_up_outlined,
                    ),
                  ],
                  onChanged: (value) => setDialogState(() => status = value),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: summaryController,
                  maxLines: 3,
                  decoration: const InputDecoration(
                    labelText: 'Ringkasan respons',
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: evidenceController,
                  decoration: const InputDecoration(
                    labelText: 'Referensi evidence',
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, {
                'status': status,
                'summary': summaryController.text.trim(),
                'evidence': evidenceController.text.trim(),
              }),
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
    summaryController.dispose();
    evidenceController.dispose();
    if (result == null) return;
    if (result['summary']?.trim().isNotEmpty != true) {
      _errorSnack('Ringkasan respons wajib diisi.');
      return;
    }
    final body = <String, dynamic>{
      'row_version': followUp['row_version'],
      'status': result['status'],
      'outcome': result['status'] == 'completed' ? 'resolved' : 'contacted',
      'response_summary': result['summary'],
      if (result['status'] == 'contacted' || result['status'] == 'completed')
        'contact_channel': 'whatsapp',
      'evidence': [
        {
          'type': 'follow_up_note',
          'reference': result['evidence']?.isNotEmpty == true
              ? result['evidence']
              : 'Catatan tindak lanjut',
        },
      ],
    };
    try {
      final response = await ApiService.post(
        '/operational/feedback-followups/${followUp['id']}',
        body,
      );
      if (mounted) {
        _messageSnack(
          response['message']?.toString() ?? 'Follow-up berhasil disimpan.',
        );
        _load();
      }
    } catch (exception) {
      if (mounted) _errorSnack(_message(exception));
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
    final pending = List<dynamic>.from(
      _data['pending_tickets'] as List? ?? const [],
    );
    final feedbacks = List<dynamic>.from(
      _data['feedbacks'] as List? ?? const [],
    );
    final stats = Map<String, dynamic>.from(_data['stats'] as Map? ?? const {});
    return Scaffold(
      appBar: AppBar(
        title: const Text('Feedback Pelanggan'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh_rounded)),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
          children: [
            Row(
              children: [
                _stat(
                  'Rerata Pelayan',
                  '${stats['average'] ?? 0}',
                  AppTheme.statusApproved,
                ),
                const SizedBox(width: 10),
                _stat(
                  'Rerata Teknisi',
                  stats['technician_average'] == null
                      ? '—'
                      : '${stats['technician_average']}',
                  AppTheme.statusRevision,
                ),
                const SizedBox(width: 10),
                _stat('Total', '${stats['total'] ?? 0}', AppTheme.primary),
              ],
            ),
            const SizedBox(height: 22),
            KpiSectionHeader(title: 'Bagikan progres servis'),
            const SizedBox(height: 8),
            if (pending.isEmpty)
              const KpiEmptyState(
                icon: Icons.check_circle_outline,
                title: 'Tidak ada tiket tersedia',
                message: 'Tidak ada link progres servis yang dapat dibagikan.',
              ),
            ...pending.map((raw) {
              final ticket = Map<String, dynamic>.from(raw as Map);
              return OpsCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${ticket['ticket_number'] ?? 'Tiket'} · ${ticket['customer_name'] ?? 'Pelanggan'}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      ticket['device']?.toString() ?? 'Perangkat',
                      style: TextStyle(color: AppTheme.textMuted),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => _copyLink(ticket['id'].toString()),
                      icon: const Icon(Icons.link_rounded),
                      label: const Text('Salin link progres'),
                    ),
                  ],
                ),
              );
            }),
            const SizedBox(height: 16),
            KpiSectionHeader(title: 'Feedback masuk'),
            const SizedBox(height: 8),
            if (feedbacks.isEmpty)
              const KpiEmptyState(
                icon: Icons.forum_outlined,
                title: 'Belum ada feedback',
                message: 'Feedback pelanggan akan tampil di sini.',
              ),
            ...feedbacks.map((raw) {
              final feedback = Map<String, dynamic>.from(raw as Map);
              final followUp = feedback['follow_up'] is Map
                  ? Map<String, dynamic>.from(feedback['follow_up'] as Map)
                  : null;
              final rating = (feedback['rating'] as num?)?.toInt() ?? 0;
              final technicianRating = (feedback['technician_rating'] as num?)
                  ?.toInt();
              final technician = feedback['technician_employee']?.toString();
              return OpsCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            feedback['customer_name']?.toString() ??
                                'Pelanggan',
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Pelayan: ${feedback['employee'] ?? '-'} · ★ $rating/5',
                      style: const TextStyle(
                        color: Colors.orange,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (technicianRating != null ||
                        technician?.trim().isNotEmpty == true)
                      Text(
                        'Teknisi: ${technician ?? '-'} · ★ ${technicianRating ?? '-'}/5',
                        style: const TextStyle(
                          color: Colors.orange,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    const SizedBox(height: 4),
                    Text(
                      feedback['comments']?.toString().isNotEmpty == true
                          ? feedback['comments'].toString()
                          : 'Tanpa komentar',
                      style: TextStyle(color: AppTheme.textMuted),
                    ),
                    if (followUp != null) ...[
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          KpiStatusPill(
                            label:
                                followUp['status']?.toString() ?? 'follow-up',
                            color: AppTheme.statusRevision,
                            icon: Icons.support_agent_rounded,
                          ),
                          const Spacer(),
                          if (followUp['status'] != 'completed')
                            TextButton(
                              onPressed: () => _updateFollowUp(followUp),
                              child: const Text('Tindak lanjuti'),
                            ),
                        ],
                      ),
                    ],
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _stat(String label, String value, Color color) => Expanded(
    child: Container(
      padding: const EdgeInsets.symmetric(vertical: 14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        children: [
          Text(
            value,
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.w800,
              color: color,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            label,
            style: TextStyle(fontSize: 11, color: AppTheme.textMuted),
          ),
        ],
      ),
    ),
  );

  void _messageSnack(String message) =>
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
  static String _message(Object exception) =>
      exception.toString().replaceFirst('Exception: ', '');
}
