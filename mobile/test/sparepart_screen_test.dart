import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/core/auth/auth_provider.dart';
import 'package:kpi_mobile/features/operational/sparepart_screen.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiService.client = MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/operational/spareparts')) {
        return http.Response(
          jsonEncode({
            'success': true,
            'data': [
              {
                'id': 1, 'code': 'HS-IP13-128', 'product_type': 'handset',
                'product_type_label': 'Handset HP', 'name': 'Handset iPhone 13',
                'stock': 3, 'min_stock': 1,
              },
              {
                'id': 2, 'code': 'TB-IPAD9-64', 'product_type': 'tablet',
                'product_type_label': 'Tablet / iPad', 'name': 'iPad 9th Gen',
                'stock': 2, 'min_stock': 1,
              },
              {
                'id': 3, 'code': 'PRT-LCD-IP13', 'product_type': 'sparepart',
                'product_type_label': 'Sparepart', 'name': 'LCD iPhone 13',
                'stock': 15, 'min_stock': 3,
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (path.endsWith('/operational/sparepart-requests')) {
        return http.Response(jsonEncode({'success': true, 'data': []}), 200,
            headers: {'content-type': 'application/json'});
      }
      return http.Response(jsonEncode({'success': false, 'message': 'Not found'}), 404,
          headers: {'content-type': 'application/json'});
    });
  });

  testWidgets('Inventory menampilkan produk dikelompokkan per jenis', (tester) async {
    final authProvider = AuthProvider();
    await authProvider.init();

    await tester.pumpWidget(
      MultiProvider(
        providers: [ChangeNotifierProvider.value(value: authProvider)],
        child: const MaterialApp(home: SparepartScreen()),
      ),
    );
    await tester.pumpAndSettle();

    // Header grup per jenis produk
    expect(find.text('Handset HP'), findsOneWidget);
    expect(find.text('Tablet / iPad'), findsOneWidget);
    expect(find.text('Sparepart'), findsOneWidget);

    // Item produk tampil
    expect(find.text('Handset iPhone 13'), findsOneWidget);
    expect(find.text('iPad 9th Gen'), findsOneWidget);
    expect(find.text('LCD iPhone 13'), findsOneWidget);

    // Stok & min tampil
    expect(find.text('3 pcs'), findsOneWidget);
    expect(find.text('15 pcs'), findsOneWidget);
  });
}
