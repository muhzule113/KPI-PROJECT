import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import 'create_ticket_screen.dart';
import 'customer_pickup_screen.dart';
import 'sparepart_screen.dart';
import 'ticket_progress_screen.dart';

class TicketsListScreen extends StatefulWidget {
  const TicketsListScreen({super.key});

  @override
  State<TicketsListScreen> createState() => _TicketsListScreenState();
}

class _TicketsListScreenState extends State<TicketsListScreen> {
  bool _isLoading = true;
  List<dynamic> _tickets = [];
  String? _errorMessage;
  String _selectedFilter = 'all'; // all, in_progress, completed, delivered
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadTickets();
  }

  Future<void> _loadTickets() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final res = await ApiService.get('/operational/tickets');
      setState(() {
        _tickets = res['data'] ?? [];
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _errorMessage = e.toString().replaceAll('Exception: ', '');
        _isLoading = false;
      });
    }
  }

  List<dynamic> get _filteredTickets {
    final query = _searchController.text.toLowerCase().trim();
    return _tickets.filter((t) {
      final matchFilter =
          _selectedFilter == 'all' ||
          (_selectedFilter == 'in_progress' &&
              [
                'intake',
                'in_progress',
                'diagnosing',
                'waiting_sparepart',
              ].contains(t['status'])) ||
          (_selectedFilter == 'completed' && t['status'] == 'completed') ||
          (_selectedFilter == 'delivered' && t['status'] == 'delivered');

      if (!matchFilter) return false;
      if (query.isEmpty) return true;

      final ticketNum = (t['ticket_number'] ?? '').toString().toLowerCase();
      final custName = (t['customer_name'] ?? '').toString().toLowerCase();
      final device = "${t['device_brand'] ?? ''} ${t['device_model'] ?? ''}"
          .toLowerCase();

      return ticketNum.contains(query) ||
          custName.contains(query) ||
          device.contains(query);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final isTeknisi = auth.isTeknisi;
    final isCs = auth.isCs;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Tiket Servis HP Operasional'),
        actions: [
          if (auth.isGudang)
            IconButton(
              icon: const Icon(Icons.inventory_2_rounded),
              tooltip: 'Inventory',
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const SparepartScreen()),
                );
              },
            ),
          IconButton(
            icon: const Icon(Icons.sync_rounded),
            tooltip: 'Sync ke KPI',
            onPressed: () async {
              try {
                final res = await ApiService.post('/operational/sync-kpi');
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(
                        res['message'] ??
                            'KPI berhasil disinkronkan dari tiket operasional!',
                      ),
                      backgroundColor: AppTheme.primary,
                    ),
                  );
                  _loadTickets();
                }
              } catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(e.toString()),
                      backgroundColor: AppTheme.statusDanger,
                    ),
                  );
                }
              }
            },
          ),
        ],
      ),
      floatingActionButton: isCs
          ? FloatingActionButton.extended(
              onPressed: () async {
                final created = await Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const CreateTicketScreen()),
                );
                if (created == true) _loadTickets();
              },
              backgroundColor: AppTheme.primary,
              icon: const Icon(Icons.add_rounded, color: Colors.white),
              label: const Text(
                'Tiket Baru',
                style: TextStyle(
                  color: AppTheme.surface,
                  fontWeight: FontWeight.bold,
                ),
              ),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _loadTickets,
        child: ListView(
          // Keep the last ticket card above the floating navigation surface.
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
          children: [
            // Search & Filter
            TextField(
              controller: _searchController,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                hintText: 'Cari No. Tiket / Nama Pelanggan / HP...',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear_rounded),
                        tooltip: 'Bersihkan pencarian',
                        onPressed: () =>
                            setState(() => _searchController.clear()),
                      )
                    : null,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 12,
                ),
              ),
            ),
            const SizedBox(height: 12),

            // Filter Chips
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _filterChip('all', 'Semua Tiket (${_tickets.length})'),
                  const SizedBox(width: 8),
                  _filterChip('in_progress', 'Pengerjaan'),
                  const SizedBox(width: 8),
                  _filterChip('completed', 'Siap Diambil'),
                  const SizedBox(width: 8),
                  _filterChip('delivered', 'Selesai / Diserahkan'),
                ],
              ),
            ),
            const SizedBox(height: 16),

            if (_isLoading)
              const OpsScreenLoading(rows: 3)
            else if (_errorMessage != null)
              Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    children: [
                      const Icon(
                        Icons.error_outline_rounded,
                        color: AppTheme.statusDanger,
                        size: 40,
                      ),
                      const SizedBox(height: 8),
                      Text(_errorMessage!),
                      const SizedBox(height: 12),
                      ElevatedButton(
                        onPressed: _loadTickets,
                        child: const Text('Coba Lagi'),
                      ),
                    ],
                  ),
                ),
              )
            else if (_filteredTickets.isEmpty)
              Container(
                padding: const EdgeInsets.all(40),
                alignment: Alignment.center,
                child: Column(
                  children: [
                    const Icon(
                      Icons.search_off_rounded,
                      size: 48,
                      color: AppTheme.textMuted,
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'Tidak ada tiket servis yang sesuai filter.',
                      style: TextStyle(color: AppTheme.textMuted),
                    ),
                    if (isCs) ...[
                      const SizedBox(height: 16),
                      ElevatedButton.icon(
                        icon: const Icon(Icons.add),
                        label: const Text('Buat Tiket Masuk Baru'),
                        onPressed: () async {
                          final created = await Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (_) => const CreateTicketScreen(),
                            ),
                          );
                          if (created == true) _loadTickets();
                        },
                      ),
                    ],
                  ],
                ),
              )
            else
              ..._filteredTickets.map((t) {
                final status = t['status'] ?? 'intake';
                final isCompleted = status == 'completed';

                return OpsReveal(
                  delay: Duration(
                    milliseconds: 80 + (_filteredTickets.indexOf(t) * 35),
                  ),
                  child: OpsCard(
                    padding: EdgeInsets.zero,
                    onTap: () async {
                      if (isTeknisi && status != 'delivered') {
                        final updated = await Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => TicketProgressScreen(
                              ticketId: t['id'].toString(),
                            ),
                          ),
                        );
                        if (updated == true) _loadTickets();
                      } else if (isCs && isCompleted) {
                        final delivered = await Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => CustomerPickupScreen(ticket: t),
                          ),
                        );
                        if (delivered == true) _loadTickets();
                      } else {
                        final updated = await Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => TicketProgressScreen(
                              ticketId: t['id'].toString(),
                            ),
                          ),
                        );
                        if (updated == true) _loadTickets();
                      }
                    },
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 8,
                                  vertical: 4,
                                ),
                                decoration: BoxDecoration(
                                  color: AppTheme.primary.withValues(
                                    alpha: 0.12,
                                  ),
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  t['ticket_number'] ?? 'SRV',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 12,
                                    color: AppTheme.primary,
                                  ),
                                ),
                              ),
                              KpiStatusPill(
                                label: _formatStatus(status),
                                color: _getStatusColor(status),
                                icon: _statusIcon(status),
                              ),
                            ],
                          ),
                          const SizedBox(height: 10),
                          Text(
                            "${t['device_brand']} ${t['device_model']}",
                            style: const TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.textInk,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            "Pelanggan: ${t['customer_name']} (${t['customer_phone']})",
                            style: const TextStyle(
                              fontSize: 13,
                              color: AppTheme.textMuted,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: AppTheme.parchment,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Row(
                              children: [
                                const Icon(
                                  Icons.build_circle_outlined,
                                  size: 16,
                                  color: AppTheme.textMuted,
                                ),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    t['initial_complaint'] ?? 'Keluhan',
                                    style: const TextStyle(
                                      fontSize: 12,
                                      color: AppTheme.textInk,
                                    ),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                "Teknisi: ${t['technician_name'] ?? 'Belum Ditugaskan'}",
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                  color: AppTheme.primaryBright,
                                ),
                              ),
                              if (isCs && isCompleted)
                                ElevatedButton.icon(
                                  icon: const Icon(
                                    Icons.handshake_rounded,
                                    size: 14,
                                  ),
                                  label: const Text('Serahkan & CSAT'),
                                  style: ElevatedButton.styleFrom(
                                    minimumSize: const Size(0, 32),
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 10,
                                    ),
                                  ),
                                  onPressed: () async {
                                    final delivered = await Navigator.push(
                                      context,
                                      MaterialPageRoute(
                                        builder: (_) =>
                                            CustomerPickupScreen(ticket: t),
                                      ),
                                    );
                                    if (delivered == true) _loadTickets();
                                  },
                                )
                              else if (isTeknisi && status != 'delivered')
                                const Icon(
                                  Icons.arrow_forward_ios_rounded,
                                  size: 14,
                                  color: AppTheme.textMuted,
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }

  Widget _filterChip(String key, String label) {
    final isSelected = _selectedFilter == key;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      onSelected: (_) => setState(() => _selectedFilter = key),
      selectedColor: AppTheme.primary.withValues(alpha: 0.15),
      labelStyle: TextStyle(
        color: isSelected ? AppTheme.primary : AppTheme.textMuted,
        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
        fontSize: 12,
      ),
    );
  }

  String _formatStatus(String status) {
    switch (status) {
      case 'intake':
        return 'Diterima CS';
      case 'diagnosing':
        return 'Diagnosa';
      case 'waiting_sparepart':
        return 'Tunggu Part';
      case 'in_progress':
        return 'Sedang Dikerjakan';
      case 'qc_ready':
        return 'Siap QC';
      case 'completed':
        return 'Selesai Sukses';
      case 'delivered':
        return 'Diserahkan';
      case 'cancelled_unrepairable':
        return 'Gagal / Batal';
      default:
        return status;
    }
  }

  IconData _statusIcon(String status) {
    switch (status) {
      case 'completed':
      case 'delivered':
        return Icons.check_circle_rounded;
      case 'in_progress':
        return Icons.handyman_rounded;
      case 'diagnosing':
        return Icons.search_rounded;
      case 'waiting_sparepart':
        return Icons.inventory_2_rounded;
      case 'cancelled_unrepairable':
        return Icons.cancel_rounded;
      default:
        return Icons.schedule_rounded;
    }
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'completed':
      case 'delivered':
        return AppTheme.statusApproved;
      case 'in_progress':
      case 'diagnosing':
        return AppTheme.primary;
      case 'waiting_sparepart':
        return AppTheme.statusRevision;
      case 'cancelled_unrepairable':
        return AppTheme.statusDanger;
      default:
        return AppTheme.textMuted;
    }
  }
}

extension ListFilterExtension on List {
  List filter(bool Function(dynamic) test) {
    return where(test).toList();
  }
}
