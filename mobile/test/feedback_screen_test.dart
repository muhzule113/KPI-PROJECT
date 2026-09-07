import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:kpi_mobile/core/api/api_service.dart';
import 'package:kpi_mobile/features/operational/feedback_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  testWidgets('menampilkan dua rating dan menyalin link progres', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    var linkRequested = false;
    String? copiedUrl;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copiedUrl = (call.arguments as Map)['text']?.toString();
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    ApiService.client = MockClient((request) async {
      if (request.url.path.endsWith('/operational/feedback')) {
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'pending_tickets': [
                {
                  'id': 'ticket-1',
                  'ticket_number': 'SRV-001',
                  'customer_name': 'Nadia',
                  'device': 'Samsung S24',
                  'status': 'in_progress',
                },
              ],
              'feedbacks': [
                {
                  'id': 'feedback-1',
                  'customer_name': 'Nadia',
                  'rating': 5,
                  'employee': 'Sari',
                  'technician_rating': 4,
                  'technician_employee': 'Budi',
                  'comments': 'Pelayanan cepat.',
                },
              ],
              'stats': {'total': 1, 'average': 5, 'technician_average': 4},
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (request.url.path.endsWith(
        '/operational/tickets/ticket-1/feedback-link',
      )) {
        linkRequested = true;
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'url': 'https://example.test/customer-feedback/ticket-1',
              'expires_at': null,
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

    await tester.binding.setSurfaceSize(const Size(480, 900));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(const MaterialApp(home: FeedbackScreen()));
    await tester.pumpAndSettle();

    expect(find.text('Rerata Pelayan'), findsOneWidget);
    expect(find.text('Rerata Teknisi'), findsOneWidget);
    expect(find.text('Pelayan: Sari · ★ 5/5'), findsOneWidget);
    expect(find.text('Teknisi: Budi · ★ 4/5'), findsOneWidget);
    expect(find.text('Pelayanan cepat.'), findsOneWidget);

    await tester.tap(find.text('Salin link progres'));
    await tester.pump();

    expect(linkRequested, isTrue);
    expect(copiedUrl, 'https://example.test/customer-feedback/ticket-1');
  });
}
