import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class CustomerPickupScreen extends StatefulWidget {
  final Map<String, dynamic> ticket;

  const CustomerPickupScreen({super.key, required this.ticket});

  @override
  State<CustomerPickupScreen> createState() => _CustomerPickupScreenState();
}

class _CustomerPickupScreenState extends State<CustomerPickupScreen> {
  String _recipientType = 'customer';
  final _recipientNameController = TextEditingController();
  final _notesController = TextEditingController();
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    _recipientNameController.text =
        widget.ticket['customer_name']?.toString() ?? '';
  }

  @override
  void dispose() {
    _recipientNameController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _submitPickup() async {
    if (_recipientNameController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Nama penerima wajib diisi.')),
      );
      return;
    }
    setState(() => _isSubmitting = true);

    try {
      final res = await ApiService.post(
        '/operational/tickets/${widget.ticket['id']}/deliver',
        {
          'row_version': widget.ticket['row_version'],
          'delivery_notes': _notesController.text.trim(),
          'recipient_type': _recipientType,
          'recipient_name': _recipientNameController.text.trim(),
        },
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              res['message'] ??
                  'Unit berhasil diserahkan. Kirim QR feedback ke customer.',
            ),
            backgroundColor: AppTheme.primary,
          ),
        );
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ticket = widget.ticket;

    return Scaffold(
      appBar: AppBar(title: const Text('Serah Terima Unit')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          OpsReveal(
            child: OpsHeroCard(
              accent: AppTheme.statusApproved,
              padding: const EdgeInsets.all(18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    ticket['ticket_number'] ?? '',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 13,
                      color: AppTheme.primary,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    "${ticket['device_brand']} ${ticket['device_model']}",
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 18,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    "Pelanggan: ${ticket['customer_name']} (${ticket['customer_phone']})",
                    style: TextStyle(color: AppTheme.textMuted),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 24),

          Text(
            'Bukti Serah-Terima',
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: AppTheme.textInk,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Catat siapa yang menerima unit. Feedback customer dikirim melalui QR atau link terpisah.',
            style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 16),

          OpsSelectionField<String>(
            label: 'Jenis penerima',
            sheetTitle: 'Pilih jenis penerima',
            value: _recipientType,
            options: const [
              OpsSelectionOption(
                value: 'customer',
                label: 'Customer',
                icon: Icons.person_outline_rounded,
              ),
              OpsSelectionOption(
                value: 'representative',
                label: 'Wakil customer',
                icon: Icons.people_outline_rounded,
              ),
            ],
            onChanged: (value) => setState(() => _recipientType = value),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _recipientNameController,
            decoration: const InputDecoration(
              labelText: 'Nama penerima *',
              prefixIcon: Icon(Icons.person_outline_rounded),
            ),
          ),
          const SizedBox(height: 28),
          TextField(
            controller: _notesController,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Catatan penyerahan',
              helperText:
                  'Wajib untuk penyerahan oleh Supervisor atau Manager.',
            ),
          ),
          const SizedBox(height: 20),

          ElevatedButton.icon(
            icon: const Icon(Icons.check_circle_rounded),
            label: _isSubmitting
                ? const CircularProgressIndicator(color: Colors.white)
                : const Text('Konfirmasi Penyerahan Unit'),
            onPressed: _isSubmitting ? null : _submitPickup,
          ),
        ],
      ),
    );
  }
}
