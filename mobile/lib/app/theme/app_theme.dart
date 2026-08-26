import 'package:flutter/material.dart';

class AppTheme {
  // Brand Design Tokens (PRD Bab 17)
  // Primary di-shade ke Emerald 700 (#047857) agar kontras teks putih >= 4.5:1 (WCAG AA).
  static const Color primary = Color(0xFF047857); // Emerald 700 — kontras 5.48:1 vs putih
  static const Color primaryPressed = Color(0xFF065F46); // Emerald 800 — pressed/depth state
  static const Color primaryLime = Color(0xFFBEFF50); // Lime Accent — signature look
  static const Color darkSurface = Color(0xFF30302A);
  static const Color background = Color(0xFFF8FAFC);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color parchment = Color(0xFFF5F5EB);
  static const Color textInk = Color(0xFF14140F);
  static const Color textMuted = Color(0xFF6E6E64);
  static const Color border = Color(0xFFE2E8F0);

  // Status Colors
  static const Color statusDraft = Color(0xFF64748B);
  static const Color statusSubmitted = Color(0xFF3B82F6);
  static const Color statusUnderReview = Color(0xFF8B5CF6);
  static const Color statusRevision = Color(0xFFF59E0B);
  static const Color statusVerified = Color(0xFF06B6D4);
  static const Color statusApproved = Color(0xFF059669); // Emerald 600 — beda dari primary
  static const Color statusDanger = Color(0xFFEF4444);

  // Spacing tokens (8dp system)
  static const double spaceXs = 4;
  static const double spaceSm = 8;
  static const double spaceMd = 12;
  static const double spaceLg = 16;
  static const double spaceXl = 24;
  static const double space2xl = 32;

  // Radius tokens
  static const double radiusSm = 8;
  static const double radiusMd = 12;
  static const double radiusLg = 16;

  static ThemeData get lightTheme {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      onPrimary: Colors.white,
      secondary: primaryLime,
      surface: surface,
      brightness: Brightness.light,
    );

    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: background,
      primaryColor: primary,
      colorScheme: colorScheme,
      // Hapus fontFamily 'Inter' yang tidak ter-bundle — pakai font platform native
      // (Roboto di Android, SF Pro di iOS) supaya rendering konsisten di semua device.
      appBarTheme: const AppBarTheme(
        backgroundColor: surface,
        elevation: 0,
        centerTitle: false,
        iconTheme: IconThemeData(color: textInk),
        titleTextStyle: TextStyle(
          color: textInk,
          fontSize: 18,
          fontWeight: FontWeight.bold,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          side: const BorderSide(color: border, width: 1),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          disabledBackgroundColor: primary.withValues(alpha: 0.5),
          disabledForegroundColor: Colors.white.withValues(alpha: 0.8),
          elevation: 0,
          minimumSize: const Size(double.infinity, 50),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(radiusMd),
          ),
          textStyle: const TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.bold,
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: const BorderSide(color: border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: const BorderSide(color: border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: const BorderSide(color: primary, width: 2),
        ),
        labelStyle: const TextStyle(color: textMuted),
      ),
      // Text scale konsisten: 12/14/16/18/24/32
      textTheme: const TextTheme(
        headlineMedium: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: textInk, letterSpacing: -0.5),
        titleLarge: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: textInk),
        titleMedium: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: textInk),
        bodyMedium: TextStyle(fontSize: 14, color: textInk),
        bodySmall: TextStyle(fontSize: 12, color: textMuted),
        labelLarge: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: textInk),
        labelMedium: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: textMuted),
      ),
      // Shared Material 3 components — berlaku untuk semua role/screen.
      chipTheme: ChipThemeData(
        backgroundColor: surface,
        selectedColor: primary.withValues(alpha: 0.12),
        disabledColor: border.withValues(alpha: 0.5),
        side: const BorderSide(color: border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        labelStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: textInk),
        secondaryLabelStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: primary),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        elevation: 3,
        height: 72,
        indicatorColor: primary.withValues(alpha: 0.12),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return TextStyle(
            fontSize: 12,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected ? primary : textMuted,
          );
        }),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(color: selected ? primary : textMuted, size: 24);
        }),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: surface,
        elevation: 3,
        selectedItemColor: primary,
        unselectedItemColor: textMuted,
        selectedLabelStyle: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
        unselectedLabelStyle: TextStyle(fontSize: 12, fontWeight: FontWeight.w500),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surface,
        elevation: 8,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusLg)),
        titleTextStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: textInk),
        contentTextStyle: const TextStyle(fontSize: 14, height: 1.5, color: textInk),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radiusMd)),
        contentTextStyle: const TextStyle(fontSize: 14, color: Colors.white),
        insetPadding: const EdgeInsets.all(spaceLg),
      ),
      dividerTheme: const DividerThemeData(color: border, thickness: 1, space: 1),
      splashFactory: InkRipple.splashFactory,
    );
  }
}
