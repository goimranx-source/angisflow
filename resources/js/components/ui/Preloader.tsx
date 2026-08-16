import { useEffect } from 'react';

export function Preloader() {
    useEffect(() => {
        // Hide the HTML preloader once React is ready
        const preloader = document.getElementById('prism-preloader');
        if (preloader) {
            // Small delay to ensure smooth transition
            const timer = setTimeout(() => {
                preloader.style.opacity = '0';
                preloader.style.transition = 'opacity 0.3s ease';
                setTimeout(() => {
                    preloader.remove();
                }, 300);
            }, 200);
            
            return () => clearTimeout(timer);
        }
    }, []);

    // This component doesn't render anything - it just removes the HTML preloader
    return null;
}
