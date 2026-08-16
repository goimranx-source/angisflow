import { useTheme } from '@/hooks/useTheme';
import { useSession } from '@/providers/SessionProvider';

type AngisflowLogoProps = {
    size?: 'small' | 'medium' | 'large';
    className?: string;
    showFallback?: boolean;
    forceType?: 'favicon' | 'full'; // Add option to force logo type
};

/**
 * Angisflow branded logo with theme-aware source selection.
 * 
 * - small size: Always uses favicon (square logo)
 * - medium/large size: Uses full logo with theme switching
 * - Theme switching: dark theme uses white logo, light theme uses regular logo
 */
export function AngisflowLogo({ 
    size = 'medium', 
    className = '', 
    showFallback = true,
    forceType
}: AngisflowLogoProps) {
    const { dark } = useTheme();
    const { app } = useSession();

    const getSrc = () => {
        // Force favicon if specified or if small size
        if (forceType === 'favicon' || size === 'small') {
            return '/img/angisflow-favicon.png';
        }
        
        // Force full logo if specified
        if (forceType === 'full') {
            return dark ? '/img/angisflow-logo-white.png' : '/img/angisflow-logo.png';
        }
        
        // Default: medium and large use full logo with theme switching
        if (size === 'medium' || size === 'large') {
            return dark ? '/img/angisflow-logo-white.png' : '/img/angisflow-logo.png';
        }
        
        // Fallback
        return '/img/angisflow-favicon.png';
    };

    const getAlt = () => app.name || 'Angisflow';

    const sizeClasses = {
        small: 'h-8 w-8', // Square favicon for sidebar and mobile
        medium: 'h-8 w-auto max-w-32', // Full logo for header, constrained width
        large: 'h-10 w-auto max-w-40' // Larger full logo
    };

    return (
        <img 
            src={getSrc()} 
            alt={getAlt()}
            className={`${sizeClasses[size]} object-contain ${className}`}
            onError={(e) => {
                // If logo fails to load and fallback is enabled, show SVG
                if (showFallback) {
                    const target = e.target as HTMLImageElement;
                    target.style.display = 'none';
                    const parent = target.parentElement;
                    if (parent) {
                        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        svg.setAttribute('viewBox', '0 0 32 32');
                        svg.setAttribute('class', `${sizeClasses.small} object-contain`); // Always use small for fallback SVG
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
                }
            }}
        />
    );
}