import { createContext, useContext, useState, useRef, type ReactNode } from 'react';

type NavigationLoadingContextType = {
    isNavigating: boolean;
    showLoader: boolean;
    startNavigation: () => void;
    stopNavigation: () => void;
};

const NavigationLoadingContext = createContext<NavigationLoadingContextType | null>(null);

export function NavigationLoadingProvider({ children }: { children: ReactNode }) {
    const [isNavigating, setIsNavigating] = useState(false);
    const [showLoader, setShowLoader] = useState(false);
    const showTimerRef = useRef<number | null>(null);
    const hideTimerRef = useRef<number | null>(null);

    const startNavigation = () => {
        // Clear any pending hide timer
        if (hideTimerRef.current) {
            window.clearTimeout(hideTimerRef.current);
            hideTimerRef.current = null;
        }

        setIsNavigating(true);
        
        // Only show loader if navigation takes longer than 150ms
        // This prevents flash for instant navigations
        showTimerRef.current = window.setTimeout(() => {
            setShowLoader(true);
        }, 150);
    };

    const stopNavigation = () => {
        // Clear the show timer if navigation completes before 150ms
        if (showTimerRef.current) {
            window.clearTimeout(showTimerRef.current);
            showTimerRef.current = null;
        }

        setIsNavigating(false);
        
        // If loader is showing, keep it visible for minimum 200ms for smooth UX
        if (showLoader) {
            hideTimerRef.current = window.setTimeout(() => {
                setShowLoader(false);
            }, 200);
        }
    };

    return (
        <NavigationLoadingContext.Provider value={{ isNavigating, showLoader, startNavigation, stopNavigation }}>
            {children}
        </NavigationLoadingContext.Provider>
    );
}

export function useNavigationLoading() {
    const context = useContext(NavigationLoadingContext);
    if (!context) {
        throw new Error('useNavigationLoading must be used within NavigationLoadingProvider');
    }
    return context;
}
