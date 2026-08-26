import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// Shared status badge: icon + text, never color-only.
class KpiStatusPill extends StatelessWidget {
  final String label;
  final Color color;
  final IconData icon;

  const KpiStatusPill({super.key, required this.label, required this.color, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(AppTheme.radiusSm),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 6),
          Text(label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: color)),
        ],
      ),
    );
  }
}

class KpiSectionHeader extends StatelessWidget {
  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  const KpiSectionHeader({super.key, required this.title, this.actionLabel, this.onAction});

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

class KpiMetricTile extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final IconData? icon;

  const KpiMetricTile({super.key, required this.label, required this.value, required this.color, this.icon});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        constraints: const BoxConstraints(minHeight: 92),
        padding: const EdgeInsets.all(AppTheme.spaceMd),
        decoration: BoxDecoration(
          color: AppTheme.surface,
          borderRadius: BorderRadius.circular(AppTheme.radiusMd),
          border: Border.all(color: AppTheme.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (icon != null) Icon(icon, size: 18, color: color),
            const Spacer(),
            Text(value, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color), maxLines: 1, overflow: TextOverflow.ellipsis),
            const SizedBox(height: 4),
            Text(label, style: const TextStyle(fontSize: 12, color: AppTheme.textMuted), maxLines: 2, overflow: TextOverflow.ellipsis),
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

  const KpiProgressBar({super.key, required this.value, this.label, this.color = AppTheme.primary});

  @override
  Widget build(BuildContext context) {
    final clamped = value.clamp(0.0, 1.0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(AppTheme.radiusSm),
          child: Stack(
            children: [
              Container(height: 9, color: AppTheme.border),
              AnimatedFractionallySizedBox(
                duration: MediaQuery.disableAnimationsOf(context) ? Duration.zero : const Duration(milliseconds: 250),
                curve: Curves.easeOut,
                widthFactor: clamped,
                child: Container(height: 9, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(AppTheme.radiusSm))),
              ),
            ],
          ),
        ),
        if (label != null) ...[
          const SizedBox(height: 6),
          Text(label!, style: const TextStyle(fontSize: 12, color: AppTheme.textMuted, fontWeight: FontWeight.w600)),
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

  const KpiEmptyState({super.key, required this.icon, required this.title, required this.message, this.actionLabel, this.onAction});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppTheme.space2xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: AppTheme.textMuted),
            const SizedBox(height: AppTheme.spaceLg),
            Text(title, style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
            const SizedBox(height: AppTheme.spaceSm),
            Text(message, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: AppTheme.textMuted), textAlign: TextAlign.center),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: AppTheme.spaceXl),
              OutlinedButton.icon(onPressed: onAction, icon: const Icon(Icons.refresh_rounded), label: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}

class KpiQuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final Color color;

  const KpiQuickAction({super.key, required this.icon, required this.label, required this.onTap, this.color = AppTheme.primary});

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: Material(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(AppTheme.radiusMd),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(AppTheme.radiusMd),
          child: Container(
            constraints: const BoxConstraints(minHeight: 88),
            padding: const EdgeInsets.symmetric(horizontal: AppTheme.spaceMd, vertical: AppTheme.spaceLg),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(AppTheme.radiusMd), border: Border.all(color: AppTheme.border)),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: color, size: 24),
                const SizedBox(height: AppTheme.spaceSm),
                Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppTheme.textInk), maxLines: 2),
              ],
            ),
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
  ImplicitlyAnimatedWidgetState<AnimatedFractionallySizedBox> createState() => _AnimatedFractionallySizedBoxState();
}

class _AnimatedFractionallySizedBoxState extends AnimatedWidgetBaseState<AnimatedFractionallySizedBox> {
  Tween<double>? _width;

  @override
  void forEachTween(TweenVisitor<dynamic> visitor) {
    _width = visitor(_width, widget.widthFactor, (value) => Tween<double>(begin: value as double)) as Tween<double>?;
  }

  @override
  Widget build(BuildContext context) {
    return FractionallySizedBox(widthFactor: _width?.evaluate(animation), child: widget.child);
  }
}

Map<String, dynamic> kpiStatusPresentation(String status) {
  switch (status) {
    case 'submitted': return {'label': 'Menunggu review', 'color': AppTheme.statusSubmitted, 'icon': Icons.schedule_rounded};
    case 'under_review': return {'label': 'Sedang direview', 'color': AppTheme.statusUnderReview, 'icon': Icons.rate_review_rounded};
    case 'revision_required': return {'label': 'Perlu revisi', 'color': AppTheme.statusRevision, 'icon': Icons.edit_note_rounded};
    case 'verified': return {'label': 'Terverifikasi', 'color': AppTheme.statusVerified, 'icon': Icons.verified_rounded};
    case 'approved': return {'label': 'Disetujui', 'color': AppTheme.statusApproved, 'icon': Icons.check_circle_rounded};
    case 'locked': return {'label': 'Terkunci', 'color': AppTheme.statusApproved, 'icon': Icons.lock_rounded};
    default: return {'label': 'Draft', 'color': AppTheme.statusDraft, 'icon': Icons.edit_note_rounded};
  }
}
