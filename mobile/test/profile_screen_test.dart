import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/core/auth/auth_provider.dart';
import 'package:kpi_mobile/features/profile/profile_screen.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiService.client = MockClient((request) async {
      if (request.url.path.endsWith('/auth/me')) {
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'user': {
                'id': 1,
                'name': 'Doni Kusuma',
                'email': 'gudang@toko.com',
                'roles': ['employee'],
              },
              'employee': {
                'id': 'emp-1',
                'employee_number': 'EMP-007',
                'name': 'Doni Kusuma',
                'position': 'Gudang / Sparepart',
                'position_code': 'POS-GUD',
                'branch': 'Toko Pusat',
                'status': 'active',
              },
            },
          }),
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
    'Profile menampilkan data dari /auth/me dan punya aksi ubah password',
    (tester) async {
      final authProvider = AuthProvider();
      await authProvider.init();

      await tester.pumpWidget(
        MultiProvider(
          providers: [ChangeNotifierProvider.value(value: authProvider)],
          child: const MaterialApp(home: ProfileScreen()),
        ),
      );
      await tester.pumpAndSettle();

      // Data karyawan dari API
      expect(find.text('Doni Kusuma'), findsWidgets);
      expect(find.text('EMP-007'), findsOneWidget);
      expect(find.text('Gudang / Sparepart'), findsOneWidget);
      expect(find.text('Toko Pusat'), findsOneWidget);

      // Tile ubah password ada & bisa dibuka
      await tester.tap(find.text('Ubah Kata Sandi'));
      await tester.pumpAndSettle();
      expect(find.text('Kata sandi saat ini'), findsOneWidget);
      expect(find.text('Kata sandi baru'), findsOneWidget);
    },
  );
}
