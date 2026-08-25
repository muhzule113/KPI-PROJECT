import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';

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

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final ticket = _ticket!;
    final isDone = ticket['status'] == 'completed' || ticket['status'] == 'delivered';

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
              color: Colors.white,
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
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppTheme.primary.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        ticket['status'].toString().toUpperCase(),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11, color: AppTheme.primary),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  "Pelanggan: ${ticket['customer_name']} • ${ticket['customer_phone']}",
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
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
          const SizedBox(height: 20),

          // Action Status Buttons
          if (!isDone) ...[
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

          // Diagnosis & Actions
          const Text(
            'Catatan Diagnosa & Tindakan Teknisi',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _diagnosisController,
            enabled: !isDone,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Hasil Diagnosa Kerusakan *',
              hintText: 'e.g. Panel OLED rusak benturan, IC charger normal',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _actionController,
            enabled: !isDone,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Tindakan Servis yang Dilakukan *',
              hintText: 'e.g. Ganti modul LCD assembly dan pasang segel baru',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _costController,
            enabled: !isDone,
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
                    onChanged: isDone
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

          if (!isDone) ...[
            const Text('Hasil Akhir Servis:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: _selectedResultStatus,
              decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 12)),
              items: const [
                DropdownMenuItem(value: 'success', child: Text('✅ Berhasil Diperbaiki (Sukses)')),
                DropdownMenuItem(value: 'unrepairable', child: Text('❌ Gagal / Tidak Dapat Diperbaiki')),
                DropdownMenuItem(value: 'warranty_return', child: Text('⚠️ Retur Garansi')),
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
