import 'package:flutter/material.dart';

class _OpsPalette {
  final Color primary;
  final Color primaryPressed;
  final Color primaryBright;
  final Color darkSurface;
  final Color background;
  final Color surface;
  final Color surfaceElevated;
  final Color surfaceMuted;
  final Color surfaceGlass;
  final Color shadow;
  final Color parchment;
  final Color textInk;
  final Color textMuted;
  final Color border;
  final Color statusDraft;
  final Color statusSubmitted;
  final Color statusUnderReview;
  final Color statusRevision;
  final Color statusVerified;
  final Color statusApproved;
  final Color statusDanger;

  const _OpsPalette({
    required this.primary,
    required this.primaryPressed,
    required this.primaryBright,
    required this.darkSurface,
    required this.background,
    required this.surface,
    required this.surfaceElevated,
    required this.surfaceMuted,
    required this.surfaceGlass,
    required this.shadow,
    required this.parchment,
    required this.textInk,
    required this.textMuted,
    required this.border,
    required this.statusDraft,
    required this.statusSubmitted,
    required this.statusUnderReview,
    required this.statusRevision,
    required this.statusVerified,
    required this.statusApproved,
    required this.statusDanger,
  });
}

class AppTheme {
  AppTheme._();

  static bool reduceMotion = false;
  static _OpsPalette _active = _darkPalette;

  // Primary tetap kontras di kedua mode dan kompatibel dengan pemakaian lama.
  static const Color primary = Color(0xFF047857);

  static const _darkPalette = _OpsPalette(
    primary: primary,
    primaryPressed: Color(0xFF065F46),
    primaryBright: Color(0xFF38B98B),
    darkSurface: Color(0xFF202622),
    background: Color(0xFF0E1210),
    surface: Color(0xFF171B19),
    surfaceElevated: Color(0xFF202522),
    surfaceMuted: Color(0xFF2A302D),
    surfaceGlass: Color(0xE6171B19),
    shadow: Color(0x66000000),
    parchment: Color(0xFF222825),
    textInk: Color(0xFFF0F4F2),
    textMuted: Color(0xFFA9B5AF),
    border: Color(0xFF35403A),
    statusDraft: Color(0xFF94A3B8),
    statusSubmitted: Color(0xFF60A5FA),
    statusUnderReview: Color(0xFFA78BFA),
    statusRevision: Color(0xFFFBBF24),
    statusVerified: Color(0xFF22D3EE),
    statusApproved: Color(0xFF34D399),
    statusDanger: Color(0xFFF87171),
  );

  // Permukaan netral menjaga hijau sebagai warna aksi, bukan warna latar.
  static const _lightPalette = _OpsPalette(
    primary: primary,
    primaryPressed: Color(0xFF065F46),
    primaryBright: Color(0xFF0F8061),
    darkSurface: Color(0xFFE7ECE9),
    background: Color(0xFFF5F6F5),
    surface: Color(0xFFFFFFFF),
    surfaceElevated: Color(0xFFF0F2F1),
    surfaceMuted: Color(0xFFE5E9E7),
    surfaceGlass: Color(0xF2FFFFFF),
    shadow: Color(0x1F101714),
    parchment: Color(0xFFEEF1EF),
    textInk: Color(0xFF17201B),
    textMuted: Color(0xFF5D6B64),
    border: Color(0xFF7F8A84),
    statusDraft: Color(0xFF475569),
    statusSubmitted: Color(0xFF1D4ED8),
    statusUnderReview: Color(0xFF6D28D9),
    statusRevision: Color(0xFF704A00),
    statusVerified: Color(0xFF0E7490),
    statusApproved: Color(0xFF047857),
    statusDanger: Color(0xFFB42318),
  );

  static void configure({
    required ThemeMode mode,
    required Brightness systemBrightness,
  }) {
    final useLight =
        mode == ThemeMode.light ||
        (mode == ThemeMode.system && systemBrightness == Brightness.light);
    _active = useLight ? _lightPalette : _darkPalette;
  }

  static Color get primaryPressed => _active.primaryPressed;
  static Color get primaryBright => _active.primaryBright;
  static Color get darkSurface => _active.darkSurface;
  static Color get background => _active.background;
  static Color get surface => _active.surface;
  static Color get surfaceElevated => _active.surfaceElevated;
  static Color get surfaceMuted => _active.surfaceMuted;
  static Color get surfaceGlass => _active.surfaceGlass;
  static Color get shadow => _active.shadow;
  static Color get parchment => _active.parchment;
  static Color get textInk => _active.textInk;
  static Color get textMuted => _active.textMuted;
  static Color get border => _active.border;
  static Color get statusDraft => _active.statusDraft;
  static Color get statusSubmitted => _active.statusSubmitted;
  static Color get statusUnderReview => _active.statusUnderReview;
  static Color get statusRevision => _active.statusRevision;
  static Color get statusVerified => _active.statusVerified;
  static Color get statusApproved => _active.statusApproved;
  static Color get statusDanger => _active.statusDanger;

  static const Duration motionFast = Duration(milliseconds: 150);
  static const Duration motionStandard = Duration(milliseconds: 250);
  static const Duration motionRoute = Duration(milliseconds: 350);
  static const Curve motionEnter = Curves.easeOutCubic;
  static const Curve motionState = Curves.easeInOutCubic;

  static Duration motion(BuildContext context, Duration duration) {
    return reduceMotion || MediaQuery.disableAnimationsOf(context)
        ? Duration.zero
        : duration;
  }

  static const double spaceXs = 4;
  static const double spaceSm = 8;
  static const double spaceMd = 12;
  static const double spaceLg = 16;
  static const double spaceXl = 24;
  static const double space2xl = 32;
  static const double radiusSm = 10;
  static const double radiusMd = 14;
  static const double radiusLg = 20;
  static const double radiusXl = 28;

  static ThemeData get lightTheme =>
      _buildTheme(_lightPalette, Brightness.light);

  static ThemeData get darkTheme => _buildTheme(_darkPalette, Brightness.dark);

  static ThemeData _buildTheme(_OpsPalette p, Brightness brightness) {
    final isLight = brightness == Brightness.light;
    final colorScheme = ColorScheme.fromSeed(
      seedColor: p.primary,
      primary: p.primary,
      onPrimary: Colors.white,
      secondary: p.primaryBright,
      onSecondary: isLight ? Colors.white : p.darkSurface,
      surface: p.surface,
      onSurface: p.textInk,
      surfaceContainerHighest: p.surfaceElevated,
      onSurfaceVariant: p.textMuted,
      outline: p.border,
      error: p.statusDanger,
      onError: Colors.white,
      brightness: brightness,
    );

    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: p.background,
      canvasColor: p.background,
      fontFamily: 'Roboto',
      primaryColor: p.primary,
      colorScheme: colorScheme,
      brightness: brightness,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: p.background,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        foregroundColor: p.textInk,
        iconTheme: IconThemeData(color: p.textInk),
        titleTextStyle: TextStyle(
          color: p.textInk,
          fontSize: 18,
          fontWeight: FontWeight.w700,
        ),
      ),
      cardTheme: CardThemeData(
        color: p.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shadowColor: p.shadow,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
          side: BorderSide(color: p.border),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: p.primary,
          foregroundColor: Colors.white,
          disabledBackgroundColor: p.surfaceMuted,
          disabledForegroundColor: p.textMuted,
          elevation: 0,
          minimumSize: const Size(double.infinity, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(radiusLg),
          ),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
          animationDuration: motionStandard,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: p.primary,
          minimumSize: const Size(double.infinity, 50),
          side: BorderSide(color: p.border),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(radiusLg),
          ),
          animationDuration: motionStandard,
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: p.primaryBright,
        foregroundColor: isLight ? Colors.white : p.darkSurface,
        elevation: 6,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
        ),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: p.primaryBright,
        linearTrackColor: p.surfaceMuted,
        circularTrackColor: p.surfaceMuted,
      ),
      listTileTheme: ListTileThemeData(
        iconColor: p.textMuted,
        textColor: p.textInk,
        minLeadingWidth: 40,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: spaceLg,
          vertical: 4,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusMd),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: p.surfaceElevated,
        modalBackgroundColor: p.surfaceElevated,
        surfaceTintColor: Colors.transparent,
        showDragHandle: true,
        dragHandleColor: p.border,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(radiusXl)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: p.surfaceElevated,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 16,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: BorderSide(color: p.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: BorderSide(color: p.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: BorderSide(color: p.primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: BorderSide(color: p.statusDanger),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(radiusMd),
          borderSide: BorderSide(color: p.statusDanger, width: 2),
        ),
        labelStyle: TextStyle(color: p.textMuted),
        hintStyle: TextStyle(color: p.textMuted),
        prefixIconColor: p.textMuted,
        suffixIconColor: p.textMuted,
      ),
      textTheme: TextTheme(
        headlineMedium: TextStyle(
          fontSize: 24,
          fontWeight: FontWeight.w700,
          color: p.textInk,
          letterSpacing: -0.5,
        ),
        titleLarge: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: p.textInk,
        ),
        titleMedium: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w600,
          color: p.textInk,
        ),
        bodyMedium: TextStyle(fontSize: 14, color: p.textInk),
        bodySmall: TextStyle(fontSize: 12, color: p.textMuted),
        labelLarge: TextStyle(
          fontSize: 15,
          fontWeight: FontWeight.w600,
          color: p.textInk,
        ),
        labelMedium: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: p.textMuted,
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: p.surfaceElevated,
        selectedColor: p.primary.withValues(alpha: isLight ? 0.12 : 0.28),
        disabledColor: p.surfaceMuted,
        side: BorderSide(color: p.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        labelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: p.textInk,
        ),
        secondaryLabelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: p.primary,
        ),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: p.surface,
        elevation: 0,
        height: 72,
        indicatorColor: p.primary.withValues(alpha: isLight ? 0.12 : 0.24),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return TextStyle(
            fontSize: 12,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected ? p.primary : p.textMuted,
          );
        }),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(
            color: selected ? p.primary : p.textMuted,
            size: 24,
          );
        }),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: p.surface,
        elevation: 0,
        selectedItemColor: p.primary,
        unselectedItemColor: p.textMuted,
        selectedLabelStyle: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
        unselectedLabelStyle: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: p.surfaceElevated,
        elevation: 8,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusLg),
        ),
        titleTextStyle: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: p.textInk,
        ),
        contentTextStyle: TextStyle(
          fontSize: 14,
          height: 1.5,
          color: p.textInk,
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: p.surfaceElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radiusMd),
        ),
        contentTextStyle: TextStyle(
          fontSize: 14,
          color: isLight ? p.textInk : Colors.white,
        ),
        insetPadding: const EdgeInsets.all(spaceLg),
      ),
      dividerTheme: DividerThemeData(color: p.border, thickness: 1, space: 1),
      scrollbarTheme: ScrollbarThemeData(
        thumbColor: WidgetStatePropertyAll(p.primary.withValues(alpha: 0.55)),
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
    if (AppTheme.reduceMotion || MediaQuery.disableAnimationsOf(context)) {
      return child;
    }

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
