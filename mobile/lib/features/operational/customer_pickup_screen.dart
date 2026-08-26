import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../core/api/api_service.dart';

class CustomerPickupScreen extends StatefulWidget {
  final Map<String, dynamic> ticket;

  const CustomerPickupScreen({super.key, required this.ticket});

  @override
  State<CustomerPickupScreen> createState() => _CustomerPickupScreenState();
}

class _CustomerPickupScreenState extends State<CustomerPickupScreen> {
  int _rating = 5;
  final _commentsController = TextEditingController();
  bool _isSubmitting = false;

  Future<void> _submitPickup() async {
    setState(() => _isSubmitting = true);

    try {
      final res = await ApiService.post('/operational/tickets/${widget.ticket['id']}/feedback', {
        'rating': _rating,
        'comments': _commentsController.text.trim(),
        'feedback_channel': 'in_store',
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Unit berhasil diserahkan dan CSAT tercatat!'),
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
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ticket = widget.ticket;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Serah Terima & CSAT Pelanggan'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
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
                  ticket['ticket_number'] ?? '',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.primary),
                ),
                const SizedBox(height: 4),
                Text(
                  "${ticket['device_brand']} ${ticket['device_model']}",
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
                ),
                const SizedBox(height: 6),
                Text(
                  "Pelanggan: ${ticket['customer_name']} (${ticket['customer_phone']})",
                  style: const TextStyle(color: AppTheme.textMuted),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          const Text(
            'Tingkat Kepuasan Pelanggan (CSAT)',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AppTheme.textInk),
          ),
          const SizedBox(height: 6),
          const Text(
            'Tanyakan kepuasan pelanggan terhadap hasil servis dan pelayanan CS.',
            style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 16),

          // Rating Stars selector
          Center(
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(5, (index) {
                final starValue = index + 1;
                return IconButton(
                  iconSize: 40,
                  tooltip: 'Berikan $starValue bintang',
                  icon: Icon(
                    starValue <= _rating ? Icons.star_rounded : Icons.star_outline_rounded,
                    color: Colors.amber[700],
                  ),
                  onPressed: () => setState(() => _rating = starValue),
                );
              }),
            ),
          ),
          Center(
            child: Text(
              _ratingLabel(_rating),
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: Colors.amber[800],
              ),
            ),
          ),
          const SizedBox(height: 20),

          TextField(
            controller: _commentsController,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Ulasan / Testimoni Pelanggan (Opsional)',
              hintText: 'e.g. Layanan ramah, HP cepat selesai dan normal kembali...',
            ),
          ),
          const SizedBox(height: 28),

          ElevatedButton.icon(
            icon: const Icon(Icons.check_circle_rounded),
            label: _isSubmitting
                ? const CircularProgressIndicator(color: Colors.white)
                : const Text('Konfirmasi Penyerahan & Catat CSAT'),
            onPressed: _isSubmitting ? null : _submitPickup,
          ),
        ],
      ),
    );
  }

  String _ratingLabel(int r) {
    switch (r) {
      case 5: return 'Sangat Puas (5/5)';
      case 4: return 'Puas (4/5)';
      case 3: return 'Cukup (3/5)';
      case 2: return 'Kurang Puas (2/5)';
      default: return 'Kecewa / Komplain (1/5)';
    }
  }
}
