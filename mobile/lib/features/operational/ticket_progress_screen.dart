import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';
import '../../core/auth/auth_provider.dart';
import 'customer_pickup_screen.dart';

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
  final _evidenceController = TextEditingController();
  final _estimatedCostController = TextEditingController();
  final _finalCostController = TextEditingController();
  final _paidAmountController = TextEditingController();
  final _costNoteController = TextEditingController();
  final _unrepairableReasonController = TextEditingController();
  final _declinedReasonController = TextEditingController();
  String? _selectedResultStatus; // success, unrepairable, customer_declined
  String? _selectedProgressStatus;
  bool _isSaving = false;

  final Map<String, bool> _qcChecks = {
    'display': false,
    'touch': false,
    'camera': false,
    'mic': false,
    'speaker': false,
    'cellular': false,
    'charging': false,
    'biometric': false,
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
      final res = await ApiService.get(
        '/operational/tickets/${widget.ticketId}',
      );
      setState(() {
        _ticket = res['data'];
        _diagnosisController.text = _ticket?['diagnosis_notes'] ?? '';
        _actionController.text = _ticket?['action_notes'] ?? '';
        _estimatedCostController.text = (_ticket?['estimated_cost'] ?? 0)
            .toString();
        _finalCostController.text = (_ticket?['final_cost'] ?? 0).toString();
        _paidAmountController.text = (_ticket?['paid_amount'] ?? 0).toString();
        _evidenceController.text = '';
        _unrepairableReasonController.text =
            _ticket?['unrepairable_reason'] ?? '';
        _declinedReasonController.text =
            _ticket?['customer_declined_reason'] ?? '';
        if (_ticket?['result_status'] != null &&
            _ticket!['result_status'] != 'pending') {
          _selectedResultStatus = _ticket!['result_status'];
        }
        _selectedProgressStatus = _ticket?['status']?.toString();
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
          SnackBar(
            content: Text(e.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
        Navigator.pop(context);
      }
    }
  }

  Future<void> _recordDecision(
    String action,
    String field,
    String title,
    Map<String, String> choices,
  ) async {
    final selected = choices.length == 1
        ? choices.keys.first
        : await showOpsSelectionSheet<String>(
            context: context,
            title: title,
            selectedValue: choices.keys.first,
            options: choices.entries
                .map(
                  (choice) => OpsSelectionOption<String>(
                    value: choice.key,
                    label: choice.value,
                  ),
                )
                .toList(),
          );
    if (selected == null || !mounted) return;

    final reason = TextEditingController();
    String? noteError;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, setSheetState) => Padding(
          padding: EdgeInsets.fromLTRB(
            AppTheme.spaceLg,
            AppTheme.spaceLg,
            AppTheme.spaceLg,
            MediaQuery.viewInsetsOf(context).bottom + AppTheme.spaceLg,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(title, style: Theme.of(sheetContext).textTheme.titleLarge),
              const SizedBox(height: AppTheme.spaceXs),
              Text(
                choices[selected]!,
                style: Theme.of(sheetContext).textTheme.bodySmall,
              ),
              const SizedBox(height: AppTheme.spaceLg),
              TextField(
                controller: reason,
                autofocus: true,
                decoration: InputDecoration(
                  labelText: 'Alasan / catatan *',
                  errorText: noteError,
                ),
                onChanged: (_) {
                  if (noteError != null) {
                    setSheetState(() => noteError = null);
                  }
                },
                maxLines: 3,
              ),
              const SizedBox(height: AppTheme.spaceLg),
              FilledButton(
                onPressed: () {
                  if (reason.text.trim().length < 3) {
                    setSheetState(
                      () => noteError = 'Isi alasan minimal 3 karakter.',
                    );
                    return;
                  }
                  Navigator.pop(sheetContext, true);
                },
                child: const Text('Simpan'),
              ),
            ],
          ),
        ),
      ),
    );
    final note = reason.text.trim();
    reason.dispose();
    if (confirmed != true || !mounted) return;
    setState(() => _isSaving = true);
    try {
      final result = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/$action',
        {
          field: selected,
          'reason': note,
          'consent_notes': note,
          'row_version': _ticket?['row_version'],
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(result['message'] ?? 'Tersimpan.')),
      );
      await _loadTicket();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _assignTechnician() async {
    final auth = context.read<AuthProvider>();
    if (auth.isTechnician) {
      setState(() => _isSaving = true);
      try {
        final response = await ApiService.post(
          '/operational/tickets/${widget.ticketId}/assign',
          {'row_version': _ticket?['row_version']},
        );
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              response['message']?.toString() ?? 'Tiket berhasil diambil.',
            ),
            backgroundColor: AppTheme.primary,
          ),
        );
        await _loadTicket();
      } catch (exception) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(exception.toString()),
              backgroundColor: AppTheme.statusDanger,
            ),
          );
        }
      } finally {
        if (mounted) setState(() => _isSaving = false);
      }
      return;
    }

    List<dynamic> technicians;
    try {
      final response = await ApiService.get('/operational/technicians');
      technicians = List<dynamic>.from(response['data'] as List? ?? const []);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
      return;
    }
    if (!mounted || technicians.isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Belum ada Teknisi aktif pada cabang tiket.'),
          ),
        );
      }
      return;
    }

    final currentId =
        _ticket?['technician_employee_id']?.toString() ??
        technicians.first['id'].toString();
    final selectedId = await showOpsSelectionSheet<String>(
      context: context,
      title: _ticket?['technician_employee_id'] == null
          ? 'Pilih Teknisi'
          : 'Ubah Teknisi',
      selectedValue: currentId,
      searchable: technicians.length > 8,
      options: technicians.map((raw) {
        final technician = Map<String, dynamic>.from(raw as Map);
        return OpsSelectionOption<String>(
          value: technician['id'].toString(),
          label: technician['name']?.toString() ?? 'Teknisi',
          supportingText: technician['employee_code']?.toString(),
          icon: Icons.engineering_outlined,
        );
      }).toList(),
    );
    if (selectedId == null || !mounted) return;

    final reasonController = TextEditingController();
    String? reasonError;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, setSheetState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.viewInsetsOf(context).bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _ticket?['technician_employee_id'] == null
                    ? 'Tugaskan Teknisi'
                    : 'Ubah Penugasan Teknisi',
                style: Theme.of(sheetContext).textTheme.titleLarge,
              ),
              const SizedBox(height: 4),
              Text(
                technicians
                        .map((raw) => Map<String, dynamic>.from(raw as Map))
                        .where(
                          (technician) =>
                              technician['id'].toString() == selectedId,
                        )
                        .firstOrNull?['name']
                        ?.toString() ??
                    'Teknisi',
                style: Theme.of(sheetContext).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: reasonController,
                autofocus: true,
                maxLines: 2,
                decoration: InputDecoration(
                  labelText: 'Alasan penugasan / perubahan *',
                  errorText: reasonError,
                ),
                onChanged: (_) {
                  if (reasonError != null) {
                    setSheetState(() => reasonError = null);
                  }
                },
              ),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: () {
                  if (reasonController.text.trim().length < 3) {
                    setSheetState(
                      () => reasonError = 'Isi alasan minimal 3 karakter.',
                    );
                    return;
                  }
                  Navigator.pop(sheetContext, true);
                },
                child: const Text('Simpan penugasan'),
              ),
            ],
          ),
        ),
      ),
    );
    final reason = reasonController.text.trim();
    reasonController.dispose();
    if (confirmed != true || !mounted) return;
    if (reason.length < 3) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Isi alasan minimal 3 karakter.')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final response = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/assign',
        {
          'technician_employee_id': selectedId,
          'assignment_reason': reason,
          'row_version': _ticket?['row_version'],
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            response['message']?.toString() ?? 'Penugasan berhasil disimpan.',
          ),
          backgroundColor: AppTheme.primary,
        ),
      );
      await _loadTicket();
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _updateProgress(String status) async {
    setState(() => _isSaving = true);
    try {
      await ApiService.post(
        '/operational/tickets/${widget.ticketId}/update-progress',
        {
          'row_version': _ticket?['row_version'],
          'status': status,
          'diagnosis_notes': _diagnosisController.text.trim(),
          'action_notes': _actionController.text.trim(),
        },
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Progress pengerjaan berhasil disimpan!'),
            backgroundColor: AppTheme.primary,
          ),
        );
        _loadTicket();
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
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _saveEstimatedCost() async {
    final amount = double.tryParse(_estimatedCostController.text.trim());
    if (amount == null || amount < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Estimasi biaya harus berupa angka valid.'),
        ),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final res = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/estimated-cost',
        {
          'estimated_cost': amount,
          'note': _costNoteController.text.trim(),
          'row_version': _ticket?['row_version'],
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Estimasi biaya berhasil dicatat.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _saveFinalCost() async {
    final finalCost = double.tryParse(_finalCostController.text.trim());
    if (finalCost == null || finalCost < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Biaya final harus berupa angka valid.')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final res = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/final-cost',
        {
          'final_cost': finalCost,
          'note': _costNoteController.text.trim(),
          'row_version': _ticket?['row_version'],
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Biaya final berhasil dicatat.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      await _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _savePayment() async {
    final paidAmount = double.tryParse(_paidAmountController.text.trim());
    if (paidAmount == null || paidAmount < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pembayaran harus berupa angka valid.')),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final res = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/payment',
        {
          'paid_amount': paidAmount,
          'note': _costNoteController.text.trim(),
          'row_version': _ticket?['row_version'],
        },
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Pembayaran berhasil dicatat.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      await _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _completeTicket() async {
    if (_diagnosisController.text.trim().isEmpty ||
        _actionController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Diagnosa dan tindakan servis wajib diisi untuk kelengkapan laporan KPI!',
          ),
        ),
      );
      return;
    }
    if (_selectedResultStatus == 'success' &&
        _ticket?['status'] != 'qc_ready') {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Tandai tiket Siap QC sebelum menutup servis sukses.'),
        ),
      );
      return;
    }
    if (_selectedResultStatus == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih hasil akhir servis terlebih dahulu.'),
        ),
      );
      return;
    }
    if (['success', 'unrepairable'].contains(_selectedResultStatus) &&
        _evidenceController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Evidence teknis wajib diisi untuk hasil ini.'),
        ),
      );
      return;
    }
    if (_selectedResultStatus == 'unrepairable' &&
        _unrepairableReasonController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Alasan tidak dapat diperbaiki wajib diisi.'),
        ),
      );
      return;
    }
    if (_selectedResultStatus == 'customer_declined' &&
        _declinedReasonController.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Alasan customer menolak servis wajib diisi.'),
        ),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      final payload = <String, dynamic>{
        'row_version': _ticket?['row_version'],
        'result_status': _selectedResultStatus,
        'diagnosis_notes': _diagnosisController.text.trim(),
        'action_notes': _actionController.text.trim(),
        'qc_checklist': _qcChecks,
      };
      if (_evidenceController.text.trim().isNotEmpty) {
        payload['technical_evidence'] = [
          {
            'type': 'service_note',
            'reference': _evidenceController.text.trim(),
          },
        ];
      }
      if (_selectedResultStatus == 'unrepairable') {
        payload['unrepairable_reason'] = _unrepairableReasonController.text
            .trim();
      }
      if (_selectedResultStatus == 'customer_declined') {
        payload['customer_declined_reason'] = _declinedReasonController.text
            .trim();
      }
      final res = await ApiService.post(
        '/operational/tickets/${widget.ticketId}/complete',
        payload,
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              res['message'] ??
                  'Tiket berhasil diselesaikan dan dicatat ke KPI Teknisi!',
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
      if (mounted) setState(() => _isSaving = false);
    }
  }

  Future<void> _openSparepartRequest() async {
    List<dynamic> parts;
    try {
      final res = await ApiService.get('/operational/spareparts');
      parts = ((res['data'] as List<dynamic>?) ?? [])
          .where((part) => part['product_type'] == 'sparepart')
          .toList();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
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
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (sheetCtx) {
        return StatefulBuilder(
          builder: (sheetCtx, setSheetState) {
            return OpsFormSheet(
              eyebrow: 'Permintaan gudang',
              title: 'Request sparepart',
              subtitle:
                  'Masukkan kebutuhan berdasarkan informasi Teknisi dan sertakan catatan untuk Gudang.',
              footer: ElevatedButton.icon(
                onPressed: () => Navigator.pop(sheetCtx, true),
                icon: const Icon(Icons.send_rounded),
                label: const Text('Kirim request'),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  OpsSelectionField<String>(
                    label: 'Pilih sparepart',
                    hint: 'Pilih sparepart',
                    sheetTitle: 'Pilih sparepart',
                    searchable: true,
                    value: selectedId,
                    options: parts.map((p) {
                      final stock = (p['stock'] as num?)?.toInt() ?? 0;
                      return OpsSelectionOption<String>(
                        value: p['id'].toString(),
                        label: p['name']?.toString() ?? 'Sparepart',
                        supportingText: '${p['code'] ?? '-'} • Stok $stock pcs',
                        icon: Icons.build_rounded,
                      );
                    }).toList(),
                    onChanged: (value) =>
                        setSheetState(() => selectedId = value),
                  ),
                  const SizedBox(height: AppTheme.spaceMd),
                  TextField(
                    controller: qtyController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Jumlah',
                      suffixText: 'pcs',
                    ),
                  ),
                  const SizedBox(height: AppTheme.spaceMd),
                  TextField(
                    controller: notesController,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'Catatan (opsional)',
                      hintText: 'mis. butuh LCD untuk Galaxy A52',
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
    await _requestSparepart(
      selectedId!,
      qty < 1 ? 1 : qty,
      notesController.text.trim(),
    );
  }

  Future<void> _requestSparepart(
    String sparepartId,
    int quantity,
    String notes,
  ) async {
    try {
      final res = await ApiService.post('/operational/spareparts/request', {
        'row_version': _ticket?['row_version'],
        'service_ticket_id': widget.ticketId,
        'sparepart_id': sparepartId,
        'quantity': quantity,
        'notes': notes,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            res['message'] ?? 'Permintaan sparepart terkirim ke Gudang.',
          ),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  Widget _buildServiceField({
    required String label,
    required String hint,
    required IconData icon,
    required TextEditingController controller,
    required bool enabled,
    int maxLines = 1,
    TextInputType? keyboardType,
    String? prefixText,
  }) {
    final radius = BorderRadius.circular(AppTheme.radiusMd);
    final border = OutlineInputBorder(
      borderRadius: radius,
      borderSide: BorderSide(color: AppTheme.border),
    );

    return Semantics(
      textField: true,
      label: label,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: AppTheme.textMuted),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  label,
                  style: TextStyle(
                    color: AppTheme.textInk,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: controller,
            enabled: enabled,
            maxLines: maxLines,
            keyboardType: keyboardType,
            textCapitalization: maxLines > 1
                ? TextCapitalization.sentences
                : TextCapitalization.none,
            textInputAction: maxLines == 1
                ? TextInputAction.done
                : TextInputAction.newline,
            cursorColor: AppTheme.primaryBright,
            decoration: InputDecoration(
              hintText: hint,
              hintMaxLines: maxLines > 1 ? maxLines : 1,
              prefixText: prefixText,
              prefixStyle: TextStyle(
                color: AppTheme.textMuted,
                fontWeight: FontWeight.w600,
              ),
              fillColor: AppTheme.surfaceElevated,
              contentPadding: const EdgeInsets.symmetric(
                horizontal: 16,
                vertical: 14,
              ),
              border: border,
              enabledBorder: border,
              disabledBorder: border,
              focusedBorder: OutlineInputBorder(
                borderRadius: radius,
                borderSide: BorderSide(color: AppTheme.primaryBright, width: 2),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _fulfillSparepart(String requestId) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Serahkan Sparepart?'),
        content: const Text(
          'Sparepart akan ditandai diserahkan ke teknisi dan stok gudang terpotong.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
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
      final res = await ApiService.post(
        '/operational/spareparts/fulfill/$requestId',
        {'row_version': _ticket?['row_version']},
      );
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
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
      );
    }
  }

  Future<void> _confirmSparepart(String requestId) async {
    try {
      final res = await ApiService.post(
        '/operational/spareparts/confirm/$requestId',
        {'row_version': _ticket?['row_version']},
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Sparepart berhasil dikonfirmasi.'),
          backgroundColor: AppTheme.primary,
        ),
      );
      _loadTicket();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString()),
          backgroundColor: AppTheme.statusDanger,
        ),
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
      'cancelled': 'Dibatalkan',
    };
    return labels[status] ?? status;
  }

  @override
  void dispose() {
    _diagnosisController.dispose();
    _actionController.dispose();
    _evidenceController.dispose();
    _estimatedCostController.dispose();
    _finalCostController.dispose();
    _paidAmountController.dispose();
    _costNoteController.dispose();
    _unrepairableReasonController.dispose();
    _declinedReasonController.dispose();
    super.dispose();
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
    final actions = List<String>.from(ticket['available_actions'] ?? const []);
    final progressStatuses = List<String>.from(
      ticket['progress_statuses'] ?? const [],
    );
    final canManageTickets = actions.contains('progress');
    final canRequestSparepart = actions.contains('sparepart-request');
    final isDone =
        ticket['status'] == 'completed' || ticket['status'] == 'delivered';
    final canReviewTechnical =
        auth.hasCapability('tickets.progress') ||
        auth.hasCapability('tickets.supervise') ||
        auth.hasCapability('tickets.manage');
    final showTechnicalSection =
        canManageTickets || (isDone && canReviewTechnical);
    final showQcSection =
        actions.contains('complete') || (isDone && canReviewTechnical);
    final sparepartRequests =
        (ticket['sparepart_requests'] as List<dynamic>?) ?? [];

    return Scaffold(
      appBar: AppBar(
        title: Text(ticket['ticket_number'] ?? 'Pengerjaan Servis'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Device & Customer Card
          OpsReveal(
            child: OpsHeroCard(
              accent: _ticketStatusColor(ticket['status'].toString()),
              padding: const EdgeInsets.all(18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        "${ticket['device_brand']} ${ticket['device_model']}",
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 18,
                          color: AppTheme.textInk,
                        ),
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
                    style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    "Teknisi: ${ticket['technician_name'] ?? 'Belum Ditugaskan'}",
                    style: TextStyle(
                      color: ticket['technician_employee_id'] == null
                          ? AppTheme.statusRevision
                          : AppTheme.textMuted,
                      fontSize: 13,
                      fontWeight: ticket['technician_employee_id'] == null
                          ? FontWeight.w600
                          : FontWeight.normal,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Divider(),
                  const SizedBox(height: 8),
                  Text(
                    'Keluhan Kerusakan:',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: AppTheme.textMuted,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    ticket['initial_complaint'] ?? '-',
                    style: TextStyle(fontSize: 14, color: AppTheme.textInk),
                  ),
                  if (ticket['physical_condition'] != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Kondisi Fisik:',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 12,
                        color: AppTheme.textMuted,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      ticket['physical_condition'],
                      style: TextStyle(fontSize: 13, color: AppTheme.textMuted),
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),

          if (actions.contains('assign')) ...[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                icon: Icon(
                  auth.isTechnician
                      ? Icons.handyman_rounded
                      : Icons.person_add_alt_1_rounded,
                ),
                label: Text(
                  auth.isTechnician
                      ? 'Ambil tiket ini'
                      : ticket['technician_employee_id'] == null
                      ? 'Tugaskan Teknisi'
                      : 'Ubah Penugasan Teknisi',
                ),
                onPressed: _isSaving ? null : _assignTechnician,
              ),
            ),
            const SizedBox(height: 20),
          ],

          if (actions.contains('estimated-cost') ||
              actions.contains('final-cost') ||
              actions.contains('payment')) ...[
            OpsCard(
              margin: EdgeInsets.zero,
              padding: const EdgeInsets.all(AppTheme.spaceLg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Biaya & Pembayaran',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Diisi oleh Kasir. Pelayan hanya mencatat penerimaan dan status tiket.',
                    style: TextStyle(fontSize: 12, color: AppTheme.textMuted),
                  ),
                  const SizedBox(height: 16),
                  if (actions.contains('estimated-cost')) ...[
                    _buildServiceField(
                      controller: _estimatedCostController,
                      enabled: !_isSaving,
                      icon: Icons.receipt_long_outlined,
                      label: 'Estimasi biaya (Rp) *',
                      hint: 'Contoh: 350000',
                      keyboardType: TextInputType.number,
                      prefixText: 'Rp ',
                    ),
                    const SizedBox(height: 16),
                  ],
                  if (actions.contains('final-cost')) ...[
                    _buildServiceField(
                      controller: _finalCostController,
                      enabled: !_isSaving,
                      icon: Icons.payments_outlined,
                      label: 'Biaya final (Rp) *',
                      hint: 'Contoh: 350000',
                      keyboardType: TextInputType.number,
                      prefixText: 'Rp ',
                    ),
                  ],
                  if (actions.contains('payment')) ...[
                    const SizedBox(height: 16),
                    _buildServiceField(
                      controller: _paidAmountController,
                      enabled: !_isSaving,
                      icon: Icons.point_of_sale_outlined,
                      label: 'Pembayaran aktual (Rp) *',
                      hint: 'Nominal yang diterima',
                      keyboardType: TextInputType.number,
                      prefixText: 'Rp ',
                    ),
                    const SizedBox(height: 16),
                  ],
                  _buildServiceField(
                    controller: _costNoteController,
                    enabled: !_isSaving,
                    icon: Icons.notes_outlined,
                    label: 'Catatan biaya (opsional)',
                    hint: 'Wajib saat mengubah nominal yang sudah tercatat.',
                    maxLines: 2,
                  ),
                  const SizedBox(height: 16),
                  if (actions.contains('estimated-cost'))
                    ElevatedButton.icon(
                      icon: const Icon(Icons.save_outlined, size: 18),
                      label: const Text('Simpan estimasi biaya'),
                      onPressed: _isSaving ? null : _saveEstimatedCost,
                    ),
                  if (actions.contains('final-cost')) ...[
                    if (actions.contains('estimated-cost'))
                      const SizedBox(height: AppTheme.spaceSm),
                    ElevatedButton.icon(
                      icon: const Icon(Icons.save_outlined, size: 18),
                      label: const Text('Simpan biaya final'),
                      onPressed: _isSaving ? null : _saveFinalCost,
                    ),
                  ],
                  if (actions.contains('payment')) ...[
                    if (actions.contains('estimated-cost') ||
                        actions.contains('final-cost'))
                      const SizedBox(height: AppTheme.spaceSm),
                    OutlinedButton.icon(
                      icon: const Icon(Icons.point_of_sale_outlined, size: 18),
                      label: const Text('Catat pembayaran'),
                      onPressed: _isSaving ? null : _savePayment,
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 20),
          ],

          if (actions.contains('deliver')) ...[
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                icon: const Icon(Icons.handshake_rounded),
                label: const Text('Serahkan Unit'),
                onPressed: () async {
                  final delivered = await Navigator.of(context).push<bool>(
                    MaterialPageRoute(
                      builder: (_) => CustomerPickupScreen(ticket: ticket),
                    ),
                  );
                  if (delivered == true && context.mounted) {
                    Navigator.pop(context, true);
                  }
                },
              ),
            ),
            const SizedBox(height: 20),
          ],

          if (actions.contains('consent')) ...[
            Text(
              'Persetujuan pelanggan: ${ticket['customer_consent_status'] ?? 'pending'}',
            ),
            OutlinedButton.icon(
              onPressed: _isSaving
                  ? null
                  : () => _recordDecision(
                      'consent',
                      'consent_status',
                      'Persetujuan pelanggan',
                      const {'approved': 'Setuju', 'declined': 'Menolak'},
                    ),
              icon: const Icon(Icons.verified_outlined),
              label: const Text('Catat persetujuan pelanggan'),
            ),
            const SizedBox(height: 16),
          ],
          if (actions.contains('payment-exception')) ...[
            OutlinedButton(
              onPressed: _isSaving
                  ? null
                  : () => _recordDecision(
                      'payment-exception',
                      'exception_type',
                      'Pengecualian pembayaran',
                      const {
                        'installment': 'Cicilan',
                        'receivable': 'Piutang',
                        'waiver': 'Pembebasan',
                      },
                    ),
              child: const Text('Sahkan pengecualian pembayaran'),
            ),
          ],
          if (actions.contains('warranty-review')) ...[
            OutlinedButton(
              onPressed: _isSaving
                  ? null
                  : () => _recordDecision(
                      'warranty-review',
                      'decision',
                      'Validasi retur garansi',
                      const {'approved': 'Setujui', 'rejected': 'Tolak'},
                    ),
              child: const Text('Validasi retur garansi'),
            ),
          ],
          if (actions.contains('cancel')) ...[
            TextButton(
              onPressed: _isSaving
                  ? null
                  : () => _recordDecision(
                      'cancel',
                      'decision',
                      'Batalkan tiket sebelum diagnosis',
                      const {'cancel': 'Batalkan tiket'},
                    ),
              child: const Text('Batalkan tiket'),
            ),
          ],
          if (canManageTickets) ...[
            OpsSelectionField<String>(
              label: 'Tahap pengerjaan',
              sheetTitle: 'Pilih tahap pengerjaan',
              value: _selectedProgressStatus,
              options: progressStatuses
                  .map(
                    (status) => OpsSelectionOption<String>(
                      value: status,
                      label: _ticketStatusLabel(status),
                      supportingText: status == ticket['status']
                          ? 'Tahap saat ini'
                          : 'Pindahkan tiket ke tahap ini',
                      icon: _ticketStatusIcon(status),
                      color: _ticketStatusColor(status),
                    ),
                  )
                  .toList(),
              onChanged: (status) =>
                  setState(() => _selectedProgressStatus = status),
            ),
            const SizedBox(height: AppTheme.spaceSm),
            ElevatedButton.icon(
              icon: const Icon(Icons.save_outlined, size: 18),
              label: const Text('Simpan progres'),
              onPressed: _isSaving || _selectedProgressStatus == null
                  ? null
                  : () => _updateProgress(_selectedProgressStatus!),
            ),
            const SizedBox(height: 20),
          ],

          // Permintaan Teknisi dan pemenuhan Gudang
          if (!isDone && canRequestSparepart) ...[
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
            Text(
              'Permintaan Sparepart',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppTheme.textInk,
              ),
            ),
            const SizedBox(height: 8),
            OpsCard(
              padding: EdgeInsets.zero,
              child: Column(
                children: sparepartRequests.map((r) {
                  final isPending = r['status'] == 'pending';
                  return ListTile(
                    dense: true,
                    leading: Icon(
                      Icons.inventory_2_outlined,
                      color: isPending
                          ? AppTheme.statusRevision
                          : AppTheme.primary,
                    ),
                    title: Text('${r['part_name']} × ${r['quantity']}'),
                    subtitle: Text(r['part_code'] ?? ''),
                    trailing: isPending
                        ? (actions.contains('sparepart-fulfill')
                              ? FilledButton(
                                  onPressed: () =>
                                      _fulfillSparepart(r['id'].toString()),
                                  style: FilledButton.styleFrom(
                                    minimumSize: const Size(0, 34),
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 14,
                                    ),
                                  ),
                                  child: const Text('Serahkan'),
                                )
                              : Text(
                                  'Menunggu Gudang',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: AppTheme.statusRevision,
                                  ),
                                ))
                        : r['status'] == 'fulfilled' &&
                              r['confirmed_at'] == null &&
                              actions.contains('sparepart-confirm')
                        ? FilledButton(
                            onPressed: () =>
                                _confirmSparepart(r['id'].toString()),
                            style: FilledButton.styleFrom(
                              minimumSize: const Size(0, 34),
                              padding: const EdgeInsets.symmetric(
                                horizontal: 12,
                              ),
                            ),
                            child: const Text('Konfirmasi'),
                          )
                        : Text(
                            r['confirmed_at'] != null
                                ? 'Terkonfirmasi'
                                : (r['status'] == 'unavailable'
                                      ? 'Tidak tersedia'
                                      : 'Diserahkan'),
                            style: TextStyle(
                              fontSize: 12,
                              color: r['status'] == 'unavailable'
                                  ? AppTheme.statusDanger
                                  : AppTheme.primary,
                            ),
                          ),
                  );
                }).toList(),
              ),
            ),
          ],

          if (showTechnicalSection) ...[
            // Diagnosis & Actions
            OpsCard(
              margin: EdgeInsets.zero,
              padding: const EdgeInsets.all(AppTheme.spaceLg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        width: 36,
                        height: 36,
                        decoration: BoxDecoration(
                          color: AppTheme.primaryBright.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(11),
                        ),
                        child: Icon(
                          Icons.description_outlined,
                          color: AppTheme.primaryBright,
                          size: 20,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Catatan Diagnosa & Tindakan Teknisi',
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Lengkapi catatan sebelum servis ditutup.',
                              style: TextStyle(
                                fontSize: 12,
                                color: AppTheme.textMuted,
                                height: 1.35,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 18),
                  _buildServiceField(
                    controller: _diagnosisController,
                    enabled: !isDone && canManageTickets,
                    icon: Icons.search_rounded,
                    label: 'Hasil Diagnosa Kerusakan *',
                    hint:
                        'Contoh: panel OLED rusak akibat benturan, IC charger normal',
                    maxLines: 2,
                  ),
                  const SizedBox(height: 16),
                  _buildServiceField(
                    controller: _actionController,
                    enabled: !isDone && canManageTickets,
                    icon: Icons.build_circle_outlined,
                    label: 'Tindakan Servis yang Dilakukan *',
                    hint:
                        'Contoh: ganti modul LCD assembly dan pasang segel baru',
                    maxLines: 2,
                  ),
                  if (!isDone) ...[
                    const SizedBox(height: 16),
                    _buildServiceField(
                      controller: _evidenceController,
                      enabled: canManageTickets,
                      icon: Icons.attach_file_rounded,
                      label:
                          'Evidence teknis * untuk sukses / tidak dapat diperbaiki',
                      hint:
                          'Contoh: foto hasil pengujian atau nomor dokumen teknis',
                      maxLines: 2,
                    ),
                  ],
                  if (_selectedResultStatus == 'unrepairable') ...[
                    const SizedBox(height: 16),
                    _buildServiceField(
                      controller: _unrepairableReasonController,
                      enabled: !isDone && canManageTickets,
                      icon: Icons.warning_amber_rounded,
                      label: 'Alasan tidak dapat diperbaiki *',
                      hint: 'Jelaskan hasil diagnosis dan alasan teknisnya',
                      maxLines: 2,
                    ),
                  ],
                  if (_selectedResultStatus == 'customer_declined') ...[
                    const SizedBox(height: 16),
                    _buildServiceField(
                      controller: _declinedReasonController,
                      enabled: !isDone && canManageTickets,
                      icon: Icons.person_off_outlined,
                      label: 'Alasan customer menolak servis *',
                      hint: 'Catat keputusan customer dan alasannya',
                      maxLines: 2,
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 24),
          ],

          if (showQcSection) ...[
            // QC Checklist
            Text(
              'Quality Control (QC)',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 6),
            Text(
              'Periksa semua fungsi sebelum unit diselesaikan.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),

            OpsCard(
              padding: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 8,
                ),
                child: Column(
                  children: _qcChecks.keys.map((key) {
                    return CheckboxListTile(
                      value: _qcChecks[key] ?? false,
                      onChanged: (isDone || !canManageTickets)
                          ? null
                          : (val) =>
                                setState(() => _qcChecks[key] = val ?? false),
                      title: Text(
                        _qcLabels[key] ?? key,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppTheme.textInk,
                        ),
                      ),
                      activeColor: AppTheme.primary,
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                    );
                  }).toList(),
                ),
              ),
            ),
            const SizedBox(height: 20),

            if (actions.contains('complete')) ...[
              Text(
                'Hasil akhir servis',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              OpsSelectionField<String>(
                label: 'Hasil servis',
                hint: 'Pilih hasil akhir servis',
                sheetTitle: 'Pilih hasil akhir servis',
                value: _selectedResultStatus,
                options: [
                  OpsSelectionOption<String>(
                    value: 'success',
                    label: 'Berhasil diperbaiki',
                    supportingText: 'Unit dapat digunakan kembali',
                    icon: Icons.check_circle_rounded,
                    color: AppTheme.statusApproved,
                  ),
                  OpsSelectionOption<String>(
                    value: 'unrepairable',
                    label: 'Tidak dapat diperbaiki',
                    supportingText: 'Servis tidak dapat dilanjutkan',
                    icon: Icons.cancel_rounded,
                    color: AppTheme.statusDanger,
                  ),
                  OpsSelectionOption<String>(
                    value: 'customer_declined',
                    label: 'Customer menolak servis',
                    supportingText:
                        'Keputusan customer dicatat sebagai hasil tersendiri',
                    icon: Icons.person_off_outlined,
                    color: AppTheme.statusRevision,
                  ),
                ],
                onChanged: (value) =>
                    setState(() => _selectedResultStatus = value),
              ),
              const SizedBox(height: 24),

              ElevatedButton.icon(
                icon: const Icon(Icons.check_circle_outline_rounded),
                label: _isSaving
                    ? const CircularProgressIndicator(color: Colors.white)
                    : const Text('Selesaikan servis'),
                onPressed: _isSaving ? null : _completeTicket,
              ),
              const SizedBox(height: 30),
            ],
          ],
        ],
      ),
    );
  }
}
