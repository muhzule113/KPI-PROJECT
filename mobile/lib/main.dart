import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'app/theme/app_theme.dart';
import 'app/widgets/kpi_ui.dart';
import 'core/auth/auth_provider.dart';
import 'core/settings/app_preferences.dart';
import 'features/auth/login_screen.dart';
import 'features/dashboard/dashboard_screen.dart';
import 'features/notifications/notification_screen.dart';
import 'core/notifications/push_notification_service.dart';

final navigatorKey = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await PushNotificationService.instance.initialize();
  PushNotificationService.instance.onOpen = (data) async {
    navigatorKey.currentState?.push(
      MaterialPageRoute(
        builder: (_) => NotificationScreen(
          initialNotificationId: data['notification_id']?.toString(),
        ),
      ),
    );
  };

  final authProvider = AuthProvider();
  await authProvider.init();
  final appPreferences = AppPreferences();
  await appPreferences.load();

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: authProvider),
        ChangeNotifierProvider.value(value: appPreferences),
      ],
      child: const KpiMobileApp(),
    ),
  );
}

class KpiMobileApp extends StatelessWidget {
  const KpiMobileApp({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final preferences = context.watch<AppPreferences>();
    AppTheme.configure(
      mode: preferences.themeMode,
      systemBrightness:
          WidgetsBinding.instance.platformDispatcher.platformBrightness,
    );
    AppTheme.reduceMotion = preferences.reduceMotion;

    return MaterialApp(
      navigatorKey: navigatorKey,
      title: 'Sistem KPI Toko HP',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: preferences.themeMode,
      scrollBehavior: const OpsScrollBehavior(),
      home: auth.isAuthenticated
          ? const DashboardScreen()
          : const LoginScreen(),
    );
  }
}
