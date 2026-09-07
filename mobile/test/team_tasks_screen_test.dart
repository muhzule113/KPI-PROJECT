import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/features/team_tasks/team_tasks_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('Tanggal hari ini menjadi filter awal', (tester) async {
    String? requestedDate;
    ApiService.client = MockClient((request) async {
      requestedDate = request.url.queryParameters['date'];
      return http.Response(
        jsonEncode({
          'success': true,
          'data': {
            'role': 'manager',
            'title': 'Penilaian Tim',
            'employees': [],
            'completed_employees': [],
          },
        }),
        200,
      );
    });

    await tester.pumpWidget(
      const MaterialApp(home: TeamTasksScreen(manager: true)),
    );
    await tester.pumpAndSettle();

    expect(requestedDate, DateTime.now().toIso8601String().substring(0, 10));
    expect(find.textContaining('Hari ini,'), findsOneWidget);
    expect(find.text('Pilih tanggal'), findsOneWidget);

    final pickerButton = find.widgetWithText(OutlinedButton, 'Pilih tanggal');
    expect(tester.getSize(pickerButton).height, greaterThanOrEqualTo(44));
    await tester.tap(pickerButton);
    await tester.pumpAndSettle();
    expect(find.text('Pilih tanggal penilaian'), findsOneWidget);
    expect(find.text('Batal'), findsOneWidget);
    expect(find.text('Pilih'), findsOneWidget);
  });

  testWidgets('Manager melihat nama belum selesai dan selesai pada dua tab', (
    tester,
  ) async {
    ApiService.client = MockClient(
      (_) async => http.Response(
        jsonEncode({
          'success': true,
          'data': {
            'role': 'manager',
            'title': 'Penilaian Tim',
            'required_count': 1,
            'optional_count': 1,
            'employees': [
              _employee('Sari Supervisor', [_task('monthly_approval')], []),
            ],
            'completed_employees': [_employee('Budi Teknisi', [], [])],
          },
        }),
        200,
      ),
    );

    await tester.pumpWidget(
      const MaterialApp(home: TeamTasksScreen(manager: true)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Penilaian Tim'), findsOneWidget);
    expect(find.text('Belum selesai'), findsOneWidget);
    expect(find.text('Selesai'), findsOneWidget);
    expect(find.text('Sari Supervisor'), findsOneWidget);
    expect(find.text('Budi Teknisi'), findsNothing);
    await tester.tap(find.text('Selesai'));
    await tester.pumpAndSettle();
    expect(find.text('Budi Teknisi'), findsOneWidget);
  });

  testWidgets('Aksi harian membuka satu KPI secara langsung', (tester) async {
    var focused = false;
    ApiService.client = MockClient((request) async {
      if (request.url.path.endsWith('/supervisor/daily')) {
        focused = request.url.queryParameters['kpi_id'] == 'kpi-1';
        return http.Response(jsonEncode({'success': true, 'data': []}), 200);
      }
      return http.Response(
        jsonEncode({
          'success': true,
          'data': {
            'role': 'supervisor',
            'title': 'Penilaian Tim',
            'required_count': 1,
            'optional_count': 0,
            'employees': [
              _employee('Budi Teknisi', [
                _task('daily_assessment', date: '2026-09-08', automatic: 2),
              ], []),
            ],
            'completed_employees': [],
          },
        }),
        200,
      );
    });

    await tester.pumpWidget(
      const MaterialApp(home: TeamTasksScreen(manager: false)),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nilai sekarang'));
    await tester.pumpAndSettle();

    expect(focused, isTrue);
    expect(find.text('Penilaian karyawan ini selesai'), findsOneWidget);
  });
}

Map<String, dynamic> _employee(
  String name,
  List<Map<String, dynamic>> required,
  List<Map<String, dynamic>> optional,
) => {
  'employee': {
    'id': name,
    'name': name,
    'position': 'Teknisi',
    'branch': 'Cabang Utama',
  },
  'required_tasks': required,
  'optional_tasks': optional,
  'secondary_actions': const [],
  'status': required.isEmpty
      ? 'Semua tindakan wajib sudah selesai.'
      : 'Penilaian harian belum selesai.',
  'primary_action': required.isEmpty ? null : required.first['action'],
};

Map<String, dynamic> _task(String type, {String? date, int automatic = 0}) => {
  'type': type,
  'kpi_id': 'kpi-1',
  'date': date,
  'period': {'id': 1, 'name': 'September 2026'},
  'indicator_count': 2,
  'automatic_indicator_count': automatic,
  'status_label': 'Menunggu tindakan',
  'action': {
    'type': type,
    'label': type == 'monthly_approval' ? 'Sahkan hasil' : 'Nilai sekarang',
    'url': '/unused',
  },
};
