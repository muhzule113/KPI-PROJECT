export default function DecorativeBackdrop() {
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <div className="absolute -right-40 -top-40 size-[28rem] rounded-full bg-indigo-300/30 blur-3xl" />
            <div className="absolute -bottom-36 -left-40 size-[24rem] rounded-full bg-sky-200/60 blur-3xl" />

            <svg
                className="absolute -right-40 top-1/2 w-[32rem] -translate-y-1/2 rotate-12 text-indigo-300/55 sm:-right-24 sm:w-[38rem]"
                viewBox="0 0 640 640"
                fill="none"
            >
                <circle cx="320" cy="320" r="238" stroke="currentColor" strokeWidth="1.5" />
                <circle cx="320" cy="320" r="174" stroke="currentColor" strokeWidth="1.5" strokeDasharray="7 14" />
                <path d="M320 82c132 0 238 106 238 238" stroke="currentColor" strokeLinecap="round" strokeWidth="12" />
                <rect x="267" y="267" width="106" height="106" rx="30" fill="currentColor" fillOpacity=".12" />
                <circle cx="320" cy="320" r="20" fill="currentColor" fillOpacity=".32" />
            </svg>

            <svg
                className="absolute -bottom-20 -left-16 w-56 -rotate-12 text-sky-300/45 sm:w-72"
                viewBox="0 0 280 280"
                fill="none"
            >
                <rect x="24" y="24" width="232" height="232" rx="58" stroke="currentColor" strokeWidth="2" />
                <circle cx="140" cy="140" r="72" stroke="currentColor" strokeWidth="2" strokeDasharray="10 12" />
                <path d="M140 68v144M68 140h144" stroke="currentColor" strokeLinecap="round" strokeWidth="2" />
            </svg>
        </div>
    );
}
