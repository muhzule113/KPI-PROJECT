import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_service.dart';

@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp(options: PushNotificationService.instance._options);
  } catch (_) {}
}

class PushNotificationService {
  PushNotificationService._();

  static final instance = PushNotificationService._();
  static const _deviceIdKey = 'push_device_id';
  StreamSubscription<String>? _refreshSubscription;
  bool _ready = false;
  Future<void> Function(Map<String, dynamic> data)? onOpen;

  Future<void> initialize() async {
    try {
      await Firebase.initializeApp(options: _options);
      FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
      await FirebaseMessaging.instance.requestPermission();
      FirebaseMessaging.onMessageOpenedApp.listen(_open);
      final initial = await FirebaseMessaging.instance.getInitialMessage();
      if (initial != null) unawaited(_open(initial));
      _ready = true;
    } catch (_) {
      _ready = false;
    }
  }

  Future<void> register() async {
    if (!_ready || await ApiService.getToken() == null) return;
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) return;
    await _sendToken(token);
    await _refreshSubscription?.cancel();
    _refreshSubscription = FirebaseMessaging.instance.onTokenRefresh.listen(
      (value) => unawaited(_sendToken(value)),
    );
  }

  Future<void> unregister() async {
    if (!_ready) return;
    final token = await FirebaseMessaging.instance.getToken();
    if (token != null && await ApiService.getToken() != null) {
      try {
        await ApiService.delete('/devices/push-token', {'token': token});
      } catch (_) {}
    }
    await _refreshSubscription?.cancel();
    _refreshSubscription = null;
  }

  Future<void> _sendToken(String token) async {
    await ApiService.put('/devices/push-token', {
      'token': token,
      'platform': _platform,
      'device_id': await _deviceId(),
      'device_name': '${defaultTargetPlatform.name} KPI Mobile',
    });
  }

  Future<String> _deviceId() async {
    final preferences = await SharedPreferences.getInstance();
    var value = preferences.getString(_deviceIdKey);
    if (value == null) {
      final bytes = List<int>.generate(18, (_) => Random.secure().nextInt(256));
      value = base64UrlEncode(bytes);
      await preferences.setString(_deviceIdKey, value);
    }
    return value;
  }

  Future<void> _open(RemoteMessage message) async {
    try {
      await ApiService.get('/notifications');
      await onOpen?.call(Map<String, dynamic>.from(message.data));
    } catch (_) {}
  }

  String get _platform => switch (defaultTargetPlatform) {
    TargetPlatform.iOS || TargetPlatform.macOS => 'ios',
    TargetPlatform.android => 'android',
    _ => 'web',
  };

  FirebaseOptions? get _options {
    const apiKey = String.fromEnvironment('FIREBASE_API_KEY');
    const appId = String.fromEnvironment('FIREBASE_APP_ID');
    const senderId = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');
    const projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
    if ([apiKey, appId, senderId, projectId].any((value) => value.isEmpty)) {
      return null;
    }

    return const FirebaseOptions(
      apiKey: apiKey,
      appId: appId,
      messagingSenderId: senderId,
      projectId: projectId,
    );
  }
}
