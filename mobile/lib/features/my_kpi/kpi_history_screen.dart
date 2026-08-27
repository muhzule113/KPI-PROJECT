import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class KpiHistoryScreen extends StatefulWidget {
  const KpiHistoryScreen({super.key});

  @override
  State<KpiHistoryScreen> createState() => _KpiHistoryScreenState();
}

class _KpiHistoryScreenState extends State<KpiHistoryScreen> {
  bool _isLoading = true;
  List<dynamic> _history = [];
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final res = await ApiService.get('/my-kpi/history');
      setState(() {
        _history = res['data'] ?? [];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Riwayat KPI')),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) {
      return const OpsScreenLoading(rows: 4);
    }
    if (_errorMessage != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(_errorMessage!),
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: _loadHistory,
              child: const Text('Muat Ulang'),
            ),
          ],
        ),
      );
    }
    if (_history.isEmpty) {
      return const KpiEmptyState(
        icon: Icons.history_rounded,
        title: 'Belum ada riwayat KPI',
        message: 'Periode KPI yang sudah selesai akan muncul di sini.',
      );
    }

    return RefreshIndicator(
      onRefresh: _loadHistory,
      child: ListView.separated(
        padding: const EdgeInsets.all(20),
        itemCount: _history.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (context, index) {
          final h = _history[index];
          return OpsReveal(
            delay: Duration(milliseconds: 60 + (index * 35)),
            child: OpsCard(
              padding: EdgeInsets.zero,
              child: ListTile(
                leading: Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(13),
                  ),
                  child: const Icon(
                    Icons.assignment_turned_in_rounded,
                    color: AppTheme.primaryBright,
                    size: 20,
                  ),
                ),
                title: Text(
                  h['period_name'] ?? 'Periode',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                  ),
                ),
                subtitle: Text(
                  h['approved_at'] != null
                      ? 'Disetujui ${h['approved_at'].toString().split('T')[0]}'
                      : 'Status: ${_formatStatus(h['status'])}',
                  style: const TextStyle(fontSize: 12),
                ),
                trailing: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      h['final_score']?.toString() ?? '-',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 16,
                        color: AppTheme.primaryBright,
                      ),
                    ),
                    Text(
                      h['rating_label'] ?? '',
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  String _formatStatus(String? status) {
    switch (status) {
      case 'approved':
        return 'Disetujui';
      case 'locked':
        return 'Terkunci (Final)';
      case 'published':
        return 'Diterbitkan';
      default:
        return status ?? '-';
    }
  }
}
