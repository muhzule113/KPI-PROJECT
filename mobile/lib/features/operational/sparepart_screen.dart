import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';

class SparepartScreen extends StatefulWidget {
  const SparepartScreen({super.key});

  @override
  State<SparepartScreen> createState() => _SparepartScreenState();
}

class _SparepartScreenState extends State<SparepartScreen> {
  bool _isLoading = true;
  String? _errorMessage;
  List<dynamic> _spareparts = [];
  List<dynamic> _requests = [];

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final results = await Future.wait([
        ApiService.get('/operational/spareparts'),
        ApiService.get('/operational/sparepart-requests'),
      ]);
      if (!mounted) return;
      setState(() {
        _spareparts = (results[0]['data'] as List<dynamic>?) ?? [];
        _requests = (results[1]['data'] as List<dynamic>?) ?? [];
        _isLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  Future<void> _fulfillRequest(dynamic request) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Serahkan Sparepart?'),
        content: Text('${request['sparepart']?['name'] ?? 'Sparepart'} x${request['quantity']} akan diserahkan dan stok gudang terpotong.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.primary),
            child: const Text('Serahkan'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      final res = await ApiService.post('/operational/spareparts/fulfill/${request['id']}');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? 'Sparepart diserahkan.'), backgroundColor: AppTheme.primary),
      );
      _loadData();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceAll('Exception: ', '')), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return Scaffold(
      appBar: AppBar(title: const Text('Inventory Sparepart')),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.error_outline_rounded, color: AppTheme.statusDanger, size: 48),
                        const SizedBox(height: 12),
                        Text(_errorMessage!, textAlign: TextAlign.center),
                        const SizedBox(height: 16),
                        ElevatedButton(onPressed: _loadData, child: const Text('Coba Lagi')),
                      ],
                    ),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _loadData,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      // Permintaan sparepart menunggu fulfillment (Gudang)
                      if (auth.isGudang && _requests.isNotEmpty) ...[
                        const Text(
                          'Permintaan Menunggu Diserahkan',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                        ),
                        const SizedBox(height: 8),
                        ..._requests.map((req) => Card(
                              margin: const EdgeInsets.only(bottom: 8),
                              child: ListTile(
                                leading: const Icon(Icons.move_to_inbox_rounded, color: AppTheme.statusRevision),
                                title: Text('${req['sparepart']?['name'] ?? '-'} x${req['quantity']}'),
                                subtitle: Text('Dari: ${req['requested_by']?['name'] ?? '-'}\nTiket: ${req['ticket']?['ticket_number'] ?? '-'}'),
                                isThreeLine: true,
                                trailing: auth.isGudang
                                    ? ElevatedButton(
                                        onPressed: () => _fulfillRequest(req),
                                        style: ElevatedButton.styleFrom(backgroundColor: AppTheme.primary),
                                        child: const Text('Serahkan'),
                                      )
                                    : null,
                              ),
                            )),
                        const SizedBox(height: 16),
                      ],

                      // Daftar stok dikelompokkan per jenis produk
                      for (final group in _groupedProducts()) ...[
                        Padding(
                          padding: const EdgeInsets.only(top: 12, bottom: 8),
                          child: Row(
                            children: [
                              Text(
                                group.key,
                                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppTheme.primary),
                              ),
                              const SizedBox(width: 8),
                              Text(
                                '(${group.value.length})',
                                style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                              ),
                            ],
                          ),
                        ),
                        ...group.value.map((sp) => Card(
                              margin: const EdgeInsets.only(bottom: 8),
                              child: ListTile(
                                leading: CircleAvatar(
                                  backgroundColor: AppTheme.primary.withValues(alpha: 0.12),
                                  child: Icon(_productIcon(sp), color: AppTheme.primary, size: 20),
                                ),
                                title: Text(sp['name'] ?? '-'),
                                subtitle: Text(sp['code'] ?? ''),
                                trailing: Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      '${sp['stock'] ?? 0} pcs',
                                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AppTheme.textInk),
                                    ),
                                    Text(
                                      'Min: ${sp['min_stock'] ?? 0}',
                                      style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
                                    ),
                                  ],
                                ),
                              ),
                            )),
                      ]
                      // akhir for-element produk
                    ],
                  ),
                ),
    );
  }

  // Kelompokkan produk per jenis (Handset, Tablet, Sparepart, Aksesoris, dst)
  List<MapEntry<String, List<dynamic>>> _groupedProducts() {
    final Map<String, List<dynamic>> groups = {};
    for (final sp in _spareparts) {
      final label = (sp['product_type_label'] as String?) ?? 'Lainnya';
      groups.putIfAbsent(label, () => []).add(sp);
    }
    // Urutan tetap: handset, tablet, sparepart, aksesoris, lalu lainnya
    const order = ['Handset HP', 'Tablet / iPad', 'Sparepart', 'Aksesoris'];
    final sorted = order
        .where(groups.containsKey)
        .map((o) => MapEntry(o, groups.remove(o)!))
        .toList();
    groups.forEach((k, v) => sorted.add(MapEntry(k, v)));

    return sorted;
  }

  IconData _productIcon(dynamic sp) {
    switch (sp['product_type']) {
      case 'handset':
        return Icons.smartphone_rounded;
      case 'tablet':
        return Icons.tablet_android_rounded;
      case 'aksesoris':
        return Icons.headphones_rounded;
      case 'sparepart':
        return Icons.build_rounded;
      default:
        return Icons.inventory_2_rounded;
    }
  }
}
