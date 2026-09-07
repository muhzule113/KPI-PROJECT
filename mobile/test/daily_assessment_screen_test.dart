import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/features/review/daily_assessment_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  testWidgets('Manager menyetujui seluruh indikator staf dari kartu karyawan', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    var approveAllRequested = false;
    ApiService.client = MockClient((request) async {
      if (request.method == 'POST' &&
          request.url.path.endsWith('/manager/daily/kpi-1/approve-all')) {
        approveAllRequested = true;
        return http.Response(
          jsonEncode({
            'success': true,
            'message': 'Seluruh penilaian harian staf berhasil disetujui.',
            'data': {'kpi_id': 'kpi-1', 'approved_count': 2},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        jsonEncode({
          'success': true,
          'data': [
            _entry(1, 'TEK-01', 'Jumlah servis selesai', 9),
            _entry(2, 'TEK-02', 'Keberhasilan servis', 95),
          ],
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      const MaterialApp(home: DailyAssessmentScreen(manager: true)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Staf'), findsOneWidget);
    expect(find.text('Supervisor'), findsOneWidget);
    expect(find.text('Budi Teknisi'), findsOneWidget);
    expect(find.text('Setujui semua'), findsOneWidget);

    await tester.tap(find.text('Setujui semua'));
    await tester.pumpAndSettle();

    expect(approveAllRequested, isTrue);
  });

  testWidgets('Mode fokus mengonfirmasi otomatis lalu menampilkan selesai', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    var completed = false;
    ApiService.client = MockClient((request) async {
      if (request.method == 'POST' &&
          request.url.path.endsWith('/supervisor/daily/kpi-1/approve-all')) {
        completed = true;
        return http.Response(
          jsonEncode({
            'success': true,
            'message': 'Data otomatis berhasil dikonfirmasi.',
            'data': {'kpi_id': 'kpi-1', 'approved_count': 1},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      expect(request.url.queryParameters['kpi_id'], 'kpi-1');
      return http.Response(
        jsonEncode({
          'success': true,
          'data': completed ? [] : [_automaticEntry()],
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    await tester.pumpWidget(
      MaterialApp(
        home: DailyAssessmentScreen(
          manager: false,
          initialDate: DateTime(2026, 9, 8),
          kpiId: 'kpi-1',
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Konfirmasi otomatis'), findsOneWidget);
    expect(find.text('Ubah'), findsNothing);
    await tester.tap(find.text('Konfirmasi otomatis'));
    await tester.pumpAndSettle();

    expect(completed, isTrue);
    expect(find.text('Penilaian karyawan ini selesai'), findsOneWidget);
    expect(find.text('Kembali ke Penilaian Tim'), findsOneWidget);
  });
}

Map<String, dynamic> _entry(int id, String code, String name, num value) => {
  'id': id,
  'kpi_id': 'kpi-1',
  'review_mode': 'staff_confirmation',
  'employee': {
    'id': 'employee-1',
    'name': 'Budi Teknisi',
    'position': 'Teknisi',
    'branch': 'Cabang Utama',
  },
  'item': {
    'code': code,
    'name': name,
    'input_type': 'numeric',
    'formula': 'higher_better',
    'source_type': 'employee',
    'target_value': 10,
    'target_unit': 'unit',
  },
  'supervisor_actual_decimal': value,
  'supervisor_status': 'approved',
  'manager_status': 'pending',
};

Map<String, dynamic> _automaticEntry() => {
  'id': 3,
  'kpi_id': 'kpi-1',
  'review_mode': 'staff_confirmation',
  'employee': {
    'id': 'employee-1',
    'name': 'Budi Teknisi',
    'position': 'Teknisi',
    'branch': 'Cabang Utama',
  },
  'item': {
    'code': 'TEK-01',
    'name': 'Jumlah servis selesai',
    'input_type': 'numeric',
    'formula': 'higher_better',
    'source_type': 'system',
    'target_value': 10,
    'target_unit': 'unit',
    'system_actual': 12,
  },
  'system_actual_decimal': 12,
  'supervisor_status': 'pending',
  'manager_status': 'pending',
};
