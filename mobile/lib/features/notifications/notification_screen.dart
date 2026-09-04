import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class NotificationScreen extends StatefulWidget {
  const NotificationScreen({super.key});

  @override
  State<NotificationScreen> createState() => _NotificationScreenState();
}

class _NotificationScreenState extends State<NotificationScreen> {
  bool _isLoading = true;
  List<dynamic> _notifications = [];

  @override
  void initState() {
    super.initState();
    _loadNotifications();
  }

  Future<void> _loadNotifications() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/notifications');
      setState(() {
        _notifications = res['data'] ?? [];
        _isLoading = false;
      });
    } catch (_) {
      setState(() => _isLoading = false);
    }
  }

  Future<void> _markAllRead() async {
    await ApiService.post('/notifications/read-all');
    _loadNotifications();
  }

  Future<void> _markRead(int index) async {
    final notif = _notifications[index];
    if (notif['is_read'] == true) return;

    try {
      await ApiService.post('/notifications/${notif['id']}/read');
    } catch (_) {
      // gagal mark read — tetap buka isi notifikasi
    }
    setState(() {
      _notifications[index] = {...notif, 'is_read': true};
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifikasi'),
        actions: [
          TextButton(
            onPressed: _markAllRead,
            child: const Text(
              'Tandai Semua Dibaca',
              style: TextStyle(fontSize: 12, color: AppTheme.primary),
            ),
          ),
        ],
      ),
      body: _isLoading
          ? const OpsScreenLoading(rows: 4)
          : _notifications.isEmpty
          ? const KpiEmptyState(
              icon: Icons.notifications_off_outlined,
              title: 'Belum ada notifikasi',
              message: 'Notifikasi aktivitas akun akan muncul di sini.',
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: _notifications.length,
              separatorBuilder: (_, index) => const Divider(),
              itemBuilder: (context, index) {
                final notif = _notifications[index];
                final isRead = notif['is_read'] == true;

                return OpsReveal(
                  delay: Duration(milliseconds: 60 + (index * 35)),
                  child: OpsCard(
                    padding: EdgeInsets.zero,
                    onTap: () => _markRead(index),
                    child: ListTile(
                      leading: Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          color: isRead
                              ? AppTheme.surfaceMuted
                              : AppTheme.primary.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(13),
                        ),
                        child: Icon(
                          Icons.notifications_active_rounded,
                          color: isRead
                              ? AppTheme.textMuted
                              : AppTheme.primaryBright,
                          size: 20,
                        ),
                      ),
                      title: Text(
                        notif['title'] ?? 'Notifikasi',
                        style: TextStyle(
                          fontWeight: isRead
                              ? FontWeight.normal
                              : FontWeight.bold,
                          fontSize: 14,
                        ),
                      ),
                      subtitle: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const SizedBox(height: 4),
                          Text(
                            notif['body'] ?? '',
                            style: const TextStyle(fontSize: 12),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            notif['created_at'].toString().split('T')[0],
                            style: TextStyle(
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
}
