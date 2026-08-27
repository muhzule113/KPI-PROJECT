import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kpi_mobile/app/theme/app_theme.dart';
import 'package:kpi_mobile/core/settings/app_preferences.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  test('menyediakan theme terang dan gelap dengan brightness yang benar', () {
    expect(AppTheme.lightTheme.brightness, Brightness.light);
    expect(AppTheme.darkTheme.brightness, Brightness.dark);
  });

  test('memuat mode mengikuti sistem dari storage', () async {
    SharedPreferences.setMockInitialValues({'app_theme_mode': 'system'});

    final preferences = AppPreferences();
    await preferences.load();

    expect(preferences.themeMode, ThemeMode.system);
  });
}
