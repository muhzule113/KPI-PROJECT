import 'package:flutter/material.dart';

class AppTheme {
  // Ops Noir design tokens: graphite surfaces + satu aksen emerald.
  // Primary tetap Emerald 700 agar teks putih memenuhi WCAG AA.
  static const Color primary = Color(
    0xFF047857,
  ); // Emerald 700 — kontras 5.48:1 vs putih
  static const Color primaryPressed = Color(
    0xFF065F46,
  ); // Emerald 800 — pressed/depth state
  static const Color primaryBright = Color(0xFF35D39E);
  static const Color primaryLime = Color(0xFFBEFF50); // signature accent
  static const Color darkSurface = Color(0xFF101A17);
  static const Color background = Color(0xFF07100E);
  static const Color surface = Color(0xFF111B18);
  static const Color surfaceElevated = Color(0xFF18241F);
  static const Color surfaceMuted = Color(0xFF20302A);
  static const Color surfaceGlass = Color(0xCC111B18);
  static const Color shadow = Color(0x66000000);
  static const Color parchment = Color(0xFF1B2924);
  static const Color textInk = Color(0xFFF1F7F3);
  static const Color textMuted = Color(0xFFA9BAB1);
  static const Color border = Color(0xFF2A3B34);

  // Status Colors
  static const Color statusDraft = Color(0xFF64748B);
  static const Color statusSubmitted = Color(0xFF3B82F6);
  static const Color statusUnderReview = Color(0xFF8B5CF6);
  static const Color statusRevision = Color(0xFFF59E0B);
  static const Color statusVerified = Color(0xFF06B6D4);
  static const Color statusApproved = Color(0xFF10B981);
  static const Color statusDanger = Color(0xFFEF4444);

  // Motion tokens — satu ritme untuk seluruh aplikasi.
  static const Duration motionFast = Duration(milliseconds: 150);
  static const Duration motionStandard = Duration(milliseconds: 250);
  static const Duration motionRoute = Duration(milliseconds: 350);
  static const Curve motionEnter = Curves.easeOutCubic;
  static const Curve motionState = Curves.easeInOutCubic;

  static Duration motion(BuildContext context, Duration duration) {
    return MediaQuery.disableAnimationsOf(context) ? Duration.zero : duration;
  }

  // Spacing tokens (8dp system)
  static const double spaceXs = 4;
  static const double spaceSm = 8;
  static const double spaceMd = 12;
  static const double spaceLg = 16;
  static const double spaceXl = 24;
  static const double space2xl = 32;

  // Radius tokens
  static const double radiusSm = 10;
  static const double radiusMd = 14;
  static const double radiusLg = 20;
  static const double radiusXl = 28;

  static ThemeData get lightTheme {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      onPrimary: Colors.white,
      secondary: primaryLime,
      surface: surface,
      onSurface: textInk,
      brightness: Brightness.dark,
    );

    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: background,
      fontFamily: 'Roboto',
      primaryColor: primary,
      colorScheme: colorScheme,
      brightness: Brightness.dark,
      visualDensity: VisualDensity.standard,
      appBarTheme: const AppBarTheme(
        backgroundColor: background,
        surfaceTintColor: Colors.transparent,
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
        margin: EdgeInsets.zero,
        shadowColor: shadow,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          side: const BorderSide(color: border, width: 1),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          disabledBackgroundColor: surfaceMuted,
          disabledForegroundColor: textMuted,
          elevation: 0,
          minimumSize: const Size(double.infinity, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(radiusLg),
          ),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
          animationDuration: motionStandard,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: primaryBright,
          minimumSize: const Size(double.infinity, 50),
          side: const BorderSide(color: border),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(radiusLg),
          ),
          animationDuration: motionStandard,
        ),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: primaryBright,
        foregroundColor: darkSurface,
        elevation: 6,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(radiusLg)),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: primaryBright,
        linearTrackColor: surfaceMuted,
        circularTrackColor: surfaceMuted,
      ),
      listTileTheme: const ListTileThemeData(
        iconColor: textMuted,
        textColor: textInk,
        minLeadingWidth: 40,
        contentPadding: EdgeInsets.symmetric(horizontal: spaceLg, vertical: 4),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(radiusMd)),
        ),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: surfaceElevated,
        modalBackgroundColor: surfaceElevated,
        surfaceTintColor: Colors.transparent,
        showDragHandle: true,
        dragHandleColor: border,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(radiusXl)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surfaceElevated,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 16,
        ),
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
        hintStyle: const TextStyle(color: textMuted),
        prefixIconColor: textMuted,
        suffixIconColor: textMuted,
      ),
      // Text scale konsisten: 12/14/16/18/24/32.
      textTheme: const TextTheme(
        headlineMedium: TextStyle(
          fontSize: 24,
          fontWeight: FontWeight.w800,
          color: textInk,
          letterSpacing: -0.5,
        ),
        titleLarge: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.bold,
          color: textInk,
        ),
        titleMedium: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w700,
          color: textInk,
        ),
        bodyMedium: TextStyle(fontSize: 14, color: textInk),
        bodySmall: TextStyle(fontSize: 12, color: textMuted),
        labelLarge: TextStyle(
          fontSize: 15,
          fontWeight: FontWeight.bold,
          color: textInk,
        ),
        labelMedium: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: textMuted,
        ),
      ),
      // Shared Material 3 components — berlaku untuk semua role/screen.
      chipTheme: ChipThemeData(
        backgroundColor: surfaceElevated,
        selectedColor: primary.withValues(alpha: 0.28),
        disabledColor: surfaceMuted,
        side: const BorderSide(color: border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        labelStyle: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: textInk,
        ),
        secondaryLabelStyle: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: primary,
        ),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        elevation: 0,
        height: 72,
        indicatorColor: primary.withValues(alpha: 0.24),
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
        elevation: 0,
        selectedItemColor: primary,
        unselectedItemColor: textMuted,
        selectedLabelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
        unselectedLabelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surfaceElevated,
        elevation: 8,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
        ),
        titleTextStyle: const TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: textInk,
        ),
        contentTextStyle: const TextStyle(
          fontSize: 14,
          height: 1.5,
          color: textInk,
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: surfaceElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusMd),
        ),
        contentTextStyle: const TextStyle(fontSize: 14, color: Colors.white),
        insetPadding: const EdgeInsets.all(spaceLg),
      ),
      dividerTheme: const DividerThemeData(
        color: border,
        thickness: 1,
        space: 1,
      ),
      scrollbarTheme: ScrollbarThemeData(
        thumbColor: WidgetStatePropertyAll(primary.withValues(alpha: 0.55)),
        radius: const Radius.circular(12),
        thickness: const WidgetStatePropertyAll(4),
      ),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: _OpsPageTransitionsBuilder(),
          TargetPlatform.iOS: _OpsPageTransitionsBuilder(),
          TargetPlatform.windows: _OpsPageTransitionsBuilder(),
          TargetPlatform.macOS: _OpsPageTransitionsBuilder(),
          TargetPlatform.linux: _OpsPageTransitionsBuilder(),
        },
      ),
      splashFactory: InkRipple.splashFactory,
    );
  }
}

class _OpsPageTransitionsBuilder extends PageTransitionsBuilder {
  const _OpsPageTransitionsBuilder();

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    if (MediaQuery.disableAnimationsOf(context)) return child;

    final curved = CurvedAnimation(
      parent: animation,
      curve: AppTheme.motionEnter,
    );
    return FadeTransition(
      opacity: curved,
      child: SlideTransition(
        position: Tween<Offset>(
          begin: const Offset(0, 0.035),
          end: Offset.zero,
        ).animate(curved),
        child: child,
      ),
    );
  }
}
