import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_service.dart';
import '../realtime/realtime_service.dart';
import '../notifications/push_notification_service.dart';

class AuthProvider extends ChangeNotifier {
  bool _isAuthenticated = false;
  bool _isLoading = false;
  Map<String, dynamic>? _user;
  Map<String, dynamic>? _employee;
  List<String> _capabilities = <String>[];
  List<String> _allowedPlatforms = <String>[];
  String? _token;

  bool get isAuthenticated => _isAuthenticated;
  bool get isLoading => _isLoading;
  Map<String, dynamic>? get user => _user;
  Map<String, dynamic>? get employee => _employee;
  List<String> get capabilities => List.unmodifiable(_capabilities);
  List<String> get allowedPlatforms => List.unmodifiable(_allowedPlatforms);
  String? get token => _token;

  bool get isSupervisor => hasCapability('kpi.supervisor.review');
  bool get isManager => hasCapability('kpi.manager.approval');
  bool get isTechnician => _employee?['position_code'] == 'POS-TEK';
  bool get isAdmin => _employee?['position_code'] == 'POS-ADM';
  bool get isKasir =>
      _employee?['position_code'] == 'POS-KSR' ||
      _employee?['position'] == 'Kasir';
  bool get isCs => _employee?['position_code'] == 'POS-CS';
  bool get isGudang => _employee?['position_code'] == 'POS-GUD';
  bool get canRequestSparepart => hasCapability('spareparts.request');
  bool get canManageTickets =>
      hasCapability('tickets.manage') ||
      hasCapability('tickets.progress') ||
      hasCapability('tickets.complete');

  bool get canSuperviseTickets => hasCapability('tickets.supervise');

  bool get canViewTickets => hasCapability('tickets.view');

  bool hasRole(String roleName) {
    if (_user == null) return false;
    final roles = _user!['roles'] as List<dynamic>?;
    return roles?.contains(roleName) ?? false;
  }

  bool hasCapability(String capability) => _capabilities.contains(capability);

  void _setSessionData(Map<String, dynamic> data) {
    if (data['user'] is Map) {
      _user = Map<String, dynamic>.from(data['user'] as Map);
    }
    if (data['employee'] is Map) {
      _employee = Map<String, dynamic>.from(data['employee'] as Map);
    } else {
      _employee = null;
    }
    _capabilities = (data['capabilities'] as List<dynamic>? ?? const [])
        .map((value) => value.toString())
        .toList(growable: false);
    _allowedPlatforms = (data['allowed_platforms'] as List<dynamic>? ?? const [])
        .map((value) => value.toString())
        .toList(growable: false);
  }

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _token = await ApiService.getToken();
    final userStr = prefs.getString('user_data');
    final empStr = prefs.getString('employee_data');

    if (_token == null || userStr == null) return;

    try {
      _user = Map<String, dynamic>.from(jsonDecode(userStr) as Map);
      if (empStr != null) {
        _employee = Map<String, dynamic>.from(jsonDecode(empStr) as Map);
      }
      ApiService.onUnauthorized = clearSession;
      final res = await ApiService.get('/auth/me');
      final data = Map<String, dynamic>.from(res['data'] as Map);
      _setSessionData(data);
      await prefs.setString('user_data', jsonEncode(_user));
      if (_employee != null) {
        await prefs.setString('employee_data', jsonEncode(_employee));
      }
      _isAuthenticated = true;
      notifyListeners();
      unawaited(RealtimeService.instance.connect());
      unawaited(PushNotificationService.instance.register());
    } catch (_) {
      await clearSession(notify: false);
    }
  }

  Future<void> clearSession({bool notify = true}) async {
    await RealtimeService.instance.disconnect();
    final prefs = await SharedPreferences.getInstance();
    await ApiService.clearToken();
    await prefs.remove('user_data');
    await prefs.remove('employee_data');

    _token = null;
    _user = null;
    _employee = null;
    _capabilities = <String>[];
    _allowedPlatforms = <String>[];
    _isAuthenticated = false;
    if (notify) notifyListeners();
  }

  Future<void> login(String email, String password) async {
    _isLoading = true;
    notifyListeners();

    try {
      final res = await ApiService.post('/auth/login', {
        'email': email,
        'password': password,
      });

      final data = res['data'];
      _token = data['token'];
      _setSessionData(Map<String, dynamic>.from(data as Map));
      _isAuthenticated = true;
      ApiService.onUnauthorized = () => clearSession();

      final prefs = await SharedPreferences.getInstance();
      await ApiService.saveToken(_token!);
      await prefs.setString('user_data', jsonEncode(_user));
      if (_employee != null) {
        await prefs.setString('employee_data', jsonEncode(_employee));
      }

      _isLoading = false;
      notifyListeners();
      unawaited(RealtimeService.instance.connect());
      unawaited(PushNotificationService.instance.register());
    } catch (e) {
      _isLoading = false;
      notifyListeners();
      rethrow;
    }
  }

  Future<void> logout() async {
    await PushNotificationService.instance.unregister();
    try {
      await ApiService.post('/auth/logout');
    } catch (_) {}

    await clearSession();
  }
}
