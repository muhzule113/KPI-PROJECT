import 'dart:async';
import 'dart:developer' as developer;

import 'package:flutter/foundation.dart';
import 'package:laravel_reverb/laravel_reverb.dart';

import '../api/api_service.dart';

final class RealtimeService {
  static final RealtimeService instance = RealtimeService._();

  RealtimeService._();

  final StreamController<void> _kpiUpdates = StreamController<void>.broadcast();
  Reverb? _client;
  Subscription? _subscription;
  bool _connecting = false;

  Stream<void> get kpiUpdates => _kpiUpdates.stream;

  Future<void> connect() async {
    if (_client != null || _connecting) return;

    const appKey = String.fromEnvironment('REVERB_APP_KEY');
    if (appKey.isEmpty) return;

    _connecting = true;
    final apiUri = Uri.parse(ApiService.baseUrl);
    final configuredScheme = const String.fromEnvironment('REVERB_SCHEME');
    final useTls = configuredScheme.isEmpty
        ? apiUri.scheme == 'https'
        : configuredScheme == 'https';
    final configuredHost = const String.fromEnvironment('REVERB_HOST');
    final host = configuredHost.isNotEmpty ? configuredHost : _defaultHost();
    final configuredPort = const String.fromEnvironment('REVERB_PORT');
    final port = int.tryParse(configuredPort) ?? (useTls ? 443 : 8080);
    final authEndpoint = apiUri
        .replace(path: '/broadcasting/auth', query: null, fragment: null)
        .toString();

    final client = Reverb(
      host: host,
      port: port,
      appKey: appKey,
      useTls: useTls,
      path: const String.fromEnvironment('REVERB_SERVER_PATH'),
      authEndpoint: authEndpoint,
      authHeaders: () async {
        final token = await ApiService.getToken();
        return token == null ? {} : {'Authorization': 'Bearer $token'};
      },
      onError: (error, stackTrace) => developer.log(
        'Reverb connection error',
        error: error,
        stackTrace: stackTrace,
        name: 'RealtimeService',
      ),
      onLog: (message) => developer.log(message, name: 'RealtimeService'),
    );

    _client = client;
    _subscription = client
        .private('kpi-updates')
        .listen('.kpi.updated', (_) => _notifyKpiUpdated());
    client.onReconnected(_notifyKpiUpdated);

    try {
      await client.connect();
    } catch (error, stackTrace) {
      developer.log(
        'Reverb startup failed; polling remains active',
        error: error,
        stackTrace: stackTrace,
        name: 'RealtimeService',
      );
      await disconnect();
    } finally {
      _connecting = false;
    }
  }

  Future<void> disconnect() async {
    _subscription?.cancel();
    _subscription = null;

    final client = _client;
    _client = null;
    if (client == null) return;

    await client.disconnect(forget: true);
    client.dispose();
  }

  void _notifyKpiUpdated() => _kpiUpdates.add(null);

  String _defaultHost() {
    if (kIsWeb) return 'localhost';
    if (defaultTargetPlatform == TargetPlatform.android) return '10.0.2.2';
    return 'localhost';
  }
}
