import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/widgets/kpi_ui.dart';
import '../../core/api/api_service.dart';

class ResourceScreen extends StatefulWidget {
  final String resource;
  final String? title;

  const ResourceScreen({super.key, required this.resource, this.title});

  @override
  State<ResourceScreen> createState() => _ResourceScreenState();
}

class _ResourceScreenState extends State<ResourceScreen> {
  bool _loading = true;
  bool _requestInFlight = false;
  String? _error;
  Map<String, dynamic> _config = {};
  List<dynamic> _records = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_requestInFlight) return;
    _requestInFlight = true;
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final response = await ApiService.get('/resources/${widget.resource}');
      final data = Map<String, dynamic>.from(response['data'] as Map);
      if (!mounted) return;
      setState(() {
        _config = Map<String, dynamic>.from(data['resource'] as Map);
        _records = List<dynamic>.from(data['records'] as List? ?? const []);
        _loading = false;
      });
    } catch (exception) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = _message(exception);
        });
      }
    } finally {
      _requestInFlight = false;
    }
  }

  Future<void> _create() async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => ResourceEditorScreen(
          resource: widget.resource,
          config: _config,
          form: const {'mode': 'create', 'values': <String, dynamic>{}},
        ),
      ),
    );
    if (changed == true) _load();
  }

  Future<void> _edit(String id) async {
    try {
      final response = await ApiService.get(
        '/resources/${widget.resource}/$id',
      );
      final data = Map<String, dynamic>.from(response['data'] as Map);
      if (!mounted) return;
      final changed = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => ResourceEditorScreen(
            resource: widget.resource,
            config: Map<String, dynamic>.from(data['resource'] as Map),
            form: Map<String, dynamic>.from(data['form'] as Map),
            detail: data['detail'] is Map
                ? Map<String, dynamic>.from(data['detail'] as Map)
                : null,
          ),
        ),
      );
      if (changed == true) _load();
    } catch (exception) {
      if (mounted) _showError(_message(exception));
    }
  }

  Future<void> _runAction(
    Map<String, dynamic> record,
    Map<String, dynamic> action,
  ) async {
    final id = record['id'];
    if (id == null) return;
    Map<String, dynamic>? body;
    final prompt = action['prompt'];
    if (prompt is Map) {
      final controller = TextEditingController();
      body = await showDialog<Map<String, dynamic>>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(action['label']?.toString() ?? 'Input'),
          content: TextField(
            controller: controller,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: prompt['label']?.toString()),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () {
                final value = int.tryParse(controller.text.trim());
                if (value != null && value > 0) {
                  Navigator.pop(dialogContext, {
                    prompt['name'].toString(): value,
                  });
                }
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      );
      controller.dispose();
      if (body == null) return;
    } else if (action['confirm'] != null) {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(action['label']?.toString() ?? 'Konfirmasi'),
          content: Text(action['confirm'].toString()),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext, false),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, true),
              child: const Text('Lanjutkan'),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
    }

    try {
      final recordId = record['id']?.toString() ?? '';
      final response = await ApiService.post(
        recordId.isEmpty
            ? '/resources/${widget.resource}/actions/${action['key']}'
            : '/resources/${widget.resource}/$id/actions/${action['key']}',
        body,
      );
      if (mounted) {
        _showMessage(
          response['message']?.toString() ?? 'Aksi berhasil dijalankan.',
        );
        _load();
      }
    } catch (exception) {
      if (mounted) _showError(_message(exception));
    }
  }

  void _showMessage(String message) =>
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: AppTheme.primary),
      );

  void _showError(String message) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(content: Text(message), backgroundColor: AppTheme.statusDanger),
  );

  @override
  Widget build(BuildContext context) {
    final title =
        widget.title ?? _config['plural_label']?.toString() ?? widget.resource;
    if (_loading) {
      return Scaffold(
        appBar: AppBar(title: Text(title)),
        body: const OpsScreenLoading(rows: 5),
      );
    }
    if (_error != null) {
      return Scaffold(
        appBar: AppBar(title: Text(title)),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(_error!, textAlign: TextAlign.center),
                const SizedBox(height: 12),
                ElevatedButton(
                  onPressed: _load,
                  child: const Text('Muat ulang'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final headerActions = List<dynamic>.from(
      _config['header_actions'] as List? ?? const [],
    );
    final canCreate = _config['can_create'] == true;
    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          ...headerActions.map((raw) {
            final action = Map<String, dynamic>.from(raw as Map);
            return IconButton(
              tooltip: action['label']?.toString(),
              icon: const Icon(Icons.sync_rounded),
              onPressed: () => _runAction(<String, dynamic>{'id': ''}, action),
            );
          }),
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh_rounded)),
        ],
      ),
      floatingActionButton: canCreate
          ? FloatingActionButton.extended(
              onPressed: _create,
              icon: const Icon(Icons.add_rounded),
              label: const Text('Tambah'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _load,
        child: _records.isEmpty
            ? ListView(
                children: [
                  const SizedBox(height: 150),
                  KpiEmptyState(
                    icon: Icons.inventory_2_outlined,
                    title: 'Belum ada data',
                    message: _emptyMessage(widget.resource),
                  ),
                ],
              )
            : ListView.builder(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 110),
                itemCount: _records.length,
                itemBuilder: (_, index) => _recordCard(_records[index]),
              ),
      ),
    );
  }

  Widget _recordCard(dynamic raw) {
    final record = Map<String, dynamic>.from(raw as Map);
    final values = Map<String, dynamic>.from(
      record['values'] as Map? ?? const {},
    );
    final actions = List<dynamic>.from(record['actions'] as List? ?? const []);
    final entries = values.entries.take(5).toList();
    return OpsCard(
      padding: EdgeInsets.zero,
      onTap: record['can_edit'] == true
          ? () => _edit(record['id'].toString())
          : null,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ...entries.asMap().entries.map((entry) {
              final value = entry.value.value;
              final label = value is Map
                  ? value['label']?.toString()
                  : value?.toString();
              return Padding(
                padding: EdgeInsets.only(
                  bottom: entry.key == entries.length - 1 ? 0 : 8,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (entry.key == 0)
                      const Icon(
                        Icons.description_outlined,
                        size: 18,
                        color: AppTheme.primary,
                      ),
                    if (entry.key == 0) const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        label?.isNotEmpty == true ? label! : '—',
                        style: TextStyle(
                          fontWeight: entry.key == 0
                              ? FontWeight.w700
                              : FontWeight.w500,
                          color: AppTheme.textInk,
                        ),
                      ),
                    ),
                  ],
                ),
              );
            }),
            if (record['can_edit'] == true || actions.isNotEmpty) ...[
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (record['can_edit'] == true)
                    OutlinedButton.icon(
                      onPressed: () => _edit(record['id'].toString()),
                      icon: const Icon(Icons.edit_outlined, size: 16),
                      label: const Text('Ubah'),
                    ),
                  ...actions.map((rawAction) {
                    final action = Map<String, dynamic>.from(rawAction as Map);
                    return OutlinedButton.icon(
                      onPressed: () => _runAction(record, action),
                      icon: const Icon(Icons.play_arrow_rounded, size: 16),
                      label: Text(action['label']?.toString() ?? 'Aksi'),
                    );
                  }),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  static String _emptyMessage(String resource) => switch (resource) {
    'complaints' => 'Belum ada komplain pada periode aktif.',
    'coaching-logs' => 'Belum ada catatan coaching.',
    'admin-work-logs' => 'Belum ada work-log admin.',
    'stock-opnames' => 'Belum ada sesi stock opname.',
    'spareparts' => 'Belum ada produk atau stok.',
    _ => 'Belum ada data untuk ditampilkan.',
  };

  static String _message(Object error) =>
      error.toString().replaceFirst('Exception: ', '');
}

class ResourceEditorScreen extends StatefulWidget {
  final String resource;
  final Map<String, dynamic> config;
  final Map<String, dynamic> form;
  final Map<String, dynamic>? detail;

  const ResourceEditorScreen({
    super.key,
    required this.resource,
    required this.config,
    required this.form,
    this.detail,
  });

  @override
  State<ResourceEditorScreen> createState() => _ResourceEditorScreenState();
}

class _ResourceEditorScreenState extends State<ResourceEditorScreen> {
  final _formKey = GlobalKey<FormState>();
  final Map<String, TextEditingController> _controllers = {};
  final Map<String, dynamic> _values = {};
  final Map<String, TextEditingController> _itemControllers = {};
  bool _saving = false;

  List<dynamic> get _fields =>
      List<dynamic>.from(widget.config['fields'] as List? ?? const []);
  Map<String, dynamic> get _options => Map<String, dynamic>.from(
    (widget.form['options'] as Map?) ??
        (widget.config['options'] as Map?) ??
        const {},
  );
  bool get _editing => widget.form['mode'] == 'edit';

  @override
  void initState() {
    super.initState();
    final initial = Map<String, dynamic>.from(
      widget.form['values'] as Map? ?? const {},
    );
    for (final raw in _fields) {
      final field = Map<String, dynamic>.from(raw as Map);
      final name = field['name'].toString();
      final value = initial[name] ?? field['default'];
      _values[name] = value;
      _controllers[name] = TextEditingController(text: _textValue(value));
    }
    for (final raw in List<dynamic>.from(
      widget.detail?['items'] as List? ?? const [],
    )) {
      final item = Map<String, dynamic>.from(raw as Map);
      final id = item['id'].toString();
      _itemControllers[id] = TextEditingController(
        text:
            item['physical_stock']?.toString() ??
            item['system_stock']?.toString() ??
            '',
      );
    }
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    for (final controller in _itemControllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final body = <String, dynamic>{};
    for (final raw in _fields) {
      final field = Map<String, dynamic>.from(raw as Map);
      final name = field['name'].toString();
      if (field['readOnly'] == true) {
        continue;
      }
      final type = field['type']?.toString();
      body[name] = type == 'checkbox'
          ? (_values[name] == true)
          : _controllers[name]!.text.trim();
    }
    if (widget.resource == 'stock-opnames' && _editing) {
      body['items'] = _itemControllers.entries
          .map(
            (entry) => {
              'id': entry.key,
              'physical_stock': int.tryParse(entry.value.text.trim()) ?? 0,
            },
          )
          .toList();
    }

    setState(() => _saving = true);
    try {
      final response = _editing
          ? await ApiService.put(
              '/resources/${widget.resource}/${widget.form['record_id']}',
              body,
            )
          : await ApiService.post('/resources/${widget.resource}', body);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            response['message']?.toString() ?? 'Data berhasil disimpan.',
          ),
          backgroundColor: AppTheme.primary,
        ),
      );
      Navigator.pop(context, true);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString().replaceFirst('Exception: ', '')),
            backgroundColor: AppTheme.statusDanger,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = _editing
        ? 'Ubah ${widget.config['label']}'
        : 'Tambah ${widget.config['label']}';
    final items = List<dynamic>.from(
      widget.detail?['items'] as List? ?? const [],
    );
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
          children: [
            if (widget.config['description'] != null)
              Text(
                widget.config['description'].toString(),
                style: TextStyle(color: AppTheme.textMuted),
              ),
            const SizedBox(height: 16),
            ..._fields.map(_field),
            if (items.isNotEmpty) ...[
              const SizedBox(height: 20),
              Text(
                'Hitung fisik',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              ...items.map((raw) {
                final item = Map<String, dynamic>.from(raw as Map);
                final controller = _itemControllers[item['id'].toString()]!;
                return Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: TextFormField(
                    controller: controller,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText:
                          '${item['sparepart'] ?? item['code'] ?? 'Item'} · sistem ${item['system_stock']}',
                    ),
                    validator: (value) =>
                        int.tryParse(value?.trim() ?? '') == null
                        ? 'Isi jumlah fisik.'
                        : null,
                  ),
                );
              }),
            ],
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: _saving ? null : _submit,
              icon: _saving
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Icon(Icons.save_rounded),
              label: Text(_saving ? 'Menyimpan…' : 'Simpan'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _field(dynamic raw) {
    final field = Map<String, dynamic>.from(raw as Map);
    final name = field['name'].toString();
    final label = field['label']?.toString() ?? name;
    final type = field['type']?.toString() ?? 'text';
    final readOnly = field['readOnly'] == true;
    final required = field['required'] == true;
    final controller = _controllers[name]!;
    if (type == 'checkbox') {
      return SwitchListTile.adaptive(
        contentPadding: EdgeInsets.zero,
        title: Text(label),
        value: _values[name] == true,
        onChanged: readOnly
            ? null
            : (value) => setState(() => _values[name] = value),
      );
    }
    if (type == 'select') {
      final options = List<dynamic>.from(
        _options[name] as List? ?? field['options'] as List? ?? const [],
      );
      final selected = controller.text.isEmpty ? null : controller.text;
      return Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: FormField<String>(
          initialValue:
              options.any(
                (rawOption) => rawOption['value'].toString() == selected,
              )
              ? selected
              : null,
          validator: required
              ? (value) => value == null || value.isEmpty
                    ? '$label wajib diisi.'
                    : null
              : null,
          builder: (formField) => OpsSelectionField<String>(
            label: label,
            value: formField.value,
            enabled: !readOnly,
            searchable: options.length > 8,
            errorText: formField.errorText,
            options: options.map((rawOption) {
              final option = Map<String, dynamic>.from(rawOption as Map);
              return OpsSelectionOption<String>(
                value: option['value'].toString(),
                label:
                    option['label']?.toString() ?? option['value'].toString(),
              );
            }).toList(),
            onChanged: (value) {
              formField.didChange(value);
              setState(() => controller.text = value);
            },
          ),
        ),
      );
    }
    final isDate = type == 'date' || type == 'datetime-local';
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        controller: controller,
        readOnly: readOnly || isDate,
        onTap: isDate && !readOnly
            ? () => _pickDate(name, type == 'datetime-local')
            : null,
        maxLines: type == 'textarea' ? 3 : 1,
        keyboardType: type == 'number'
            ? TextInputType.number
            : TextInputType.text,
        decoration: InputDecoration(
          labelText: label,
          helperText: field['help']?.toString(),
        ),
        validator: required
            ? (value) => value == null || value.trim().isEmpty
                  ? '$label wajib diisi.'
                  : null
            : null,
      ),
    );
  }

  Future<void> _pickDate(String name, bool dateTime) async {
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
      initialDate:
          DateTime.tryParse(_controllers[name]!.text) ?? DateTime.now(),
    );
    if (picked != null) {
      _controllers[name]!.text = dateTime
          ? '${picked.toIso8601String().substring(0, 10)}T00:00'
          : picked.toIso8601String().substring(0, 10);
    }
  }

  static String _textValue(dynamic value) {
    if (value == null) return '';
    if (value is bool) return '';
    return value.toString();
  }
}
