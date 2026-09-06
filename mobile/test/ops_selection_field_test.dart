import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kpi_mobile/app/theme/app_theme.dart';
import 'package:kpi_mobile/app/widgets/kpi_ui.dart';

void main() {
  test('form fitur memakai sheet pilihan, bukan dropdown inline', () {
    final offenders = Directory('lib/features')
        .listSync(recursive: true)
        .whereType<File>()
        .where((file) => file.path.endsWith('.dart'))
        .where((file) => file.readAsStringSync().contains('DropdownButton'))
        .map((file) => file.path)
        .toList();

    expect(
      offenders,
      isEmpty,
      reason:
          'Gunakan OpsSelectionField agar pilihan tampil sebagai bottom sheet.',
    );
  });

  testWidgets(
    'OpsSelectionField membuka bottom sheet dan mengembalikan pilihan',
    (tester) async {
      String? selected;

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: StatefulBuilder(
            builder: (context, setState) => Scaffold(
              body: OpsSelectionField<String>(
                label: 'Status servis',
                hint: 'Pilih status',
                value: selected,
                options: const [
                  OpsSelectionOption<String>(
                    value: 'ready',
                    label: 'Siap diuji',
                    supportingText: 'Unit masuk tahap quality control',
                    icon: Icons.check_circle_rounded,
                  ),
                  OpsSelectionOption<String>(
                    value: 'hold',
                    label: 'Tahan sementara',
                    supportingText: 'Menunggu komponen atau konfirmasi',
                    icon: Icons.pause_circle_rounded,
                  ),
                ],
                onChanged: (value) => setState(() => selected = value),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Pilih status'));
      await tester.pumpAndSettle();

      expect(find.text('Status servis'), findsNWidgets(2));
      expect(find.text('Siap diuji'), findsOneWidget);
      expect(find.text('Tahan sementara'), findsOneWidget);

      await tester.tap(find.text('Siap diuji'));
      await tester.pumpAndSettle();

      expect(selected, 'ready');
      expect(find.text('Siap diuji'), findsOneWidget);
    },
  );
}
