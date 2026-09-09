import type { Icon } from "@phosphor-icons/react";
import type { UserRole } from "@/modules/access/policy";

export function AuthWorkbenchIllustration({ compact = false }: { compact?: boolean }) {
  return (
    <svg
      className={compact ? "workbench-illustration compact" : "workbench-illustration"}
      viewBox="0 0 640 480"
      role="img"
      aria-label="Alur penilaian dari ponsel, checklist harian, dan kalender bulanan"
    >
      <path className="illustration-bench" d="M20 420H620" />
      <path className="illustration-detail" d="M66 438h116m278 0h112" />

      <g className="illustration-phone">
        <rect x="44" y="62" width="228" height="352" rx="34" />
        <path d="M126 88h64" />
        <rect className="illustration-paper" x="68" y="114" width="180" height="250" rx="12" />
        <path className="illustration-accent" d="M68 114h180v54H68z" />
        <path d="M93 143h78m-78 64h124m-124 28h96m-96 28h112m-112 28h72" />
        <circle className="illustration-accent" cx="212" cy="322" r="20" />
        <path d="m203 322 7 7 13-16" />
        <circle cx="158" cy="389" r="6" />
      </g>

      <g className="illustration-ticket">
        <path className="illustration-paper" d="M326 42h244v258l-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8-13 8-13-8V42Z" />
        <path className="illustration-accent" d="M326 42h244v44H326z" />
        <path d="M351 64h88m-88 62h116" />
        <rect x="351" y="151" width="18" height="18" rx="3" />
        <path d="m355 159 5 5 10-13m18 8h139" />
        <rect x="351" y="193" width="18" height="18" rx="3" />
        <path d="m355 201 5 5 10-13m18 8h112" />
        <rect x="351" y="235" width="18" height="18" rx="3" />
        <path d="m355 243 5 5 10-13m18 8h128" />
      </g>

      <g className="illustration-calendar">
        <path className="illustration-paper" d="M348 326h188v112H348z" />
        <path d="M348 358h188M380 312v30m124-30v30" />
        <path className="illustration-accent" d="M377 380h28v24h-28zm46 0h28v24h-28zm46 0h28v24h-28z" />
        <path d="M377 419h28m18 0h28m18 0h28" />
      </g>

      <g className="illustration-tool">
        <path d="m584 286 24 24-78 78-24-24 78-78Z" />
        <path className="illustration-accent" d="m502 368 32 32-15 15-32-32 15-15Z" />
        <path d="M591 303c18-18 19-34 9-45l-13 22-20-4-4-20 21-13c-11-9-27-8-45 10-16 16-19 35-11 51" />
      </g>
    </svg>
  );
}

export function RoleWorkbenchIllustration({ role }: { role: UserRole }) {
  return (
    <svg className="role-illustration" viewBox="0 0 360 260" aria-hidden="true" focusable="false">
      <path className="illustration-bench" d="M18 226h324" />
      <g className="role-ticket">
        <path className="illustration-paper" d="M54 30h202v174l-12-7-12 7-12-7-12 7-12-7-12 7-12-7-12 7-12-7-12 7-12-7-12 7-12-7-12 7-12-7-12 7V30Z" />
        <path className="illustration-accent" d="M54 30h202v38H54z" />
        <path d="M78 49h80M78 92h72m-72 24h122m-122 24h98" />
        <RoleMark role={role} />
      </g>
      <g className="role-tool">
        <path d="m264 175 43-43 21 21-43 43" />
        <path className="illustration-accent" d="m254 184 39 39-17 17-39-39 17-17Z" />
        <path d="M303 138c15-15 17-30 9-39l-12 20-18-4-4-18 20-12c-9-8-24-6-39 9-13 13-16 29-9 43" />
      </g>
      <path className="illustration-detail" d="M30 241h86m174 0h38" />
    </svg>
  );
}

function RoleMark({ role }: { role: UserRole }) {
  if (role === "ADMIN") {
    return <g className="role-mark"><rect x="173" y="85" width="27" height="27" /><rect x="207" y="85" width="27" height="27" /><rect x="173" y="119" width="27" height="27" /><rect className="illustration-accent" x="207" y="119" width="27" height="27" /></g>;
  }
  if (role === "MANAGER") {
    return <g className="role-mark"><circle className="illustration-accent" cx="205" cy="116" r="32" /><path d="m189 116 11 11 22-27" /></g>;
  }
  if (role === "SUPERVISOR") {
    return <g className="role-mark"><rect className="illustration-accent" x="171" y="80" width="65" height="76" rx="4" /><path d="M190 76h27v12h-27zm-4 36 7 7 13-16m-20 35 7 7 13-16m8-17h12m-12 26h12" /></g>;
  }
  return <g className="role-mark"><path className="illustration-accent" d="M171 153a37 37 0 1 1 70 0Z" /><path d="M183 144a25 25 0 0 1 46 0m-23 0 15-28" /><circle cx="206" cy="144" r="4" /></g>;
}

export function StateVignette({ icon: Icon }: { icon: Icon }) {
  return (
    <div className="state-vignette" aria-hidden="true">
      <svg viewBox="0 0 170 118" focusable="false">
        <path className="illustration-paper" d="M26 15h118v78l-10-6-10 6-10-6-10 6-10-6-10 6-10-6-10 6-10-6-10 6V15Z" />
        <path className="illustration-accent" d="M26 15h118v22H26z" />
        <path d="M42 26h44M42 53h71M42 69h52" />
        <path className="illustration-detail" d="M13 105h144" />
      </svg>
      <span className="state-vignette-icon"><Icon size={28} weight="duotone" /></span>
    </div>
  );
}
