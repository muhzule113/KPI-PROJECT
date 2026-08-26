import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';

class TicketProgressScreen extends StatefulWidget {
  final String ticketId;

  const TicketProgressScreen({super.key, required this.ticketId});

  @override
  State<TicketProgressScreen> createState() => _TicketProgressScreenState();
}

class _TicketProgressScreenState extends State<TicketProgressScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _ticket;
  final _diagnosisController = TextEditingController();
  final _actionController = TextEditingController();
  final _costController = TextEditingController();
  String _selectedResultStatus = 'success'; // success, unrepairable, warranty_return
  bool _isSaving = false;

  final Map<String, bool> _qcChecks = {
    'display': true,
    'touch': true,
    'camera': true,
    'mic': true,
    'speaker': true,
    'cellular': true,
    'charging': true,
    'biometric': true,
  };

  final Map<String, String> _qcLabels = {
    'display': 'Layar / Display (Warna & Kecerahan)',
    'touch': 'Touchscreen (Multi-touch & Respons)',
    'camera': 'Kamera Depan & Belakang',
    'mic': 'Microphone Suara',
    'speaker': 'Speaker Nada Dering & Earpiece',
    'cellular': 'Sinyal Seluler & WiFi / Bluetooth',
    'charging': 'Pengisian Daya / Fast Charging',
    'biometric': 'Fingerprint / Face ID',
  };

  @override
  void initState() {
    super.initState();
    _loadTicket();
  }

  Future<void> _loadTicket() async {
    setState(() => _isLoading = true);
    try {
      final res = await ApiService.get('/operational/tickets/${widget.ticketId}');
      setState(() {
        _ticket = res['data'];
        _diagnosisController.text = _ticket?['diagnosis_notes'] ?? '';
        _actionController.text = _ticket?['action_notes'] ?? '';
        _costController.text = (_ticket?['final_cost'] ?? _ticket?['estimated_cost'] ?? 0).toString();
        if (_ticket?['result_status'] != null && _ticket!['result_status'] != 'pending') {
          _selectedResultStatus = _ticket!['result_status'];
        }
        if (_ticket?['qc_checklist'] != null) {
          final savedQc = _ticket!['qc_checklist'] as Map<String, dynamic>;
          savedQc.forEach((key, val) {
            if (_qcChecks.containsKey(key)) {
              _qcChecks[key] = val == true;
            }
          });
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

  Future<void> _updateProgress(String status) async {
    setState(() => _isSaving = true);
    try {
      await ApiService.post('/operational/tickets/${widget.ticketId}/update-progress', {
        'status': status,
        'diagnosis_notes': _diagnosisController.text.trim(),
        'action_notes': _actionController.text.trim(),
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Progress pengerjaan berhasil disimpan!'), backgroundColor: AppTheme.primary),
        );
        _loadTicket();
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

  Future<void> _completeTicket() async {
    if (_diagnosisController.text.trim().isEmpty || _actionController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Diagnosa dan tindakan servis wajib diisi untuk kelengkapan laporan KPI!')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final res = await ApiService.post('/operational/tickets/${widget.ticketId}/complete', {
        'result_status': _selectedResultStatus,
        'diagnosis_notes': _diagnosisController.text.trim(),
        'action_notes': _actionController.text.trim(),
        'qc_checklist': _qcChecks,
        'final_cost': double.tryParse(_costController.text.trim()) ?? 0,
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Tiket berhasil diselesaikan dan dicatat ke KPI Teknisi!'),
            backgroundColor: AppTheme.primary,
          ),
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

  Future<void> _claimTicket() async {
    try {
      final res = await ApiService.post('/operational/tickets/${widget.ticketId}/assign');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Tiket berhasil diambil.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  Future<void> _openSparepartRequest() async {
    List<dynamic> parts;
    try {
      final res = await ApiService.get('/operational/spareparts');
      parts = (res['data'] as List<dynamic>?) ?? [];
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
      );
      return;
    }

    if (parts.isEmpty) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Belum ada sparepart yang terdaftar.')),
      );
      return;
    }

    if (!mounted) return;

    String? selectedId = parts.first['id'].toString();
    final qtyController = TextEditingController(text: '1');
    final notesController = TextEditingController();

    final submitted = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (sheetCtx) {
        return StatefulBuilder(
          builder: (sheetCtx, setSheetState) {
            return Padding(
              padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 20,
                bottom: MediaQuery.of(sheetCtx).viewInsets.bottom + 20,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Request Sparepart ke Gudang',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    initialValue: selectedId,
                    decoration: const InputDecoration(labelText: 'Pilih Sparepart'),
                    items: parts.map((p) {
                      final stock = (p['stock'] as num?)?.toInt() ?? 0;
                      return DropdownMenuItem(
                        value: p['id'].toString(),
                        child: Text('${p['name']} (${p['code']}) — stok $stock'),
                      );
                    }).toList(),
                    onChanged: (val) => setSheetState(() => selectedId = val),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: qtyController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Jumlah', suffixText: 'pcs'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: notesController,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'Catatan (Opsional)',
                      hintText: 'mis. butuh LCD untuk Galaxy A52',
                    ),
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () => Navigator.pop(sheetCtx, true),
                      child: const Text('Kirim Request'),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    if (submitted != true || selectedId == null) return;

    final qty = int.tryParse(qtyController.text.trim()) ?? 1;
    await _requestSparepart(selectedId!, qty < 1 ? 1 : qty, notesController.text.trim());
  }

  Future<void> _requestSparepart(String sparepartId, int quantity, String notes) async {
    try {
      final res = await ApiService.post('/operational/spareparts/request', {
        'service_ticket_id': widget.ticketId,
        'sparepart_id': sparepartId,
        'quantity': quantity,
        'notes': notes,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Permintaan sparepart terkirim ke Gudang.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  Future<void> _fulfillSparepart(String requestId) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Serahkan Sparepart?'),
        content: const Text('Sparepart akan ditandai diserahkan ke teknisi dan stok gudang terpotong.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Batal')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(minimumSize: const Size(100, 40)),
            child: const Text('Serahkan'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      final res = await ApiService.post('/operational/spareparts/fulfill/$requestId');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Sparepart berhasil diserahkan.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString()), backgroundColor: AppTheme.statusDanger),
      );
    }
  }

  String _ticketStatusLabel(String status) {
    const labels = {
      'intake': 'Intake',
      'diagnosing': 'Diagnosa',
      'waiting_sparepart': 'Menunggu part',
      'in_progress': 'Dikerjakan',
      'qc_ready': 'Siap QC',
      'completed': 'Selesai',
      'delivered': 'Diserahkan',
    };
    return labels[status] ?? status;
  }

  Color _ticketStatusColor(String status) {
    switch (status) {
      case 'completed':
      case 'delivered':
        return AppTheme.statusApproved;
      case 'waiting_sparepart':
        return AppTheme.statusRevision;
      case 'in_progress':
      case 'diagnosing':
      case 'qc_ready':
        return AppTheme.statusSubmitted;
      default:
        return AppTheme.statusDraft;
    }
  }

  IconData _ticketStatusIcon(String status) {
    switch (status) {
      case 'completed':
      case 'delivered':
        return Icons.check_circle_rounded;
      case 'waiting_sparepart':
        return Icons.inventory_2_rounded;
      case 'in_progress':
      case 'diagnosing':
      case 'qc_ready':
        return Icons.build_circle_rounded;
      default:
        return Icons.assignment_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final auth = context.watch<AuthProvider>();
    final ticket = _ticket!;
    final isDone = ticket['status'] == 'completed' || ticket['status'] == 'delivered';
    final sparepartRequests = (ticket['sparepart_requests'] as List<dynamic>?) ?? [];

    return Scaffold(
      appBar: AppBar(
        title: Text(ticket['ticket_number'] ?? 'Pengerjaan Servis'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Device & Customer Card
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppTheme.surface,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      "${ticket['device_brand']} ${ticket['device_model']}",
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppTheme.textInk),
                    ),
                    KpiStatusPill(
                      label: _ticketStatusLabel(ticket['status'].toString()),
                      color: _ticketStatusColor(ticket['status'].toString()),
                      icon: _ticketStatusIcon(ticket['status'].toString()),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  "Pelanggan: ${ticket['customer_name']} • ${ticket['customer_phone']}",
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                ),
                const SizedBox(height: 4),
                Text(
                  "Teknisi: ${ticket['technician_name'] ?? 'Belum Ditugaskan'}",
                  style: TextStyle(
                    color: ticket['technician_employee_id'] == null ? AppTheme.statusRevision : AppTheme.textMuted,
                    fontSize: 13,
                    fontWeight: ticket['technician_employee_id'] == null ? FontWeight.w600 : FontWeight.normal,
                  ),
                ),
                const SizedBox(height: 12),
                const Divider(),
                const SizedBox(height: 8),
                const Text('Keluhan Kerusakan:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppTheme.textMuted)),
                const SizedBox(height: 2),
                Text(ticket['initial_complaint'] ?? '-', style: const TextStyle(fontSize: 14, color: AppTheme.textInk)),
                if (ticket['physical_condition'] != null) ...[
                  const SizedBox(height: 8),
                  const Text('Kondisi Fisik:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: AppTheme.textMuted)),
                  const SizedBox(height: 2),
                  Text(ticket['physical_condition'], style: const TextStyle(fontSize: 13, color: AppTheme.textMuted)),
                ],
              ],
            ),
          ),
          // Claim button — teknisi mengambil tiket yang belum ditugaskan
          if (!isDone && auth.isTeknisi && ticket['technician_employee_id'] == null) ...[
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                icon: const Icon(Icons.handyman_rounded),
                label: const Text('Ambil Tiket Ini'),
                style: ElevatedButton.styleFrom(minimumSize: const Size(0, 44)),
                onPressed: _claimTicket,
              ),
            ),
          ],
          const SizedBox(height: 20),

          // Action Status Buttons (hanya Teknisi)
          if (!isDone && auth.isTeknisi) ...[
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    icon: const Icon(Icons.play_arrow_rounded, size: 18),
                    label: const Text('Mulai Servis'),
                    style: ElevatedButton.styleFrom(minimumSize: const Size(0, 42)),
                    onPressed: () => _updateProgress('in_progress'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.pause_circle_outline_rounded, size: 18),
                    label: const Text('Tunggu Part'),
                    style: OutlinedButton.styleFrom(minimumSize: const Size(0, 42)),
                    onPressed: () => _updateProgress('waiting_sparepart'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
          ],

          // Sparepart Request (Teknisi) + Status Permintaan (Gudang/Teknisi)
          if (!isDone && auth.isTeknisi) ...[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                icon: const Icon(Icons.handyman_outlined, size: 18),
                label: const Text('Request Sparepart ke Gudang'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(0, 42)),
                onPressed: _openSparepartRequest,
              ),
            ),
          ],
          if (sparepartRequests.isNotEmpty) ...[
            const SizedBox(height: 20),
            const Text(
              'Permintaan Sparepart',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
            ),
            const SizedBox(height: 8),
            Card(
              child: Column(
                children: sparepartRequests.map((r) {
                  final isPending = r['status'] == 'pending';
                  return ListTile(
                    dense: true,
                    leading: Icon(
                      Icons.inventory_2_outlined,
                      color: isPending ? AppTheme.statusRevision : AppTheme.primary,
                    ),
                    title: Text('${r['part_name']} × ${r['quantity']}'),
                    subtitle: Text(r['part_code'] ?? ''),
                    trailing: isPending
                        ? (auth.isGudang
                            ? FilledButton(
                                onPressed: () => _fulfillSparepart(r['id'].toString()),
                                style: FilledButton.styleFrom(
                                  minimumSize: const Size(0, 34),
                                  padding: const EdgeInsets.symmetric(horizontal: 14),
                                ),
                                child: const Text('Serahkan'),
                              )
                            : const Text(
                                'Menunggu Gudang',
                                style: TextStyle(fontSize: 12, color: AppTheme.statusRevision),
                              ))
                        : const Text(
                            'Diserahkan',
                            style: TextStyle(fontSize: 12, color: AppTheme.primary),
                          ),
                  );
                }).toList(),
              ),
            ),
          ],

          // Diagnosis & Actions
          const Text(
            'Catatan Diagnosa & Tindakan Teknisi',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _diagnosisController,
            enabled: !isDone && auth.isTeknisi,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Hasil Diagnosa Kerusakan *',
              hintText: 'e.g. Panel OLED rusak benturan, IC charger normal',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _actionController,
            enabled: !isDone && auth.isTeknisi,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Tindakan Servis yang Dilakukan *',
              hintText: 'e.g. Ganti modul LCD assembly dan pasang segel baru',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _costController,
            enabled: !isDone && auth.isTeknisi,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'Biaya Final Servis (Rp)',
              prefixText: 'Rp ',
            ),
          ),
          const SizedBox(height: 24),

          // QC Checklist
          const Text(
            'Quality Control (QC) Checklist Pengujian HP',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 6),
          const Text(
            'Uji semua komponen sebelum menyerahkan HP ke pelanggan untuk kepuasan CSAT & pencegahan retur.',
            style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 12),

          Card(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              child: Column(
                children: _qcChecks.keys.map((key) {
                  return CheckboxListTile(
                    value: _qcChecks[key] ?? false,
                    onChanged: (isDone || !auth.isTeknisi)
                        ? null
                        : (val) => setState(() => _qcChecks[key] = val ?? false),
                    title: Text(_qcLabels[key] ?? key, style: const TextStyle(fontSize: 13)),
                    activeColor: AppTheme.primary,
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                  );
                }).toList(),
              ),
            ),
          ),
          const SizedBox(height: 20),

          if (!isDone && auth.isTeknisi) ...[
            const Text('Hasil Akhir Servis:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: _selectedResultStatus,
              decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 12)),
              items: [
                const DropdownMenuItem(
                  value: 'success',
                  child: Row(
                    children: [
                      Icon(Icons.check_circle_rounded, color: AppTheme.statusApproved, size: 18),
                      SizedBox(width: 8),
                      Text('Berhasil Diperbaiki (Sukses)', style: TextStyle(fontSize: 13)),
                    ],
                  ),
                ),
                const DropdownMenuItem(
                  value: 'unrepairable',
                  child: Row(
                    children: [
                      Icon(Icons.cancel_rounded, color: AppTheme.statusDanger, size: 18),
                      SizedBox(width: 8),
                      Text('Gagal / Tidak Dapat Diperbaiki', style: TextStyle(fontSize: 13)),
                    ],
                  ),
                ),
                const DropdownMenuItem(
                  value: 'warranty_return',
                  child: Row(
                    children: [
                      Icon(Icons.autorenew_rounded, color: AppTheme.statusRevision, size: 18),
                      SizedBox(width: 8),
                      Text('Retur Garansi', style: TextStyle(fontSize: 13)),
                    ],
                  ),
                ),
              ],
              onChanged: (val) => setState(() => _selectedResultStatus = val ?? 'success'),
            ),
            const SizedBox(height: 24),

            ElevatedButton.icon(
              icon: const Icon(Icons.check_circle_outline_rounded),
              label: _isSaving
                  ? const CircularProgressIndicator(color: Colors.white)
                  : const Text('Selesaikan Servis & Sinkronkan KPI'),
              onPressed: _isSaving ? null : _completeTicket,
            ),
            const SizedBox(height: 30),
          ],
        ],
      ),
    );
  }
}
