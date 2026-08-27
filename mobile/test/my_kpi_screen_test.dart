import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/features/my_kpi/my_kpi_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

Map<String, dynamic> _kpiData() => {
  'id': 'kpi-1',
  'period': {'id': 1, 'name': 'Periode Agustus 2026'},
  'status': 'draft',
  'progress_percentage': 0.0,
  'supervisor': 'Budi Supervisor',
  'items': [
    {
      'id': 'item-1',
      'code': 'TEK-01',
      'name': 'Jumlah Servis Selesai',
      'weight': 25.0,
      'target_value': 20.0,
      'target_unit': 'unit',
      'source_type': 'system',
      'status': 'draft',
      'actual_decimal': 12.0,
      'actual_json': null,
    },
    {
      'id': 'item-2',
      'code': 'TEK-05',
      'name': 'Kepatuhan SOP Servis',
      'weight': 10.0,
      'target_value': 95.0,
      'target_unit': '%',
      'source_type': 'supervisor',
      'status': 'draft',
      'actual_decimal': null,
      'actual_json': null,
    },
  ],
};

Map<String, dynamic> _itemDetail() => {
  'id': 'item-1',
  'kpi_id': 'kpi-1',
  'code': 'TEK-01',
  'name': 'Jumlah Servis Selesai',
  'weight': 25.0,
  'target_value': 20.0,
  'target_unit': 'unit',
  'source_type': 'system',
  'status': 'draft',
  'actual_decimal': 12.0,
  'actual_json': null,
  'achievement_percentage': 60.0,
  'weighted_score': 15.0,
  'evidences': [],
  'latest_review': null,
  'rubric': null,
};

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiService.client = MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/my-kpi/active')) {
        return http.Response(
          jsonEncode({'success': true, 'data': _kpiData()}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (path.contains('/my-kpi/items/')) {
        return http.Response(
          jsonEncode({'success': true, 'data': _itemDetail()}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        jsonEncode({'success': false, 'message': 'Not found'}),
        404,
        headers: {'content-type': 'application/json'},
      );
    });
  });

  testWidgets(
    'My KPI menampilkan indikator tanpa tombol submit & tanpa input manual',
    (tester) async {
      await tester.pumpWidget(const MaterialApp(home: MyKpiScreen()));
      await tester.pumpAndSettle();

      // Header periode & indikator tampil
      expect(find.text('Periode Agustus 2026'), findsOneWidget);
      expect(find.text('Jumlah Servis Selesai'), findsOneWidget);
      expect(find.text('Kepatuhan SOP Servis'), findsOneWidget);

      // Tidak ada tombol submit / self-input
      expect(find.text('Kirim KPI ke Supervisor (Submit)'), findsNothing);
      expect(find.byType(TextField), findsNothing);

      // Nilai dari sistem & placeholder supervisor tampil
      expect(find.text('12.0 unit'), findsOneWidget);
      expect(find.text('Menunggu penilaian Supervisor'), findsOneWidget);
    },
  );

  testWidgets(
    'Detail indikator read-only — tidak ada field input nilai aktual',
    (tester) async {
      await tester.pumpWidget(const MaterialApp(home: MyKpiScreen()));
      await tester.pumpAndSettle();

      // Buka detail indikator pertama
      await tester.tap(find.text('Jumlah Servis Selesai'));
      await tester.pumpAndSettle();

      // Read-only: target tampil, tapi tidak ada TextField & tombol simpan
      expect(find.text('Target Sasaran'), findsOneWidget);
      expect(find.text('20.0 unit'), findsWidgets);
      expect(find.byType(TextField), findsNothing);
      expect(find.text('Simpan Draft Nilai'), findsNothing);

      // Penjelasan sumber data sistem
      expect(
        find.textContaining('Dihitung otomatis oleh sistem'),
        findsOneWidget,
      );
    },
  );
}
