import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kpi_mobile/core/settings/app_preferences.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('menyimpan mode tema dan preferensi animasi secara lokal', () async {
    final preferences = AppPreferences();

    await preferences.setThemeMode(ThemeMode.light);
    await preferences.setReduceMotion(true);

    final stored = await SharedPreferences.getInstance();
    expect(stored.getString('app_theme_mode'), 'light');
    expect(stored.getBool('app_reduce_motion'), isTrue);
    expect(preferences.themeMode, ThemeMode.light);
    expect(preferences.reduceMotion, isTrue);
  });

  test('memuat dan mereset preferensi tampilan', () async {
    SharedPreferences.setMockInitialValues({
      'app_theme_mode': 'light',
      'app_reduce_motion': true,
    });

    final preferences = AppPreferences();
    await preferences.load();

    expect(preferences.themeMode, ThemeMode.light);
    expect(preferences.reduceMotion, isTrue);

    await preferences.reset();

    expect(preferences.themeMode, ThemeMode.dark);
    expect(preferences.reduceMotion, isFalse);
    final stored = await SharedPreferences.getInstance();
    expect(stored.containsKey('app_theme_mode'), isFalse);
    expect(stored.containsKey('app_reduce_motion'), isFalse);
  });
}
