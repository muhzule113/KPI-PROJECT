import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/core/auth/auth_provider.dart';
import 'package:kpi_mobile/features/dashboard/dashboard_screen.dart';
import 'package:kpi_mobile/features/notifications/notification_screen.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _Session extends AuthProvider {
  final Set<String> grants;
  _Session(this.grants);
  @override
  bool hasCapability(String capability) => grants.contains(capability);
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiService.onUnauthorized = null;
  });
  tearDown(() => ApiService.onUnauthorized = null);

  test('pencabutan sesi menghapus login, penolakan aksi biasa tidak', () async {
    var cleared = false;
    ApiService.onUnauthorized = () async => cleared = true;
    ApiService.client = MockClient(
      (_) async =>
          http.Response(jsonEncode({'message': 'Aksi tidak diizinkan'}), 403),
    );
    await expectLater(
      ApiService.get('/dashboard'),
      throwsA(isA<ApiException>()),
    );
    expect(cleared, false);
    ApiService.client = MockClient(
      (_) async => http.Response(
        jsonEncode({'message': 'Akun tidak aktif', 'code': 'SESSION_REVOKED'}),
        403,
      ),
    );
    await expectLater(
      ApiService.get('/dashboard'),
      throwsA(isA<ApiException>()),
    );
    expect(cleared, true);
  });

  testWidgets('notifikasi yang sudah dibaca membuka periode KPI terkait', (
    tester,
  ) async {
    String? requestedPeriod;
    ApiService.client = MockClient((request) async {
      dynamic data;
      if (request.url.path.endsWith('/notifications')) {
        data = [
          {
            'id': 'n-1',
            'title': 'Hasil periode lalu',
            'body': 'Sudah terbit',
            'is_read': true,
            'created_at': '2026-07-01T09:00:00',
            'destination': {'screen': 'my-kpi', 'period_id': 7},
          },
        ];
      } else {
        requestedPeriod = request.url.queryParameters['period_id'];
        data = {
          'id': 'k-7',
          'period': {'id': 7, 'name': 'Riwayat Juli'},
          'status': 'locked',
          'score_visible': true,
          'final_score': 95,
          'rating_label': 'Sangat Baik',
          'items': [],
        };
      }
      return http.Response(jsonEncode({'success': true, 'data': data}), 200);
    });
    await tester.pumpWidget(
      ChangeNotifierProvider<AuthProvider>(
        create: (_) => _Session({'kpi.self.view'}),
        child: const MaterialApp(home: NotificationScreen()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hasil periode lalu'));
    await tester.pumpAndSettle();
    expect(requestedPeriod, '7');
    expect(find.text('Riwayat Juli'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('tugas Manager memakai satu pintu Penilaian Tim', (tester) async {
    ApiService.client = MockClient(
      (_) async =>
          http.Response(jsonEncode({'success': true, 'data': {}}), 200),
    );
    await tester.pumpWidget(
      ChangeNotifierProvider<AuthProvider>(
        create: (_) => _Session({'kpi.manager.approval', 'reports.view'}),
        child: const MaterialApp(home: DashboardScreen()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tugas'));
    await tester.pumpAndSettle();
    expect(find.text('Penilaian Tim'), findsOneWidget);
    expect(find.text('Approval'), findsNothing);
    expect(find.text('Penilaian Harian'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
