import 'dart:async';

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// Shared status badge: warna + ikon + label, bukan warna saja.
class OpsScrollBehavior extends MaterialScrollBehavior {
  const OpsScrollBehavior();

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) {
    return const BouncingScrollPhysics(parent: AlwaysScrollableScrollPhysics());
  }

  @override
  Widget buildOverscrollIndicator(
    BuildContext context,
    Widget child,
    ScrollableDetails details,
  ) {
    return child;
  }
}

class KpiStatusPill extends StatelessWidget {
  final String label;
  final Color color;
  final IconData icon;

  const KpiStatusPill({
    super.key,
    required this.label,
    required this.color,
    required this.icon,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: 32),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.26)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

class KpiSectionHeader extends StatelessWidget {
  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  const KpiSectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Expanded(
          child: Text(title, style: Theme.of(context).textTheme.titleMedium),
        ),
        if (actionLabel != null && onAction != null)
          TextButton(onPressed: onAction, child: Text(actionLabel!)),
      ],
    );
  }
}

/// Surface utama aplikasi. Bisa dipakai sebagai kartu statis atau tappable.
class OpsCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry margin;
  final VoidCallback? onTap;
  final Color? color;
  final Color? borderColor;
  final BorderRadiusGeometry? borderRadius;
  final bool emphasized;

  const OpsCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(AppTheme.spaceLg),
    this.margin = const EdgeInsets.only(bottom: AppTheme.spaceMd),
    this.onTap,
    this.color,
    this.borderColor,
    this.borderRadius,
    this.emphasized = false,
  });

  @override
  Widget build(BuildContext context) {
    final radius = borderRadius ?? BorderRadius.circular(AppTheme.radiusLg);
    final surfaceColor =
        color ?? (emphasized ? AppTheme.surfaceElevated : AppTheme.surface);
    final border =
        borderColor ??
        (emphasized
            ? AppTheme.primary.withValues(alpha: 0.36)
            : AppTheme.border);

    final content = Container(
      margin: margin,
      child: Material(
        color: surfaceColor,
        borderRadius: radius,
        clipBehavior: Clip.antiAlias,
        elevation: emphasized ? 4 : 1,
        shadowColor: emphasized
            ? AppTheme.primary.withValues(alpha: 0.18)
            : AppTheme.shadow,
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            borderRadius: radius,
            border: Border.all(color: border),
          ),
          child: child,
        ),
      ),
    );

    if (onTap == null) return content;

    return OpsTapScale(
      child: Material(
        color: Colors.transparent,
        borderRadius: radius is BorderRadius
            ? radius
            : BorderRadius.circular(AppTheme.radiusLg),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          borderRadius: radius is BorderRadius
              ? radius
              : BorderRadius.circular(AppTheme.radiusLg),
          splashColor: AppTheme.primary.withValues(alpha: 0.12),
          highlightColor: AppTheme.primary.withValues(alpha: 0.05),
          child: content,
        ),
      ),
    );
  }
}

/// Tactile press feedback tanpa mengubah layout di sekitarnya.
class OpsTapScale extends StatefulWidget {
  final Widget child;
  final double pressedScale;

  const OpsTapScale({
    super.key,
    required this.child,
    this.pressedScale = 0.985,
  });

  @override
  State<OpsTapScale> createState() => _OpsTapScaleState();
}

class _OpsTapScaleState extends State<OpsTapScale> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final scale = _pressed && !MediaQuery.disableAnimationsOf(context)
        ? widget.pressedScale
        : 1.0;

    return Listener(
      onPointerDown: (_) => setState(() => _pressed = true),
      onPointerUp: (_) => setState(() => _pressed = false),
      onPointerCancel: (_) => setState(() => _pressed = false),
      child: AnimatedScale(
        scale: scale,
        duration: AppTheme.motion(context, AppTheme.motionFast),
        curve: AppTheme.motionState,
        child: widget.child,
      ),
    );
  }
}

/// Reveal entrance yang ringan: fade + translate, dengan stagger opsional.
class OpsReveal extends StatefulWidget {
  final Widget child;
  final Duration delay;
  final Duration duration;
  final Offset beginOffset;

  const OpsReveal({
    super.key,
    required this.child,
    this.delay = Duration.zero,
    this.duration = AppTheme.motionStandard,
    this.beginOffset = const Offset(0, 0.04),
  });

  @override
  State<OpsReveal> createState() => _OpsRevealState();
}

class _OpsRevealState extends State<OpsReveal>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _opacity;
  late final Animation<Offset> _offset;
  Timer? _delayTimer;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: widget.duration);
    final curve = CurvedAnimation(
      parent: _controller,
      curve: AppTheme.motionEnter,
    );
    _opacity = Tween<double>(begin: 0, end: 1).animate(curve);
    _offset = Tween<Offset>(
      begin: widget.beginOffset,
      end: Offset.zero,
    ).animate(curve);
    WidgetsBinding.instance.addPostFrameCallback((_) => _scheduleStart());
  }

  void _scheduleStart() {
    if (!mounted) return;
    if (MediaQuery.disableAnimationsOf(context)) {
      _controller.value = 1;
      return;
    }
    if (widget.delay <= Duration.zero) {
      _startNow();
      return;
    }
    _delayTimer = Timer(widget.delay, _startNow);
  }

  void _startNow() {
    if (!mounted) return;
    if (MediaQuery.disableAnimationsOf(context)) {
      _controller.value = 1;
      return;
    }
    _controller.forward();
  }

  @override
  void dispose() {
    _delayTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _opacity,
      child: SlideTransition(position: _offset, child: widget.child),
    );
  }
}

class KpiMetricTile extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final IconData? icon;

  const KpiMetricTile({
    super.key,
    required this.label,
    required this.value,
    required this.color,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: OpsCard(
        padding: const EdgeInsets.all(AppTheme.spaceMd),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (icon != null)
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(icon, size: 17, color: color),
              ),
            const Spacer(),
            Text(
              value,
              style: TextStyle(
                fontSize: 21,
                fontWeight: FontWeight.w800,
                color: color,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: const TextStyle(fontSize: 12, color: AppTheme.textMuted),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}

class KpiProgressBar extends StatelessWidget {
  final double value;
  final String? label;
  final Color color;

  const KpiProgressBar({
    super.key,
    required this.value,
    this.label,
    this.color = AppTheme.primaryBright,
  });

  @override
  Widget build(BuildContext context) {
    final clamped = value.clamp(0.0, 1.0);
    final duration = AppTheme.motion(context, AppTheme.motionStandard);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(AppTheme.radiusSm),
          child: Stack(
            children: [
              Container(height: 9, color: AppTheme.surfaceMuted),
              AnimatedFractionallySizedBox(
                duration: duration,
                curve: AppTheme.motionEnter,
                widthFactor: clamped,
                child: Container(
                  height: 9,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [color, color.withValues(alpha: 0.62)],
                    ),
                    borderRadius: BorderRadius.circular(AppTheme.radiusSm),
                  ),
                ),
              ),
            ],
          ),
        ),
        if (label != null) ...[
          const SizedBox(height: 7),
          Text(
            label!,
            style: const TextStyle(
              fontSize: 12,
              color: AppTheme.textMuted,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ],
    );
  }
}

class KpiEmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  const KpiEmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppTheme.space2xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                color: AppTheme.surfaceElevated,
                borderRadius: BorderRadius.circular(24),
                border: Border.all(color: AppTheme.border),
              ),
              child: Icon(icon, size: 32, color: AppTheme.primaryBright),
            ),
            const SizedBox(height: AppTheme.spaceLg),
            Text(
              title,
              style: Theme.of(context).textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppTheme.spaceSm),
            Text(
              message,
              style: Theme.of(
                context,
              ).textTheme.bodyMedium?.copyWith(color: AppTheme.textMuted),
              textAlign: TextAlign.center,
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppTheme.spaceXl),
              OutlinedButton.icon(
                onPressed: onAction,
                icon: const Icon(Icons.refresh_rounded),
                label: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Loading state yang mempertahankan bentuk konten agar scroll tidak meloncat.
class OpsScreenLoading extends StatelessWidget {
  final int rows;

  const OpsScreenLoading({super.key, this.rows = 3});

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppTheme.spaceXl),
      children: [
        const OpsSkeleton(height: 28, width: 190),
        const SizedBox(height: AppTheme.spaceSm),
        const OpsSkeleton(height: 14, width: 250),
        const SizedBox(height: AppTheme.spaceXl),
        for (var i = 0; i < rows; i++) ...[
          const OpsSkeleton(height: 112),
          if (i != rows - 1) const SizedBox(height: AppTheme.spaceMd),
        ],
      ],
    );
  }
}

/// Header internal page dengan eyebrow kecil dan hierarchy yang jelas.
class OpsPageHeader extends StatelessWidget {
  final String eyebrow;
  final String title;
  final String? subtitle;
  final Widget? trailing;

  const OpsPageHeader({
    super.key,
    required this.eyebrow,
    required this.title,
    this.subtitle,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                eyebrow.toUpperCase(),
                style: const TextStyle(
                  color: AppTheme.primaryBright,
                  fontSize: 10,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1.25,
                ),
              ),
              const SizedBox(height: 5),
              Text(title, style: Theme.of(context).textTheme.titleLarge),
              if (subtitle != null) ...[
                const SizedBox(height: 4),
                Text(
                  subtitle!,
                  style: const TextStyle(
                    color: AppTheme.textMuted,
                    fontSize: 13,
                    height: 1.4,
                  ),
                ),
              ],
            ],
          ),
        ),
        trailing ?? const SizedBox.shrink(),
      ],
    );
  }
}

/// Field group yang otomatis berubah menjadi kolom di layar sempit.
/// Menjaga form tetap mudah dibaca tanpa mengubah field atau validasinya.
class OpsAdaptiveFieldRow extends StatelessWidget {
  final List<Widget> children;
  final double breakpoint;

  const OpsAdaptiveFieldRow({
    super.key,
    required this.children,
    this.breakpoint = 460,
  });

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= breakpoint;
        if (isWide) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children:
                children
                    .map(
                      (child) => Expanded(
                        child: Padding(
                          padding: const EdgeInsets.only(
                            right: AppTheme.spaceMd,
                          ),
                          child: child,
                        ),
                      ),
                    )
                    .toList()
                  ..last = Expanded(child: children.last),
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children:
              children
                  .expand(
                    (child) => [
                      child,
                      const SizedBox(height: AppTheme.spaceMd),
                    ],
                  )
                  .toList()
                ..removeLast(),
        );
      },
    );
  }
}

/// Frame form premium yang terinspirasi pola Finvoice: handle, eyebrow,
/// headline editorial, body scrollable, dan footer CTA yang aman terhadap IME.
class OpsFormSheet extends StatelessWidget {
  final String eyebrow;
  final String title;
  final String? subtitle;
  final Widget child;
  final Widget? footer;

  const OpsFormSheet({
    super.key,
    required this.eyebrow,
    required this.title,
    required this.child,
    this.subtitle,
    this.footer,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppTheme.surfaceElevated,
      borderRadius: const BorderRadius.vertical(
        top: Radius.circular(AppTheme.radiusXl),
      ),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SafeArea(
          top: false,
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.sizeOf(context).height * 0.88,
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                AppTheme.spaceXl,
                AppTheme.spaceSm,
                AppTheme.spaceXl,
                AppTheme.spaceLg,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 38,
                      height: 4,
                      decoration: BoxDecoration(
                        color: AppTheme.border,
                        borderRadius: BorderRadius.circular(99),
                      ),
                    ),
                  ),
                  const SizedBox(height: AppTheme.spaceLg),
                  Text(
                    eyebrow.toUpperCase(),
                    style: const TextStyle(
                      color: AppTheme.primaryBright,
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.25,
                    ),
                  ),
                  const SizedBox(height: AppTheme.spaceSm),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: Theme.of(context).textTheme.headlineMedium,
                        ),
                      ),
                      IconButton(
                        tooltip: 'Tutup',
                        onPressed: () => Navigator.of(context).pop(false),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: AppTheme.spaceXs),
                    Text(
                      subtitle!,
                      style: const TextStyle(
                        color: AppTheme.textMuted,
                        fontSize: 13,
                        height: 1.45,
                      ),
                    ),
                  ],
                  const SizedBox(height: AppTheme.spaceXl),
                  Flexible(
                    child: SingleChildScrollView(
                      physics: const BouncingScrollPhysics(),
                      child: child,
                    ),
                  ),
                  if (footer != null) ...[
                    const SizedBox(height: AppTheme.spaceLg),
                    footer!,
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Form teks satu langkah untuk catatan, alasan, atau keterangan operasional.
/// Mengembalikan null saat dibatalkan dan teks apa adanya saat disimpan.
Future<String?> showOpsTextInputSheet({
  required BuildContext context,
  required String eyebrow,
  required String title,
  required String label,
  required String actionLabel,
  String? subtitle,
  String? hintText,
  String? helperText,
  int maxLines = 1,
  Color? actionColor,
}) async {
  final controller = TextEditingController();
  final result = await showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (sheetContext) => OpsFormSheet(
      eyebrow: eyebrow,
      title: title,
      subtitle: subtitle,
      footer: ElevatedButton.icon(
        onPressed: () => Navigator.of(sheetContext).pop(controller.text),
        icon: const Icon(Icons.check_rounded),
        label: Text(actionLabel),
        style: ElevatedButton.styleFrom(
          backgroundColor: actionColor ?? AppTheme.primary,
        ),
      ),
      child: TextField(
        controller: controller,
        autofocus: false,
        maxLines: maxLines,
        textInputAction: maxLines == 1
            ? TextInputAction.done
            : TextInputAction.newline,
        decoration: InputDecoration(
          labelText: label,
          hintText: hintText,
          helperText: helperText,
          alignLabelWithHint: maxLines > 1,
        ),
      ),
    ),
  );
  controller.dispose();
  return result;
}

class KpiQuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color color;

  const KpiQuickAction({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.color = AppTheme.primaryBright,
  });

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: OpsCard(
        onTap: onTap,
        padding: const EdgeInsets.symmetric(
          horizontal: AppTheme.spaceMd,
          vertical: AppTheme.spaceLg,
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(13),
              ),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(height: AppTheme.spaceSm),
            Text(
              label,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: AppTheme.textInk,
              ),
              maxLines: 2,
            ),
          ],
        ),
      ),
    );
  }
}

class OpsSelectionOption<T> {
  final T value;
  final String label;
  final String? supportingText;
  final IconData? icon;
  final Color? color;

  const OpsSelectionOption({
    required this.value,
    required this.label,
    this.supportingText,
    this.icon,
    this.color,
  });
}

/// Selector reusable untuk input mobile. Selalu membuka modal bottom sheet,
/// sehingga dropdown tidak lagi bergantung pada popup kecil di viewport.
class OpsSelectionField<T> extends StatelessWidget {
  final String label;
  final String? hint;
  final T? value;
  final List<OpsSelectionOption<T>> options;
  final ValueChanged<T> onChanged;
  final String? sheetTitle;
  final bool searchable;
  final bool enabled;

  const OpsSelectionField({
    super.key,
    required this.label,
    required this.options,
    required this.onChanged,
    this.value,
    this.hint,
    this.sheetTitle,
    this.searchable = false,
    this.enabled = true,
  });

  @override
  Widget build(BuildContext context) {
    final selected = options
        .where((option) => option.value == value)
        .firstOrNull;
    return Semantics(
      button: true,
      enabled: enabled,
      label: selected == null
          ? '$label, ${hint ?? 'belum dipilih'}'
          : '$label, ${selected.label}',
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppTheme.radiusMd),
          onTap: enabled
              ? () async {
                  final picked = await showOpsSelectionSheet<T>(
                    context: context,
                    title: sheetTitle ?? label,
                    options: options,
                    selectedValue: value,
                    searchable: searchable,
                  );
                  if (picked != null) onChanged(picked);
                }
              : null,
          child: InputDecorator(
            decoration: InputDecoration(
              labelText: label,
              enabled: enabled,
              suffixIcon: const Icon(Icons.keyboard_arrow_down_rounded),
            ),
            child: Row(
              children: [
                if (selected?.icon != null) ...[
                  Icon(
                    selected!.icon,
                    size: 20,
                    color: selected.color ?? AppTheme.primaryBright,
                  ),
                  const SizedBox(width: AppTheme.spaceSm),
                ],
                Expanded(
                  child: Text(
                    selected?.label ?? (hint ?? 'Pilih $label'),
                    style: TextStyle(
                      color: selected == null
                          ? AppTheme.textMuted
                          : AppTheme.textInk,
                      fontSize: 14,
                      fontWeight: selected == null
                          ? FontWeight.w400
                          : FontWeight.w600,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

Future<T?> showOpsSelectionSheet<T>({
  required BuildContext context,
  required String title,
  required List<OpsSelectionOption<T>> options,
  T? selectedValue,
  bool searchable = false,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (sheetContext) => _OpsSelectionSheet<T>(
      title: title,
      options: options,
      selectedValue: selectedValue,
      searchable: searchable,
    ),
  );
}

class _OpsSelectionSheet<T> extends StatefulWidget {
  final String title;
  final List<OpsSelectionOption<T>> options;
  final T? selectedValue;
  final bool searchable;

  const _OpsSelectionSheet({
    required this.title,
    required this.options,
    required this.selectedValue,
    required this.searchable,
  });

  @override
  State<_OpsSelectionSheet<T>> createState() => _OpsSelectionSheetState<T>();
}

class _OpsSelectionSheetState<T> extends State<_OpsSelectionSheet<T>> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered = widget.options.where((option) {
      return option.label.toLowerCase().contains(_query.toLowerCase()) ||
          (option.supportingText?.toLowerCase().contains(
                _query.toLowerCase(),
              ) ??
              false);
    }).toList();

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.78,
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            AppTheme.spaceXl,
            AppTheme.spaceSm,
            AppTheme.spaceXl,
            AppTheme.spaceLg,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      widget.title,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  IconButton(
                    tooltip: 'Tutup',
                    onPressed: () => Navigator.pop(context),
                    icon: const Icon(Icons.close_rounded),
                  ),
                ],
              ),
              if (widget.searchable) ...[
                const SizedBox(height: AppTheme.spaceSm),
                TextField(
                  autofocus: false,
                  onChanged: (value) => setState(() => _query = value),
                  decoration: const InputDecoration(
                    labelText: 'Cari pilihan',
                    prefixIcon: Icon(Icons.search_rounded),
                  ),
                ),
              ],
              const SizedBox(height: AppTheme.spaceSm),
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: filtered.length,
                  separatorBuilder: (_, _) =>
                      const SizedBox(height: AppTheme.spaceSm),
                  itemBuilder: (context, index) {
                    final option = filtered[index];
                    final selected = option.value == widget.selectedValue;
                    return OpsTapScale(
                      child: Material(
                        color: selected
                            ? AppTheme.primary.withValues(alpha: 0.14)
                            : AppTheme.surface,
                        borderRadius: BorderRadius.circular(AppTheme.radiusMd),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(
                            AppTheme.radiusMd,
                          ),
                          onTap: () => Navigator.pop(context, option.value),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: AppTheme.spaceMd,
                              vertical: AppTheme.spaceMd,
                            ),
                            child: Row(
                              children: [
                                if (option.icon != null) ...[
                                  Icon(
                                    option.icon,
                                    color:
                                        option.color ?? AppTheme.primaryBright,
                                  ),
                                  const SizedBox(width: AppTheme.spaceMd),
                                ],
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        option.label,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                          color: AppTheme.textInk,
                                        ),
                                      ),
                                      if (option.supportingText != null) ...[
                                        const SizedBox(height: 3),
                                        Text(
                                          option.supportingText!,
                                          style: const TextStyle(
                                            fontSize: 12,
                                            color: AppTheme.textMuted,
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                if (selected)
                                  const Icon(
                                    Icons.check_circle_rounded,
                                    color: AppTheme.primaryBright,
                                  ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Bottom navigation premium dengan surface floating dan safe-area.
class OpsBottomNavigationBar extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onTap;
  final List<NavigationDestination> destinations;

  const OpsBottomNavigationBar({
    super.key,
    required this.currentIndex,
    required this.onTap,
    required this.destinations,
  });

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      minimum: const EdgeInsets.fromLTRB(12, 0, 12, 8),
      child: Material(
        color: AppTheme.surface,
        elevation: 10,
        shadowColor: AppTheme.shadow,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppTheme.radiusXl),
          side: const BorderSide(color: AppTheme.border),
        ),
        clipBehavior: Clip.antiAlias,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(AppTheme.radiusXl),
          child: NavigationBar(
            backgroundColor: Colors.transparent,
            surfaceTintColor: Colors.transparent,
            shadowColor: Colors.transparent,
            selectedIndex: currentIndex,
            onDestinationSelected: onTap,
            destinations: destinations,
          ),
        ),
      ),
    );
  }
}

class OpsHeroCard extends StatelessWidget {
  final Widget child;
  final Color accent;
  final EdgeInsetsGeometry padding;

  const OpsHeroCard({
    super.key,
    required this.child,
    this.accent = AppTheme.primaryBright,
    this.padding = const EdgeInsets.all(AppTheme.spaceXl),
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [AppTheme.surfaceElevated, AppTheme.darkSurface],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(AppTheme.radiusXl),
        border: Border.all(color: accent.withValues(alpha: 0.32)),
        boxShadow: [
          BoxShadow(
            color: accent.withValues(alpha: 0.09),
            blurRadius: 30,
            offset: const Offset(0, 16),
          ),
        ],
      ),
      child: Stack(
        children: [
          Positioned(
            right: -42,
            top: -46,
            child: Container(
              width: 150,
              height: 150,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: accent.withValues(alpha: 0.1),
              ),
            ),
          ),
          child,
        ],
      ),
    );
  }
}

class OpsSkeleton extends StatefulWidget {
  final double height;
  final double? width;
  final BorderRadiusGeometry borderRadius;

  const OpsSkeleton({
    super.key,
    required this.height,
    this.width,
    this.borderRadius = const BorderRadius.all(
      Radius.circular(AppTheme.radiusMd),
    ),
  });

  @override
  State<OpsSkeleton> createState() => _OpsSkeletonState();
}

class _OpsSkeletonState extends State<OpsSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.disableAnimationsOf(context)) {
      return Container(
        height: widget.height,
        width: widget.width,
        decoration: BoxDecoration(
          color: AppTheme.surfaceElevated,
          borderRadius: widget.borderRadius,
        ),
      );
    }

    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) => Container(
        height: widget.height,
        width: widget.width,
        decoration: BoxDecoration(
          borderRadius: widget.borderRadius,
          gradient: LinearGradient(
            begin: Alignment(-1 + (_controller.value * 2), 0),
            end: Alignment(0.2 + (_controller.value * 2), 0),
            colors: const [
              AppTheme.surfaceElevated,
              AppTheme.surfaceMuted,
              AppTheme.surfaceElevated,
            ],
          ),
        ),
      ),
    );
  }
}

class AnimatedFractionallySizedBox extends ImplicitlyAnimatedWidget {
  final double widthFactor;
  final Widget child;

  const AnimatedFractionallySizedBox({
    super.key,
    required this.widthFactor,
    required this.child,
    required super.duration,
    required super.curve,
  });

  @override
  ImplicitlyAnimatedWidgetState<AnimatedFractionallySizedBox> createState() =>
      _AnimatedFractionallySizedBoxState();
}

class _AnimatedFractionallySizedBoxState
    extends AnimatedWidgetBaseState<AnimatedFractionallySizedBox> {
  Tween<double>? _width;

  @override
  void forEachTween(TweenVisitor<dynamic> visitor) {
    _width =
        visitor(
              _width,
              widget.widthFactor,
              (value) => Tween<double>(begin: value as double),
            )
            as Tween<double>?;
  }

  @override
  Widget build(BuildContext context) {
    return FractionallySizedBox(
      widthFactor: _width?.evaluate(animation),
      child: widget.child,
    );
  }
}

Map<String, dynamic> kpiStatusPresentation(String status) {
  switch (status) {
    case 'submitted':
      return {
        'label': 'Menunggu review',
        'color': AppTheme.statusSubmitted,
        'icon': Icons.schedule_rounded,
      };
    case 'under_review':
      return {
        'label': 'Sedang direview',
        'color': AppTheme.statusUnderReview,
        'icon': Icons.rate_review_rounded,
      };
    case 'revision_required':
      return {
        'label': 'Perlu revisi',
        'color': AppTheme.statusRevision,
        'icon': Icons.edit_note_rounded,
      };
    case 'verified':
      return {
        'label': 'Terverifikasi',
        'color': AppTheme.statusVerified,
        'icon': Icons.verified_rounded,
      };
    case 'approved':
      return {
        'label': 'Disetujui',
        'color': AppTheme.statusApproved,
        'icon': Icons.check_circle_rounded,
      };
    case 'locked':
      return {
        'label': 'Terkunci',
        'color': AppTheme.statusApproved,
        'icon': Icons.lock_rounded,
      };
    default:
      return {
        'label': 'Draft',
        'color': AppTheme.statusDraft,
        'icon': Icons.edit_note_rounded,
      };
  }
}
