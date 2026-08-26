import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
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
            child: const Text('Tandai Semua Dibaca', style: TextStyle(fontSize: 12, color: AppTheme.primary)),
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _notifications.isEmpty
              ? const Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.notifications_off_outlined, color: AppTheme.textMuted, size: 48),
                      SizedBox(height: 12),
                      Text('Belum ada notifikasi.', style: TextStyle(color: AppTheme.textMuted)),
                    ],
                  ),
                )
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: _notifications.length,
                  separatorBuilder: (_, index) => const Divider(),
                  itemBuilder: (context, index) {
                    final notif = _notifications[index];
                    final isRead = notif['is_read'] == true;

                    return ListTile(
                      onTap: () => _markRead(index),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      leading: CircleAvatar(
                        backgroundColor: isRead ? Colors.grey[200] : AppTheme.primary.withValues(alpha: 0.15),
                        child: Icon(
                          Icons.notifications_active_rounded,
                          color: isRead ? AppTheme.textMuted : AppTheme.primary,
                          size: 20,
                        ),
                      ),
                      title: Text(
                        notif['title'] ?? 'Notifikasi',
                        style: TextStyle(
                          fontWeight: isRead ? FontWeight.normal : FontWeight.bold,
                          fontSize: 14,
                        ),
                      ),
                      subtitle: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const SizedBox(height: 4),
                          Text(notif['body'] ?? '', style: const TextStyle(fontSize: 12)),
                          const SizedBox(height: 4),
                          Text(
                            notif['created_at'].toString().split('T')[0],
                            style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                          ),
                        ],
                      ),
                    );
                  },
                ),
    );
  }
}
