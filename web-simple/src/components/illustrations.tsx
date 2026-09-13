import type { Icon } from "@phosphor-icons/react";
import type { UserRole } from "@/modules/access/policy";

export function AuthWorkbenchIllustration({ compact = false }: { compact?: boolean }) {
  return (
    <svg
      className={compact ? "workbench-illustration compact" : "workbench-illustration"}
      viewBox="0 0 600 420"
      role="img"
      aria-label="Visual telemetri performa KPI harian toko dan servis"
    >
      <defs>
        <linearGradient id="authGlow" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="var(--accent)" stopOpacity="0.3" />
          <stop offset="100%" stopColor="var(--accent)" stopOpacity="0.02" />
        </linearGradient>
        <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">
          <stop offset="0%" stopColor="var(--surface-soft)" stopOpacity="0.9" />
          <stop offset="100%" stopColor="var(--surface)" stopOpacity="0.95" />
        </linearGradient>
        <linearGradient id="barGrad" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stopColor="var(--accent)" />
          <stop offset="100%" stopColor="var(--info)" />
        </linearGradient>
      </defs>

      {/* Ambient background glow */}
      <circle cx="300" cy="210" r="180" fill="url(#authGlow)" filter="blur(28px)" />

      {/* Main Container Card */}
      <rect x="70" y="40" width="460" height="340" rx="20" fill="url(#cardGrad)" stroke="var(--line)" strokeWidth="1.5" />
      
      {/* Top Header Bar */}
      <rect x="70" y="40" width="460" height="48" rx="20" fill="var(--surface-strong)" opacity="0.6" />
      <circle cx="102" cy="64" r="6" fill="var(--danger)" opacity="0.75" />
      <circle cx="122" cy="64" r="6" fill="var(--warning)" opacity="0.75" />
      <circle cx="142" cy="64" r="6" fill="var(--accent)" opacity="0.75" />
      <rect x="180" y="56" width="160" height="16" rx="8" fill="var(--line)" />

      {/* Left Stat Widget */}
      <rect x="100" y="112" width="180" height="110" rx="14" fill="var(--surface)" stroke="var(--line)" strokeWidth="1" />
      <rect x="118" y="130" width="70" height="12" rx="6" fill="var(--muted)" opacity="0.5" />
      <rect x="118" y="152" width="110" height="24" rx="6" fill="var(--accent)" opacity="0.85" />
      <rect x="118" y="190" width="144" height="6" rx="3" fill="var(--line)" />
      <rect x="118" y="190" width="105" height="6" rx="3" fill="var(--accent)" />

      {/* Right Stat Widget */}
      <rect x="300" y="112" width="200" height="110" rx="14" fill="var(--surface)" stroke="var(--line)" strokeWidth="1" />
      <rect x="318" y="130" width="80" height="12" rx="6" fill="var(--muted)" opacity="0.5" />
      <circle cx="440" cy="165" r="32" stroke="var(--line-strong)" strokeWidth="6" fill="none" />
      <path d="M440 133 A32 32 0 0 1 472 165" stroke="var(--accent)" strokeWidth="6" fill="none" strokeLinecap="round" />
      <rect x="318" y="155" width="80" height="20" rx="6" fill="var(--ink)" opacity="0.8" />
      <rect x="318" y="185" width="60" height="10" rx="5" fill="var(--muted)" opacity="0.4" />

      {/* Bottom Activity Matrix */}
      <rect x="100" y="244" width="400" height="110" rx="14" fill="var(--surface)" stroke="var(--line)" strokeWidth="1" />
      <rect x="118" y="262" width="100" height="12" rx="6" fill="var(--muted)" opacity="0.5" />
      
      {/* Mini Bar Columns */}
      <rect x="120" y="306" width="24" height="32" rx="6" fill="var(--accent)" opacity="0.7" />
      <rect x="156" y="292" width="24" height="46" rx="6" fill="var(--accent)" opacity="0.9" />
      <rect x="192" y="318" width="24" height="20" rx="6" fill="var(--line-strong)" />
      <rect x="228" y="284" width="24" height="54" rx="6" fill="var(--accent)" />
      <rect x="264" y="300" width="24" height="38" rx="6" fill="var(--accent)" opacity="0.8" />
      <rect x="300" y="290" width="24" height="48" rx="6" fill="url(#barGrad)" />
      <rect x="336" y="312" width="24" height="26" rx="6" fill="var(--line-strong)" />
      <rect x="372" y="280" width="24" height="58" rx="6" fill="var(--accent)" />
      <rect x="408" y="296" width="24" height="42" rx="6" fill="var(--accent)" opacity="0.85" />
      <rect x="444" y="304" width="24" height="34" rx="6" fill="var(--accent)" opacity="0.65" />
    </svg>
  );
}

export function RoleWorkbenchIllustration({ role }: { role: UserRole }) {
  return (
    <svg className="role-illustration" viewBox="0 0 340 220" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id={`glow-${role}`} x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor="var(--accent)" stopOpacity="0.25" />
          <stop offset="100%" stopColor="var(--accent)" stopOpacity="0.02" />
        </linearGradient>
      </defs>

      {/* Soft Backdrop Card */}
      <rect x="16" y="16" width="308" height="188" rx="18" fill="var(--surface)" stroke="var(--line)" strokeWidth="1.2" />
      <circle cx="270" cy="54" r="50" fill={`url(#glow-${role})`} />

      {/* Clean Grid & Precision Guides */}
      <line x1="38" y1="52" x2="180" y2="52" stroke="var(--line-strong)" strokeWidth="1" strokeDasharray="3 3" />
      <line x1="38" y1="172" x2="302" y2="172" stroke="var(--line-soft)" strokeWidth="1" />

      {/* Header telemetry pill */}
      <rect x="38" y="34" width="80" height="18" rx="9" fill="var(--accent-soft)" stroke="var(--accent)" strokeWidth="0.8" />
      <circle cx="48" cy="43" r="3.5" fill="var(--accent)" />
      <rect x="58" y="40" width="50" height="6" rx="3" fill="var(--accent)" opacity="0.8" />

      {/* Role specific telemetry visualization */}
      <RoleTelemetry role={role} />
    </svg>
  );
}

function RoleTelemetry({ role }: { role: UserRole }) {
  if (role === "ADMIN") {
    return (
      <g className="telemetry-admin">
        <rect x="38" y="74" width="120" height="80" rx="10" fill="var(--surface-soft)" stroke="var(--line)" />
        <rect x="50" y="86" width="40" height="10" rx="5" fill="var(--muted)" opacity="0.6" />
        <rect x="50" y="104" width="70" height="18" rx="5" fill="var(--ink)" opacity="0.9" />
        <rect x="50" y="132" width="96" height="6" rx="3" fill="var(--accent)" />

        {/* Matrix Nodes */}
        <circle cx="210" cy="85" r="14" fill="var(--surface-strong)" stroke="var(--line-strong)" />
        <circle cx="260" cy="85" r="14" fill="var(--accent-soft)" stroke="var(--accent)" />
        <circle cx="210" cy="135" r="14" fill="var(--accent-soft)" stroke="var(--accent)" />
        <circle cx="260" cy="135" r="14" fill="var(--surface-strong)" stroke="var(--line-strong)" />
        <line x1="224" y1="85" x2="246" y2="85" stroke="var(--line-strong)" strokeWidth="1.5" />
        <line x1="210" y1="99" x2="210" y2="121" stroke="var(--line-strong)" strokeWidth="1.5" />
        <line x1="260" y1="99" x2="260" y2="121" stroke="var(--line-strong)" strokeWidth="1.5" />
        <line x1="224" y1="135" x2="246" y2="135" stroke="var(--line-strong)" strokeWidth="1.5" />
      </g>
    );
  }

  if (role === "MANAGER") {
    return (
      <g className="telemetry-manager">
        {/* Verification Check Gauge */}
        <circle cx="95" cy="115" r="38" fill="var(--surface-soft)" stroke="var(--line)" strokeWidth="1" />
        <circle cx="95" cy="115" r="30" fill="none" stroke="var(--line-strong)" strokeWidth="4" />
        <path d="M95 85 A30 30 0 1 1 68 126" fill="none" stroke="var(--accent)" strokeWidth="4" strokeLinecap="round" />
        <path d="m86 115 6 6 14-14" fill="none" stroke="var(--accent)" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />

        {/* Progress List Items */}
        <rect x="156" y="74" width="146" height="24" rx="8" fill="var(--surface-soft)" stroke="var(--line)" />
        <circle cx="170" cy="86" r="4" fill="var(--accent)" />
        <rect x="182" y="82" width="80" height="8" rx="4" fill="var(--ink)" opacity="0.8" />

        <rect x="156" y="104" width="146" height="24" rx="8" fill="var(--surface-soft)" stroke="var(--line)" />
        <circle cx="170" cy="116" r="4" fill="var(--warning)" />
        <rect x="182" y="112" width="94" height="8" rx="4" fill="var(--ink)" opacity="0.8" />

        <rect x="156" y="134" width="146" height="24" rx="8" fill="var(--surface-soft)" stroke="var(--line)" />
        <circle cx="170" cy="146" r="4" fill="var(--info)" />
        <rect x="182" y="142" width="65" height="8" rx="4" fill="var(--ink)" opacity="0.8" />
      </g>
    );
  }

  if (role === "SUPERVISOR") {
    return (
      <g className="telemetry-supervisor">
        {/* Daily scoring bars */}
        <rect x="38" y="72" width="264" height="84" rx="10" fill="var(--surface-soft)" stroke="var(--line)" />
        <rect x="52" y="84" width="70" height="8" rx="4" fill="var(--muted)" opacity="0.6" />

        {/* Mini progress tracker pills */}
        <rect x="52" y="104" width="236" height="8" rx="4" fill="var(--line)" />
        <rect x="52" y="104" width="180" height="8" rx="4" fill="var(--accent)" />

        <rect x="52" y="122" width="236" height="8" rx="4" fill="var(--line)" />
        <rect x="52" y="122" width="210" height="8" rx="4" fill="var(--accent)" opacity="0.8" />

        <rect x="52" y="140" width="236" height="8" rx="4" fill="var(--line)" />
        <rect x="52" y="140" width="140" height="8" rx="4" fill="var(--info)" />
      </g>
    );
  }

  return (
    <g className="telemetry-employee">
      {/* Gauge score */}
      <circle cx="100" cy="114" r="36" fill="var(--surface-soft)" stroke="var(--line)" />
      <circle cx="100" cy="114" r="28" fill="none" stroke="var(--line)" strokeWidth="4" />
      <path d="M100 86 A28 28 0 1 1 76 128" fill="none" stroke="var(--accent)" strokeWidth="4" strokeLinecap="round" />
      <text x="100" y="120" textAnchor="middle" fill="var(--ink)" fontSize="15" fontWeight="700" fontFamily="inherit">KPI</text>

      {/* Target & Achievement pills */}
      <rect x="160" y="80" width="142" height="30" rx="8" fill="var(--surface-soft)" stroke="var(--line)" />
      <rect x="174" y="91" width="50" height="8" rx="4" fill="var(--muted)" opacity="0.6" />
      <rect x="248" y="88" width="40" height="14" rx="6" fill="var(--accent-soft)" stroke="var(--accent)" strokeWidth="0.8" />

      <rect x="160" y="120" width="142" height="30" rx="8" fill="var(--surface-soft)" stroke="var(--line)" />
      <rect x="174" y="131" width="60" height="8" rx="4" fill="var(--muted)" opacity="0.6" />
      <rect x="248" y="128" width="40" height="14" rx="6" fill="var(--info-soft)" stroke="var(--info)" strokeWidth="0.8" />
    </g>
  );
}

export function StateVignette({ icon: Icon }: { icon: Icon }) {
  return (
    <div className="state-vignette" aria-hidden="true">
      <div className="state-vignette-backdrop" />
      <span className="state-vignette-icon">
        <Icon size={32} weight="duotone" />
      </span>
    </div>
  );
}
