import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Preferensi tampilan lokal yang tidak bergantung pada akun/API.
class AppPreferences extends ChangeNotifier {
  static const _themeModeKey = 'app_theme_mode';
  static const _reduceMotionKey = 'app_reduce_motion';

  ThemeMode _themeMode = ThemeMode.dark;
  bool _reduceMotion = false;

  ThemeMode get themeMode => _themeMode;
  bool get reduceMotion => _reduceMotion;

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    final themeName = prefs.getString(_themeModeKey);
    _themeMode = switch (themeName) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      'system' => ThemeMode.system,
      _ => ThemeMode.dark,
    };
    _reduceMotion = prefs.getBool(_reduceMotionKey) ?? false;
    notifyListeners();
  }

  Future<void> setThemeMode(ThemeMode mode) async {
    _themeMode = mode;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_themeModeKey, mode.name);
    notifyListeners();
  }

  Future<void> setReduceMotion(bool value) async {
    _reduceMotion = value;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_reduceMotionKey, value);
    notifyListeners();
  }

  Future<void> reset() async {
    _themeMode = ThemeMode.dark;
    _reduceMotion = false;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_themeModeKey);
    await prefs.remove(_reduceMotionKey);
    notifyListeners();
  }
}

String themeModeLabel(ThemeMode mode) {
  switch (mode) {
    case ThemeMode.system:
      return 'Mengikuti sistem';
    case ThemeMode.light:
      return 'Mode terang';
    case ThemeMode.dark:
      return 'Mode gelap';
  }
}

IconData themeModeIcon(ThemeMode mode) {
  switch (mode) {
    case ThemeMode.system:
      return Icons.brightness_auto_rounded;
    case ThemeMode.light:
      return Icons.light_mode_rounded;
    case ThemeMode.dark:
      return Icons.dark_mode_rounded;
  }
}
