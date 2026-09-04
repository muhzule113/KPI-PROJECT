import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_service.dart';
import '../realtime/realtime_service.dart';

class AuthProvider extends ChangeNotifier {
  bool _isAuthenticated = false;
  bool _isLoading = false;
  Map<String, dynamic>? _user;
  Map<String, dynamic>? _employee;
  String? _token;

  bool get isAuthenticated => _isAuthenticated;
  bool get isLoading => _isLoading;
  Map<String, dynamic>? get user => _user;
  Map<String, dynamic>? get employee => _employee;
  String? get token => _token;

  bool get isSupervisor => hasRole('supervisor') || hasRole('super_admin');
  bool get isManager => hasRole('owner_manager') || hasRole('super_admin');
  bool get isKasir =>
      _employee?['position_code'] == 'POS-KSR' ||
      _employee?['position'] == 'Kasir';
  bool get isTeknisi => _employee?['position_code'] == 'POS-TEK';
  bool get isCs => _employee?['position_code'] == 'POS-CS';
  bool get isGudang => _employee?['position_code'] == 'POS-GUD';

  bool hasRole(String roleName) {
    if (_user == null) return false;
    final roles = _user!['roles'] as List<dynamic>?;
    return roles?.contains(roleName) ?? false;
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
      _user = data['user'] is Map
          ? Map<String, dynamic>.from(data['user'] as Map)
          : _user;
      _employee = data['employee'] is Map
          ? Map<String, dynamic>.from(data['employee'] as Map)
          : _employee;
      await prefs.setString('user_data', jsonEncode(_user));
      if (_employee != null) {
        await prefs.setString('employee_data', jsonEncode(_employee));
      }
      _isAuthenticated = true;
      notifyListeners();
      unawaited(RealtimeService.instance.connect());
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
      _user = data['user'];
      _employee = data['employee'];
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
    } catch (e) {
      _isLoading = false;
      notifyListeners();
      rethrow;
    }
  }

  Future<void> logout() async {
    try {
      await ApiService.post('/auth/logout');
    } catch (_) {}

    await clearSession();
  }
}
