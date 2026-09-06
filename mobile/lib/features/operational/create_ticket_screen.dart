import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class CreateTicketScreen extends StatefulWidget {
  const CreateTicketScreen({super.key});

  @override
  State<CreateTicketScreen> createState() => _CreateTicketScreenState();
}

class _CreateTicketScreenState extends State<CreateTicketScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _brandController = TextEditingController();
  final _modelController = TextEditingController();
  final _imeiController = TextEditingController();
  final _passcodeController = TextEditingController();
  final _complaintController = TextEditingController();
  final _conditionController = TextEditingController();
  String _serviceComplexity = 'light';

  bool _isSubmitting = false;

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _brandController.dispose();
    _modelController.dispose();
    _imeiController.dispose();
    _passcodeController.dispose();
    _complaintController.dispose();
    _conditionController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);

    try {
      final res = await ApiService.post('/operational/tickets', {
        'customer_name': _nameController.text.trim(),
        'customer_phone': _phoneController.text.trim(),
        'device_brand': _brandController.text.trim(),
        'device_model': _modelController.text.trim(),
        'imei_or_serial': _imeiController.text.trim(),
        'passcode_or_pattern': _passcodeController.text.trim(),
        'initial_complaint': _complaintController.text.trim(),
        'physical_condition': _conditionController.text.trim(),
        'service_complexity': _serviceComplexity,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Tiket servis berhasil dibuat!'),
            backgroundColor: AppTheme.primary,
          ),
        );
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(e.toString().replaceAll('Exception: ', '')),
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
    return Scaffold(
      appBar: AppBar(title: const Text('Tiket Servis Baru')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'Informasi Pelanggan',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _nameController,
              decoration: const InputDecoration(
                labelText: 'Nama Pelanggan *',
                hintText: 'e.g. Budi Santoso',
                prefixIcon: Icon(Icons.person_outline_rounded),
              ),
              validator: (v) => v == null || v.trim().isEmpty
                  ? 'Nama pelanggan wajib diisi'
                  : null,
            ),
            const SizedBox(height: 12),
            OpsSelectionField<String>(
              label: 'Kompleksitas servis',
              sheetTitle: 'Pilih kompleksitas servis',
              value: _serviceComplexity,
              options: const [
                OpsSelectionOption(
                  value: 'light',
                  label: 'Ringan',
                  supportingText: 'SLA 1 hari kerja',
                  icon: Icons.speed_rounded,
                ),
                OpsSelectionOption(
                  value: 'medium',
                  label: 'Sedang',
                  supportingText: 'SLA 3 hari kerja',
                  icon: Icons.build_outlined,
                ),
                OpsSelectionOption(
                  value: 'heavy',
                  label: 'Berat',
                  supportingText: 'SLA 7 hari kerja',
                  icon: Icons.precision_manufacturing_outlined,
                ),
              ],
              onChanged: (value) => setState(() => _serviceComplexity = value),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phoneController,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'No. WhatsApp / HP *',
                hintText: 'e.g. 081234567890',
                prefixIcon: Icon(Icons.phone_outlined),
              ),
              validator: (v) => v == null || v.trim().isEmpty
                  ? 'Nomor HP/WA wajib diisi'
                  : null,
            ),
            const SizedBox(height: 24),

            Text(
              'Informasi Perangkat HP',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            OpsAdaptiveFieldRow(
              children: [
                TextFormField(
                  controller: _brandController,
                  decoration: const InputDecoration(
                    labelText: 'Merek HP *',
                    hintText: 'e.g. Apple / Samsung',
                  ),
                  validator: (v) =>
                      v == null || v.trim().isEmpty ? 'Wajib diisi' : null,
                ),
                TextFormField(
                  controller: _modelController,
                  decoration: const InputDecoration(
                    labelText: 'Tipe / Model *',
                    hintText: 'e.g. iPhone 13 Pro',
                  ),
                  validator: (v) =>
                      v == null || v.trim().isEmpty ? 'Wajib diisi' : null,
                ),
              ],
            ),
            const SizedBox(height: 12),
            OpsAdaptiveFieldRow(
              children: [
                TextFormField(
                  controller: _imeiController,
                  decoration: const InputDecoration(
                    labelText: 'IMEI / Serial (Opsional)',
                    hintText: 'e.g. 3567890...',
                  ),
                ),
                TextFormField(
                  controller: _passcodeController,
                  decoration: const InputDecoration(
                    labelText: 'Pola / PIN Layar',
                    hintText: 'Jika diizinkan',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _conditionController,
              decoration: const InputDecoration(
                labelText: 'Kondisi Fisik Luar',
                hintText: 'e.g. Lecet pemakaian di sudut bawah, backdoor mulus',
              ),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _complaintController,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Keluhan Kerusakan Awal *',
                hintText:
                    'Jelaskan gejala kerusakan (e.g. Layar mati total setelah jatuh, tidak respon saat dicas)...',
              ),
              validator: (v) => v == null || v.trim().isEmpty
                  ? 'Keluhan kerusakan wajib diisi'
                  : null,
            ),
            const SizedBox(height: 28),

            OpsReveal(
              delay: const Duration(milliseconds: 80),
              child: ElevatedButton.icon(
                icon: const Icon(Icons.arrow_forward_rounded),
                onPressed: _isSubmitting ? null : _submit,
                label: _isSubmitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Text('Buat tiket'),
              ),
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}
