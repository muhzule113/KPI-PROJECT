import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kpi_mobile/app/theme/app_theme.dart';
import 'package:kpi_mobile/core/settings/app_preferences.dart';
import 'package:shared_preferences/shared_preferences.dart';

double _contrastRatio(Color foreground, Color background) {
  final foregroundLuminance = foreground.computeLuminance();
  final backgroundLuminance = background.computeLuminance();
  final lighter = foregroundLuminance > backgroundLuminance
      ? foregroundLuminance
      : backgroundLuminance;
  final darker = foregroundLuminance > backgroundLuminance
      ? backgroundLuminance
      : foregroundLuminance;
  return (lighter + 0.05) / (darker + 0.05);
}

void main() {
  tearDown(() {
    AppTheme.configure(mode: ThemeMode.dark, systemBrightness: Brightness.dark);
  });

  test('menyediakan theme terang dan gelap dengan brightness yang benar', () {
    expect(AppTheme.lightTheme.brightness, Brightness.light);
    expect(AppTheme.darkTheme.brightness, Brightness.dark);
    expect(AppTheme.lightTheme.colorScheme.onSurface, const Color(0xFF10231A));
    expect(AppTheme.darkTheme.colorScheme.onSurface, const Color(0xFFF1F7F3));
  });

  test('palette legacy mengikuti mode terang dan menjaga kontras teks', () {
    AppTheme.configure(
      mode: ThemeMode.light,
      systemBrightness: Brightness.dark,
    );

    expect(AppTheme.background, const Color(0xFFF4F8F5));
    expect(AppTheme.surface, Colors.white);
    expect(AppTheme.textInk, const Color(0xFF10231A));
    expect(AppTheme.textMuted, const Color(0xFF355247));
    expect(
      _contrastRatio(AppTheme.textInk, AppTheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrastRatio(AppTheme.textMuted, AppTheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrastRatio(AppTheme.primary, AppTheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrastRatio(AppTheme.border, AppTheme.surface),
      greaterThanOrEqualTo(3.0),
    );
    expect(
      _contrastRatio(
        AppTheme.lightTheme.colorScheme.onSecondary,
        AppTheme.lightTheme.colorScheme.secondary,
      ),
      greaterThanOrEqualTo(4.5),
    );
  });

  test('status color light mode terbaca di atas surface', () {
    AppTheme.configure(
      mode: ThemeMode.light,
      systemBrightness: Brightness.light,
    );

    final statusColors = [
      AppTheme.statusDraft,
      AppTheme.statusSubmitted,
      AppTheme.statusUnderReview,
      AppTheme.statusRevision,
      AppTheme.statusVerified,
      AppTheme.statusApproved,
      AppTheme.statusDanger,
    ];

    for (final color in statusColors) {
      expect(
        _contrastRatio(color, AppTheme.surface),
        greaterThanOrEqualTo(4.5),
      );
    }
  });

  test('mode sistem memilih palette sesuai brightness perangkat', () {
    AppTheme.configure(
      mode: ThemeMode.system,
      systemBrightness: Brightness.light,
    );
    expect(AppTheme.textInk, const Color(0xFF10231A));

    AppTheme.configure(
      mode: ThemeMode.system,
      systemBrightness: Brightness.dark,
    );
    expect(AppTheme.textInk, const Color(0xFFF1F7F3));
  });

  test('dark Ops Noir tetap memakai token gelap', () {
    AppTheme.configure(
      mode: ThemeMode.dark,
      systemBrightness: Brightness.light,
    );

    expect(AppTheme.background, const Color(0xFF07100E));
    expect(AppTheme.surface, const Color(0xFF111B18));
    expect(AppTheme.textInk, const Color(0xFFF1F7F3));
  });

  test('memuat mode mengikuti sistem dari storage', () async {
    SharedPreferences.setMockInitialValues({'app_theme_mode': 'system'});

    final preferences = AppPreferences();
    await preferences.load();

    expect(preferences.themeMode, ThemeMode.system);
  });
}
