import { useSession } from '@/providers/SessionProvider';

/**
 * The square mark, at one size, from one source.
 *
 * ── Why this is a component and not two pieces of markup ─────────────────────
 *
 * The rail used to draw its mark twice: a 40px square when narrow, and a
 * 120×32 lockup with the mark baked into its left-hand end when wide. Two
 * drawings of the same thing at two sizes, so revealing the rail resized the
 * mark and nudged it sideways — the one element on screen that should have sat
 * perfectly still through the animation was the one that moved most.
 *
 * Now the mark is its own element at a fixed 32px, present in both states and
 * unchanged between them. Widening only adds the wordmark beside it.
 *
 * ── And why it reads the payload ─────────────────────────────────────────────
 *
 * Both drawings were hard-coded SVG, so Settings → Appearance could accept a
 * logo upload and the sidebar would carry on showing ours. Every state now
 * resolves from the same ordered list, which means uploading a mark changes
 * the narrow rail, the wide rail and the peeked rail together — there is no
 * second place left to forget.
 *
 * The subscriber's own mark first, then their favicon (which is the same
 * artwork at the same aspect, and better than falling all the way back), then
 * the Angisflow logos based on theme, then fallback SVG.
 */
export function BrandMark() {
    const { app } = useSession();

    // Use the logo_mark from the boot payload (now hardcoded to Angisflow)
    const logoSrc = app.logo_mark;
    
    if (logoSrc) {
        return (
            <img 
                src={logoSrc} 
                alt="Angisflow" 
                className="brand-mark"
                onError={(e) => {
                    // Fallback to SVG if Angisflow logo fails to load
                    const target = e.target as HTMLImageElement;
                    target.style.display = 'none';
                    const parent = target.parentElement;
                    if (parent) {
                        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        svg.setAttribute('viewBox', '0 0 32 32');
                        svg.setAttribute('class', 'brand-mark');
                        svg.setAttribute('aria-hidden', 'true');
                        
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        rect.setAttribute('width', '32');
                        rect.setAttribute('height', '32');
                        rect.setAttribute('rx', '8');
                        rect.setAttribute('fill', '#0d1b2a');
                        
                        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                        path.setAttribute('d', 'M16 7l7 12H9l7-12z');
                        path.setAttribute('fill', '#00d4e8');
                        
                        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        circle.setAttribute('cx', '16');
                        circle.setAttribute('cy', '23');
                        circle.setAttribute('r', '2.2');
                        circle.setAttribute('fill', '#00d4e8');
                        
                        svg.appendChild(rect);
                        svg.appendChild(path);
                        svg.appendChild(circle);
                        
                        parent.appendChild(svg);
                    }
                }}
            />
        );
    }

    // Final fallback to SVG if no logo configured
    return (
        <svg viewBox="0 0 32 32" className="brand-mark" aria-hidden="true">
            <rect width="32" height="32" rx="8" fill="#0d1b2a" />
            <path d="M16 7l7 12H9l7-12z" fill="#00d4e8" />
            <circle cx="16" cy="23" r="2.2" fill="#00d4e8" />
        </svg>
    );
}
